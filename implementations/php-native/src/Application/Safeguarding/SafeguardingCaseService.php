<?php
declare(strict_types=1);

namespace Talyvane\Application\Safeguarding;

use DomainException;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Security\Authorization;
use Talyvane\Security\Principal;

/** Restricted case intake and assigned-specialist workflow. Reporter access ends at acknowledgement. */
final class SafeguardingCaseService
{
    private const CATEGORIES=[
        'consent_concern','coercion_or_exploitation','trafficking_concern',
        'minor_safety_concern','nonconsensual_content','threat_or_harassment',
        'privacy_or_data_risk','other',
    ];
    private const SEVERITIES=['low','medium','high','critical'];
    private const TRANSITIONS=[
        'open'=>['triaged','investigating','action_required'],
        'triaged'=>['investigating','action_required','resolved'],
        'investigating'=>['action_required','resolved'],
        'action_required'=>['investigating','resolved'],
        'resolved'=>['investigating','closed'],
        'closed'=>[],
    ];

    public function __construct(
        private PDO $pdo,
        private SafeguardingCryptoService $crypto,
        private AuditLogger $audit,
        private ?SafeguardingNotificationOutbox $notifications=null
    ) {}

    /** @return list<array{id:string,email:string}> */
    public function availableSpecialists(Principal $actor): array
    {
        $this->assertReporter($actor);
        $query=$this->pdo->query(
            'SELECT u.id,u.email FROM users u
             JOIN user_roles ur ON ur.user_id=u.id AND ur.role_key="safeguarding"
             JOIN admin_mfa_credentials mfa ON mfa.user_id=u.id AND mfa.enabled_at IS NOT NULL
             WHERE u.status="active" AND u.email_verified_at IS NOT NULL
             ORDER BY u.email ASC'
        );
        return $query->fetchAll();
    }

    /** @return array{case_reference:string} */
    public function create(
        Principal $reporter,
        string $category,
        string $severity,
        string $summary,
        string $details,
        string $subjectReference,
        string $specialistId,
        string $requestId
    ): array {
        $this->assertReporter($reporter);
        $category=trim($category);
        $severity=trim($severity);
        $summary=trim($summary);
        $details=trim($details);
        $subjectReference=trim($subjectReference);
        if (!in_array($category,self::CATEGORIES,true) || !in_array($severity,self::SEVERITIES,true)) {
            throw new InvalidArgumentException('Choose a valid safeguarding category and severity.');
        }
        if (strlen($summary)<20 || strlen($summary)>300 || strlen($details)<30 || strlen($details)>5000 ||
            strlen($subjectReference)>160 || strlen($specialistId)>64) {
            throw new InvalidArgumentException('Safeguarding report fields are missing or exceed their limits.');
        }
        $caseId=self::uuid();
        $caseReference='SAFE-'.gmdate('Ymd').'-'.strtoupper(bin2hex(random_bytes(4)));
        $caseKey=$this->crypto->generateCaseKey();
        $wrappedKey=$this->crypto->wrapCaseKey($caseId,$caseKey);
        $summaryCipher=$this->crypto->encryptField($caseId,'summary',$summary,$caseKey);
        $detailsCipher=$this->crypto->encryptField($caseId,'details',$details,$caseKey);
        $subjectCipher=$subjectReference===''?null:$this->crypto->encryptField($caseId,'subject_reference',$subjectReference,$caseKey);
        $eventId=self::uuid();
        $eventNote=$this->crypto->encryptEventNote($caseId,$eventId,'Initial report submitted and assigned to a safeguarding specialist.',$caseKey);

        $this->pdo->beginTransaction();
        try {
            $specialist=$this->pdo->prepare(
                'SELECT u.id FROM users u
                 JOIN user_roles ur ON ur.user_id=u.id AND ur.role_key="safeguarding"
                 JOIN admin_mfa_credentials mfa ON mfa.user_id=u.id AND mfa.enabled_at IS NOT NULL
                 WHERE u.id=:id AND u.status="active" AND u.email_verified_at IS NOT NULL LIMIT 1 FOR UPDATE'
            );
            $specialist->execute(['id'=>$specialistId]);
            if ($specialist->fetchColumn()===false) {
                throw new DomainException('Select an active safeguarding specialist with enrolled MFA.');
            }
            $insert=$this->pdo->prepare(
                'INSERT INTO safeguarding_cases
                 (id,case_reference,assigned_specialist_id,reporter_user_id,category,severity,status,
                  encrypted_case_key,key_version,encryption_provider,summary_ciphertext,details_ciphertext,
                  subject_reference_ciphertext,legal_hold,created_at,updated_at)
                 VALUES (:id,:reference,:specialist,:reporter,:category,:severity,"open",
                         :key,"case-key-v1",:provider,:summary,:details,:subject,0,UTC_TIMESTAMP(),UTC_TIMESTAMP())'
            );
            $insert->execute([
                'id'=>$caseId,'reference'=>$caseReference,'specialist'=>$specialistId,'reporter'=>$reporter->id,
                'category'=>$category,'severity'=>$severity,'key'=>$wrappedKey,'provider'=>$this->crypto->providerName(),
                'summary'=>$summaryCipher,'details'=>$detailsCipher,'subject'=>$subjectCipher,
            ]);
            $this->insertCaseEvent($caseId,$reporter->id,'case.created',null,'open',$eventNote,$eventId);
            $this->notifications?->enqueue($caseId,$specialistId,$caseReference,$category,$severity);
            $this->insertAccessEvent($caseId,$reporter->id,'submitted',$requestId);
            $this->audit->record($reporter->id,'safeguarding.case.created','safeguarding_case',$caseId,
                'Restricted safeguarding report submitted',
                null,['case_reference'=>$caseReference,'category'=>$category,'severity'=>$severity,'status'=>'open'],
                $requestId,'safeguarding-case-v1');
            $this->pdo->commit();
            return ['case_reference'=>$caseReference];
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $error;
        } finally {
            if (function_exists('sodium_memzero')) { sodium_memzero($caseKey); }
        }
    }

