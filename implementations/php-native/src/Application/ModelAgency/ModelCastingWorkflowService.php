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
            $this->event($callId,null,$actor->id,'administrator','casting.call_closed',$reason);
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
            )->execute(['actor'=>$actor->id,'reason'=>$reason,'id'=>$callId]);
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
        return $query->fetchAll(PDO::FETCH_ASSOC);
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
        $sql='SELECT c.enabled,m.enabled AS market_enabled,m.decision,m.expires_at
              FROM countries c LEFT JOIN market_policies m ON m.country_code=c.alpha2
              WHERE c.alpha2=:country LIMIT 1'.($lock?' FOR UPDATE':'');
        $query=$this->pdo->prepare($sql);$query->execute(['country'=>$countryCode]);$row=$query->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row) || (int)$row['enabled']!==1 || (int)($row['market_enabled']??0)!==1 ||
            $row['decision']!=='approved' || $row['expires_at']===null ||
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
        )->execute(['id'=>$id,'call'=>$callId,'submission'=>$submissionId,'actor'=>$actorId,'actor_type'=>$actorType,'event'=>$type,'reason'=>$reason]);
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
