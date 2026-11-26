<?php
declare(strict_types=1);

namespace Talyvane\Application\ModelAgency;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Infrastructure\Storage\PrivateMediaStorage;
use Talyvane\Security\Authorization;
use Talyvane\Security\Principal;
use Talyvane\Security\SecretBox;

/** Purpose-, territory-, channel- and term-bound image usage licences. */
final class ModelImageLicenceService
{
    private readonly Closure $usageRightsEnabled;
    private const PURPOSES=['editorial','commercial_campaign','e_commerce','social_media','advertising','casting_distribution','private_portfolio','other'];
    private const CHANNELS=['website','print','social_media','email','e_commerce','casting_platform','out_of_home','broadcast'];

    /** @param callable():bool $usageRightsEnabled */
    public function __construct(private PDO $pdo,private AuditLogger $audit,private SecretBox $cipher,private PrivateMediaStorage $storage,callable $usageRightsEnabled)
    {
        $this->usageRightsEnabled=Closure::fromCallable($usageRightsEnabled);
    }

    /** @return array{assets:list<array<string,mixed>>,agreements:list<array<string,mixed>>,licences:list<array<string,mixed>>} */
    public function dashboard(Principal $actor,int $limit=100): array
    {
        $this->authorize($actor);
        $limit=max(1,min(100,$limit));
        $assetsQuery=$this->pdo->prepare(
            'SELECT a.id,a.application_id,m.application_reference,m.country_code,a.status,a.mime_type,a.width,a.height,a.created_at
             FROM model_portfolio_assets a JOIN model_talent_applications m ON m.id=a.application_id
             JOIN consent_grants g ON g.id=a.consent_grant_id AND g.granted=1 AND g.withdrawn_at IS NULL
             WHERE a.status="approved" AND a.consent_withdrawn_at IS NULL AND a.private_storage_key IS NOT NULL
             ORDER BY a.created_at DESC LIMIT :limit'
        );
        $assetsQuery->bindValue(':limit',$limit,PDO::PARAM_INT);$assetsQuery->execute();
        $agreementsQuery=$this->pdo->prepare(
            'SELECT id,agreement_reference,application_id,effective_from,effective_until
             FROM model_representation_agreements WHERE status="executed" AND effective_until>UTC_TIMESTAMP()
             ORDER BY created_at DESC LIMIT :limit'
        );
        $agreementsQuery->bindValue(':limit',$limit,PDO::PARAM_INT);$agreementsQuery->execute();
        $licencesQuery=$this->pdo->prepare(
            'SELECT id,licence_reference,application_id,portfolio_asset_id,status,purpose,territory_code,
                    channels,starts_on,ends_on,exclusivity,compensation_amount,currency_code,
                    licence_policy_version,activated_at,revoked_at,expired_at,created_at
             FROM model_image_licences ORDER BY created_at DESC LIMIT :limit'
        );
        $licencesQuery->bindValue(':limit',$limit,PDO::PARAM_INT);$licencesQuery->execute();
        return [
            'assets'=>$assetsQuery->fetchAll(PDO::FETCH_ASSOC),
            'agreements'=>$agreementsQuery->fetchAll(PDO::FETCH_ASSOC),
            'licences'=>$licencesQuery->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    /** @param array<string,mixed> $terms */
    public function createDraft(Principal $actor,string $assetId,string $agreementId,array $terms,string $requestId): string
    {
        $this->authorize($actor);$this->assertFeature();$this->assertRequestId($requestId);$this->validateTerms($terms);
        $this->pdo->beginTransaction();
        try {
            $asset=$this->lockRow('model_portfolio_assets',$assetId);
            $application=$this->lockRow('model_talent_applications',(string)$asset['application_id']);
            $agreement=$this->lockRow('model_representation_agreements',$agreementId);
            $policy=$this->approvedPolicy();
            $this->assertEligible($application,$asset,$agreement,$terms,$policy['policy_version']);
            $this->assertNoConflict($assetId,$terms);
            $id=self::uuid();$reference='MTL-'.gmdate('Y').'-'.strtoupper(bin2hex(random_bytes(6)));
            $grantId=self::uuid();
            $this->pdo->prepare(
                'INSERT INTO consent_grants (id,subject_type,subject_id,purpose,audience,policy_version,granted,granted_at,created_at)
                 VALUES (:id,"model_image_licence",:subject,"image_usage","agency_distribution",:policy,0,UTC_TIMESTAMP(),UTC_TIMESTAMP())'
            )->execute(['id'=>$grantId,'subject'=>$id,'policy'=>$policy['policy_version']]);
            $start=$terms['starts_on'].' 00:00:00';$end=$terms['ends_on'].' 23:59:59';
            $this->pdo->prepare(
                'INSERT INTO model_image_licences
                 (id,licence_reference,portfolio_asset_id,agreement_id,purpose,territory_code,distribution_channel,
                  consent_grant_id,external_execution_reference,status,valid_from,valid_until,created_by,created_at,
                  application_id,licensee_name,licensee_reference,channels,purpose_details,starts_on,ends_on,exclusivity,
                  compensation_amount,currency_code,licence_policy_version,updated_at)
                 VALUES (:id,:reference,:asset,:agreement,:purpose,:territory,:channel,:grant,NULL,"draft",
                  :valid_from,:valid_until,:actor,UTC_TIMESTAMP(),:application,:licensee,:licensee_reference,:channels,
                  :details,:starts_on,:ends_on,:exclusive,:amount,:currency,:policy,UTC_TIMESTAMP())'
            )->execute([
                'id'=>$id,'reference'=>$reference,'asset'=>$assetId,'agreement'=>$agreementId,'purpose'=>$terms['purpose'],
                'territory'=>$terms['country_code'],'channel'=>$terms['channels'][0],'grant'=>$grantId,
                'valid_from'=>$start,'valid_until'=>$end,'actor'=>$actor->id,'application'=>$application['id'],
                'licensee'=>$this->encrypt($id,'licensee_name',trim($terms['licensee_name'])),
                'licensee_reference'=>isset($terms['licensee_reference'])?$this->encrypt($id,'licensee_reference',trim((string)$terms['licensee_reference'])):null,
                'channels'=>json_encode(array_values($terms['channels']),JSON_THROW_ON_ERROR),
                'details'=>$this->encrypt($id,'purpose_details',trim($terms['purpose_details'])),
                'starts_on'=>$terms['starts_on'],'ends_on'=>$terms['ends_on'],'exclusive'=>$terms['exclusivity']?1:0,
                'amount'=>$terms['compensation_amount']??null,'currency'=>$terms['currency_code']??null,'policy'=>$policy['policy_version'],
            ]);
            $this->event((string)$application['id'],$actor->id,'application.image_licence_drafted','Private purpose-specific image licence draft created; no distribution is authorized.',$policy['policy_version']);
            $this->audit->record($actor->id,'model_licence.created','model_image_licence',$id,
                'Created a private draft image usage licence',null,
                ['application_id'=>$application['id'],'portfolio_asset_id'=>$assetId,'purpose'=>$terms['purpose'],
                 'territory'=>$terms['country_code'],'channels'=>$terms['channels'],'starts_on'=>$terms['starts_on'],
                 'ends_on'=>$terms['ends_on'],'policy_version'=>$policy['policy_version']],
                $requestId,'model-image-licence-v1');
            $this->pdo->commit();return $id;
        } catch(Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function activate(Principal $actor,string $licenceId,string $executionEvidenceReference,string $modelConsentEvidenceReference,string $requestId): void
    {
        $this->authorize($actor);$this->assertFeature();$this->assertRequestId($requestId);
        $this->assertEvidenceReference($executionEvidenceReference,'private/model-licences/');
        if($modelConsentEvidenceReference!=='' && !str_starts_with($modelConsentEvidenceReference,'applicant-portal-attestation:')) {
            $this->assertEvidenceReference($modelConsentEvidenceReference,'private/model-consent/');
        }
        $this->pdo->beginTransaction();
        try {
            $licence=$this->lockRow('model_image_licences',$licenceId);
            if($licence['status']!=='draft') throw new \DomainException('Only a draft image licence can be activated.');
            if($modelConsentEvidenceReference==='') {
                if(!is_string($licence['model_consent_evidence_reference']) || $licence['model_consent_evidence_reference']==='') {
                    throw new \DomainException('A model applicant must first grant purpose-specific consent through the workspace or provide verified consent evidence.');
                }
                $modelConsentEvidenceReference=$this->cipher->decrypt(
                    $licence['model_consent_evidence_reference'],'model-image-licence-v1:'.$licenceId.':model_consent_evidence_reference'
                );
            }
            $applicantAttestation=str_starts_with($modelConsentEvidenceReference,'applicant-portal-attestation:');
            if($applicantAttestation) {
                $storedConsent=$this->cipher->decrypt(
                    (string)$licence['model_consent_evidence_reference'],'model-image-licence-v1:'.$licenceId.':model_consent_evidence_reference'
                );
                if(!hash_equals($storedConsent,$modelConsentEvidenceReference)) {
                    throw new \DomainException('Applicant consent attestation does not match the recorded model decision.');
                }
            } else {
                $this->assertEvidenceReference($modelConsentEvidenceReference,'private/model-consent/');
            }
            $asset=$this->lockRow('model_portfolio_assets',(string)$licence['portfolio_asset_id']);
            $application=$this->lockRow('model_talent_applications',(string)$licence['application_id']);
            $agreement=$this->lockRow('model_representation_agreements',(string)$licence['agreement_id']);
            $policy=$this->approvedPolicy();
            $terms=[
                'purpose'=>$licence['purpose'],'channels'=>json_decode((string)$licence['channels'],true),
                'country_code'=>$licence['territory_code'],'starts_on'=>$licence['starts_on'],'ends_on'=>$licence['ends_on'],
                'exclusivity'=>(bool)$licence['exclusivity'],'compensation_amount'=>$licence['compensation_amount'],
                'currency_code'=>$licence['currency_code'],
            ];
            $this->assertEligible($application,$asset,$agreement,$terms,$policy['policy_version']);
            $this->assertNoConflict((string)$asset['id'],$terms);
            if(!hash_equals((string)$licence['licence_policy_version'],$policy['policy_version'])) {
                throw new \DomainException('Image licence draft uses a stale policy version.');
            }
            $grant=$this->pdo->prepare(
                'SELECT id,granted,withdrawn_at,policy_version,evidence_reference FROM consent_grants
                 WHERE id=:id AND subject_type="model_image_licence" AND subject_id=:subject
                 AND purpose="image_usage" AND audience="agency_distribution" LIMIT 1 FOR UPDATE'
            );
            $grant->execute(['id'=>$licence['consent_grant_id'],'subject'=>$licenceId]);$consent=$grant->fetch(PDO::FETCH_ASSOC);
            if(!is_array($consent) || (int)$consent['granted']!==0 || $consent['withdrawn_at']!==null ||
                !hash_equals($policy['policy_version'],(string)$consent['policy_version']) ||
                ($applicantAttestation && !hash_equals(hash('sha256',$modelConsentEvidenceReference),(string)$consent['evidence_reference']))) {
                throw new \DomainException('A fresh, purpose-specific model consent record is required for activation.');
            }
            $this->pdo->prepare('UPDATE consent_grants SET granted=1,granted_at=UTC_TIMESTAMP(),evidence_reference=:evidence WHERE id=:id')
                ->execute(['evidence'=>hash('sha256',$modelConsentEvidenceReference),'id'=>$licence['consent_grant_id']]);
            $this->pdo->prepare(
                'UPDATE model_image_licences SET status="active",activated_by=:actor,activated_at=UTC_TIMESTAMP(),
                 execution_evidence_reference=:execution,model_consent_evidence_reference=:consent,updated_at=UTC_TIMESTAMP()
                 WHERE id=:id AND status="draft"'
            )->execute([
                'actor'=>$actor->id,'execution'=>$this->encrypt($licenceId,'execution_evidence_reference',$executionEvidenceReference),
                'consent'=>$this->encrypt($licenceId,'model_consent_evidence_reference',$modelConsentEvidenceReference),'id'=>$licenceId,
            ]);
            $this->event((string)$application['id'],$actor->id,'application.image_licence_activated',
                'Externally executed image licence activated after current model consent evidence was recorded.',$policy['policy_version']);
            $this->audit->record($actor->id,'model_licence.activated','model_image_licence',$licenceId,
                'Activated externally executed, purpose-specific image licence', ['status'=>'draft'],['status'=>'active','execution_evidence_present'=>true,'model_consent_present'=>true],
                $requestId,'model-image-licence-v1');
            $this->pdo->commit();
        } catch(Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    /** @return list<array<string,mixed>> */
    public function applicantPortalLicences(string $rightsRequestId): array
    {
        [, $application]=$this->applicantPortalContext($rightsRequestId,false);
        $query=$this->pdo->prepare(
            'SELECT l.id,l.licence_reference,l.purpose,l.territory_code,l.distribution_channel,l.channels,
                    l.starts_on,l.ends_on,l.exclusivity,l.compensation_amount,l.currency_code,l.status,
                    l.licensee_name,l.licensee_reference,l.purpose_details,l.model_consent_evidence_reference,
                    l.execution_evidence_reference,cg.granted,cg.withdrawn_at
             FROM model_image_licences l
             INNER JOIN consent_grants cg ON cg.id=l.consent_grant_id
             WHERE l.application_id=:application ORDER BY l.created_at DESC LIMIT 100'
        );
        $query->execute(['application'=>$application['id']]);
        $rows=[];
        foreach($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['licensee_name']=is_string($row['licensee_name']) && $row['licensee_name']!==''
                ? $this->cipher->decrypt($row['licensee_name'],'model-image-licence-v1:'.$row['id'].':licensee_name') : '';
            $row['licensee_reference']=is_string($row['licensee_reference']) && $row['licensee_reference']!==''
                ? $this->cipher->decrypt($row['licensee_reference'],'model-image-licence-v1:'.$row['id'].':licensee_reference') : null;
            $row['purpose_details']=is_string($row['purpose_details']) && $row['purpose_details']!==''
                ? $this->cipher->decrypt($row['purpose_details'],'model-image-licence-v1:'.$row['id'].':purpose_details') : '';
            $row['channels']=json_decode((string)$row['channels'],true,512,JSON_THROW_ON_ERROR);
            $row['model_consent_recorded']=is_string($row['model_consent_evidence_reference']) && $row['model_consent_evidence_reference']!=='';
            $row['model_consent_granted']=(int)$row['granted']===1 && $row['withdrawn_at']===null;
            $row['execution_evidence_present']=is_string($row['execution_evidence_reference']) && $row['execution_evidence_reference']!=='';
            unset($row['model_consent_evidence_reference'],$row['execution_evidence_reference'],$row['granted'],$row['withdrawn_at']);
            $rows[]=$row;
        }
        return $rows;
    }

    public function recordApplicantDecision(string $rightsRequestId,string $licenceId,string $decision,string $reason,string $requestId): void
    {
        $this->assertRequestId($requestId);
        if(!in_array($decision,['grant','withdraw'],true)) throw new \InvalidArgumentException('Consent decision is invalid.');
        $reason=trim($reason);
        if($decision==='withdraw' && (strlen($reason)<20 || strlen($reason)>2000)) {
            throw new \InvalidArgumentException('Consent withdrawal requires a reason of 20 to 2000 characters.');
        }
        $this->pdo->beginTransaction();
        try {
            [, $application]=$this->applicantPortalContext($rightsRequestId,true);
            $licence=$this->lockRow('model_image_licences',$licenceId);
            if(!hash_equals((string)$application['id'],(string)$licence['application_id'])) {
                throw new \DomainException('This licence does not belong to the authenticated applicant.');
            }
            $asset=$this->lockRow('model_portfolio_assets',(string)$licence['portfolio_asset_id']);
            $agreement=$this->lockRow('model_representation_agreements',(string)$licence['agreement_id']);
            $grantQuery=$this->pdo->prepare(
                'SELECT id,granted,withdrawn_at,policy_version FROM consent_grants
                 WHERE id=:id AND subject_type="model_image_licence" AND subject_id=:subject
                   AND purpose="image_usage" AND audience="agency_distribution" LIMIT 1 FOR UPDATE'
            );
            $grantQuery->execute(['id'=>$licence['consent_grant_id'],'subject'=>$licenceId]);
            $grant=$grantQuery->fetch(PDO::FETCH_ASSOC);
            if(!is_array($grant)) throw new \DomainException('The licence consent record is unavailable.');
            $attestation='applicant-portal-attestation:licence-consent:'.$licenceId.':'.self::uuid();
            if($decision==='grant') {
                if($licence['status']!=='draft' || (int)$application['legal_hold']===1 ||
                    $application['status']!=='accepted' || $application['anonymized_at']!==null ||
                    (int)$grant['granted']!==0 || $grant['withdrawn_at']!==null) {
                    throw new \DomainException('This draft licence is not eligible for applicant consent.');
                }
                if(!is_string($licence['licensee_name']) || $licence['licensee_name']==='' ||
                    !is_string($licence['purpose_details']) || $licence['purpose_details']==='') {
                    throw new \DomainException('The licence is missing a named licensee or intelligible purpose details.');
                }
                $licenseeName=$this->cipher->decrypt($licence['licensee_name'],'model-image-licence-v1:'.$licenceId.':licensee_name');
                $purposeDetails=$this->cipher->decrypt($licence['purpose_details'],'model-image-licence-v1:'.$licenceId.':purpose_details');
                if(trim($licenseeName)==='' || trim($purposeDetails)==='') throw new \DomainException('The licence terms are incomplete and cannot receive applicant consent.');
                $policy=$this->approvedPolicy();
                $terms=[
                    'purpose'=>$licence['purpose'],'channels'=>json_decode((string)$licence['channels'],true,512,JSON_THROW_ON_ERROR),
                    'country_code'=>$licence['territory_code'],'starts_on'=>$licence['starts_on'],'ends_on'=>$licence['ends_on'],
                    'exclusivity'=>(bool)$licence['exclusivity'],'compensation_amount'=>$licence['compensation_amount'],
                    'currency_code'=>$licence['currency_code'],
                ];
                $this->assertEligible($application,$asset,$agreement,$terms,$policy['policy_version']);
                $this->assertNoConflict((string)$asset['id'],$terms);
                if(!hash_equals((string)$licence['licence_policy_version'],$policy['policy_version']) ||
                    !hash_equals((string)$grant['policy_version'],$policy['policy_version'])) {
                    throw new \DomainException('This licence uses a stale consent policy and must be reissued.');
                }
                $this->pdo->prepare('UPDATE consent_grants SET granted=1,granted_at=UTC_TIMESTAMP(),evidence_reference=:evidence WHERE id=:id AND granted=0 AND withdrawn_at IS NULL')
                    ->execute(['evidence'=>hash('sha256',$attestation),'id'=>$grant['id']]);
                $this->pdo->prepare(
                    'UPDATE model_image_licences SET model_consent_evidence_reference=:evidence,updated_at=UTC_TIMESTAMP()
                     WHERE id=:id AND status="draft"'
                )->execute(['evidence'=>$this->encrypt($licenceId,'model_consent_evidence_reference',$attestation),'id'=>$licenceId]);
                $this->applicantEvent((string)$application['id'],'application.image_licence_consent_granted',
                    'Applicant granted affirmative, purpose-specific consent for a draft image licence.',$policy['policy_version']);
                $this->audit->record(null,'model_licence.applicant_consent_granted','model_image_licence',$licenceId,
                    'Applicant granted purpose-specific image usage consent', ['status'=>'draft'],
                    ['consent_granted'=>true,'evidence_type'=>'applicant_attestation','policy_version'=>$policy['policy_version']],
                    $requestId,'model-image-licence-v1');
            } else {
                if(!in_array($licence['status'],['draft','active'],true)) {
                    throw new \DomainException('Only a draft or active image licence can be declined or withdrawn.');
                }
                $reasonForRecord=$reason!==''?$reason:'Applicant declined the draft image licence.';
                $this->pdo->prepare(
                    'UPDATE model_image_licences SET status="revoked",revoked_by=NULL,revoked_at=UTC_TIMESTAMP(),
                     revocation_reason=:reason,updated_at=UTC_TIMESTAMP() WHERE id=:id AND status IN ("draft","active")'
                )->execute(['reason'=>$this->encrypt($licenceId,'revocation_reason',$reasonForRecord),'id'=>$licenceId]);
                $this->pdo->prepare(
                    'UPDATE consent_grants SET granted=0,withdrawn_at=COALESCE(withdrawn_at,UTC_TIMESTAMP()),evidence_reference=:evidence
                     WHERE id=:id'
                )->execute(['evidence'=>hash('sha256',$attestation),'id'=>$grant['id']]);
                $this->invalidateSubmissions($licenceId,null,$reasonForRecord,'applicant');
                $this->applicantEvent((string)$application['id'],'application.image_licence_consent_withdrawn',
                    'Applicant declined or withdrew consent; the image licence and dependent casting submissions were revoked.',
                    $this->policyVersionOrLegacy($licence));
                $this->audit->record(null,'model_licence.applicant_consent_withdrawn','model_image_licence',$licenceId,
                    'Applicant declined or withdrew image usage consent',['status'=>$licence['status']],
                    ['status'=>'revoked','consent_granted'=>false,'reason_hash'=>hash('sha256',$reasonForRecord)],
                    $requestId,'model-image-licence-v1');
            }
            $this->pdo->commit();
        } catch(Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function revoke(Principal $actor,string $licenceId,string $reason,string $requestId): void
    {
        $this->authorize($actor);$this->assertRequestId($requestId);$reason=trim($reason);
        if(strlen($reason)<20 || strlen($reason)>2000) throw new \InvalidArgumentException('Licence revocation requires a reason of 20 to 2000 characters.');
        $this->pdo->beginTransaction();
        try {
            $licence=$this->lockRow('model_image_licences',$licenceId);
            if(!in_array($licence['status'],['draft','active'],true)) throw new \DomainException('Only a draft or active image licence can be revoked.');
            $this->pdo->prepare(
                'UPDATE model_image_licences SET status="revoked",revoked_by=:actor,revoked_at=UTC_TIMESTAMP(),
                 revocation_reason=:reason,updated_at=UTC_TIMESTAMP() WHERE id=:id'
            )->execute(['actor'=>$actor->id,'reason'=>$this->encrypt($licenceId,'revocation_reason',$reason),'id'=>$licenceId]);
            $this->pdo->prepare('UPDATE consent_grants SET granted=0,withdrawn_at=UTC_TIMESTAMP() WHERE id=:id AND granted=1')
                ->execute(['id'=>$licence['consent_grant_id']]);
            $this->invalidateSubmissions($licenceId,$actor->id,$reason);
            $this->event((string)$licence['application_id'],$actor->id,'application.image_licence_revoked','Image usage licence revoked; dependent casting submissions were invalidated.',$this->policyVersionOrLegacy($licence));
            $this->audit->record($actor->id,'model_licence.revoked','model_image_licence',$licenceId,
                'Image usage licence revoked',['status'=>$licence['status']],['status'=>'revoked','reason_hash'=>hash('sha256',$reason)],
                $requestId,'model-image-licence-v1');
            $this->pdo->commit();
        } catch(Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function expireDue(): int
    {
        $query=$this->pdo->query('SELECT id FROM model_image_licences WHERE status="active" AND ends_on<UTC_DATE() ORDER BY ends_on ASC LIMIT 100');
        $ids=$query->fetchAll(PDO::FETCH_COLUMN);$expired=0;
        foreach($ids as $id) {
            $this->pdo->beginTransaction();
            try {
                $licence=$this->lockRow('model_image_licences',(string)$id);
                if($licence['status']!=='active' || $licence['ends_on']>=gmdate('Y-m-d')) {$this->pdo->rollBack();continue;}
                $this->pdo->prepare('UPDATE model_image_licences SET status="expired",expired_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id')
                    ->execute(['id'=>$licence['id']]);
                $this->pdo->prepare('UPDATE consent_grants SET granted=0,withdrawn_at=UTC_TIMESTAMP() WHERE id=:id AND granted=1')
                    ->execute(['id'=>$licence['consent_grant_id']]);
                $this->invalidateSubmissions((string)$licence['id'],null,'Image licence expired at the end of its recorded term.');
                $this->event((string)$licence['application_id'],null,'application.image_licence_expired','Image licence expired and dependent casting submissions were invalidated.',$this->policyVersionOrLegacy($licence));
                $this->pdo->commit();$expired++;
            } catch(Throwable $error) {
                if($this->pdo->inTransaction()) $this->pdo->rollBack();
                throw $error;
            }
        }
        return $expired;
    }

    /** @param array<string,mixed> $application @param array<string,mixed> $asset @param array<string,mixed> $agreement @param array<string,mixed> $terms */
    private function assertEligible(array $application,array $asset,array $agreement,array $terms,string $policyVersion): void
    {
        if($application['status']!=='accepted' || (int)$application['legal_hold']===1 || $application['anonymized_at']!==null ||
            $asset['application_id']!==$application['id'] || $asset['status']!=='approved' || $asset['consent_withdrawn_at']!==null ||
            !is_string($asset['private_storage_key']) || $asset['private_storage_key']==='' ||
            $agreement['application_id']!==$application['id'] || $agreement['status']!=='executed' ||
            $agreement['effective_from']===null || $agreement['effective_until']===null) {
            throw new \DomainException('Image licences require an accepted verified applicant, approved private asset and executed representation agreement.');
        }
        $verification=$this->pdo->prepare('SELECT outcome,expires_at FROM model_applicant_verifications WHERE application_id=:application ORDER BY decision_order DESC LIMIT 1 FOR UPDATE');
        $verification->execute(['application'=>$application['id']]);$v=$verification->fetch(PDO::FETCH_ASSOC);
        if(!is_array($v) || $v['outcome']!=='verified' || $v['expires_at']===null ||
            new DateTimeImmutable((string)$v['expires_at'],new DateTimeZone('UTC'))<=new DateTimeImmutable('now',new DateTimeZone('UTC'))) {
            throw new \DomainException('A current independent adult-eligibility verification is required for image licensing.');
        }
        $this->assertMarketApproved((string)$application['country_code']);
        $assetGrant=$this->pdo->prepare('SELECT granted,withdrawn_at,policy_version FROM consent_grants WHERE id=:id LIMIT 1 FOR UPDATE');
        $assetGrant->execute(['id'=>$asset['consent_grant_id']]);$g=$assetGrant->fetch(PDO::FETCH_ASSOC);
        $portfolioPolicyQuery=$this->pdo->prepare('SELECT setting_json FROM deployment_settings WHERE setting_key="model_agency.portfolio" LIMIT 1');
        $portfolioPolicyQuery->execute();$portfolioRaw=$portfolioPolicyQuery->fetchColumn();
        $portfolioPolicy=is_string($portfolioRaw)?json_decode($portfolioRaw,true):null;
        if(!is_array($g) || (int)$g['granted']!==1 || $g['withdrawn_at']!==null ||
            !hash_equals((string)$asset['consent_policy_version'],(string)$g['policy_version']) ||
            !is_array($portfolioPolicy) || !($portfolioPolicy['enabled']??false) || !($portfolioPolicy['policy_approved']??false) ||
            !is_string($portfolioPolicy['policy_version']??null) || !hash_equals((string)$asset['consent_policy_version'],(string)$portfolioPolicy['policy_version'])) {
            throw new \DomainException('The underlying private portfolio consent and its current policy are not approved.');
        }
        $termsSnapshot=json_decode((string)$agreement['terms_snapshot'],true);
        $requiredScope=$terms['purpose']==='casting_distribution'?'casting_submissions':'portfolio_development';
        if(!is_array($termsSnapshot) || !in_array($requiredScope,$termsSnapshot['service_scope']??[],true)) {
            throw new \DomainException('The executed representation agreement does not cover the requested image usage purpose.');
        }
        $country=(string)$application['country_code'];
        if(($terms['country_code']??'')!==$country) throw new \DomainException('Image licence territory must match the model application and executed representation scope.');
        $start=(string)$terms['starts_on'];$end=(string)$terms['ends_on'];
        $agreementStart=substr((string)$agreement['effective_from'],0,10);$agreementEnd=substr((string)$agreement['effective_until'],0,10);
        if($start<gmdate('Y-m-d') || $end<$start || $start<$agreementStart || $end>$agreementEnd) {
            throw new \DomainException('Image licence dates must be current and remain within the executed representation agreement term.');
        }
        $version=$this->approvedPolicy()['policy_version'];
        if(!hash_equals($policyVersion,$version)) throw new \DomainException('Image licence policy version is no longer current.');
        $amount=$terms['compensation_amount']??null;$currency=$terms['currency_code']??null;
        if(($amount===null)!==($currency===null)) throw new \InvalidArgumentException('Compensation amount and currency must be supplied together.');
        if($amount!==null && (!is_string($amount) || preg_match('/^(?:0|[1-9][0-9]{0,13})(?:\.[0-9]{1,4})?$/D',$amount)!==1 || (float)$amount<=0)) {
            throw new \InvalidArgumentException('Licence compensation must be a positive decimal amount.');
        }
        if($currency!==null) {
            $q=$this->pdo->prepare('SELECT enabled FROM currencies WHERE code=:code LIMIT 1 FOR UPDATE');$q->execute(['code'=>$currency]);
            if((int)$q->fetchColumn()!==1) throw new \DomainException('Licence compensation requires an enabled ISO currency.');
        }
        $assetBytes=$this->storage->read((string)$asset['private_storage_key']);
        if(!hash_equals((string)$asset['sha256'],hash('sha256',$assetBytes))) {
            throw new \DomainException('The approved portfolio asset is missing or failed integrity verification.');
        }
    }

    /** @param array<string,mixed> $terms */
    private function validateTerms(array $terms): void
    {
        $channels=$terms['channels']??null;
        $validDate=static function(mixed $value): bool {
            if(!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value)!==1) return false;
            $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('UTC'));
            $errors=DateTimeImmutable::getLastErrors();
            return $date!==false && ($errors===false || ($errors['warning_count']===0 && $errors['error_count']===0)) && $date->format('Y-m-d')===$value;
        };
        if(!is_string($terms['licensee_name']??null) || strlen(trim($terms['licensee_name']))<2 || strlen($terms['licensee_name'])>160 ||
            !in_array($terms['purpose']??null,self::PURPOSES,true) || !is_array($channels) || !array_is_list($channels) ||
            $channels===[] || count($channels)>8 || count($channels)!==count(array_unique($channels)) ||
            array_diff($channels,self::CHANNELS)!==[] || !is_string($terms['purpose_details']??null) ||
            strlen(trim($terms['purpose_details']))<20 || strlen($terms['purpose_details'])>2000 ||
            !is_string($terms['country_code']??null) || preg_match('/^[A-Z]{2}$/D',$terms['country_code'])!==1 ||
            !$validDate($terms['starts_on']??null) || !$validDate($terms['ends_on']??null) ||
            !is_bool($terms['exclusivity']??null)) {
            throw new \InvalidArgumentException('Image licence terms are invalid.');
        }
        if($terms['purpose']==='casting_distribution' && !in_array('casting_platform',$channels,true)) {
            throw new \InvalidArgumentException('Casting-distribution licences must include the casting platform channel.');
        }
        if(isset($terms['licensee_reference']) && (!is_string($terms['licensee_reference']) || strlen($terms['licensee_reference'])>500)) {
            throw new \InvalidArgumentException('Licensee reference is invalid.');
        }
    }

    /** @param array<string,mixed> $terms */
    private function assertNoConflict(string $assetId,array $terms): void
    {
        $query=$this->pdo->prepare(
            'SELECT purpose,channels,exclusivity FROM model_image_licences
             WHERE portfolio_asset_id=:asset AND territory_code=:territory AND status="active"
               AND starts_on<=:ends_on AND ends_on>=:starts_on FOR UPDATE'
        );
        $query->execute(['asset'=>$assetId,'territory'=>$terms['country_code'],'ends_on'=>$terms['ends_on'],'starts_on'=>$terms['starts_on']]);
        foreach($query->fetchAll(PDO::FETCH_ASSOC) as $existing) {
            $channels=json_decode((string)$existing['channels'],true);
            $overlap=is_array($channels)?array_intersect($terms['channels'],$channels):[];
            if($overlap!==[] && ($terms['exclusivity'] || (int)$existing['exclusivity']===1 || $existing['purpose']===$terms['purpose'])) {
                throw new \DomainException('The requested licence conflicts with an active licence for this asset, territory, channel and overlapping term.');
            }
        }
    }

    private function assertMarketApproved(string $country): void
    {
        $query=$this->pdo->prepare(
            'SELECT c.enabled,m.enabled AS market_enabled,m.decision,m.expires_at,m.evidence_reference
             FROM countries c LEFT JOIN market_policies m ON m.country_code=c.alpha2 WHERE c.alpha2=:country LIMIT 1 FOR UPDATE'
        );
        $query->execute(['country'=>$country]);$row=$query->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row) || (int)$row['enabled']!==1 || (int)($row['market_enabled']??0)!==1 ||
            $row['decision']!=='approved' || $row['expires_at']===null || $row['evidence_reference']===null ||
            new DateTimeImmutable((string)$row['expires_at'],new DateTimeZone('UTC'))<=new DateTimeImmutable('now',new DateTimeZone('UTC'))) {
            throw new \DomainException('Image licensing requires a current approved market with evidence.');
        }
    }

    /** @return array{policy_version:string} */
    private function approvedPolicy(): array
    {
        $q=$this->pdo->prepare('SELECT setting_json FROM deployment_settings WHERE setting_key="model_agency.image_licence" LIMIT 1');$q->execute();
        $raw=$q->fetchColumn();$policy=is_string($raw)?json_decode($raw,true):null;
        if(!is_array($policy) || !($policy['enabled']??false) || !($policy['policy_approved']??false) ||
            !is_string($policy['policy_version']??null) || trim($policy['policy_version'])==='') {
            throw new \DomainException('A current, versioned image usage licence policy must be explicitly approved.');
        }
        return ['policy_version'=>trim($policy['policy_version'])];
    }

    private function invalidateSubmissions(string $licenceId,?string $actorId,string $reason,?string $actorType=null): void
    {
        $q=$this->pdo->prepare('SELECT id,casting_call_id FROM model_casting_submissions WHERE image_licence_id=:licence AND status IN ("submitted","shortlisted","selected") FOR UPDATE');
        $q->execute(['licence'=>$licenceId]);
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $submission) {
            $this->pdo->prepare('UPDATE model_casting_submissions SET status="invalidated",invalidated_at=UTC_TIMESTAMP(),invalidation_reason=:reason,updated_at=UTC_TIMESTAMP() WHERE id=:id')
                ->execute(['reason'=>$this->cipher->encrypt('Underlying image licence was revoked or expired.','model-casting-submission-v1:'.$submission['id'].':invalidation_reason'),'id'=>$submission['id']]);
            $this->pdo->prepare(
                'INSERT INTO model_casting_events (id,call_id,submission_id,actor_id,actor_type,event_type,reason,occurred_at,created_at,updated_at)
                 VALUES (:id,:call,:submission,:actor,:actor_type,"casting.submission_invalidated","Submission invalidated because its image licence is no longer active.",UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())'
            )->execute(['id'=>self::uuid(),'call'=>$submission['casting_call_id'],'submission'=>$submission['id'],'actor'=>$actorId,'actor_type'=>$actorType??($actorId===null?'system':'administrator')]);
        }
    }

    private function event(string $applicationId,?string $actorId,string $type,string $reason,string $policyVersion): void
    {
        $q=$this->pdo->prepare('SELECT status FROM model_talent_applications WHERE id=:id LIMIT 1');$q->execute(['id'=>$applicationId]);$status=(string)$q->fetchColumn();
        $this->pdo->prepare(
            'INSERT INTO model_talent_application_events (id,application_id,actor_user_id,actor_type,from_status,to_status,reason,policy_version,created_at)
             VALUES (:id,:application,:actor,:actor_type,:from_status,:to_status,:reason,:policy,UTC_TIMESTAMP())'
        )->execute(['id'=>self::uuid(),'application'=>$applicationId,'actor'=>$actorId,'actor_type'=>$actorId===null?'system':'administrator',
            'from_status'=>$status,'to_status'=>$status,'reason'=>substr($reason,0,500),'policy'=>$policyVersion]);
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>} */
    private function applicantPortalContext(string $rightsRequestId,bool $lock): array
    {
        $sql='SELECT r.id,r.application_id,r.status,r.request_type,r.expires_at,r.completed_at,
                     a.status AS application_status,a.legal_hold,a.anonymized_at,a.country_code
              FROM model_applicant_rights_requests r
              INNER JOIN model_talent_applications a ON a.id=r.application_id
              WHERE r.id=:id LIMIT 1'.($lock?' FOR UPDATE':'');
        $query=$this->pdo->prepare($sql);$query->execute(['id'=>$rightsRequestId]);$row=$query->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row) || $row['status']!=='verified' || $row['request_type']!=='portal_access' ||
            $row['completed_at']!==null || $row['anonymized_at']!==null ||
            new \DateTimeImmutable((string)$row['expires_at'],new \DateTimeZone('UTC'))<=new \DateTimeImmutable('now',new \DateTimeZone('UTC'))) {
            throw new \DomainException('The verified model workspace session is invalid or expired.');
        }
        $rights=['id'=>$row['id'],'application_id'=>$row['application_id'],'status'=>$row['status']];
        $application=['id'=>$row['application_id'],'status'=>$row['application_status'],
            'legal_hold'=>$row['legal_hold'],'anonymized_at'=>$row['anonymized_at'],'country_code'=>$row['country_code']];
        return [$rights,$application];
    }

    private function applicantEvent(string $applicationId,string $eventType,string $reason,string $policyVersion): void
    {
        $this->pdo->prepare(
            'INSERT INTO model_talent_application_events
             (id,application_id,actor_user_id,actor_type,from_status,to_status,reason,policy_version,created_at)
             VALUES (:id,:application,NULL,"applicant",NULL,NULL,:reason,:policy,UTC_TIMESTAMP())'
        )->execute(['id'=>self::uuid(),'application'=>$applicationId,'reason'=>$eventType.': '.$reason,'policy'=>$policyVersion]);
    }

    private function lockRow(string $table,string $id): array
    {
        $allowed=['model_image_licences','model_portfolio_assets','model_talent_applications','model_representation_agreements'];
        if(!in_array($table,$allowed,true) || !preg_match('/^[0-9a-f-]{36}$/iD',$id)) throw new \InvalidArgumentException('Model image licence identifier is invalid.');
        $q=$this->pdo->prepare('SELECT * FROM '.$table.' WHERE id=:id LIMIT 1 FOR UPDATE');$q->execute(['id'=>$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row)) throw new \DomainException('Model image licence resource does not exist.');
        return $row;
    }

    private function authorize(Principal $actor): void
    {
        Authorization::require($actor,'model_portfolio.manage',true);
        if(!$actor->hasRole('admin') && !$actor->hasRole('owner') && !$actor->hasRole('super_admin')) throw new \DomainException('MFA-verified agency administrator role is required.');
    }

    private function assertFeature(): void
    {
        if(!(($this->usageRightsEnabled)())) throw new \DomainException('Image usage licensing is disabled until legal, consent and market prerequisites are approved.');
    }

    private function assertRequestId(string $requestId): void
    {
        if(strlen($requestId)<16 || strlen($requestId)>64) throw new \InvalidArgumentException('Request identifier is invalid.');
    }

    private function assertEvidenceReference(string $reference,string $prefix): void
    {
        if(strlen($reference)<20 || strlen($reference)>200 || !str_starts_with($reference,$prefix) ||
            preg_match('#^'.preg_quote($prefix,'#').'[A-Za-z0-9][A-Za-z0-9._/-]{10,180}$#D',$reference)!==1 ||
            str_contains($reference,'..') || str_contains($reference,'\\')) {
            throw new \InvalidArgumentException('External execution and model-consent evidence must use valid private evidence references.');
        }
    }

    private function encrypt(string $id,string $field,string $value): string
    {
        return $this->cipher->encrypt($value,'model-image-licence-v1:'.$id.':'.$field);
    }

    private function policyVersionOrLegacy(array $licence): string
    {
        return is_string($licence['licence_policy_version']??null) && $licence['licence_policy_version']!==''?(string)$licence['licence_policy_version']:'model-image-licence-legacy';
    }

    private static function uuid(): string
    {
        $bytes=random_bytes(16);$bytes[6]=chr((ord($bytes[6])&15)|64);$bytes[8]=chr((ord($bytes[8])&63)|128);$hex=bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }
}