    /** @return list<array<string,mixed>> */
    public function assignedCases(Principal $specialist): array
    {
        $this->assertSpecialist($specialist,'safeguarding.cases.assigned.view');
        $query=$this->pdo->prepare(
            'SELECT id,case_reference,category,severity,status,created_at,updated_at,closed_at,
                    retention_due_at,retention_applied_at
             FROM safeguarding_cases WHERE assigned_specialist_id=:actor
             ORDER BY FIELD(severity,"critical","high","medium","low"),created_at ASC LIMIT 100'
        );
        $query->execute(['actor'=>$specialist->id]);
        return $query->fetchAll();
    }

    /** @return array<string,mixed> */
    public function assignedCase(Principal $specialist,string $caseId,string $requestId): array
    {
        $this->assertSpecialist($specialist,'safeguarding.cases.assigned.view');
        $query=$this->pdo->prepare(
            'SELECT id,case_reference,assigned_specialist_id,category,severity,status,
                    encrypted_case_key,key_version,encryption_provider,summary_ciphertext,
                    details_ciphertext,subject_reference_ciphertext,legal_hold,created_at,updated_at,
                    closed_at,retention_due_at,retention_applied_at
             FROM safeguarding_cases WHERE id=:id AND assigned_specialist_id=:actor LIMIT 1'
        );
        $query->execute(['id'=>$caseId,'actor'=>$specialist->id]);
        $case=$query->fetch();
        if (!is_array($case)) {
            throw new DomainException('Assigned safeguarding case was not found.');
        }
        $this->insertAccessEvent($caseId,$specialist->id,'viewed',$requestId);
        $case['summary']=null;
        $case['details']=null;
        $case['subject_reference']=null;
        $case['content_destroyed']=$case['encrypted_case_key']===null;
        $events=[];
        if (!$case['content_destroyed']) {
            $caseKey=$this->crypto->unwrapCaseKey($caseId,(string) $case['encrypted_case_key']);
            try {
                $case['summary']=$this->crypto->decryptField($caseId,'summary',(string) $case['summary_ciphertext'],$caseKey);
                $case['details']=$this->crypto->decryptField($caseId,'details',(string) $case['details_ciphertext'],$caseKey);
                if (is_string($case['subject_reference_ciphertext'])) {
                    $case['subject_reference']=$this->crypto->decryptField($caseId,'subject_reference',$case['subject_reference_ciphertext'],$caseKey);
                }
                $eventQuery=$this->pdo->prepare(
                    'SELECT id,event_key,from_status,to_status,encrypted_note,created_at,actor_kind
                     FROM safeguarding_case_events WHERE case_id=:case ORDER BY created_at ASC LIMIT 200'
                );
                $eventQuery->execute(['case'=>$caseId]);
                foreach ($eventQuery->fetchAll() as $event) {
                    $note=null;
                    if (is_string($event['encrypted_note'])) {
                        $note=$this->crypto->decryptEventNote($caseId,(string) $event['id'],$event['encrypted_note'],$caseKey);
                    }
                    $events[]=[
                        'event_key'=>$event['event_key'],'from_status'=>$event['from_status'],
                        'to_status'=>$event['to_status'],'note'=>$note,'created_at'=>$event['created_at'],
                        'actor_kind'=>$event['actor_kind'],
                    ];
                }
            } finally {
                if (function_exists('sodium_memzero')) { sodium_memzero($caseKey); }
            }
        }
        $case['events']=$events;
        unset($case['encrypted_case_key'],$case['summary_ciphertext'],$case['details_ciphertext'],$case['subject_reference_ciphertext']);
        return $case;
    }

