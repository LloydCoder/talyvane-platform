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
        'safety_concern','exploitation','coercion','trafficking','minor_safety',
        'harassment','violence','wellbeing','other',
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
        private AuditLogger $audit
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
        if (strlen($summary)<10 || strlen($summary)>1000 || strlen($details)<20 || strlen($details)>10000 ||
            strlen($subjectReference)>1000 || strlen($specialistId)>64) {
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

    /** @return array{due_at:string,policy_version:string}|null */
    private function retentionSchedule(string $category): ?array
    {
        if (getenv('SAFEGUARDING_CASE_RETENTION_SCHEDULE_APPROVED')!=='true' ||
            getenv('SAFEGUARDING_CASE_BACKUP_POLICY_APPROVED')!=='true') {
            return null;
        }
        $days=filter_var(getenv('SAFEGUARDING_CASE_RETENTION_DAYS'),FILTER_VALIDATE_INT);
        $backupDays=filter_var(getenv('SAFEGUARDING_CASE_BACKUP_EXPIRY_DAYS'),FILTER_VALIDATE_INT);
        $version=trim((string) getenv('SAFEGUARDING_CASE_RETENTION_POLICY_VERSION'));
        if (!is_int($days) || $days<1 || !is_int($backupDays) || $backupDays<1 || $version==='' || strlen($version)>64) {
            return null;
        }
        $query=$this->pdo->prepare(
            'SELECT retention_days,policy_version,legal_basis_reference
             FROM safeguarding_retention_policies WHERE category=:category AND enabled=1 LIMIT 1'
        );
        $query->execute(['category'=>$category]);
        $policy=$query->fetch();
        if (!is_array($policy) || (int) $policy['retention_days']!==$days ||
            !hash_equals((string) $policy['policy_version'],$version) ||
            trim((string) $policy['legal_basis_reference'])==='') {
            return null;
        }
        $due=(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->modify('+'.$days.' days');
        return ['due_at'=>$due->format('Y-m-d H:i:s'),'policy_version'=>$version];
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
    }

    private function assertSpecialist(Principal $actor,string $permission): void
    {
        Authorization::require($actor,$permission,true);
        if (!$actor->hasRole('safeguarding') || count($actor->roles)!==1) {
            throw new DomainException('Safeguarding specialists can access only their assigned safeguarding workspace.');
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
