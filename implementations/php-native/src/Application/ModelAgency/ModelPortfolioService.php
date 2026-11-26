<?php
declare(strict_types=1);

namespace Talyvane\Application\ModelAgency;

use Closure;
use PDO;
use Throwable;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Infrastructure\Storage\PrivateMediaStorage;
use Talyvane\Security\Authorization;
use Talyvane\Security\Principal;
use Talyvane\Security\SecretBox;

/** Private, agency-curated model portfolio assets; never a public media source. */
final class ModelPortfolioService
{
    private readonly Closure $usageRightsEnabled;

    /** @param callable():bool $usageRightsEnabled */
    public function __construct(
        private PDO $pdo,
        private PrivateMediaStorage $storage,
        private AuditLogger $audit,
        private SecretBox $cipher,
        callable $usageRightsEnabled
    ) {
        $this->usageRightsEnabled=Closure::fromCallable($usageRightsEnabled);
    }

    /** @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $upload
     *  @param array{source_description:string,consent_scope:string,rights_evidence_reference:string,consent_evidence_reference:string} $evidence
     */
    public function store(Principal $actor,string $applicationId,array $upload,array $evidence,string $requestId): string
    {
        $this->authorize($actor);
        $this->assertFeature();
        $this->assertRequestId($requestId);
        $this->validateEvidence($evidence);
        $this->assertEligible($applicationId,false);
        $image=$this->storage->storeImage($upload,'model-portfolio',8_388_608);
        $assetId=self::uuid();
        $grantId=self::uuid();
        try {
            $this->pdo->beginTransaction();
            $application=$this->assertEligible($applicationId,true);
            $policy=$this->approvedPolicy();
            $duplicate=$this->pdo->prepare('SELECT id FROM model_portfolio_assets WHERE application_id=:application AND sha256=:hash LIMIT 1 FOR UPDATE');
            $duplicate->execute(['application'=>$applicationId,'hash'=>$image['sha256']]);
            if($duplicate->fetchColumn()!==false) throw new \DomainException('This exact image is already recorded in the applicant portfolio.');
            $this->pdo->prepare(
                'INSERT INTO consent_grants
                 (id,subject_type,subject_id,purpose,audience,policy_version,granted,granted_at,withdrawn_at,evidence_reference,created_at)
                 VALUES (:id,"model_portfolio_asset",:subject,"portfolio_curation","agency_internal",:policy,1,UTC_TIMESTAMP(),NULL,:evidence,UTC_TIMESTAMP())'
            )->execute([
                'id'=>$grantId,'subject'=>$assetId,'policy'=>$policy['policy_version'],
                'evidence'=>hash('sha256',$evidence['consent_evidence_reference']),
            ]);
            $this->pdo->prepare(
                'INSERT INTO model_portfolio_assets
                 (id,application_id,uploaded_by,private_storage_key,mime_type,width,height,file_size,sha256,
                  consent_grant_id,review_status,status,source_description,consent_scope,rights_evidence_reference,
                  consent_evidence_reference,consent_policy_version,consent_recorded_at,created_at,updated_at)
                 VALUES (:id,:application,:actor,:key,:mime,:width,:height,:size,:hash,:grant,
                  "pending","pending_review",:source,:scope,:rights,:consent,:policy,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())'
            )->execute([
                'id'=>$assetId,'application'=>$applicationId,'actor'=>$actor->id,'key'=>$image['key'],
                'mime'=>$image['mime'],'width'=>$image['width'],'height'=>$image['height'],'size'=>$image['size'],
                'hash'=>$image['sha256'],'grant'=>$grantId,'source'=>$this->encrypt($assetId,'source_description',$evidence['source_description']),
                'scope'=>$this->encrypt($assetId,'consent_scope',$evidence['consent_scope']),
                'rights'=>$this->encrypt($assetId,'rights_evidence_reference',$evidence['rights_evidence_reference']),
                'consent'=>$this->encrypt($assetId,'consent_evidence_reference',$evidence['consent_evidence_reference']),
                'policy'=>$policy['policy_version'],
            ]);
            $this->event($applicationId,$actor->id,'application.portfolio_asset_uploaded','Private portfolio image received for human review. It is not authorized for public display or campaign use.',$policy['policy_version']);
            $this->audit->record($actor->id,'model_portfolio.asset_uploaded','model_portfolio_asset',$assetId,
                'Private model portfolio asset submitted for review',null,
                ['application_id'=>$applicationId,'status'=>'pending_review','mime_type'=>'image/webp','file_size'=>$image['size'],'sha256'=>$image['sha256'],'consent_policy_version'=>$policy['policy_version']],
                $requestId,'model-portfolio-v1');
            $this->pdo->commit();
            return $assetId;
        } catch(Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            $this->storage->delete($image['key']);
            throw $error;
        }
    }