    public function transition(Principal $specialist,string $caseId,string $toStatus,string $reason,string $requestId): void
    {
        $this->assertSpecialist($specialist,'safeguarding.cases.assigned.update');
        $toStatus=trim($toStatus);
        $reason=trim($reason);
        if (strlen($reason)<10 || strlen($reason)>1000) {
            throw new InvalidArgumentException('A case transition note between 10 and 1,000 characters is required.');
        }
        $this->pdo->beginTransaction();
        try {
            $query=$this->pdo->prepare(
                'SELECT id,case_reference,assigned_specialist_id,status,encrypted_case_key,category,severity
                 FROM safeguarding_cases WHERE id=:id AND assigned_specialist_id=:actor FOR UPDATE'
            );
            $query->execute(['id'=>$caseId,'actor'=>$specialist->id]);
            $case=$query->fetch();
            if (!is_array($case)) { throw new DomainException('Assigned safeguarding case was not found.'); }
            $fromStatus=(string) $case['status'];
            if (!in_array($toStatus,self::TRANSITIONS[$fromStatus] ?? [],true)) {
                throw new DomainException('This safeguarding case status transition is not permitted.');
            }
            $caseKey=$this->crypto->unwrapCaseKey($caseId,is_string($case['encrypted_case_key'])?$case['encrypted_case_key']:null);
            $eventId=self::uuid();
            try {
                $encryptedNote=$this->crypto->encryptEventNote($caseId,$eventId,$reason,$caseKey);
            } finally {
                if (function_exists('sodium_memzero')) { sodium_memzero($caseKey); }
            }
            $retention=$toStatus==='closed'?$this->retentionSchedule((string) $case['category']):null;
            $update=$this->pdo->prepare(
                'UPDATE safeguarding_cases SET status=:status,updated_at=UTC_TIMESTAMP(),
                    closed_at=:closed_at,retention_due_at=:retention_due_at,
                    retention_policy_version=:retention_policy_version
                 WHERE id=:id AND assigned_specialist_id=:actor'
            );
            $update->execute([
                'status'=>$toStatus,'closed_at'=>$toStatus==='closed'?gmdate('Y-m-d H:i:s'):null,
                'retention_due_at'=>$retention['due_at']??null,
                'retention_policy_version'=>$retention['policy_version']??null,
                'id'=>$caseId,'actor'=>$specialist->id,
            ]);
            $this->insertCaseEvent($caseId,$specialist->id,'case.status.changed',$fromStatus,$toStatus,$encryptedNote,$eventId);
            $this->insertAccessEvent($caseId,$specialist->id,'status_changed',$requestId);
            $this->audit->record($specialist->id,'safeguarding.case.status_changed','safeguarding_case',$caseId,
                'Assigned specialist changed safeguarding case status',
                ['status'=>$fromStatus],['status'=>$toStatus,'retention_scheduled'=>is_array($retention)],
                $requestId,'safeguarding-case-v1');
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $error;
        }
    }

