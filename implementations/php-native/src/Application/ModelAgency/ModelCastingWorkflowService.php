<?php
declare(strict_types=1);

namespace Talyvane\Application\ModelAgency;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;
use Talyvane\Domain\ModelAgency\CastingStateMachine;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Infrastructure\Storage\PrivateMediaStorage;
use Talyvane\Security\SecretBox;
use Talyvane\Security\Authorization;
use Talyvane\Security\Principal;

/** Private casting-call lifecycle; no public listing or client distribution. */
final class ModelCastingWorkflowService
{
    private readonly \Closure $castingFeatureEnabled;

    /** @param callable():bool $castingFeatureEnabled */
    public function __construct(
        private PDO $pdo,
        private AuditLogger $audit,
        private PrivateMediaStorage $storage,
        private SecretBox $cipher,
        callable $castingFeatureEnabled
    ) {
        $this->castingFeatureEnabled = \Closure::fromCallable($castingFeatureEnabled);
    }

    /** @param array<string,mixed> $terms */
    public function createDraft(Principal $actor,array $terms,string $requestId): string
    {
        $this->authorize($actor);
        $this->assertFeature();
        $normalized=$this->validateTerms($terms);
        if(strlen($requestId)<16 || strlen($requestId)>64) throw new \InvalidArgumentException('Casting request reference is invalid.');
        $this->pdo->beginTransaction();
        try {
            $this->assertMarketApproved($normalized['country_code'],true);
            $policy=$this->approvedPolicy();
            $currency=$normalized['currency_code'];
            if($currency!==null) {
                $currencyQuery=$this->pdo->prepare('SELECT enabled FROM currencies WHERE code=:code LIMIT 1 FOR UPDATE');
                $currencyQuery->execute(['code'=>$currency]);
                if((int)$currencyQuery->fetchColumn()!==1) throw new \DomainException('Casting compensation requires an enabled ISO currency.');
            }
            $id=self::uuid();
            $reference='MCC-'.gmdate('Y').'-'.strtoupper(bin2hex(random_bytes(6)));
            $brief=$normalized['description']."\n\nRequirements:\n".$normalized['requirements'];
            $insert=$this->pdo->prepare(
                'INSERT INTO model_casting_calls
                 (id,casting_reference,title,client_name,client_reference,project_purpose,description,requirements,
                  private_brief,country_code,opens_at,closes_at,shoot_starts_at,shoot_ends_at,
                  compensation_amount,currency_code,max_submissions,casting_policy_version,agreement_id,
                  status,created_by,created_at,updated_at)
                 VALUES (:id,:reference,:title,:client,:client_reference,:purpose,:description,:requirements,
                  :brief,:country,:opens,:closes,:shoot_start,:shoot_end,:amount,:currency,:max_submissions,
                  :policy,NULL,"draft",:actor,UTC_TIMESTAMP(),UTC_TIMESTAMP())'
            );
            $insert->execute([
                'id'=>$id,'reference'=>$reference,'title'=>$normalized['title'],'client'=>$this->encryptField($id,'client_name',$normalized['client_name']),
                'client_reference'=>$normalized['client_reference']===null?null:$this->encryptField($id,'client_reference',$normalized['client_reference']),'purpose'=>$normalized['project_purpose'],
                'description'=>$this->encryptField($id,'description',$normalized['description']),'requirements'=>$this->encryptField($id,'requirements',$normalized['requirements']),'brief'=>$this->encryptField($id,'private_brief',$brief),
                'country'=>$normalized['country_code'],'opens'=>$normalized['opens_at'],'closes'=>$normalized['closes_at'],
                'shoot_start'=>$normalized['shoot_starts_at'],'shoot_end'=>$normalized['shoot_ends_at'],
                'amount'=>$normalized['compensation_amount'],'currency'=>$currency,
                'max_submissions'=>$normalized['max_submissions'],'policy'=>$policy['policy_version'],'actor'=>$actor->id,
            ]);
            $this->event($id,null,$actor->id,'administrator','casting.call_created','Created a private casting brief. It is not publicly listed or distributed to clients.');
            $this->audit->record($actor->id,'model_casting.call_created','model_casting_call',$id,
                'Created a private draft casting call',null,[
                    'reference'=>$reference,'country_code'=>$normalized['country_code'],
                    'project_purpose'=>$normalized['project_purpose'],'casting_policy_version'=>$policy['policy_version'],
                ],$requestId,'model-casting-v1');
            $this->pdo->commit();
            return $id;
        } catch(Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function publish(Principal $actor,string $callId,string $requestId): void
    {
        $this->authorize($actor);$this->assertFeature();$this->assertRequestId($requestId);
        $this->pdo->beginTransaction();
        try {
            $call=$this->lockCall($callId);
            if(CastingStateMachine::call((string)$call['status'],'open')!=='open') throw new \DomainException('Casting call cannot be published.');
            $policy=$this->approvedPolicy();
            if(!hash_equals((string)$call['casting_policy_version'],$policy['policy_version'])) {
                throw new \DomainException('Casting call was drafted under a stale policy version.');
            }
            $this->assertMarketApproved((string)$call['country_code'],true);
            $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
            $opens=new DateTimeImmutable((string)$call['opens_at'],new DateTimeZone('UTC'));
            $closes=new DateTimeImmutable((string)$call['closes_at'],new DateTimeZone('UTC'));
            $shootStart=new DateTimeImmutable((string)$call['shoot_starts_at'],new DateTimeZone('UTC'));
            $shootEnd=new DateTimeImmutable((string)$call['shoot_ends_at'],new DateTimeZone('UTC'));
            if($closes<=$now || $closes<=$opens || $shootStart<$closes || $shootEnd<=$shootStart) {
                throw new \DomainException('Casting call dates are no longer valid for publication.');
            }
            $this->pdo->prepare(
                'UPDATE model_casting_calls SET status="open",published_by=:actor,published_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id'
            )->execute(['actor'=>$actor->id,'id'=>$callId]);
            $this->event($callId,null,$actor->id,'administrator','casting.call_published','Opened a private agency casting call. It is not publicly listed.');
            $this->audit->record($actor->id,'model_casting.call_published','model_casting_call',$callId,
                'Published a private casting call', ['status'=>'draft'],['status'=>'open'],$requestId,'model-casting-v1');
            $this->pdo->commit();
        } catch(Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function close(Principal $actor,string $callId,string $reason,string $requestId): void
    {
        $this->authorize($actor);$this->assertRequestId($requestId);$reason=trim($reason);
        if(strlen($reason)<8 || strlen($reason)>500) throw new \InvalidArgumentException('Casting close requires a reason of 8 to 500 characters.');
        $this->pdo->beginTransaction();
        try {
            $call=$this->lockCall($callId);
            CastingStateMachine::call((string)$call['status'],'closed');
            $this->pdo->prepare(
                'UPDATE model_casting_calls SET status="closed",closed_by=:actor,closed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id'
            )->execute(['actor'=>$actor->id,'id'=>$callId]);
            $this->event($callId,null,$actor->id,'administrator','casting.call_closed','Private casting call closed by agency operations.');
            $this->audit->record($actor->id,'model_casting.call_closed','model_casting_call',$callId,
                'Closed a private casting call',['status'=>'open'],['status'=>'closed','reason_sha256'=>hash('sha256',$reason)],$requestId,'model-casting-v1');
            $this->pdo->commit();
        } catch(Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function cancel(Principal $actor,string $callId,string $reason,string $requestId): void
    {
        $this->authorize($actor);$this->assertRequestId($requestId);$reason=trim($reason);
        if(strlen($reason)<8 || strlen($reason)>500) throw new \InvalidArgumentException('Casting cancellation requires a reason of 8 to 500 characters.');
        $this->pdo->beginTransaction();
        try {
            $call=$this->lockCall($callId);
            CastingStateMachine::call((string)$call['status'],'cancelled');
            $this->pdo->prepare(
                'UPDATE model_casting_calls SET status="cancelled",cancelled_by=:actor,cancelled_at=UTC_TIMESTAMP(),
                 cancellation_reason=:reason,updated_at=UTC_TIMESTAMP() WHERE id=:id'
            )->execute(['actor'=>$actor->id,'reason'=>$this->encryptField($callId,'cancellation_reason',$reason),'id'=>$callId]);
            $submissions=$this->pdo->prepare(
                'SELECT id,status FROM model_casting_submissions WHERE casting_call_id=:call
                 AND status IN ("submitted","shortlisted","selected") FOR UPDATE'
            );
            $submissions->execute(['call'=>$callId]);
            foreach($submissions->fetchAll(PDO::FETCH_ASSOC) as $submission) {
                $this->pdo->prepare(
                    'UPDATE model_casting_submissions SET status="invalidated",invalidated_at=UTC_TIMESTAMP(),
                     invalidation_reason=:reason,updated_at=UTC_TIMESTAMP() WHERE id=:id'
                )->execute(['reason'=>$this->cipher->encrypt('Casting call cancelled.','model-casting-submission-v1:'.$submission['id'].':invalidation_reason'),'id'=>$submission['id']]);
                $this->event($callId,(string)$submission['id'],$actor->id,'administrator',
                    'casting.submission_invalidated','Submission invalidated because its casting call was cancelled.');
            }
            $this->event($callId,null,$actor->id,'administrator','casting.call_cancelled','Private casting call cancelled.');
            $this->audit->record($actor->id,'model_casting.call_cancelled','model_casting_call',$callId,
                'Cancelled a private casting call',['status'=>$call['status']],['status'=>'cancelled','reason_sha256'=>hash('sha256',$reason)],
                $requestId,'model-casting-v1');
            $this->pdo->commit();
        } catch(Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    /** @return list<array<string,mixed>> */
    public function list(Principal $actor,int $limit=50): array
    {
        $this->authorize($actor);
        $limit=max(1,min(100,$limit));
        $query=$this->pdo->prepare(
            'SELECT id,casting_reference,title,client_name,project_purpose,country_code,opens_at,closes_at,
                    shoot_starts_at,shoot_ends_at,compensation_amount,currency_code,max_submissions,
                    casting_policy_version,status,created_at,published_at,closed_at,cancelled_at
             FROM model_casting_calls ORDER BY created_at DESC LIMIT :limit'
        );
        $query->bindValue(':limit',$limit,PDO::PARAM_INT);$query->execute();
        $rows=$query->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as &$row) $row['client_name']=$this->decryptField((string)$row['id'],'client_name',(string)$row['client_name']);
        unset($row);
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    public function submissionsForCall(Principal $actor,string $callId,int $limit=100): array
    {
        $this->authorize($actor);$limit=max(1,min(200,$limit));
        $query=$this->pdo->prepare(
            'SELECT s.id,s.submission_reference,s.status,s.submitted_at,s.decided_at,
                    a.application_reference,asset.mime_type,asset.width,asset.height,lic.licence_reference
             FROM model_casting_submissions s
             JOIN model_talent_applications a ON a.id=s.application_id
             JOIN model_portfolio_assets asset ON asset.id=s.portfolio_asset_id
             JOIN model_image_licences lic ON lic.id=s.image_licence_id
             WHERE s.casting_call_id=:call ORDER BY s.submitted_at DESC LIMIT :limit'
        );
        $query->bindValue(':call',$callId);$query->bindValue(':limit',$limit,PDO::PARAM_INT);$query->execute();
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    public function eligibleCandidates(Principal $actor,string $callId,int $limit=100): array
    {
        $this->authorize($actor);
        if(!(($this->castingFeatureEnabled)())) return [];
        $call=$this->pdo->prepare('SELECT * FROM model_casting_calls WHERE id=:id LIMIT 1');
        $call->execute(['id'=>$callId]);$row=$call->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row) || $row['status']!=='open') return [];
        $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
        if(new DateTimeImmutable((string)$row['opens_at'],new DateTimeZone('UTC'))>$now ||
            new DateTimeImmutable((string)$row['closes_at'],new DateTimeZone('UTC'))<=$now) return [];
        $castingPolicy=$this->approvedPolicy();
        if(!hash_equals((string)$row['casting_policy_version'],$castingPolicy['policy_version'])) return [];
        $portfolioPolicy=$this->approvedPolicySetting('model_agency.portfolio');
        $licencePolicy=$this->approvedPolicySetting('model_agency.image_licence');
        $this->assertMarketApproved((string)$row['country_code'],false);
        $query=$this->pdo->prepare(
            'SELECT a.id AS application_id,a.application_reference,asset.id AS asset_id,
                    lic.id AS licence_id,lic.licence_reference
             FROM model_talent_applications a
             JOIN model_applicant_verifications v ON v.id=(
                SELECT v2.id FROM model_applicant_verifications v2
                WHERE v2.application_id=a.id ORDER BY v2.decision_order DESC LIMIT 1
             )
             JOIN model_portfolio_assets asset ON asset.application_id=a.id
             JOIN consent_grants asset_consent ON asset_consent.id=asset.consent_grant_id
             JOIN model_image_licences lic ON lic.portfolio_asset_id=asset.id AND lic.application_id=a.id
             JOIN consent_grants licence_consent ON licence_consent.id=lic.consent_grant_id
             JOIN model_representation_agreements agreement ON agreement.id=lic.agreement_id
             WHERE a.status="accepted" AND a.legal_hold=0 AND a.anonymized_at IS NULL
               AND v.outcome="verified" AND v.expires_at>UTC_TIMESTAMP()
               AND asset.status="approved" AND asset.consent_withdrawn_at IS NULL
               AND asset.private_storage_key IS NOT NULL AND asset.consent_policy_version=:portfolio_policy
               AND asset_consent.granted=1 AND asset_consent.withdrawn_at IS NULL
               AND lic.status="active" AND lic.purpose="casting_distribution"
               AND lic.territory_code=:country AND JSON_CONTAINS(lic.channels,JSON_QUOTE("casting_platform"))
               AND lic.starts_on<=DATE(:shoot_start_licence) AND lic.ends_on>=DATE(:shoot_end_licence)
               AND lic.licence_policy_version=:licence_policy
               AND licence_consent.granted=1 AND licence_consent.withdrawn_at IS NULL AND lic.model_consent_evidence_reference IS NOT NULL
               AND agreement.status="executed" AND agreement.effective_from<=:shoot_start_agreement AND agreement.effective_until>=:shoot_end_agreement
               AND JSON_CONTAINS(agreement.terms_snapshot,JSON_QUOTE("casting_submissions"),"$.service_scope")
               AND NOT EXISTS (SELECT 1 FROM model_casting_submissions existing
                               WHERE existing.casting_call_id=:call AND existing.application_id=a.id)
               AND (SELECT COUNT(*) FROM model_casting_submissions active
                    WHERE active.casting_call_id=:call_count AND active.status IN ("submitted","shortlisted","selected"))<:max_submissions
             ORDER BY a.updated_at DESC LIMIT :limit'
        );
        $query->bindValue(':portfolio_policy',$portfolioPolicy['policy_version']);
        $query->bindValue(':country',$row['country_code']);
        $query->bindValue(':shoot_start_licence',$row['shoot_starts_at']);
        $query->bindValue(':shoot_start_agreement',$row['shoot_starts_at']);
        $query->bindValue(':shoot_end_licence',$row['shoot_ends_at']);
        $query->bindValue(':shoot_end_agreement',$row['shoot_ends_at']);
        $query->bindValue(':licence_policy',$licencePolicy['policy_version']);
        $query->bindValue(':call',$callId);
        $query->bindValue(':call_count',$callId);
        $query->bindValue(':max_submissions',(int)$row['max_submissions'],PDO::PARAM_INT);
        $query->bindValue(':limit',max(1,min(100,$limit)),PDO::PARAM_INT);
        $query->execute();
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function submit(Principal $actor,string $callId,string $applicationId,string $assetId,string $licenceId,string $requestId): string
    {
        $this->authorize($actor);$this->assertFeature();$this->assertRequestId($requestId);
        $this->pdo->beginTransaction();
        try {
            $call=$this->lockCall($callId);
            $application=$this->lockResource('model_talent_applications',$applicationId);
            $asset=$this->lockResource('model_portfolio_assets',$assetId);
            $licence=$this->lockResource('model_image_licences',$licenceId);
            $agreement=$this->lockResource('model_representation_agreements',(string)$licence['agreement_id']);
            $this->assertSubmissionEligible($call,$application,$asset,$licence,$agreement,true);
            $duplicate=$this->pdo->prepare('SELECT id FROM model_casting_submissions WHERE casting_call_id=:call AND application_id=:application LIMIT 1 FOR UPDATE');
            $duplicate->execute(['call'=>$callId,'application'=>$applicationId]);
            if($duplicate->fetchColumn()!==false) throw new \DomainException('This model already has a submission for the private casting call.');
            $count=$this->pdo->prepare('SELECT COUNT(*) FROM model_casting_submissions WHERE casting_call_id=:call AND status IN ("submitted","shortlisted","selected")');
            $count->execute(['call'=>$callId]);
            if((int)$count->fetchColumn()>=(int)$call['max_submissions']) throw new \DomainException('The casting call has reached its active submission limit.');
            $id=self::uuid();$reference='MCS-'.gmdate('Y').'-'.strtoupper(bin2hex(random_bytes(6)));
            $this->pdo->prepare(
                'INSERT INTO model_casting_submissions
                 (id,casting_call_id,application_id,portfolio_asset_id,image_licence_id,submission_reference,
                  status,submitted_by,submitted_at,created_at,updated_at)
                 VALUES (:id,:call,:application,:asset,:licence,:reference,"submitted",:actor,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())'
            )->execute(['id'=>$id,'call'=>$callId,'application'=>$applicationId,'asset'=>$assetId,'licence'=>$licenceId,
                'reference'=>$reference,'actor'=>$actor->id]);
            $this->event($callId,$id,$actor->id,'administrator','casting.submission_created',
                'An eligible model was submitted using an active, purpose-specific casting licence.');
            $this->audit->record($actor->id,'model_casting.submission_created','model_casting_submission',$id,
                'Submitted an eligible model to a private casting call',null,
                ['call_id'=>$callId,'application_id'=>$applicationId,'asset_id'=>$assetId,'licence_id'=>$licenceId,'status'=>'submitted'],
                $requestId,'model-casting-v1');
            $this->pdo->commit();return $id;
        } catch(Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    /**
     * Submit an eligible model from the OTP-verified applicant workspace.
     * The caller must resolve applicationId through ModelApplicantRightsService::portalApplicationId;
     * no user identity is fabricated for applicant-originated records.
     */
    public function submitByApplicant(string $applicationId,string $callId,string $assetId,string $licenceId,string $requestId): string
    {
        $this->assertFeature();$this->assertRequestId($requestId);
        if(!self::isUuid($applicationId)) throw new \\InvalidArgumentException('Applicant identity is invalid.');
        $this->pdo->beginTransaction();
        try {
            $call=$this->lockCall($callId);
            $application=$this->lockResource('model_talent_applications',$applicationId);
            $asset=$this->lockResource('model_portfolio_assets',$assetId);
            $licence=$this->lockResource('model_image_licences',$licenceId);
            $agreement=$this->lockResource('model_representation_agreements',(string)$licence['agreement_id']);
            $this->assertSubmissionEligible($call,$application,$asset,$licence,$agreement,true);
            $duplicate=$this->pdo->prepare('SELECT id FROM model_casting_submissions WHERE casting_call_id=:call AND application_id=:application LIMIT 1 FOR UPDATE');
            $duplicate->execute(['call'=>$callId,'application'=>$applicationId]);
            if($duplicate->fetchColumn()!==false) throw new \\DomainException('You already have a submission for this private casting call.');
            $count=$this->pdo->prepare('SELECT COUNT(*) FROM model_casting_submissions WHERE casting_call_id=:call AND status IN ("submitted","shortlisted","selected")');
            $count->execute(['call'=>$callId]);
            if((int)$count->fetchColumn()>=(int)$call['max_submissions']) throw new \\DomainException('This casting call has reached its active submission limit.');
            $id=self::uuid();$reference='MCS-'.gmdate('Y').'-'.strtoupper(bin2hex(random_bytes(6)));
            $this->pdo->prepare(
                'INSERT INTO model_casting_submissions
                 (id,casting_call_id,application_id,portfolio_asset_id,image_licence_id,submission_reference,
                  status,submitted_by,submitted_at,created_at,updated_at)
                 VALUES (:id,:call,:application,:asset,:licence,:reference,"submitted",NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())'
            )->execute(['id'=>$id,'call'=>$callId,'application'=>$applicationId,'asset'=>$assetId,'licence'=>$licenceId,'reference'=>$reference]);
            $this->event($callId,$id,'','applicant','casting.applicant_submitted',
                'An OTP-verified model submitted an eligible portfolio asset to a private casting call.');
            $this->audit->record(null,'model_casting.applicant_submitted','model_casting_submission',$id,
                'Applicant submitted an eligible private casting application',null,
                ['call_id'=>$callId,'asset_id'=>$assetId,'licence_id'=>$licenceId,'status'=>'submitted'],
                $requestId,'model-casting-v1');
            $this->pdo->commit();return $id;
        } catch(Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function decide(Principal $actor,string $submissionId,string $decision,string $note,string $requestId): void
    {
        $this->authorize($actor);$this->assertRequestId($requestId);$note=trim($note);
        if(!in_array($decision,['shortlisted','selected','declined'],true) || strlen($note)<20 || strlen($note)>1500) {
            throw new \InvalidArgumentException('Choose a valid manual casting decision and provide a note of 20 to 1500 characters.');
        }
        if(in_array($decision,['shortlisted','selected'],true)) $this->assertFeature();
        $this->pdo->beginTransaction();
        try {
            $submission=$this->lockResource('model_casting_submissions',$submissionId);
            $call=$this->lockCall((string)$submission['casting_call_id']);
            $application=$this->lockResource('model_talent_applications',(string)$submission['application_id']);
            $role=$this->actorRole($actor);
            CastingStateMachine::submission((string)$submission['status'],$decision,$role);
            if(in_array($decision,['shortlisted','selected'],true)) {
                $asset=$this->lockResource('model_portfolio_assets',(string)$submission['portfolio_asset_id']);
                $licence=$this->lockResource('model_image_licences',(string)$submission['image_licence_id']);
                $agreement=$this->lockResource('model_representation_agreements',(string)$licence['agreement_id']);
                $this->assertSubmissionEligible($call,$application,$asset,$licence,$agreement,false);
            }
            $this->pdo->prepare(
                'UPDATE model_casting_submissions SET status=:status,decided_by=:actor,decided_at=UTC_TIMESTAMP(),
                 decision_note=:note,updated_at=UTC_TIMESTAMP() WHERE id=:id'
            )->execute(['status'=>$decision,'actor'=>$actor->id,'note'=>$this->cipher->encrypt($note,'model-casting-submission-v1:'.$submissionId.':decision_note'),'id'=>$submissionId]);
            $this->event((string)$call['id'],$submissionId,$actor->id,'administrator','casting.submission_'.$decision,
                'Manual agency casting decision recorded: '.$decision.'.');
            $this->audit->record($actor->id,'model_casting.submission_'.$decision,'model_casting_submission',$submissionId,
                'Recorded a manual casting decision', ['status'=>$submission['status']],['status'=>$decision,'note_sha256'=>hash('sha256',$note)],
                $requestId,'model-casting-v1');
            $this->pdo->commit();
        } catch(Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function withdraw(Principal $actor,string $submissionId,string $reason,string $evidenceReference,string $requestId): void
    {
        $this->authorize($actor);$this->assertRequestId($requestId);$reason=trim($reason);
        if(strlen($reason)<20 || strlen($reason)>1500 || !$this->validEvidenceReference($evidenceReference,'private/model-consent/')) {
            throw new \InvalidArgumentException('Submission withdrawal requires a reason and private model-consent evidence reference.');
        }
        $this->pdo->beginTransaction();
        try {
            $submission=$this->lockResource('model_casting_submissions',$submissionId);
            $call=$this->lockCall((string)$submission['casting_call_id']);
            CastingStateMachine::submission((string)$submission['status'],'withdrawn',$this->actorRole($actor));
            $this->pdo->prepare(
                'UPDATE model_casting_submissions SET status="withdrawn",decided_by=:actor,decided_at=UTC_TIMESTAMP(),
                 decision_note=:note,withdrawal_evidence_reference=:evidence,updated_at=UTC_TIMESTAMP() WHERE id=:id'
            )->execute([
                'actor'=>$actor->id,'note'=>$this->cipher->encrypt($reason,'model-casting-submission-v1:'.$submissionId.':decision_note'),
                'evidence'=>$this->cipher->encrypt($evidenceReference,'model-casting-submission-v1:'.$submissionId.':withdrawal_evidence_reference'),
                'id'=>$submissionId,
            ]);
            $this->event((string)$call['id'],$submissionId,$actor->id,'administrator','casting.submission_withdrawn',
                'Model casting submission withdrawal recorded.');
            $this->audit->record($actor->id,'model_casting.submission_withdrawn','model_casting_submission',$submissionId,
                'Recorded a model casting submission withdrawal', ['status'=>$submission['status']],['status'=>'withdrawn','reason_sha256'=>hash('sha256',$reason)],
                $requestId,'model-casting-v1');
            $this->pdo->commit();
        } catch(Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    /** @param array<string,mixed> $call @param array<string,mixed> $application @param array<string,mixed> $asset @param array<string,mixed> $licence @param array<string,mixed> $agreement */
    private function assertSubmissionEligible(array $call,array $application,array $asset,array $licence,array $agreement,bool $requireOpen): void
    {
        $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
        $opens=new DateTimeImmutable((string)$call['opens_at'],new DateTimeZone('UTC'));
        $closes=new DateTimeImmutable((string)$call['closes_at'],new DateTimeZone('UTC'));
        if(($requireOpen && ($call['status']!=='open' || $opens>$now || $closes<=$now)) ||
            $application['status']!=='accepted' || (int)$application['legal_hold']===1 || $application['anonymized_at']!==null ||
            $asset['application_id']!==$application['id'] || $asset['status']!=='approved' || $asset['consent_withdrawn_at']!==null ||
            !is_string($asset['private_storage_key']) || $asset['private_storage_key']==='' ||
            $licence['application_id']!==$application['id'] || $licence['portfolio_asset_id']!==$asset['id'] ||
            $licence['status']!=='active' || $licence['purpose']!=='casting_distribution' ||
            $licence['territory_code']!==$call['country_code'] || $licence['model_consent_evidence_reference']===null ||
            $agreement['application_id']!==$application['id'] || $agreement['status']!=='executed' ||
            $agreement['effective_from']===null || $agreement['effective_until']===null) {
            throw new \DomainException('Casting submission requires an open call, eligible verified model, approved private asset, active matching licence and executed representation agreement.');
        }
        $castingPolicy=$this->approvedPolicy();
        if(!hash_equals((string)$call['casting_policy_version'],$castingPolicy['policy_version'])) throw new \DomainException('Casting policy changed; this call is no longer eligible.');
        $portfolioPolicy=$this->approvedPolicySetting('model_agency.portfolio');
        $licencePolicy=$this->approvedPolicySetting('model_agency.image_licence');
        if(!hash_equals((string)$asset['consent_policy_version'],$portfolioPolicy['policy_version']) ||
            !hash_equals((string)$licence['licence_policy_version'],$licencePolicy['policy_version'])) {
            throw new \DomainException('Portfolio consent or image licence policy changed; submission is no longer eligible.');
        }
        $verification=$this->pdo->prepare('SELECT outcome,expires_at FROM model_applicant_verifications WHERE application_id=:application ORDER BY decision_order DESC LIMIT 1 FOR UPDATE');
        $verification->execute(['application'=>$application['id']]);$v=$verification->fetch(PDO::FETCH_ASSOC);
        if(!is_array($v) || $v['outcome']!=='verified' || $v['expires_at']===null ||
            new DateTimeImmutable((string)$v['expires_at'],new DateTimeZone('UTC'))<=$now) {
            throw new \DomainException('A current independent adult-eligibility verification is required.');
        }
        $this->assertMarketApproved((string)$call['country_code'],true);
        $shootStart=substr((string)$call['shoot_starts_at'],0,10);$shootEnd=substr((string)$call['shoot_ends_at'],0,10);
        if((string)$licence['starts_on']>$shootStart || (string)$licence['ends_on']<$shootEnd) {
            throw new \DomainException('The image licence must cover the complete shoot window.');
        }
        $channels=json_decode((string)$licence['channels'],true);
        if(!is_array($channels) || !in_array('casting_platform',$channels,true)) throw new \DomainException('Image licence does not permit private casting distribution.');
        $agreementTerms=json_decode((string)$agreement['terms_snapshot'],true);
        if(!is_array($agreementTerms) || !in_array('casting_submissions',$agreementTerms['service_scope']??[],true) ||
            substr((string)$agreement['effective_from'],0,10)>$shootStart || substr((string)$agreement['effective_until'],0,10)<$shootEnd) {
            throw new \DomainException('Executed representation terms do not cover this casting and shoot period.');
        }
        foreach([
            ['id'=>$asset['consent_grant_id'],'subject_type'=>'model_portfolio_asset','subject_id'=>$asset['id'],'purpose'=>'portfolio_curation','audience'=>'agency_internal','version'=>$asset['consent_policy_version']],
            ['id'=>$licence['consent_grant_id'],'subject_type'=>'model_image_licence','subject_id'=>$licence['id'],'purpose'=>'image_usage','audience'=>'agency_distribution','version'=>$licence['licence_policy_version']],
        ] as $expected) {
            $grant=$this->pdo->prepare(
                'SELECT granted,withdrawn_at,policy_version FROM consent_grants WHERE id=:id AND subject_type=:type
                 AND subject_id=:subject AND purpose=:purpose AND audience=:audience LIMIT 1 FOR UPDATE'
            );
            $grant->execute(['id'=>$expected['id'],'type'=>$expected['subject_type'],'subject'=>$expected['subject_id'],
                'purpose'=>$expected['purpose'],'audience'=>$expected['audience']]);
            $row=$grant->fetch(PDO::FETCH_ASSOC);
            if(!is_array($row) || (int)$row['granted']!==1 || $row['withdrawn_at']!==null ||
                !hash_equals((string)$expected['version'],(string)$row['policy_version'])) {
                throw new \DomainException('Current portfolio and image-use consent grants are required.');
            }
        }
        $bytes=$this->storage->read((string)$asset['private_storage_key'],8_388_608);
        if(!hash_equals((string)$asset['sha256'],hash('sha256',$bytes))) throw new \DomainException('Private portfolio image failed integrity verification.');
    }

    /** @return array<string,mixed> */
    private function lockResource(string $table,string $id): array
    {
        $allowed=['model_casting_submissions','model_talent_applications','model_portfolio_assets','model_image_licences','model_representation_agreements'];
        if(!in_array($table,$allowed,true) || !self::isUuid($id)) throw new \InvalidArgumentException('Model casting resource identifier is invalid.');
        $query=$this->pdo->prepare('SELECT * FROM '.$table.' WHERE id=:id LIMIT 1 FOR UPDATE');
        $query->execute(['id'=>$id]);$row=$query->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row)) throw new \DomainException('Model casting resource does not exist.');
        return $row;
    }

    /** @return array{policy_version:string} */
    private function approvedPolicySetting(string $key): array
    {
        $query=$this->pdo->prepare('SELECT setting_json FROM deployment_settings WHERE setting_key=:key LIMIT 1');
        $query->execute(['key'=>$key]);$raw=$query->fetchColumn();$policy=is_string($raw)?json_decode($raw,true):null;
        if(!is_array($policy) || !($policy['enabled']??false) || !($policy['policy_approved']??false) ||
            !is_string($policy['policy_version']??null) || trim($policy['policy_version'])==='') {
            throw new \DomainException('A current, versioned model usage policy must be approved.');
        }
        return ['policy_version'=>trim($policy['policy_version'])];
    }

    private function actorRole(Principal $actor): string
    {
        foreach(['super_admin','owner','admin'] as $role) if($actor->hasRole($role)) return $role;
        throw new \DomainException('Agency administrator role is required.');
    }

    private function validEvidenceReference(string $reference,string $prefix): bool
    {
        return strlen($reference)>=20 && strlen($reference)<=200 && str_starts_with($reference,$prefix) &&
            preg_match('#^'.preg_quote($prefix,'#').'[A-Za-z0-9][A-Za-z0-9._/-]{10,180}$#D',$reference)===1 &&
            !str_contains($reference,'..') && !str_contains($reference,'\\');
    }

    /** @return array<string,mixed> */
    private function validateTerms(array $terms): array
    {
        $purposes=['editorial','commercial_campaign','e_commerce','social_media','advertising','other'];
        $title=trim((string)($terms['title']??''));$client=trim((string)($terms['client_name']??''));
        $description=trim((string)($terms['description']??''));$requirements=trim((string)($terms['requirements']??''));
        $country=strtoupper(trim((string)($terms['country_code']??'')));
        $purpose=(string)($terms['project_purpose']??'');
        $max=(int)($terms['max_submissions']??0);
        if($title==='' || mb_strlen($title)>180 || $client==='' || mb_strlen($client)>200 ||
            !in_array($purpose,$purposes,true) || mb_strlen($description)<20 || mb_strlen($description)>10000 ||
            mb_strlen($requirements)<20 || mb_strlen($requirements)>10000 || !preg_match('/^[A-Z]{2}$/D',$country) ||
            $max<1 || $max>500) {
            throw new \InvalidArgumentException('Provide a complete casting brief, purpose, territory and valid submission limit.');
        }
        $dates=[];
        foreach(['opens_at','closes_at','shoot_starts_at','shoot_ends_at'] as $field) {
            $value=$terms[$field]??null;
            if(!is_string($value) || trim($value)==='') throw new \InvalidArgumentException('All casting dates are required.');
            try {$date=(new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));}
            catch(Throwable) {throw new \InvalidArgumentException('Casting dates must be valid ISO date/time values.');}
            $dates[$field]=$date->format('Y-m-d H:i:s');
        }
        $opens=new DateTimeImmutable($dates['opens_at'],new DateTimeZone('UTC'));
        $closes=new DateTimeImmutable($dates['closes_at'],new DateTimeZone('UTC'));
        $shootStart=new DateTimeImmutable($dates['shoot_starts_at'],new DateTimeZone('UTC'));
        $shootEnd=new DateTimeImmutable($dates['shoot_ends_at'],new DateTimeZone('UTC'));
        if($closes<=$opens || $shootStart<$closes || $shootEnd<=$shootStart) {
            throw new \InvalidArgumentException('Casting open, close and shoot dates must form a valid chronological window.');
        }
        $amount=$terms['compensation_amount']??null;$currency=$terms['currency_code']??null;
        if(($amount===null)!==($currency===null)) throw new \InvalidArgumentException('Compensation amount and currency must be supplied together.');
        if($amount!==null) {
            $amount=trim((string)$amount);
            if(preg_match('/^(?:0|[1-9][0-9]{0,13})(?:\.[0-9]{1,4})?$/D',$amount)!==1 || (float)$amount<=0 ||
                !is_string($currency) || preg_match('/^[A-Z]{3}$/D',$currency)!==1) {
                throw new \InvalidArgumentException('Casting compensation must be a positive decimal amount and ISO currency.');
            }
        }
        $clientReference=isset($terms['client_reference'])?trim((string)$terms['client_reference']):null;
        if($clientReference!==null && strlen($clientReference)>500) throw new \InvalidArgumentException('Client reference is too long.');
        return [
            'title'=>$title,'client_name'=>$client,'client_reference'=>$clientReference,
            'project_purpose'=>$purpose,'description'=>$description,'requirements'=>$requirements,
            'country_code'=>$country,'max_submissions'=>$max,'compensation_amount'=>$amount,
            'currency_code'=>$currency,'opens_at'=>$dates['opens_at'],'closes_at'=>$dates['closes_at'],
            'shoot_starts_at'=>$dates['shoot_starts_at'],'shoot_ends_at'=>$dates['shoot_ends_at'],
        ];
    }

    /** @return array{enabled:bool,policy_approved:bool,policy_version:string} */
    private function approvedPolicy(): array
    {
        $query=$this->pdo->prepare('SELECT setting_json FROM deployment_settings WHERE setting_key=:key LIMIT 1');
        $query->execute(['key'=>'model_agency.casting']);
        $raw=$query->fetchColumn();
        $policy=is_string($raw)?json_decode($raw,true):null;
        if(!is_array($policy) || !($policy['enabled']??false) || !($policy['policy_approved']??false) ||
            !is_string($policy['policy_version']??null) || trim($policy['policy_version'])==='') {
            throw new \DomainException('A current, versioned casting policy must be explicitly approved before calls can be created or published.');
        }
        return ['enabled'=>true,'policy_approved'=>true,'policy_version'=>trim($policy['policy_version'])];
    }

    private function assertMarketApproved(string $countryCode,bool $lock): void
    {
        $sql='SELECT c.enabled,m.enabled AS market_enabled,m.decision,m.expires_at,m.evidence_reference
              FROM countries c LEFT JOIN market_policies m ON m.country_code=c.alpha2
              WHERE c.alpha2=:country LIMIT 1'.($lock?' FOR UPDATE':'');
        $query=$this->pdo->prepare($sql);$query->execute(['country'=>$countryCode]);$row=$query->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row) || (int)$row['enabled']!==1 || (int)($row['market_enabled']??0)!==1 ||
            $row['decision']!=='approved' || $row['expires_at']===null || $row['evidence_reference']===null ||
            new DateTimeImmutable((string)$row['expires_at'],new DateTimeZone('UTC'))<=new DateTimeImmutable('now',new DateTimeZone('UTC'))) {
            throw new \DomainException('Casting calls require an enabled market with current compliance approval and evidence.');
        }
    }

    /** @return array<string,mixed> */
    private function lockCall(string $callId): array
    {
        if(!self::isUuid($callId)) throw new \InvalidArgumentException('Casting call identifier is invalid.');
        $query=$this->pdo->prepare('SELECT * FROM model_casting_calls WHERE id=:id LIMIT 1 FOR UPDATE');
        $query->execute(['id'=>$callId]);$row=$query->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row)) throw new \DomainException('Casting call does not exist.');
        return $row;
    }

    private function event(string $callId,?string $submissionId,string $actorId,string $actorType,string $type,string $reason): void
    {
        $id=self::uuid();
        $this->pdo->prepare(
            'INSERT INTO model_casting_events (id,call_id,submission_id,actor_id,actor_type,event_type,reason,occurred_at,created_at,updated_at)
             VALUES (:id,:call,:submission,:actor,:actor_type,:event,:reason,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())'
        )->execute(['id'=>$id,'call'=>$callId,'submission'=>$submissionId,'actor'=>$actorId===''?null:$actorId,'actor_type'=>$actorType,'event'=>$type,'reason'=>$reason]);
    }

    private function encryptField(string $callId,string $field,string $value): string
    {
        return $this->cipher->encrypt($value,'model-casting-call-v1:'.$callId.':'.$field);
    }

    private function decryptField(string $callId,string $field,string $value): string
    {
        return $this->cipher->decrypt($value,'model-casting-call-v1:'.$callId.':'.$field);
    }

    private function authorize(Principal $actor): void
    {
        Authorization::require($actor,'model_castings.manage',true);
        if(!$actor->hasRole('admin') && !$actor->hasRole('owner') && !$actor->hasRole('super_admin')) {
            throw new \DomainException('Agency administrator role is required for casting management.');
        }
    }

    private function assertFeature(): void
    {
        if(!(($this->castingFeatureEnabled)())) throw new \DomainException('Model-agency casting is not operationally enabled; required legal, consent and market approvals are missing.');
    }

    private function assertRequestId(string $requestId): void
    {
        if(strlen($requestId)<16 || strlen($requestId)>64) throw new \InvalidArgumentException('Casting request reference is invalid.');
    }

    private static function uuid(): string
    {
        $bytes=random_bytes(16);$bytes[6]=chr((ord($bytes[6])&15)|64);$bytes[8]=chr((ord($bytes[8])&63)|128);$hex=bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }

    private static function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD',$value)===1;
    }
}