    /**
     * Applicant-owned portfolio upload, authorized by the active OTP model workspace.
     * Rights and consent evidence are recorded as an explicit applicant attestation,
     * not represented as independent third-party verification.
     *
     * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $upload
     * @param array{source_description:string,consent_scope:string} $evidence
     */
    public function storeByVerifiedApplicantPortal(string $rightsRequestId,array $upload,array $evidence,string $requestId): string
    {
        $this->assertFeature();
        $this->assertRequestId($requestId);
        foreach(['source_description','consent_scope'] as $field) {
            $value=$evidence[$field]??null;
            if(!is_string($value) || strlen(trim($value))<20 || strlen(trim($value))>2000) {
                throw new \InvalidArgumentException('Portfolio source description and consent scope must each contain 20 to 2000 characters.');
            }
        }
        $image=$this->storage->storeImage($upload,'model-portfolio',8_388_608);
        $assetId=self::uuid();$grantId=self::uuid();
        try {
            $this->pdo->beginTransaction();
            $rightsQuery=$this->pdo->prepare(
                'SELECT r.id,r.application_id,r.status,r.request_type,r.expires_at,r.completed_at,
                        a.status AS application_status,a.legal_hold,a.anonymized_at
                 FROM model_applicant_rights_requests r
                 INNER JOIN model_talent_applications a ON a.id=r.application_id
                 WHERE r.id=:rights LIMIT 1 FOR UPDATE'
            );
            $rightsQuery->execute(['rights'=>$rightsRequestId]);$rights=$rightsQuery->fetch(PDO::FETCH_ASSOC);
            if(!is_array($rights) || $rights['status']!=='verified' || $rights['request_type']!=='portal_access' ||
                $rights['completed_at']!==null || $rights['anonymized_at']!==null ||
                new \DateTimeImmutable((string)$rights['expires_at'],new \DateTimeZone('UTC'))<=new \DateTimeImmutable('now',new \DateTimeZone('UTC'))) {
                throw new \DomainException('The verified model workspace session is invalid or expired.');
            }
            if((int)$rights['legal_hold']===1) throw new \DomainException('Portfolio uploads are paused while safeguarding/legal-hold review is active.');
            $application=$this->assertEligible((string)$rights['application_id'],true);
            $policy=$this->approvedPolicy();
            $duplicate=$this->pdo->prepare('SELECT id FROM model_portfolio_assets WHERE application_id=:application AND sha256=:hash LIMIT 1 FOR UPDATE');
            $duplicate->execute(['application'=>$rights['application_id'],'hash'=>$image['sha256']]);
            if($duplicate->fetchColumn()!==false) throw new \DomainException('This exact image is already recorded in your portfolio.');
            $attestation='applicant-portal-attestation:'.$assetId;
            $this->pdo->prepare(
                'INSERT INTO consent_grants
                 (id,subject_type,subject_id,purpose,audience,policy_version,granted,granted_at,withdrawn_at,evidence_reference,created_at)
                 VALUES (:id,"model_portfolio_asset",:subject,"portfolio_curation","agency_internal",:policy,1,UTC_TIMESTAMP(),NULL,:evidence,UTC_TIMESTAMP())'
            )->execute(['id'=>$grantId,'subject'=>$assetId,'policy'=>$policy['policy_version'],'evidence'=>hash('sha256',$attestation)]);
            $this->pdo->prepare(
                'INSERT INTO model_portfolio_assets
                 (id,application_id,uploaded_by,private_storage_key,mime_type,width,height,file_size,sha256,
                  consent_grant_id,review_status,status,source_description,consent_scope,rights_evidence_reference,
                  consent_evidence_reference,consent_policy_version,consent_recorded_at,created_at,updated_at)
                 VALUES (:id,:application,NULL,:key,:mime,:width,:height,:size,:hash,:grant,
                  "pending","pending_review",:source,:scope,:rights,:consent,:policy,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())'
            )->execute([
                'id'=>$assetId,'application'=>$rights['application_id'],'key'=>$image['key'],'mime'=>$image['mime'],
                'width'=>$image['width'],'height'=>$image['height'],'size'=>$image['size'],'hash'=>$image['sha256'],'grant'=>$grantId,
                'source'=>$this->encrypt($assetId,'source_description',trim($evidence['source_description'])),
                'scope'=>$this->encrypt($assetId,'consent_scope',trim($evidence['consent_scope'])),
                'rights'=>$this->encrypt($assetId,'rights_evidence_reference',$attestation),
                'consent'=>$this->encrypt($assetId,'consent_evidence_reference',$attestation),'policy'=>$policy['policy_version'],
            ]);
            $this->pdo->prepare(
                'INSERT INTO model_talent_application_events
                 (id,application_id,actor_user_id,actor_type,from_status,to_status,reason,policy_version,created_at)
                 VALUES (:id,:application,NULL,"applicant",:status,:status,
                         "Applicant uploaded a private portfolio image and recorded a portfolio-curation consent attestation.",:policy,UTC_TIMESTAMP())'
            )->execute(['id'=>self::uuid(),'application'=>$rights['application_id'],'status'=>$application['status'],'policy'=>$policy['policy_version']]);
            $this->audit->record(null,'model_portfolio.applicant_asset_uploaded','model_portfolio_asset',$assetId,
                'Applicant uploaded a private portfolio image through verified model self-service',null,
                ['application_id'=>$rights['application_id'],'status'=>'pending_review','mime_type'=>'image/webp',
                 'file_size'=>$image['size'],'sha256'=>$image['sha256'],'consent_policy_version'=>$policy['policy_version'],
                 'evidence_type'=>'applicant_attestation'],$requestId,'model-applicant-portal-v1');
            $this->pdo->commit();
            return $assetId;
        } catch(Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            $this->storage->delete($image['key']);
            throw $error;
        }
    }