    /** @return list<array<string,mixed>> Metadata-only governance projection. */
    public function governanceCases(Principal $actor): array
    {
        $this->assertGovernance($actor);
        $query=$this->pdo->query(
            'SELECT id,case_reference,category,severity,status,assigned_specialist_id,
                    created_at,closed_at,legal_hold,legal_hold_actor_id,legal_hold_at,
                    retention_due_at,retention_applied_at
             FROM safeguarding_cases ORDER BY created_at DESC LIMIT 100'
        );
        $cases=$query->fetchAll();
        foreach ($cases as $case) {
            $this->insertAccessEvent((string) $case['id'],$actor->id,'governance_viewed',bin2hex(random_bytes(16)));
        }
        return $cases;
    }

    /** Owner/Super Admin reassignment revokes the former specialist's access immediately. */
    public function reassign(Principal $actor,string $caseId,string $specialistId,string $reason,string $requestId): void
    {
        $this->assertGovernance($actor);
        $reason=trim($reason);
        if(strlen($reason)<20 || strlen($reason)>1000 || !preg_match('/^[0-9a-f-]{36}$/iD',$specialistId)) {
            throw new InvalidArgumentException('Choose an eligible specialist and provide a reassignment reason of 20 to 1,000 characters.');
        }
        $this->pdo->beginTransaction();
        try {
            $query=$this->pdo->prepare(
                'SELECT id,case_reference,assigned_specialist_id,category,severity,status,encrypted_case_key
                 FROM safeguarding_cases WHERE id=:id FOR UPDATE'
            );
            $query->execute(['id'=>$caseId]);
            $case=$query->fetch(PDO::FETCH_ASSOC);
            if(!is_array($case)) throw new DomainException('Safeguarding case was not found.');
            if($case['status']==='closed') throw new DomainException('Closed safeguarding cases cannot be reassigned.');
            if(hash_equals((string)$case['assigned_specialist_id'],$specialistId)) {
                throw new DomainException('Choose a different specialist for reassignment.');
            }
            $eligible=$this->pdo->prepare(
                'SELECT u.id FROM users u
                 JOIN user_roles r ON r.user_id=u.id AND r.role_key="safeguarding"
                 JOIN admin_mfa_credentials m ON m.user_id=u.id AND m.enabled_at IS NOT NULL
                 WHERE u.id=:id AND u.status="active" AND u.email_verified_at IS NOT NULL LIMIT 1 FOR UPDATE'
            );
            $eligible->execute(['id'=>$specialistId]);
            if($eligible->fetchColumn()===false) throw new DomainException('The new assignee must be an active, email-verified safeguarding specialist with enrolled MFA.');
            $eventId=self::uuid();
            $caseKey=$this->crypto->unwrapCaseKey($caseId,is_string($case['encrypted_case_key'])?$case['encrypted_case_key']:null);
            try {
                $encryptedNote=$this->crypto->encryptEventNote($caseId,$eventId,$reason,$caseKey);
            } finally {
                if(function_exists('sodium_memzero')) sodium_memzero($caseKey);
            }
            $this->pdo->prepare(
                'UPDATE safeguarding_cases SET assigned_specialist_id=:specialist,updated_at=UTC_TIMESTAMP()
                 WHERE id=:id AND assigned_specialist_id=:previous'
            )->execute(['specialist'=>$specialistId,'id'=>$caseId,'previous'=>$case['assigned_specialist_id']]);
            $this->insertCaseEvent($caseId,$actor->id,'case.reassigned',(string)$case['status'],(string)$case['status'],$encryptedNote,$eventId);
            $this->insertAccessEvent($caseId,$actor->id,'case_reassigned',$requestId);
            $this->notifications?->enqueue($caseId,$specialistId,(string)$case['case_reference'],(string)$case['category'],(string)$case['severity'],'case.reassigned',$eventId);
            $this->audit->record($actor->id,'safeguarding.case.reassigned','safeguarding_case',$caseId,
                'Safeguarding case reassigned by privileged governance actor',
                ['assigned_specialist_id'=>$case['assigned_specialist_id']],
                ['assigned_specialist_id'=>$specialistId,'status'=>$case['status']],
                $requestId,'safeguarding-case-v1');
            $this->pdo->commit();
        } catch(\\Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function placeLegalHold(Principal $actor,string $caseId,string $reason,string $requestId): void
    {
        $this->assertGovernance($actor);
        $reason=trim($reason);
        if (strlen($reason)<20 || strlen($reason)>1000) {
            throw new InvalidArgumentException('A legal-hold reason between 20 and 1,000 characters is required.');
        }
        $this->pdo->beginTransaction();
        try {
            $query=$this->pdo->prepare('SELECT id,legal_hold,encrypted_case_key FROM safeguarding_cases WHERE id=:id FOR UPDATE');
            $query->execute(['id'=>$caseId]);
            $case=$query->fetch();
            if (!is_array($case)) { throw new DomainException('Safeguarding case was not found.'); }
            if ((int) $case['legal_hold']===1) { throw new DomainException('This safeguarding case already has an active legal hold.'); }
            $caseKey=$this->crypto->unwrapCaseKey($caseId,is_string($case['encrypted_case_key'])?$case['encrypted_case_key']:null);
            $eventId=self::uuid();
            try { $encryptedReason=$this->crypto->encryptHoldReason($caseId,$eventId,$reason,$caseKey); }
            finally { if (function_exists('sodium_memzero')) { sodium_memzero($caseKey); } }
            $update=$this->pdo->prepare(
                'UPDATE safeguarding_cases SET legal_hold=1,legal_hold_reason_ciphertext=:reason,
                    legal_hold_actor_id=:actor,legal_hold_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id'
            );
            $update->execute(['reason'=>$encryptedReason,'actor'=>$actor->id,'id'=>$caseId]);
            $insert=$this->pdo->prepare(
                'INSERT INTO safeguarding_legal_hold_events (id,case_id,actor_user_id,action,encrypted_reason,created_at)
                 VALUES (:id,:case,:actor,"placed",:reason,UTC_TIMESTAMP())'
            );
            $insert->execute(['id'=>$eventId,'case'=>$caseId,'actor'=>$actor->id,'reason'=>$encryptedReason]);
            $this->insertAccessEvent($caseId,$actor->id,'legal_hold_placed',$requestId);
            $this->audit->record($actor->id,'safeguarding.legal_hold.placed','safeguarding_case',$caseId,
                'Metadata-only legal hold placed by privileged reviewer',null,['legal_hold'=>true],$requestId,'safeguarding-governance-v1');
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $error;
        }
    }

    public function releaseLegalHold(Principal $actor,string $caseId,string $reason,string $requestId): void
    {
        $this->assertGovernance($actor);
        $reason=trim($reason);
        if (strlen($reason)<20 || strlen($reason)>1000) {
            throw new InvalidArgumentException('A legal-hold release reason between 20 and 1,000 characters is required.');
        }
        $this->pdo->beginTransaction();
        try {
            $query=$this->pdo->prepare('SELECT id,legal_hold,legal_hold_actor_id,encrypted_case_key FROM safeguarding_cases WHERE id=:id FOR UPDATE');
            $query->execute(['id'=>$caseId]);
            $case=$query->fetch();
            if (!is_array($case)) { throw new DomainException('Safeguarding case was not found.'); }
            if ((int) $case['legal_hold']!==1) { throw new DomainException('This safeguarding case has no active legal hold.'); }
            if ((string) $case['legal_hold_actor_id']===$actor->id) {
                throw new DomainException('The actor who placed a legal hold cannot release that same hold.');
            }
            $caseKey=$this->crypto->unwrapCaseKey($caseId,is_string($case['encrypted_case_key'])?$case['encrypted_case_key']:null);
            $eventId=self::uuid();
            try { $encryptedReason=$this->crypto->encryptHoldReason($caseId,$eventId,$reason,$caseKey); }
            finally { if (function_exists('sodium_memzero')) { sodium_memzero($caseKey); } }
            $update=$this->pdo->prepare(
                'UPDATE safeguarding_cases SET legal_hold=0,legal_hold_reason_ciphertext=NULL,
                    legal_hold_actor_id=NULL,legal_hold_at=NULL,updated_at=UTC_TIMESTAMP() WHERE id=:id'
            );
            $update->execute(['id'=>$caseId]);
            $insert=$this->pdo->prepare(
                'INSERT INTO safeguarding_legal_hold_events (id,case_id,actor_user_id,action,encrypted_reason,created_at)
                 VALUES (:id,:case,:actor,"released",:reason,UTC_TIMESTAMP())'
            );
            $insert->execute(['id'=>$eventId,'case'=>$caseId,'actor'=>$actor->id,'reason'=>$encryptedReason]);
            $this->insertAccessEvent($caseId,$actor->id,'legal_hold_released',$requestId);
            $this->audit->record($actor->id,'safeguarding.legal_hold.released','safeguarding_case',$caseId,
                'Metadata-only legal hold released by independent privileged reviewer',['legal_hold'=>true],['legal_hold'=>false],
                $requestId,'safeguarding-governance-v1');
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $error;
        }
    }

    private function assertGovernance(Principal $actor): void
    {
        Authorization::require($actor,'safeguarding.governance',true);
        if (!$actor->hasRole('owner') && !$actor->hasRole('super_admin')) {
            throw new DomainException('Only an MFA-verified Owner or Super Admin can access safeguarding governance.');
        }
        $this->assertActorActive($actor,['owner','super_admin']);
    }

    /** @return array{due_at:string,policy_version:string}|null */
    private function retentionSchedule(string $category): ?array
    {
        if (!$this->crypto->supportsDestruction()) { return null; }
        $backup=$this->pdo->query(
            'SELECT policy_version,expiry_days,encrypted_backups,enabled,approved_by
             FROM safeguarding_backup_retention_policies WHERE singleton_id=1 LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        if (!is_array($backup)||(int)$backup['enabled']!==1||(int)$backup['encrypted_backups']!==1||
            (int)$backup['expiry_days']<1) { return null; }
        $approver=$this->pdo->prepare(
            'SELECT 1 FROM users u JOIN user_roles r ON r.user_id=u.id AND r.role_key IN ("owner","super_admin")
             JOIN admin_mfa_credentials m ON m.user_id=u.id AND m.enabled_at IS NOT NULL
             WHERE u.id=:id AND u.status="active" AND u.email_verified_at IS NOT NULL LIMIT 1'
        );
        $approver->execute(['id'=>$backup['approved_by']]);
        if ($approver->fetchColumn()===false) { return null; }
        $restore=$this->pdo->prepare(
            'SELECT 1 FROM safeguarding_restore_verifications v
             JOIN users u ON u.id=v.verified_by AND u.status="active"
             JOIN user_roles r ON r.user_id=u.id AND r.role_key IN ("owner","super_admin")
             JOIN admin_mfa_credentials m ON m.user_id=u.id AND m.enabled_at IS NOT NULL
             WHERE v.policy_version=:version AND v.verification_status="passed"
               AND v.verified_at>=UTC_TIMESTAMP()-INTERVAL 90 DAY
               AND v.verified_by<>:approver LIMIT 1'
        );
        $restore->execute(['version'=>$backup['policy_version'],'approver'=>$backup['approved_by']]);
        if ($restore->fetchColumn()===false) { return null; }
        $query=$this->pdo->prepare(
            'SELECT retention_days,policy_version,legal_basis_reference
             FROM safeguarding_retention_policies
             WHERE category=:category AND enabled=1 LIMIT 1'
        );
        $query->execute(['category'=>$category]);
        $policy=$query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($policy)||(int)$policy['retention_days']<1||
            !hash_equals((string)$backup['policy_version'],(string)$policy['policy_version'])||
            trim((string)$policy['legal_basis_reference'])==='') { return null; }
        $days=(int)$policy['retention_days']+(int)$backup['expiry_days'];
        $due=(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->modify('+'.$days.' days');
        return ['due_at'=>$due->format('Y-m-d H:i:s'),'policy_version'=>(string)$policy['policy_version']];
    }

    private function insertCaseEvent(
        string $caseId,string $actorId,string $eventKey,?string $fromStatus,?string $toStatus,?string $encryptedNote,string $eventId
    ): void {
        $query=$this->pdo->prepare(
            'INSERT INTO safeguarding_case_events
             (id,case_id,actor_user_id,actor_kind,event_key,from_status,to_status,encrypted_note,created_at)
             VALUES (:id,:case,:actor,"user",:event,:from_status,:to_status,:note,UTC_TIMESTAMP())'
        );
        $query->execute([
            'id'=>$eventId,'case'=>$caseId,'actor'=>$actorId,'event'=>$eventKey,
            'from_status'=>$fromStatus,'to_status'=>$toStatus,'note'=>$encryptedNote,
        ]);
    }

    private function insertAccessEvent(string $caseId,string $actorId,string $accessType,string $requestId): void
    {
        $query=$this->pdo->prepare(
            'INSERT INTO safeguarding_case_access_events
             (id,case_id,actor_user_id,access_type,request_id,occurred_at)
             VALUES (:id,:case,:actor,:type,:request,UTC_TIMESTAMP())'
        );
        $query->execute([
            'id'=>self::uuid(),'case'=>$caseId,'actor'=>$actorId,'type'=>$accessType,'request'=>$requestId,
        ]);
    }

    private function assertReporter(Principal $actor): void
    {
        Authorization::require($actor,'safeguarding.report',true);
        if (!$actor->hasRole('admin') && !$actor->hasRole('owner') && !$actor->hasRole('super_admin')) {
            throw new DomainException('Only MFA-verified administrators can submit safeguarding reports.');
        }
        $this->assertActorActive($actor,['admin','owner','super_admin']);
    }

    private function assertSpecialist(Principal $actor,string $permission): void
    {
        Authorization::require($actor,$permission,true);
        if (!$actor->hasRole('safeguarding') || count($actor->roles)!==1) {
            throw new DomainException('Safeguarding specialists can access only their assigned safeguarding workspace.');
        }
        $this->assertActorActive($actor,['safeguarding']);
    }

    /** Re-check live role, account and MFA state; a stale browser principal is never sufficient. */
    private function assertActorActive(Principal $actor,array $roles): void
    {
        $allowed=implode(',',array_map(static fn(string $role):string=>"'".str_replace("'","",$role)."'", $roles));
        $query=$this->pdo->prepare(
            'SELECT 1 FROM users u
             JOIN user_roles r ON r.user_id=u.id AND r.role_key IN ('.$allowed.')
             JOIN admin_mfa_credentials mfa ON mfa.user_id=u.id AND mfa.enabled_at IS NOT NULL
             WHERE u.id=:id AND u.status="active" AND u.email_verified_at IS NOT NULL LIMIT 1'
        );
        $query->execute(['id'=>$actor->id]);
        if ($query->fetchColumn()===false) {
            throw new DomainException('This safeguarding action requires an active, verified account with current MFA enrollment and role authorization.');
        }
    }

    private static function uuid(): string
    {
        $bytes=random_bytes(16);
        $bytes[6]=chr((ord($bytes[6])&0x0f)|0x40);
        $bytes[8]=chr((ord($bytes[8])&0x3f)|0x80);
        $hex=bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }
}
