<?php
declare(strict_types=1);

namespace Talyvane\Application\ModelAgency;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;
use Talyvane\Domain\ModelAgency\AssignmentStateMachine;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Infrastructure\Storage\PrivateMediaStorage;
use Talyvane\Security\Authorization;
use Talyvane\Security\Principal;
use Talyvane\Security\SecretBox;

/** Governed assignment lifecycle; all execution evidence is external and separately attested. */
final class ModelAssignmentService
{
    private readonly Closure $assignmentsEnabled;

    /** @param callable():bool $assignmentsEnabled */
    public function __construct(private PDO $pdo,private AuditLogger $audit,private SecretBox $cipher,private PrivateMediaStorage $storage,callable $assignmentsEnabled)
    {
        $this->assignmentsEnabled=Closure::fromCallable($assignmentsEnabled);
    }

    /** @param array<string,mixed> $terms */
    public function createDraft(Principal $actor,string $submissionId,array $terms,string $requestId): string
    {
        $this->authorize($actor);$this->assertFeature();$this->assertRequestId($requestId);$this->validateTerms($terms);
        $this->pdo->beginTransaction();
        try {
            $submission=$this->lock('model_casting_submissions',$submissionId);
            if($submission['status']!=='selected') throw new \DomainException('Only a selected private casting submission can become an assignment.');
            $application=$this->lock('model_talent_applications',(string)$submission['application_id']);
            $asset=$this->lock('model_portfolio_assets',(string)$submission['portfolio_asset_id']);
            $licence=$this->lock('model_image_licences',(string)$submission['image_licence_id']);
            $call=$this->lock('model_casting_calls',(string)$submission['casting_call_id']);
            $agreement=$this->lock('model_representation_agreements',(string)$licence['agreement_id']);
            $policy=$this->approvedPolicy();
            $this->assertEligible($application,$asset,$licence,$call,$agreement,$terms,$policy['policy_version']);
            $this->assertScheduleAvailable((string)$application['id'],$terms['shoot_starts_at'],$terms['shoot_ends_at']);
            $agreementTerms=json_decode((string)$agreement['terms_snapshot'],true);
            $commission=(int)($agreementTerms['commission_basis_points']??-1);
            if($commission<0 || $commission>10000) throw new \DomainException('Executed representation terms have an invalid commission basis.');
            $currency=$terms['currency_code']??null;
            $precision=0;$compensation=null;$expenseCap=0;
            if($currency!==null) {
                $currencyQuery=$this->pdo->prepare('SELECT minor_units,enabled FROM currencies WHERE code=:code LIMIT 1 FOR UPDATE');
                $currencyQuery->execute(['code'=>$currency]);$currencyRow=$currencyQuery->fetch(PDO::FETCH_ASSOC);
                if(!is_array($currencyRow) || (int)$currencyRow['enabled']!==1) throw new \DomainException('Assignment requires an enabled ISO currency.');
                $precision=(int)$currencyRow['minor_units'];
                $compensation=$terms['compensation_amount']===null?null:$this->decimalToMinor($terms['compensation_amount'],$precision);
                $expenseCap=$terms['expense_cap_amount']===null?0:$this->decimalToMinor($terms['expense_cap_amount'],$precision);
            }
            $id=self::uuid();$reference='MAS-'.gmdate('Y').'-'.strtoupper(bin2hex(random_bytes(6)));
            $snapshot=[
                'project_purpose'=>$terms['project_purpose'],'shoot_starts_at'=>$terms['shoot_starts_at'],
                'shoot_ends_at'=>$terms['shoot_ends_at'],'currency_code'=>$currency,'currency_precision'=>$precision,
                'compensation_minor'=>$compensation,'expense_cap_minor'=>$expenseCap,'commission_basis_points'=>$commission,
                'client_name'=>$this->encryptField($id,'client_name',$terms['client_name']),
                'client_reference'=>$terms['client_reference']===null?null:$this->encryptField($id,'client_reference',$terms['client_reference']),
                'brief'=>$this->encryptField($id,'brief',$terms['brief']),
                'location_city'=>$this->encryptField($id,'location_city',$terms['location_city']),
                'deliverables'=>$this->encryptField($id,'deliverables',$terms['deliverables']),
                'assignment_policy_version'=>$policy['policy_version'],
            ];
            $this->pdo->prepare(
                'INSERT INTO model_assignments
                 (id,assignment_reference,submission_id,application_id,representation_agreement_id,country_code,
                  project_purpose,client_name,client_reference,brief,location_city,shoot_starts_at,shoot_ends_at,
                  deliverables,assignment_policy_version,status,compensation_minor,currency_code,expense_cap_minor,
                  commission_basis_points,terms_snapshot,created_by,created_at,updated_at)
                 VALUES (:id,:reference,:submission,:application,:agreement,:country,:purpose,:client,:client_reference,
                  :brief,:city,:shoot_start,:shoot_end,:deliverables,:policy,"draft",:compensation,:currency,:expense_cap,
                  :commission,:snapshot,:actor,UTC_TIMESTAMP(),UTC_TIMESTAMP())'
            )->execute([
                'id'=>$id,'reference'=>$reference,'submission'=>$submissionId,'application'=>$application['id'],
                'agreement'=>$agreement['id'],'country'=>$application['country_code'],'purpose'=>$terms['project_purpose'],
                'client'=>$snapshot['client_name'],'client_reference'=>$snapshot['client_reference'],
                'brief'=>$snapshot['brief'],'city'=>$snapshot['location_city'],'shoot_start'=>$terms['shoot_starts_at'],
                'shoot_end'=>$terms['shoot_ends_at'],'deliverables'=>$snapshot['deliverables'],'policy'=>$policy['policy_version'],
                'compensation'=>$compensation,'currency'=>$currency,'expense_cap'=>$expenseCap,'commission'=>$commission,
                'snapshot'=>json_encode($snapshot,JSON_THROW_ON_ERROR),'actor'=>$actor->id,
            ]);
            $this->event($id,$actor->id,'assignment.created','none','draft','Private assignment draft created.',$policy['policy_version']);
            $this->audit->record($actor->id,'model_assignment.created','model_assignment',$id,
                'Created a private assignment draft',null,
                ['submission_id'=>$submissionId,'application_id'=>$application['id'],'currency'=>$currency,
                 'compensation_minor'=>$compensation,'expense_cap_minor'=>$expenseCap,'commission_basis_points'=>$commission,
                 'policy_version'=>$policy['policy_version']],
                $requestId,'model-assignment-v1');
            $this->pdo->commit();return $id;
        } catch(Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function offer(Principal $actor,string $assignmentId,string $requestId): void
    {
        $this->transition($actor,$assignmentId,'offered',null,null,$requestId);
    }

    public function recordExecutionEvidence(Principal $actor,string $assignmentId,string $executedDocumentReference,string $requestId): void
    {
        $this->authorize($actor);$this->assertRequestId($requestId);
        $this->assertEvidenceReference($executedDocumentReference,'private/model-assignments/');
        $this->pdo->beginTransaction();
        try {
            $assignment=$this->lock('model_assignments',$assignmentId);
            if($assignment['status']!=='offered' || !empty($assignment['executed_document_reference'])) {
                throw new \DomainException('External execution evidence can only be recorded once for an offered assignment.');
            }
            $this->pdo->prepare(
                'UPDATE model_assignments SET executed_document_reference=:evidence,acceptance_recorded_by=:actor,updated_at=UTC_TIMESTAMP() WHERE id=:id'
            )->execute(['evidence'=>$this->encryptField($assignmentId,'executed_document_reference',$executedDocumentReference),
                'actor'=>$actor->id,'id'=>$assignmentId]);
            $this->event($assignmentId,$actor->id,'assignment.execution_evidence_recorded','offered','offered',
                'External assignment execution evidence was recorded before applicant response.',$this->policyVersionOrLegacy($assignment));
            $this->audit->record($actor->id,'model_assignment.execution_evidence_recorded','model_assignment',$assignmentId,
                'Recorded external assignment execution evidence',null,['evidence_sha256'=>hash('sha256',$executedDocumentReference)],
                $requestId,'model-assignment-v1');
            $this->pdo->commit();
        } catch(Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function respondByApplicant(string $applicationId,string $assignmentId,string $decision,string $statement,string $requestId): void
    {
        $this->assertRequestId($requestId);
        if(!preg_match('/^[0-9a-f-]{36}$/iD',$applicationId) || !in_array($decision,['accept','decline'],true)) {
            throw new \InvalidArgumentException('Applicant assignment response is invalid.');
        }
        $statement=trim($statement);
        if(strlen($statement)<20 || strlen($statement)>2000) {
            throw new \InvalidArgumentException('A reasoned assignment response of 20 to 2000 characters is required.');
        }
        $this->pdo->beginTransaction();
        try {
            $assignment=$this->lock('model_assignments',$assignmentId);
            if(!hash_equals((string)$assignment['application_id'],$applicationId) || $assignment['status']!=='offered') {
                throw new \DomainException('This assignment offer is not available to the verified applicant.');
            }
            $submission=$this->lock('model_casting_submissions',(string)$assignment['submission_id']);
            $application=$this->lock('model_talent_applications',$applicationId);
            $asset=$this->lock('model_portfolio_assets',(string)$submission['portfolio_asset_id']);
            $licence=$this->lock('model_image_licences',(string)$submission['image_licence_id']);
            $call=$this->lock('model_casting_calls',(string)$submission['casting_call_id']);
            $agreement=$this->lock('model_representation_agreements',(string)$assignment['representation_agreement_id']);
            $this->assertCurrentEligibility($assignment,$submission,$application,$asset,$licence,$call,$agreement);
            $policyVersion=$this->policyVersionOrLegacy($assignment);
            if($decision==='accept') {
                $stored=$assignment['executed_document_reference']??null;
                if(!is_string($stored) || $stored==='') throw new \DomainException('The executed assignment document must be recorded before the model can accept.');
                $this->cipher->decrypt($stored,'model-assignment-v1:'.$assignmentId.':executed_document_reference');
                $evidence='applicant-consent:'.hash('sha256',$statement."\0".$requestId);
                AssignmentStateMachine::transition('offered','accepted','applicant',$evidence);
                $this->pdo->prepare(
                    'UPDATE model_assignments SET status="accepted",acceptance_recorded_by=NULL,accepted_at=UTC_TIMESTAMP(),
                     model_acceptance_evidence_reference=:evidence,updated_at=UTC_TIMESTAMP() WHERE id=:id AND status="offered"'
                )->execute(['evidence'=>$this->encryptField($assignmentId,'model_acceptance_evidence_reference',$statement),'id'=>$assignmentId]);
                $this->event($assignmentId,null,'assignment.applicant_accepted','offered','accepted',
                    'Applicant voluntarily accepted the offered assignment and recorded their consent.',$policyVersion,'applicant');
                $this->audit->record(null,'model_assignment.applicant_accepted','model_assignment',$assignmentId,
                    'Applicant accepted a private model assignment',['status'=>'offered'],['status'=>'accepted','consent_sha256'=>hash('sha256',$statement)],
                    $requestId,'model-assignment-v1');
            } else {
                AssignmentStateMachine::transition('offered','declined','applicant',null);
                $evidence='applicant-decline:'.hash('sha256',$statement."\0".$requestId);
                $this->pdo->prepare(
                    'UPDATE model_assignments SET status="declined",declined_by=NULL,declined_at=UTC_TIMESTAMP(),
                     decline_reason=:reason,model_decline_evidence_reference=:evidence,updated_at=UTC_TIMESTAMP()
                     WHERE id=:id AND status="offered"'
                )->execute([
                    'reason'=>$this->encryptField($assignmentId,'decline_reason',$statement),
                    'evidence'=>$this->encryptField($assignmentId,'model_decline_evidence_reference',$evidence),
                    'id'=>$assignmentId,
                ]);
                $this->event($assignmentId,null,'assignment.applicant_declined','offered','declined',
                    'Applicant declined the offered assignment.',$policyVersion,'applicant');
                $this->audit->record(null,'model_assignment.applicant_declined','model_assignment',$assignmentId,
                    'Applicant declined a private model assignment',['status'=>'offered'],['status'=>'declined','reason_sha256'=>hash('sha256',$statement)],
                    $requestId,'model-assignment-v1');
            }
            $this->pdo->commit();
        } catch(Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function recordAcceptance(Principal $actor,string $assignmentId,string $executedDocumentReference,string $modelConsentEvidenceReference,string $requestId): void
    {
        $this->assertEvidenceReference($executedDocumentReference,'private/model-assignments/');
        $this->assertEvidenceReference($modelConsentEvidenceReference,'private/model-consent/');
        $this->transition($actor,$assignmentId,'accepted',[
            'executed_document_reference'=>$executedDocumentReference,
            'model_acceptance_evidence_reference'=>$modelConsentEvidenceReference,
        ],$executedDocumentReference,$requestId);
    }

    public function confirm(Principal $actor,string $assignmentId,string $requestId): void
    {
        $this->transition($actor,$assignmentId,'confirmed',null,'assignment-acceptance-recorded',$requestId);
    }

    public function start(Principal $actor,string $assignmentId,string $requestId): void
    {
        $this->transition($actor,$assignmentId,'in_progress',null,null,$requestId);
    }

    public function complete(Principal $actor,string $assignmentId,string $completionEvidenceReference,string $requestId): void
    {
        $this->assertEvidenceReference($completionEvidenceReference,'private/model-assignments/');
        $this->transition($actor,$assignmentId,'completed',['completion_evidence_reference'=>$completionEvidenceReference],
            $completionEvidenceReference,$requestId);
    }

    public function decline(Principal $actor,string $assignmentId,string $reason,string $modelEvidenceReference,string $requestId): void
    {
        $reason=trim($reason);
        if(strlen($reason)<20 || strlen($reason)>1500) throw new \InvalidArgumentException('Model decline requires a reason of 20 to 1500 characters.');
        $this->assertEvidenceReference($modelEvidenceReference,'private/model-consent/');
        $this->transition($actor,$assignmentId,'declined',['decline_reason'=>$reason,'model_decline_evidence_reference'=>$modelEvidenceReference],
            $modelEvidenceReference,$requestId);
    }

    public function cancel(Principal $actor,string $assignmentId,string $reason,string $requestId): void
    {
        $reason=trim($reason);
        if(strlen($reason)<20 || strlen($reason)>1500) throw new \InvalidArgumentException('Assignment cancellation requires a reason of 20 to 1500 characters.');
        $this->transition($actor,$assignmentId,'cancelled',['cancellation_reason'=>$reason],null,$requestId);
    }

    public function dispute(Principal $actor,string $assignmentId,string $reason,string $requestId): void
    {
        $reason=trim($reason);
        if(strlen($reason)<20 || strlen($reason)>1500) throw new \InvalidArgumentException('Assignment dispute requires a reason of 20 to 1500 characters.');
        $this->transition($actor,$assignmentId,'disputed',['dispute_reason'=>$reason],null,$requestId);
    }

    /** @return list<array<string,mixed>> */
    public function eligibleSubmissions(Principal $actor,int $limit=100): array
    {
        $this->authorize($actor);$limit=max(1,min(200,$limit));
        $query=$this->pdo->prepare(
            'SELECT s.id,s.submission_reference,a.application_reference,c.casting_reference,
                    c.project_purpose,c.country_code,c.shoot_starts_at,c.shoot_ends_at,
                    asset.mime_type,asset.width,asset.height,lic.licence_reference
             FROM model_casting_submissions s
             JOIN model_talent_applications a ON a.id=s.application_id
             JOIN model_casting_calls c ON c.id=s.casting_call_id
             JOIN model_portfolio_assets asset ON asset.id=s.portfolio_asset_id
             JOIN model_image_licences lic ON lic.id=s.image_licence_id
             WHERE s.status="selected" AND c.status<>"cancelled"
               AND NOT EXISTS (SELECT 1 FROM model_assignments existing WHERE existing.submission_id=s.id)
             ORDER BY s.submitted_at DESC LIMIT :limit'
        );
        $query->bindValue(':limit',$limit,PDO::PARAM_INT);$query->execute();return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    public function list(Principal $actor,int $limit=100): array
    {
        $this->authorize($actor);$limit=max(1,min(200,$limit));
        $query=$this->pdo->prepare(
            'SELECT id,assignment_reference,submission_id,application_id,country_code,project_purpose,client_name,
                    status,compensation_minor,currency_code,expense_cap_minor,commission_basis_points,
                    shoot_starts_at,shoot_ends_at,created_at,offered_at,accepted_at,confirmed_at,started_at,completed_at
             FROM model_assignments ORDER BY created_at DESC LIMIT :limit'
        );
        $query->bindValue(':limit',$limit,PDO::PARAM_INT);$query->execute();$rows=$query->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as &$row) {
            $stored=$row['client_name']??null;
            $row['client_name']=is_string($stored) && $stored!==''?
                $this->cipher->decrypt($stored,'model-assignment-v1:'.$row['id'].':client_name'):'Legacy assignment';
        }
        unset($row);return $rows;
    }

    /** @return list<array<string,mixed>> */
    public function expensesForAssignment(Principal $actor,string $assignmentId): array
    {
        $this->authorize($actor);
        $query=$this->pdo->prepare('SELECT id,assignment_id,amount_minor,currency_code,status,created_by,reviewed_by,created_at,reviewed_at FROM model_assignment_expenses WHERE assignment_id=:assignment ORDER BY created_at DESC');
        $query->execute(['assignment'=>$assignmentId]);return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    private function transition(Principal $actor,string $assignmentId,string $toStatus,?array $updates,?string $evidenceReference,string $requestId): void
    {
        $this->authorize($actor);$this->assertRequestId($requestId);
        if(in_array($toStatus,['offered','accepted','confirmed','in_progress','completed'],true)) $this->assertFeature();
        $this->pdo->beginTransaction();
        try {
            $assignment=$this->lock('model_assignments',$assignmentId);
            $submission=$this->lock('model_casting_submissions',(string)$assignment['submission_id']);
            $application=$this->lock('model_talent_applications',(string)$assignment['application_id']);
            $asset=$this->lock('model_portfolio_assets',(string)$submission['portfolio_asset_id']);
            $licence=$this->lock('model_image_licences',(string)$submission['image_licence_id']);
            $call=$this->lock('model_casting_calls',(string)$submission['casting_call_id']);
            $agreement=$this->lock('model_representation_agreements',(string)$assignment['representation_agreement_id']);
            if(in_array($toStatus,['offered','accepted','confirmed','in_progress','completed'],true)) {
                $this->assertCurrentEligibility($assignment,$submission,$application,$asset,$licence,$call,$agreement);
            }
            $from=(string)$assignment['status'];
            if($toStatus==='confirmed') {
                foreach(['executed_document_reference','model_acceptance_evidence_reference'] as $evidenceField) {
                    $stored=$assignment[$evidenceField]??null;
                    if(!is_string($stored) || $stored==='') {
                        throw new \DomainException('Confirmation requires recorded external execution and model-acceptance evidence.');
                    }
                    $this->cipher->decrypt($stored,'model-assignment-v1:'.$assignmentId.':'.$evidenceField);
                }
            }
            $role=$this->actorRole($actor);
            AssignmentStateMachine::transition($from,$toStatus,$role,$evidenceReference);
            $columns=[
                'offered'=>['status'=>'offered','offered_by'=>$actor->id,'offered_at'=>true],
                'accepted'=>['status'=>'accepted','acceptance_recorded_by'=>$actor->id,'accepted_at'=>true],
                'confirmed'=>['status'=>'confirmed','confirmed_by'=>$actor->id,'confirmed_at'=>true],
                'in_progress'=>['status'=>'in_progress','started_by'=>$actor->id,'started_at'=>true],
                'completed'=>['status'=>'completed','completed_by'=>$actor->id,'completed_at'=>true],
                'declined'=>['status'=>'declined','declined_by'=>$actor->id,'declined_at'=>true],
                'cancelled'=>['status'=>'cancelled','cancelled_by'=>$actor->id,'cancelled_at'=>true],
                'disputed'=>['status'=>'disputed','disputed_by'=>$actor->id,'disputed_at'=>true],
            ][$toStatus];
            $set=['status=:status','updated_at=UTC_TIMESTAMP()'];$params=['status'=>$toStatus,'id'=>$assignmentId];
            foreach($columns as $column=>$value) {
                if($column==='status') continue;
                if($value===true) $set[]=$column.'=UTC_TIMESTAMP()';
                else {$set[]=$column.'=:'.$column;$params[$column]=$value;}
            }
            if(is_array($updates)) {
                foreach($updates as $field=>$value) {
                    $set[]=$field.'=:'.$field;
                    $params[$field]=match($field) {
                        'executed_document_reference'=>$this->encryptField($assignmentId,$field,(string)$value),
                        'model_acceptance_evidence_reference'=>$this->encryptField($assignmentId,$field,(string)$value),
                        'completion_evidence_reference'=>$this->encryptField($assignmentId,$field,(string)$value),
                        'decline_reason'=>$this->encryptField($assignmentId,$field,(string)$value),
                        'model_decline_evidence_reference'=>$this->encryptField($assignmentId,$field,(string)$value),
                        'cancellation_reason'=>$this->encryptField($assignmentId,$field,(string)$value),
                        'dispute_reason'=>$this->encryptField($assignmentId,$field,(string)$value),
                        default=>$value,
                    };
                }
            }
            if($toStatus==='accepted' && is_array($updates)) $set[]='acceptance_evidence_reference=:acceptance_evidence_reference';
            if($toStatus==='accepted' && is_array($updates)) $params['acceptance_evidence_reference']=$this->encryptField($assignmentId,'acceptance_evidence_reference',(string)($updates['executed_document_reference']??''));
            $this->pdo->prepare('UPDATE model_assignments SET '.implode(',',$set).' WHERE id=:id')->execute($params);
            $this->event($assignmentId,$actor->id,'assignment.'.$toStatus,$from,$toStatus,'Assignment status changed to '.$toStatus.'.',$this->policyVersionOrLegacy($assignment));
            $this->audit->record($actor->id,'model_assignment.'.$toStatus,'model_assignment',$assignmentId,
                'Assignment status changed',['status'=>$from],['status'=>$toStatus,'evidence_present'=>$evidenceReference!==null],
                $requestId,'model-assignment-v1');
            $this->pdo->commit();
        } catch(Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    private function assertCurrentEligibility(array $assignment,array $submission,array $application,array $asset,array $licence,array $call,array $agreement): void
    {
        if($submission['status']!=='selected' || $assignment['application_id']!==$application['id'] ||
            $assignment['country_code']!==$application['country_code'] || $assignment['representation_agreement_id']!==$agreement['id']) {
            throw new \DomainException('Assignment requires a currently selected submission within the same model, territory and executed agreement.');
        }
        $terms=[
            'project_purpose'=>$assignment['project_purpose'],
            'client_name'=>$this->cipher->decrypt((string)$assignment['client_name'],'model-assignment-v1:'.$assignment['id'].':client_name'),
            'shoot_starts_at'=>$assignment['shoot_starts_at'],
            'shoot_ends_at'=>$assignment['shoot_ends_at'],
        ];
        $this->assertEligible($application,$asset,$licence,$call,$agreement,$terms,(string)$assignment['assignment_policy_version']);
        $agreementTerms=json_decode((string)$agreement['terms_snapshot'],true);
        if(!is_array($agreementTerms) || (int)($agreementTerms['commission_basis_points']??-1)!==(int)$assignment['commission_basis_points']) {
            throw new \DomainException('Executed representation commission terms changed after assignment creation.');
        }
        $this->assertScheduleAvailable((string)$application['id'],(string)$assignment['shoot_starts_at'],(string)$assignment['shoot_ends_at'],(string)$assignment['id']);
    }

    /** @param array<string,mixed> $terms */
    private function assertEligible(array $application,array $asset,array $licence,array $call,array $agreement,array $terms,string $assignmentPolicyVersion): void
    {
        if($application['status']!=='accepted' || (int)$application['legal_hold']===1 || $application['anonymized_at']!==null ||
            $asset['application_id']!==$application['id'] || $asset['status']!=='approved' || $asset['consent_withdrawn_at']!==null ||
            !is_string($asset['private_storage_key']) || $asset['private_storage_key']==='' ||
            $licence['application_id']!==$application['id'] || $licence['portfolio_asset_id']!==$asset['id'] ||
            $licence['status']!=='active' || $licence['purpose']!=='casting_distribution' ||
            $licence['model_consent_evidence_reference']===null || $licence['execution_evidence_reference']===null ||
            $licence['territory_code']!==$application['country_code'] || $call['country_code']!==$application['country_code'] ||
            $call['project_purpose']!==$terms['project_purpose'] || $call['status']==='cancelled' ||
            $agreement['application_id']!==$application['id'] || $agreement['status']!=='executed' ||
            $agreement['id']!==$licence['agreement_id'] || $agreement['effective_from']===null || $agreement['effective_until']===null) {
            throw new \DomainException('Assignment requires an accepted verified model, selected call, approved private asset, active matching licence and executed agreement.');
        }
        $callClient=$this->cipher->decrypt((string)$call['client_name'],'model-casting-call-v1:'.$call['id'].':client_name');
        if(!hash_equals($callClient,(string)$terms['client_name']) ||
            (string)$call['shoot_starts_at']!==(string)$terms['shoot_starts_at'] ||
            (string)$call['shoot_ends_at']!==(string)$terms['shoot_ends_at']) {
            throw new \DomainException('Assignment client, purpose and schedule must match the selected casting call snapshot.');
        }
        $policy=$this->approvedPolicy();
        $castingPolicy=$this->approvedPolicySetting('model_agency.casting');
        if(!hash_equals($assignmentPolicyVersion,$policy['policy_version']) || !hash_equals((string)$call['casting_policy_version'],$castingPolicy['policy_version'])) throw new \DomainException('Assignment or casting policy changed; this assignment requires re-review.');
        $verification=$this->pdo->prepare('SELECT outcome,expires_at FROM model_applicant_verifications WHERE application_id=:application ORDER BY decision_order DESC LIMIT 1 FOR UPDATE');
        $verification->execute(['application'=>$application['id']]);$v=$verification->fetch(PDO::FETCH_ASSOC);
        if(!is_array($v) || $v['outcome']!=='verified' || $v['expires_at']===null ||
            new DateTimeImmutable((string)$v['expires_at'],new DateTimeZone('UTC'))<=new DateTimeImmutable('now',new DateTimeZone('UTC'))) {
            throw new \DomainException('Current independent adult-eligibility verification is required.');
        }
        $this->assertMarketApproved((string)$application['country_code']);
        $portfolioPolicy=$this->approvedPolicySetting('model_agency.portfolio');
        $licencePolicy=$this->approvedPolicySetting('model_agency.image_licence');
        if(!hash_equals((string)$asset['consent_policy_version'],$portfolioPolicy['policy_version']) ||
            !hash_equals((string)$licence['licence_policy_version'],$licencePolicy['policy_version'])) {
            throw new \DomainException('Portfolio consent or image licence policy changed.');
        }
        $assetGrant=$this->pdo->prepare('SELECT granted,withdrawn_at,policy_version FROM consent_grants WHERE id=:id LIMIT 1 FOR UPDATE');
        $assetGrant->execute(['id'=>$asset['consent_grant_id']]);$ag=$assetGrant->fetch(PDO::FETCH_ASSOC);
        $licenceGrant=$this->pdo->prepare('SELECT granted,withdrawn_at,policy_version FROM consent_grants WHERE id=:id LIMIT 1 FOR UPDATE');
        $licenceGrant->execute(['id'=>$licence['consent_grant_id']]);$lg=$licenceGrant->fetch(PDO::FETCH_ASSOC);
        if(!is_array($ag) || (int)$ag['granted']!==1 || $ag['withdrawn_at']!==null ||
            !hash_equals((string)$asset['consent_policy_version'],(string)$ag['policy_version']) ||
            !is_array($lg) || (int)$lg['granted']!==1 || $lg['withdrawn_at']!==null ||
            !hash_equals((string)$licence['licence_policy_version'],(string)$lg['policy_version'])) {
            throw new \DomainException('Current model portfolio and image-use consent are required.');
        }
        $licenceChannels=json_decode((string)$licence['channels'],true);
        if(!is_array($licenceChannels) || !in_array('casting_platform',$licenceChannels,true) ||
            (string)$licence['starts_on']>substr((string)$terms['shoot_starts_at'],0,10) ||
            (string)$licence['ends_on']<substr((string)$terms['shoot_ends_at'],0,10)) {
            throw new \DomainException('The active image licence must cover the complete assignment and casting channel.');
        }
        $agreementTerms=json_decode((string)$agreement['terms_snapshot'],true);
        if(!is_array($agreementTerms) || !in_array('casting_submissions',$agreementTerms['service_scope']??[],true) ||
            substr((string)$agreement['effective_from'],0,10)>substr((string)$terms['shoot_starts_at'],0,10) ||
            substr((string)$agreement['effective_until'],0,10)<substr((string)$terms['shoot_ends_at'],0,10)) {
            throw new \DomainException('Executed representation agreement does not cover the assignment period.');
        }
        $bytes=$this->storage->read((string)$asset['private_storage_key'],8_388_608);
        if(!hash_equals((string)$asset['sha256'],hash('sha256',$bytes))) throw new \DomainException('Private portfolio asset failed integrity verification.');
    }

    /** @param array<string,mixed> $terms */
    private function validateTerms(array $terms): void
    {
        foreach(['project_purpose','client_name','brief','location_city','shoot_starts_at','shoot_ends_at','deliverables'] as $field) {
            if(!is_string($terms[$field]??null) || trim($terms[$field])==='') throw new \InvalidArgumentException('Assignment terms are incomplete.');
        }
        if(!in_array($terms['project_purpose'],['editorial','commercial_campaign','e_commerce','social_media','advertising','other'],true) ||
            strlen($terms['client_name'])>200 || strlen($terms['brief'])<20 || strlen($terms['brief'])>5000 ||
            strlen($terms['location_city'])>180 || strlen($terms['deliverables'])<20 || strlen($terms['deliverables'])>3000) {
            throw new \InvalidArgumentException('Assignment project, client, brief, location or deliverables are invalid.');
        }
        foreach(['shoot_starts_at','shoot_ends_at'] as $field) {
            try {$date=new DateTimeImmutable($terms[$field]);}
            catch(Throwable) {throw new \InvalidArgumentException('Assignment schedule must use valid date/time values.');}
            if($date->format('Y-m-d H:i:s')!==$terms[$field]) throw new \InvalidArgumentException('Assignment schedule must use UTC SQL datetime format.');
        }
        if(new DateTimeImmutable($terms['shoot_ends_at'],new DateTimeZone('UTC'))<=new DateTimeImmutable($terms['shoot_starts_at'],new DateTimeZone('UTC'))) {
            throw new \InvalidArgumentException('Assignment end must be after its start.');
        }
        $amount=$terms['compensation_amount']??null;$currency=$terms['currency_code']??null;
        $expense=$terms['expense_cap_amount']??null;
        if(($amount===null)!==($currency===null) || ($expense!==null && $currency===null)) throw new \InvalidArgumentException('Assignment compensation and expense cap require a currency.');
        foreach([$amount,$expense] as $value) if($value!==null && (!is_string($value) || preg_match('/^(?:0|[1-9][0-9]{0,13})(?:\\.[0-9]{1,4})?$/D',$value)!==1)) throw new \InvalidArgumentException('Assignment monetary values must be positive decimal strings.');
        if($currency!==null && preg_match('/^[A-Z]{3}$/D',$currency)!==1) throw new \InvalidArgumentException('Assignment currency must be ISO 4217.');
        if(isset($terms['client_reference']) && (!is_string($terms['client_reference']) || strlen($terms['client_reference'])>500)) throw new \InvalidArgumentException('Assignment client reference is invalid.');
    }

    private function assertScheduleAvailable(string $applicationId,string $startsAt,string $endsAt,?string $exceptId=null): void
    {
        $query=$this->pdo->prepare(
            'SELECT id FROM model_assignments WHERE application_id=:application
             AND status IN ("offered","accepted","confirmed","in_progress")
             AND shoot_starts_at<:ends_at AND shoot_ends_at>:starts_at
             AND (:except_null IS NULL OR id<>:except_match) LIMIT 1 FOR UPDATE'
        );
        $query->execute(['application'=>$applicationId,'ends_at'=>$endsAt,'starts_at'=>$startsAt,'except_null'=>$exceptId,'except_match'=>$exceptId]);
        if($query->fetchColumn()!==false) throw new \DomainException('The model has an overlapping active assignment.');
    }

    private function approvedPolicy(): array
    {
        $q=$this->pdo->prepare('SELECT setting_json FROM deployment_settings WHERE setting_key="model_agency.assignment" LIMIT 1');$q->execute();
        $raw=$q->fetchColumn();$policy=is_string($raw)?json_decode($raw,true):null;
        if(!is_array($policy) || !($policy['enabled']??false) || !($policy['policy_approved']??false) ||
            !is_string($policy['policy_version']??null) || trim($policy['policy_version'])==='') {
            throw new \DomainException('A current versioned assignment policy must be explicitly approved.');
        }
        return ['policy_version'=>trim($policy['policy_version'])];
    }

    private function approvedPolicySetting(string $key): array
    {
        $q=$this->pdo->prepare('SELECT setting_json FROM deployment_settings WHERE setting_key=:key LIMIT 1');$q->execute(['key'=>$key]);
        $raw=$q->fetchColumn();$policy=is_string($raw)?json_decode($raw,true):null;
        if(!is_array($policy) || !($policy['enabled']??false) || !($policy['policy_approved']??false) ||
            !is_string($policy['policy_version']??null) || trim($policy['policy_version'])==='') throw new \DomainException('A current approved model usage policy is required.');
        return ['policy_version'=>trim($policy['policy_version'])];
    }

    private function assertMarketApproved(string $country): void
    {
        $q=$this->pdo->prepare('SELECT c.enabled,m.enabled AS market_enabled,m.decision,m.expires_at,m.evidence_reference FROM countries c LEFT JOIN market_policies m ON m.country_code=c.alpha2 WHERE c.alpha2=:country LIMIT 1 FOR UPDATE');
        $q->execute(['country'=>$country]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row) || (int)$row['enabled']!==1 || (int)($row['market_enabled']??0)!==1 || $row['decision']!=='approved' ||
            $row['expires_at']===null || $row['evidence_reference']===null ||
            new DateTimeImmutable((string)$row['expires_at'],new DateTimeZone('UTC'))<=new DateTimeImmutable('now',new DateTimeZone('UTC'))) {
            throw new \DomainException('Assignment requires a current approved market with evidence.');
        }
    }

    private function decimalToMinor(string $amount,int $precision): int
    {
        if($precision<0 || $precision>4) throw new \DomainException('Currency precision is unsupported.');
        [$whole,$fraction]=array_pad(explode('.',$amount,2),2,'');
        if(strlen($fraction)>$precision && trim(substr($fraction,$precision),'0')!=='') throw new \InvalidArgumentException('Amount exceeds currency minor-unit precision.');
        $scale=10**$precision;
        $fractionMinor=(int)str_pad(substr($fraction,0,$precision),$precision,'0');
        if((int)$whole>intdiv(PHP_INT_MAX-$fractionMinor,$scale)) throw new \InvalidArgumentException('Amount exceeds supported integer range.');
        $minor=((int)$whole*$scale)+$fractionMinor;
        if($minor<0) throw new \InvalidArgumentException('Amount is invalid.');
        return $minor;
    }

    private function event(string $assignmentId,?string $actorId,string $type,string $fromStatus,string $toStatus,string $reason,string $policyVersion,string $actorType='administrator'): void
    {
        $this->pdo->prepare(
            'INSERT INTO model_assignment_events (id,assignment_id,actor_user_id,actor_type,from_status,to_status,event_type,reason,evidence_reference,occurred_at,created_at,updated_at)
             VALUES (:id,:assignment,:actor,:actor_type,:from_status,:to_status,:event,:reason,NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())'
        )->execute(['id'=>self::uuid(),'assignment'=>$assignmentId,'actor'=>$actorId,'actor_type'=>$actorType,'from_status'=>$fromStatus,'to_status'=>$toStatus,'event'=>$type,
            'reason'=>$this->cipher->encrypt($reason,'model-assignment-event-v1:'.$assignmentId.':'.$type)]);
    }

    private function lock(string $table,string $id): array
    {
        $allowed=['model_assignments','model_casting_submissions','model_talent_applications','model_portfolio_assets','model_image_licences','model_casting_calls','model_representation_agreements'];
        if(!in_array($table,$allowed,true) || !preg_match('/^[0-9a-f-]{36}$/iD',$id)) throw new \InvalidArgumentException('Assignment resource identifier is invalid.');
        $q=$this->pdo->prepare('SELECT * FROM '.$table.' WHERE id=:id LIMIT 1 FOR UPDATE');$q->execute(['id'=>$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row)) throw new \DomainException('Assignment resource does not exist.');return $row;
    }

    private function authorize(Principal $actor): void
    {
        Authorization::require($actor,'model_assignments.manage',true);
        if(!$actor->hasRole('admin') && !$actor->hasRole('owner') && !$actor->hasRole('super_admin')) throw new \DomainException('MFA-verified agency administrator role is required.');
    }

    private function actorRole(Principal $actor): string
    {
        foreach(['super_admin','owner','admin'] as $role) if($actor->hasRole($role)) return $role;
        throw new \DomainException('Agency administrator role is required.');
    }

    private function assertFeature(): void
    {
        if(!(($this->assignmentsEnabled)())) throw new \DomainException('Model assignments are disabled until legal, consent and market prerequisites are approved.');
    }

    private function assertRequestId(string $requestId): void
    {
        if(strlen($requestId)<16 || strlen($requestId)>64) throw new \InvalidArgumentException('Request identifier is invalid.');
    }

    private function assertEvidenceReference(string $reference,string $prefix): void
    {
        if(strlen($reference)<20 || strlen($reference)>200 || !str_starts_with($reference,$prefix) ||
            preg_match('#^'.preg_quote($prefix,'#').'[A-Za-z0-9][A-Za-z0-9._/-]{10,180}$#D',$reference)!==1 ||
            str_contains($reference,'..') || str_contains($reference,'\\')) throw new \InvalidArgumentException('Assignment evidence reference is invalid.');
    }

    private function encryptField(string $id,string $field,string $value): string
    {
        return $this->cipher->encrypt($value,'model-assignment-v1:'.$id.':'.$field);
    }

    private function policyVersionOrLegacy(array $assignment): string
    {
        return is_string($assignment['assignment_policy_version']??null) && $assignment['assignment_policy_version']!==''?(string)$assignment['assignment_policy_version']:'model-assignment-legacy';
    }

    private static function uuid(): string
    {
        $bytes=random_bytes(16);$bytes[6]=chr((ord($bytes[6])&15)|64);$bytes[8]=chr((ord($bytes[8])&63)|128);$hex=bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }
}