    /** @return array{bytes:string,mime:string} */
    public function previewForVerifiedApplicantPortal(string $rightsRequestId,string $assetId,string $requestId): array
    {
        $this->assertRequestId($requestId);
        $query=$this->pdo->prepare(
            'SELECT a.id,a.application_id,a.private_storage_key,a.mime_type,a.sha256,a.status,a.consent_withdrawn_at,
                    r.application_id AS portal_application_id,r.status AS portal_status,r.request_type,r.expires_at,r.completed_at
             FROM model_portfolio_assets a
             INNER JOIN model_applicant_rights_requests r ON r.id=:rights
             WHERE a.id=:asset AND a.application_id=r.application_id LIMIT 1'
        );
        $query->execute(['rights'=>$rightsRequestId,'asset'=>$assetId]);$row=$query->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row) || $row['portal_status']!=='verified' || $row['request_type']!=='portal_access' ||
            $row['completed_at']!==null || $row['consent_withdrawn_at']!==null ||
            !in_array($row['status'],['pending_review','approved'],true) ||
            !is_string($row['private_storage_key']) || $row['private_storage_key']==='' ||
            new \DateTimeImmutable((string)$row['expires_at'],new \DateTimeZone('UTC'))<=new \DateTimeImmutable('now',new \DateTimeZone('UTC'))) {
            throw new \DomainException('The applicant portfolio preview is unavailable.');
        }
        $bytes=$this->storage->read((string)$row['private_storage_key'],8_388_608);
        if($row['mime_type']!=='image/webp' || !hash_equals((string)$row['sha256'],hash('sha256',$bytes))) {
            throw new \DomainException('Applicant portfolio preview failed integrity verification.');
        }
        $this->audit->record(null,'model_portfolio.applicant_asset_viewed','model_portfolio_asset',$assetId,
            'Applicant viewed their own private portfolio image',null,['sha256'=>(string)$row['sha256']],
            $requestId,'model-applicant-portal-v1');
        return ['bytes'=>$bytes,'mime'=>'image/webp'];
    }

    /** @return list<array<string,mixed>> */
    public function eligibleApplications(Principal $actor,int $limit=100): array
    {
        $this->authorize($actor);
        $limit=max(1,min(100,$limit));
        $query=$this->pdo->prepare(
            'SELECT a.id,a.application_reference,a.country_code
             FROM model_talent_applications a
             WHERE a.status="accepted" AND a.legal_hold=0 AND a.anonymized_at IS NULL
               AND EXISTS (
                 SELECT 1 FROM model_applicant_verifications v
                 WHERE v.application_id=a.id AND v.outcome="verified" AND v.expires_at>UTC_TIMESTAMP()
                   AND v.decision_order=(SELECT MAX(v2.decision_order) FROM model_applicant_verifications v2 WHERE v2.application_id=a.id)
               )
             ORDER BY a.updated_at DESC LIMIT :limit'
        );
        $query->bindValue(':limit',$limit,PDO::PARAM_INT);$query->execute();
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    public function pendingReview(Principal $actor,int $limit=50): array
    {
        $this->authorize($actor);
        $limit=max(1,min(100,$limit));
        $query=$this->pdo->prepare(
            'SELECT a.id,a.application_id,m.application_reference,m.country_code,a.mime_type,a.width,a.height,
                    a.file_size,a.status,a.consent_policy_version,a.created_at
             FROM model_portfolio_assets a JOIN model_talent_applications m ON m.id=a.application_id
             WHERE a.status="pending_review" AND a.consent_withdrawn_at IS NULL
             ORDER BY a.created_at ASC LIMIT :limit'
        );
        $query->bindValue(':limit',$limit,PDO::PARAM_INT);$query->execute();
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{bytes:string,mime:string} */
    public function preview(Principal $actor,string $assetId): array
    {
        $this->authorize($actor);
        $query=$this->pdo->prepare('SELECT private_storage_key,mime_type,sha256,status,consent_withdrawn_at FROM model_portfolio_assets WHERE id=:id LIMIT 1');
        $query->execute(['id'=>$assetId]);$asset=$query->fetch(PDO::FETCH_ASSOC);
        if(!is_array($asset) || !in_array($asset['status'],['pending_review','approved'],true) ||
            $asset['consent_withdrawn_at']!==null || !is_string($asset['private_storage_key']) || $asset['private_storage_key']==='') {
            throw new \DomainException('Private portfolio preview is unavailable.');
        }
        $bytes=$this->storage->read($asset['private_storage_key'],8_388_608);
        if(!hash_equals((string)$asset['sha256'],hash('sha256',$bytes)) || $asset['mime_type']!=='image/webp') {
            throw new \DomainException('Private portfolio asset integrity verification failed.');
        }
        return ['bytes'=>$bytes,'mime'=>'image/webp'];
    }

    public function review(Principal $actor,string $assetId,string $decision,string $note,string $requestId): void
    {
        $this->authorize($actor);
        $this->assertRequestId($requestId);
        $note=trim($note);
        if(!in_array($decision,['approved','rejected'],true) || strlen($note)<20 || strlen($note)>2000) {
            throw new \InvalidArgumentException('Choose an allowed review decision and provide a reason of 20 to 2000 characters.');
        }
        if($decision==='approved') $this->assertFeature();
        $this->pdo->beginTransaction();
        try {
            $query=$this->pdo->prepare('SELECT * FROM model_portfolio_assets WHERE id=:id LIMIT 1 FOR UPDATE');
            $query->execute(['id'=>$assetId]);$asset=$query->fetch(PDO::FETCH_ASSOC);
            if(!is_array($asset) || $asset['status']!=='pending_review' || $asset['consent_withdrawn_at']!==null) {
                throw new \DomainException('Only a current private asset awaiting review can be approved or rejected.');
            }
            if($decision==='approved') {
                $this->assertEligible((string)$asset['application_id'],true);
                $grant=$this->pdo->prepare(
                    'SELECT granted,withdrawn_at,policy_version FROM consent_grants
                     WHERE id=:id AND subject_type="model_portfolio_asset" AND subject_id=:subject
                     AND purpose="portfolio_curation" AND audience="agency_internal" LIMIT 1 FOR UPDATE'
                );
                $grant->execute(['id'=>$asset['consent_grant_id'],'subject'=>$assetId]);$consent=$grant->fetch(PDO::FETCH_ASSOC);
                if(!is_array($consent) || (int)$consent['granted']!==1 || $consent['withdrawn_at']!==null ||
                    !hash_equals((string)$asset['consent_policy_version'],(string)$consent['policy_version']) ||
                    !hash_equals($this->approvedPolicy()['policy_version'],(string)$asset['consent_policy_version'])) {
                    throw new \DomainException('Approval requires current, unwithdrawn, purpose-specific portfolio consent.');
                }
                if(!is_string($asset['private_storage_key']) || $asset['private_storage_key']==='') throw new \DomainException('Portfolio image is missing from private storage.');
                $bytes=$this->storage->read($asset['private_storage_key'],8_388_608);
                if(!hash_equals((string)$asset['sha256'],hash('sha256',$bytes))) throw new \DomainException('Portfolio image integrity verification failed.');
            } elseif(is_string($asset['private_storage_key']) && $asset['private_storage_key']!=='') {
                $this->storage->delete($asset['private_storage_key']);
            }
            $key=$decision==='rejected'?null:$asset['private_storage_key'];
            $this->pdo->prepare(
                'UPDATE model_portfolio_assets SET status=:status,review_status=:legacy_status,
                 private_storage_key=:key,reviewed_by=:actor,reviewed_at=UTC_TIMESTAMP(),
                 review_note=:note,updated_at=UTC_TIMESTAMP() WHERE id=:id'
            )->execute([
                'status'=>$decision,'legacy_status'=>$decision,'key'=>$key,'actor'=>$actor->id,
                'note'=>$this->encrypt($assetId,'review_note',$note),'id'=>$assetId,
            ]);
            $this->event((string)$asset['application_id'],$actor->id,'application.portfolio_asset_'.$decision,
                $decision==='approved'?'Portfolio asset approved for private agency curation only; no campaign or public usage is authorized.':'Portfolio asset rejected and private image bytes removed.',
                (string)$asset['consent_policy_version']);
            $this->audit->record($actor->id,'model_portfolio.asset_'.$decision,'model_portfolio_asset',$assetId,
                'Private portfolio review decision recorded',['status'=>'pending_review'],['status'=>$decision,'private_storage_retained'=>$decision==='approved'],
                $requestId,'model-portfolio-v1');
            $this->pdo->commit();
        } catch(Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function withdrawConsent(Principal $actor,string $assetId,string $reason,string $evidenceReference,string $requestId): void
    {
        $this->authorize($actor);
        $this->assertRequestId($requestId);
        $reason=trim($reason);
        if(strlen($reason)<20 || strlen($reason)>2000 || !$this->validEvidenceReference($evidenceReference,'private/model-consent/')) {
            throw new \InvalidArgumentException('Consent withdrawal requires a reason and private model-consent evidence reference.');
        }
        $this->pdo->beginTransaction();
        try {
            $query=$this->pdo->prepare('SELECT * FROM model_portfolio_assets WHERE id=:id LIMIT 1 FOR UPDATE');
            $query->execute(['id'=>$assetId]);$asset=$query->fetch(PDO::FETCH_ASSOC);
            if(!is_array($asset) || $asset['consent_withdrawn_at']!==null || $asset['status']==='withdrawn') {
                throw new \DomainException('Portfolio consent has already been withdrawn or the asset does not exist.');
            }
            $application=$this->pdo->prepare('SELECT id,status FROM model_talent_applications WHERE id=:id LIMIT 1 FOR UPDATE');
            $application->execute(['id'=>$asset['application_id']]);$applicationRow=$application->fetch(PDO::FETCH_ASSOC);
            if(!is_array($applicationRow)) throw new \DomainException('Model application does not exist.');
            if(is_string($asset['private_storage_key']) && $asset['private_storage_key']!=='') $this->storage->delete($asset['private_storage_key']);
            $this->pdo->prepare('UPDATE consent_grants SET granted=0,withdrawn_at=UTC_TIMESTAMP() WHERE id=:id AND granted=1')->execute(['id'=>$asset['consent_grant_id']]);
            $this->pdo->prepare(
                'UPDATE model_portfolio_assets SET status="withdrawn",review_status="withdrawn",private_storage_key=NULL,
                 consent_withdrawn_at=UTC_TIMESTAMP(),withdrawn_at=UTC_TIMESTAMP(),
                 withdrawal_evidence_reference=:evidence,withdrawal_reason=:reason,updated_at=UTC_TIMESTAMP() WHERE id=:id'
            )->execute([
                'evidence'=>$this->encrypt($assetId,'withdrawal_evidence_reference',$evidenceReference),
                'reason'=>$this->encrypt($assetId,'withdrawal_reason',$reason),'id'=>$assetId,
            ]);
            $this->revokeDependentLicences($assetId,$actor->id,$reason);
            $this->event((string)$asset['application_id'],$actor->id,'application.portfolio_consent_withdrawn',
                'Consent withdrawal recorded; private image bytes deleted and dependent usage licences revoked.',(string)($asset['consent_policy_version']??'model-portfolio-legacy'));
            $this->audit->record($actor->id,'model_portfolio.consent_withdrawn','model_portfolio_asset',$assetId,
                'Private portfolio consent withdrawal recorded',['status'=>$asset['status']],['status'=>'withdrawn','private_storage_deleted'=>true,'dependent_licences_revoked'=>true],
                $requestId,'model-portfolio-v1');
            $this->pdo->commit();
        } catch(Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    /**
     * Fulfil an applicant-initiated portfolio withdrawal only from a live, OTP-verified
     * rights request bound to this exact application and asset.
     */
    public function withdrawByVerifiedApplicantRights(string $rightsRequestId,string $assetId,string $requestId): void
    {
        $this->assertRequestId($requestId);
        $this->pdo->beginTransaction();
        try {
            $rightsQuery=$this->pdo->prepare(
                'SELECT r.id,r.application_id,r.related_asset_id,r.status,r.request_type,r.expires_at,
                        a.anonymized_at,a.legal_hold,a.status AS application_status
                 FROM model_applicant_rights_requests r
                 JOIN model_talent_applications a ON a.id=r.application_id
                 WHERE r.id=:rights FOR UPDATE'
            );
            $rightsQuery->execute(['rights'=>$rightsRequestId]);
            $rights=$rightsQuery->fetch(PDO::FETCH_ASSOC);
            if(!is_array($rights) || $rights['status']!=='verified' ||
                $rights['request_type']!=='portfolio_withdrawal' ||
                !hash_equals((string)$rights['related_asset_id'],$assetId) ||
                $rights['anonymized_at']!==null ||
                new \DateTimeImmutable((string)$rights['expires_at'],new \DateTimeZone('UTC'))<=new \DateTimeImmutable('now',new \DateTimeZone('UTC'))) {
                throw new \DomainException('The verified applicant portfolio-withdrawal session is invalid or expired.');
            }
            $assetQuery=$this->pdo->prepare(
                'SELECT id,application_id,private_storage_key,status,consent_grant_id,consent_withdrawn_at
                 FROM model_portfolio_assets WHERE id=:asset AND application_id=:application FOR UPDATE'
            );
            $assetQuery->execute(['asset'=>$assetId,'application'=>$rights['application_id']]);
            $asset=$assetQuery->fetch(PDO::FETCH_ASSOC);
            if(!is_array($asset) || !in_array($asset['status'],['pending_review','approved'],true) ||
                $asset['consent_withdrawn_at']!==null) {
                throw new \DomainException('The requested portfolio asset is no longer available for withdrawal.');
            }
            if((int)$rights['legal_hold']===1) {
                throw new \DomainException('Portfolio withdrawal requires safeguarding/legal-hold review before private bytes can be changed.');
            }
            if(is_string($asset['private_storage_key']) && $asset['private_storage_key']!=='') {
                $this->storage->delete($asset['private_storage_key']);
            }
            $this->pdo->prepare(
                'UPDATE consent_grants SET granted=0,withdrawn_at=UTC_TIMESTAMP()
                 WHERE id=:id AND granted=1 AND withdrawn_at IS NULL'
            )->execute(['id'=>$asset['consent_grant_id']]);
            $this->pdo->prepare(
                'UPDATE model_portfolio_assets SET status="withdrawn",review_status="withdrawn",
                 private_storage_key=NULL,consent_withdrawn_at=UTC_TIMESTAMP(),withdrawn_at=UTC_TIMESTAMP(),
                 withdrawal_evidence_reference=:evidence,withdrawal_reason=:reason,updated_at=UTC_TIMESTAMP()
                 WHERE id=:id'
            )->execute([
                'evidence'=>$this->encrypt($assetId,'withdrawal_evidence_reference','otp-rights:'.$rightsRequestId),
                'reason'=>$this->encrypt($assetId,'withdrawal_reason','Applicant withdrew portfolio consent through a verified privacy-rights session.'),
                'id'=>$assetId,
            ]);
            $licences=$this->pdo->prepare(
                'SELECT id,consent_grant_id FROM model_image_licences
                 WHERE portfolio_asset_id=:asset AND status IN ("draft","active") FOR UPDATE'
            );
            $licences->execute(['asset'=>$assetId]);
            foreach($licences->fetchAll(PDO::FETCH_ASSOC) as $licence) {
                $licenceId=(string)$licence['id'];
                $this->pdo->prepare(
                    'UPDATE model_image_licences SET status="revoked",revoked_at=UTC_TIMESTAMP(),revoked_by=NULL,
                     revocation_reason=:reason,updated_at=UTC_TIMESTAMP() WHERE id=:id'
                )->execute([
                    'reason'=>$this->cipher->encrypt('Applicant withdrew the underlying portfolio consent.','model-image-licence-v1:'.$licenceId.':revocation_reason'),
                    'id'=>$licenceId,
                ]);
                $this->pdo->prepare('UPDATE consent_grants SET granted=0,withdrawn_at=UTC_TIMESTAMP() WHERE id=:id AND granted=1')
                    ->execute(['id'=>$licence['consent_grant_id']]);
                $submissions=$this->pdo->prepare(
                    'SELECT id,casting_call_id FROM model_casting_submissions
                     WHERE image_licence_id=:licence AND status IN ("submitted","shortlisted","selected") FOR UPDATE'
                );
                $submissions->execute(['licence'=>$licenceId]);
                foreach($submissions->fetchAll(PDO::FETCH_ASSOC) as $submission) {
                    $this->pdo->prepare(
                        'UPDATE model_casting_submissions SET status="invalidated",invalidated_at=UTC_TIMESTAMP(),
                         invalidation_reason=:reason,updated_at=UTC_TIMESTAMP() WHERE id=:id'
                    )->execute([
                        'reason'=>$this->cipher->encrypt('Underlying portfolio consent was withdrawn by the applicant.','model-casting-submission-v1:'.$submission['id'].':invalidation_reason'),
                        'id'=>$submission['id'],
                    ]);
                    $this->pdo->prepare(
                        'INSERT INTO model_casting_events
                         (id,call_id,submission_id,actor_id,actor_type,event_type,reason,occurred_at,created_at,updated_at)
                         VALUES (:id,:call,:submission,NULL,"applicant","casting.submission_invalidated",
                                 "Submission invalidated after applicant withdrew portfolio consent.",UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())'
                    )->execute(['id'=>self::uuid(),'call'=>$submission['casting_call_id'],'submission'=>$submission['id']]);
                }
            }
            $this->pdo->prepare(
                'INSERT INTO model_talent_application_events
                 (id,application_id,actor_user_id,actor_type,from_status,to_status,reason,policy_version,created_at)
                 VALUES (:id,:application,NULL,"applicant",:from_status,:to_status,
                         "Applicant withdrew a specific portfolio asset through verified privacy rights.","applicant-portfolio-rights-v1",UTC_TIMESTAMP())'
            )->execute(['id'=>self::uuid(),'application'=>$rights['application_id'],'from_status'=>$rights['application_status'],'to_status'=>$rights['application_status']]);
            $this->pdo->prepare(
                'UPDATE model_applicant_rights_requests SET status="completed",otp_hash="",completed_at=UTC_TIMESTAMP()
                 WHERE id=:id AND status="verified"'
            )->execute(['id'=>$rightsRequestId]);
            $this->audit->record(null,'model_application.portfolio_asset.withdrawn','model_portfolio_asset',$assetId,
                'Applicant withdrew a specific portfolio asset through verified email rights',
                ['status'=>$asset['status']],['status'=>'withdrawn','private_storage_deleted'=>true,'dependent_licences_revoked'=>true],
                $requestId,'applicant-portfolio-rights-v1');
            $this->pdo->commit();
        } catch(Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    /** @return array<string,mixed> */
    private function assertEligible(string $applicationId,bool $lock): array
    {
        $sql='SELECT id,status,country_code,legal_hold,anonymized_at FROM model_talent_applications WHERE id=:id LIMIT 1'.($lock?' FOR UPDATE':'');
        $query=$this->pdo->prepare($sql);$query->execute(['id'=>$applicationId]);$application=$query->fetch(PDO::FETCH_ASSOC);
        if(!is_array($application) || $application['status']!=='accepted' || (int)$application['legal_hold']===1 || $application['anonymized_at']!==null) {
            throw new \DomainException('Portfolio assets require an accepted applicant, no legal hold and no completed anonymization.');
        }
        $verification=$this->pdo->prepare(
            'SELECT outcome,expires_at FROM model_applicant_verifications
             WHERE application_id=:application ORDER BY decision_order DESC LIMIT 1'.($lock?' FOR UPDATE':'')
        );
        $verification->execute(['application'=>$applicationId]);$row=$verification->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row) || $row['outcome']!=='verified' || $row['expires_at']===null ||
            new \DateTimeImmutable((string)$row['expires_at'],new \DateTimeZone('UTC'))<=new \DateTimeImmutable('now',new \DateTimeZone('UTC'))) {
            throw new \DomainException('A current independent adult-eligibility verification is required for portfolio assets.');
        }
        $this->assertMarketApproved((string)$application['country_code'],$lock);
        $this->approvedPolicy();
        return $application;
    }

    private function assertMarketApproved(string $countryCode,bool $lock): void
    {
        $sql='SELECT c.enabled,m.enabled AS market_enabled,m.decision,m.expires_at,m.evidence_reference
              FROM countries c LEFT JOIN market_policies m ON m.country_code=c.alpha2
              WHERE c.alpha2=:country LIMIT 1'.($lock?' FOR UPDATE':'');
        $query=$this->pdo->prepare($sql);$query->execute(['country'=>$countryCode]);$row=$query->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row) || (int)$row['enabled']!==1 || (int)($row['market_enabled']??0)!==1 ||
            $row['decision']!=='approved' || $row['expires_at']===null || $row['evidence_reference']===null ||
            new \DateTimeImmutable((string)$row['expires_at'],new \DateTimeZone('UTC'))<=new \DateTimeImmutable('now',new \DateTimeZone('UTC'))) {
            throw new \DomainException('Model portfolio operations require current country and market compliance approval with evidence.');
        }
    }

    /** @return array{policy_version:string} */
    private function approvedPolicy(): array
    {
        $query=$this->pdo->prepare('SELECT setting_json FROM deployment_settings WHERE setting_key=:key LIMIT 1');
        $query->execute(['key'=>'model_agency.portfolio']);$raw=$query->fetchColumn();
        $policy=is_string($raw)?json_decode($raw,true):null;
        if(!is_array($policy) || !($policy['enabled']??false) || !($policy['policy_approved']??false) ||
            !is_string($policy['policy_version']??null) || trim($policy['policy_version'])==='') {
            throw new \DomainException('A current, versioned model portfolio consent policy must be explicitly approved.');
        }
        return ['policy_version'=>trim($policy['policy_version'])];
    }

    private function revokeDependentLicences(string $assetId,string $actorId,string $reason): void
    {
        $query=$this->pdo->prepare('SELECT id,consent_grant_id FROM model_image_licences WHERE portfolio_asset_id=:asset AND status IN ("draft","active") FOR UPDATE');
        $query->execute(['asset'=>$assetId]);
        foreach($query->fetchAll(PDO::FETCH_ASSOC) as $licence) {
            $licenceId=(string)$licence['id'];
            $this->pdo->prepare(
                'UPDATE model_image_licences SET status="revoked",revoked_at=UTC_TIMESTAMP(),revoked_by=:actor,
                 revocation_reason=:reason,updated_at=UTC_TIMESTAMP() WHERE id=:id'
            )->execute(['actor'=>$actorId,'reason'=>$this->cipher->encrypt($reason,'model-image-licence-v1:'.$licenceId.':revocation_reason'),'id'=>$licenceId]);
            $this->pdo->prepare('UPDATE consent_grants SET granted=0,withdrawn_at=UTC_TIMESTAMP() WHERE id=:id AND granted=1')
                ->execute(['id'=>$licence['consent_grant_id']]);
            $submissions=$this->pdo->prepare(
                'SELECT id,casting_call_id FROM model_casting_submissions WHERE image_licence_id=:licence
                 AND status IN ("submitted","shortlisted","selected") FOR UPDATE'
            );
            $submissions->execute(['licence'=>$licenceId]);
            foreach($submissions->fetchAll(PDO::FETCH_ASSOC) as $submission) {
                $this->pdo->prepare(
                    'UPDATE model_casting_submissions SET status="invalidated",invalidated_at=UTC_TIMESTAMP(),
                     invalidation_reason=:reason,updated_at=UTC_TIMESTAMP() WHERE id=:id'
                )->execute(['reason'=>$this->cipher->encrypt('Underlying portfolio consent was withdrawn.','model-casting-submission-v1:'.$submission['id'].':invalidation_reason'),'id'=>$submission['id']]);
                $this->insertCastingEvent((string)$submission['casting_call_id'],(string)$submission['id'],$actorId,
                    'casting.submission_invalidated','Submission invalidated because underlying portfolio consent was withdrawn.');
            }
        }
    }

    private function insertCastingEvent(string $callId,string $submissionId,string $actorId,string $type,string $reason): void
    {
        $this->pdo->prepare(
            'INSERT INTO model_casting_events (id,call_id,submission_id,actor_id,actor_type,event_type,reason,occurred_at,created_at,updated_at)
             VALUES (:id,:call,:submission,:actor,"administrator",:event,:reason,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())'
        )->execute(['id'=>self::uuid(),'call'=>$callId,'submission'=>$submissionId,'actor'=>$actorId,'event'=>$type,'reason'=>$reason]);
    }

    private function event(string $applicationId,string $actorId,string $type,string $reason,string $policyVersion): void
    {
        $query=$this->pdo->prepare('SELECT status FROM model_talent_applications WHERE id=:id LIMIT 1');
        $query->execute(['id'=>$applicationId]);$status=(string)$query->fetchColumn();
        $this->pdo->prepare(
            'INSERT INTO model_talent_application_events
             (id,application_id,actor_user_id,actor_type,from_status,to_status,reason,policy_version,created_at)
             VALUES (:id,:application,:actor,"administrator",:from_status,:to_status,:reason,:policy,UTC_TIMESTAMP())'
        )->execute([
            'id'=>self::uuid(),'application'=>$applicationId,'actor'=>$actorId,
            'from_status'=>$status,'to_status'=>$status,'reason'=>substr($reason,0,500),'policy'=>$policyVersion,
        ]);
    }

    /** @param array<string,string> $evidence */
    private function validateEvidence(array $evidence): void
    {
        foreach(['source_description','consent_scope'] as $field) {
            $raw=$evidence[$field]??null;
            if(!is_string($raw)) throw new \InvalidArgumentException('Portfolio source description and consent scope must be text.');
            $value=trim($raw);
            if(strlen($value)<20 || strlen($value)>2000) throw new \InvalidArgumentException('Portfolio source description and consent scope must each contain 20 to 2000 characters.');
        }
        $rights=$evidence['rights_evidence_reference']??null;
        $consent=$evidence['consent_evidence_reference']??null;
        if(!is_string($rights) || !$this->validEvidenceReference($rights,'private/model-rights/') ||
            !is_string($consent) || !$this->validEvidenceReference($consent,'private/model-consent/')) {
            throw new \InvalidArgumentException('Portfolio rights and consent evidence must use valid private evidence references.');
        }
    }

    private function validEvidenceReference(string $reference,string $prefix): bool
    {
        return strlen($reference)>=20 && strlen($reference)<=200 && str_starts_with($reference,$prefix) &&
            preg_match('#^'.preg_quote($prefix,'#').'[A-Za-z0-9][A-Za-z0-9._/-]{10,180}$#D',$reference)===1 &&
            !str_contains($reference,'..') && !str_contains($reference,'\\');
    }

    private function encrypt(string $assetId,string $field,string $value): string
    {
        return $this->cipher->encrypt($value,'model-portfolio-asset-v1:'.$assetId.':'.$field);
    }

    private function authorize(Principal $actor): void
    {
        Authorization::require($actor,'model_portfolio.manage',true);
        if(!$actor->hasRole('admin') && !$actor->hasRole('owner') && !$actor->hasRole('super_admin')) {
            throw new \DomainException('MFA-verified agency administrator role is required.');
        }
    }

    private function assertFeature(): void
    {
        if(!(($this->usageRightsEnabled)())) throw new \DomainException('Model portfolio operations are disabled until usage-rights, consent, legal and market prerequisites are approved.');
    }

    private function assertRequestId(string $requestId): void
    {
        if(strlen($requestId)<16 || strlen($requestId)>64) throw new \InvalidArgumentException('Request identifier is invalid.');
    }

    private static function uuid(): string
    {
        $bytes=random_bytes(16);$bytes[6]=chr((ord($bytes[6])&15)|64);$bytes[8]=chr((ord($bytes[8])&63)|128);$hex=bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }
}
