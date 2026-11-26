<?php
declare(strict_types=1);

namespace Talyvane\Application\ModelAgency;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;
use Talyvane\Infrastructure\Audit\AuditLogger;

/** Applicant-controlled unavailability blocks; all schedule instants are stored in UTC. */
final class ModelAvailabilityService
{
    public function __construct(private PDO $pdo,private AuditLogger $audit) {}

    /** @return list<array<string,mixed>> */
    public function listForApplicant(string $applicationId): array
    {
        $this->assertUuid($applicationId);
        $q=$this->pdo->prepare(
            'SELECT id,starts_at,ends_at,reason_code,status,created_at,cancelled_at
             FROM model_availability_blocks WHERE application_id=:application
             ORDER BY starts_at DESC LIMIT 100'
        );
        $q->execute(['application'=>$applicationId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return string new or replayed block UUID
     * Inputs must be RFC 3339 timestamps with an explicit UTC offset.
     */
    public function createByApplicant(string $applicationId,string $startsAt,string $endsAt,string $reasonCode,string $requestId): string
    {
        $this->assertUuid($applicationId);
        $this->assertRequestId($requestId);
        if(!in_array($reasonCode,['personal','travel','other'],true)) {
            throw new \InvalidArgumentException('Choose a valid unavailability reason.');
        }
        $start=$this->normalizeUtc($startsAt);
        $end=$this->normalizeUtc($endsAt);
        $startTime=new DateTimeImmutable($start,new DateTimeZone('UTC'));
        $endTime=new DateTimeImmutable($end,new DateTimeZone('UTC'));
        $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
        if($startTime<=$now || $endTime<=$startTime || $endTime->getTimestamp()-$startTime->getTimestamp()>90*86400) {
            throw new \InvalidArgumentException('Unavailability must be a future interval of at most 90 days.');
        }
        $fingerprint=hash('sha256',$requestId);
        $termsHash=hash('sha256',json_encode([
            'application_id'=>$applicationId,'starts_at'=>$start,'ends_at'=>$end,'reason_code'=>$reasonCode,
        ],JSON_THROW_ON_ERROR));
        $this->pdo->beginTransaction();
        try {
            $replay=$this->pdo->prepare('SELECT id,application_id,terms_hash FROM model_availability_blocks WHERE request_fingerprint=:fingerprint LIMIT 1 FOR UPDATE');
            $replay->execute(['fingerprint'=>$fingerprint]);
            $existing=$replay->fetch(PDO::FETCH_ASSOC);
            if(is_array($existing)) {
                if(!hash_equals((string)$existing['application_id'],$applicationId) || !hash_equals((string)$existing['terms_hash'],$termsHash)) {
                    throw new \DomainException('This request identifier was already used for different availability terms.');
                }
                $this->pdo->commit();
                return (string)$existing['id'];
            }
            $app=$this->lockApplication($applicationId);
            if($app['status']!=='accepted' || (int)$app['legal_hold']===1 || $app['anonymized_at']!==null) {
                throw new \DomainException('Only an accepted model application without a legal hold can add unavailability.');
            }
            $conflict=$this->pdo->prepare(
                'SELECT id FROM model_assignments WHERE application_id=:application
                 AND status IN ("offered","accepted","confirmed","in_progress")
                 AND shoot_starts_at<:ends_at AND shoot_ends_at>:starts_at LIMIT 1 FOR UPDATE'
            );
            $conflict->execute(['application'=>$applicationId,'ends_at'=>$end,'starts_at'=>$start]);
            if($conflict->fetchColumn()!==false) {
                throw new \DomainException('This interval overlaps an active assignment. Resolve the assignment before adding unavailability.');
            }
            $overlap=$this->pdo->prepare(
                'SELECT id FROM model_availability_blocks WHERE application_id=:application AND status="active"
                 AND starts_at<:ends_at AND ends_at>:starts_at LIMIT 1 FOR UPDATE'
            );
            $overlap->execute(['application'=>$applicationId,'ends_at'=>$end,'starts_at'=>$start]);
            if($overlap->fetchColumn()!==false) throw new \DomainException('This interval overlaps an existing unavailability block.');
            $id=self::uuid();
            $this->pdo->prepare(
                'INSERT INTO model_availability_blocks
                 (id,application_id,starts_at,ends_at,reason_code,status,request_fingerprint,terms_hash,created_at)
                 VALUES (:id,:application,:starts_at,:ends_at,:reason,"active",:fingerprint,:terms_hash,UTC_TIMESTAMP())'
            )->execute([
                'id'=>$id,'application'=>$applicationId,'starts_at'=>$start,'ends_at'=>$end,
                'reason'=>$reasonCode,'fingerprint'=>$fingerprint,'terms_hash'=>$termsHash,
            ]);
            $this->recordEvent($applicationId,'model.availability_block_created','Applicant recorded an unavailability interval.',$app['status']);
            $this->audit->record(null,'model_availability.block_created','model_availability_block',$id,
                'Applicant recorded a future unavailability interval',null,
                ['application_id'=>$applicationId,'starts_at'=>$start,'ends_at'=>$end,'reason_code'=>$reasonCode],
                $requestId,'model-availability-v1');
            $this->pdo->commit();
            return $id;
        } catch(Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function cancelByApplicant(string $applicationId,string $blockId,string $requestId): void
    {
        $this->assertUuid($applicationId);
        $this->assertUuid($blockId);
        $this->assertRequestId($requestId);
        $this->pdo->beginTransaction();
        try {
            $q=$this->pdo->prepare('SELECT * FROM model_availability_blocks WHERE id=:id LIMIT 1 FOR UPDATE');
            $q->execute(['id'=>$blockId]);$block=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($block) || !hash_equals((string)$block['application_id'],$applicationId)) {
                throw new \DomainException('This unavailability block does not belong to the verified model workspace.');
            }
            if($block['status']==='cancelled') {
                $this->pdo->commit();
                return;
            }
            if($block['status']!=='active') throw new \DomainException('This unavailability block cannot be cancelled.');
            $app=$this->lockApplication($applicationId);
            $this->pdo->prepare('UPDATE model_availability_blocks SET status="cancelled",cancelled_at=UTC_TIMESTAMP() WHERE id=:id AND status="active"')
                ->execute(['id'=>$blockId]);
            $this->recordEvent($applicationId,'model.availability_block_cancelled','Applicant cancelled an unavailability interval.',$app['status']);
            $this->audit->record(null,'model_availability.block_cancelled','model_availability_block',$blockId,
                'Applicant cancelled a future unavailability interval',['status'=>'active'],['status'=>'cancelled'],
                $requestId,'model-availability-v1');
            $this->pdo->commit();
        } catch(Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    private function lockApplication(string $applicationId): array
    {
        $q=$this->pdo->prepare('SELECT id,status,legal_hold,anonymized_at FROM model_talent_applications WHERE id=:id LIMIT 1 FOR UPDATE');
        $q->execute(['id'=>$applicationId]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row)) throw new \DomainException('Model application is unavailable.');
        return $row;
    }

    private function normalizeUtc(string $value): string
    {
        $pattern='/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?(?:Z|[+-](?:(?:0\d|1[0-3]):[0-5]\d|14:00))$/D';
        if(preg_match($pattern,$value)!==1) {
            throw new \InvalidArgumentException('Use an ISO 8601 date/time with an explicit UTC offset, such as 2026-12-01T09:00:00+01:00.');
        }
        try {$parsed=new DateTimeImmutable($value);}
        catch(Throwable) {throw new \InvalidArgumentException('The unavailability date/time is invalid.');}
        $errors=DateTimeImmutable::getLastErrors();
        if(is_array($errors) && ($errors['warning_count']>0 || $errors['error_count']>0)) {
            throw new \InvalidArgumentException('The unavailability date/time is invalid.');
        }
        return $parsed->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function recordEvent(string $applicationId,string $event,string $reason,string $status): void
    {
        $this->pdo->prepare(
            'INSERT INTO model_talent_application_events
             (id,application_id,actor_user_id,actor_type,from_status,to_status,reason,policy_version,created_at)
             VALUES (:id,:application,NULL,"applicant",:status,:status,:reason,"model-availability-v1",UTC_TIMESTAMP())'
        )->execute(['id'=>self::uuid(),'application'=>$applicationId,'status'=>$status,'reason'=>substr($event.': '.$reason,0,500)]);
    }

    private function assertUuid(string $value): void
    {
        if(!preg_match('/^[0-9a-f-]{36}$/iD',$value)) throw new \InvalidArgumentException('Model availability identifier is invalid.');
    }

    private function assertRequestId(string $value): void
    {
        if(strlen($value)<16 || strlen($value)>64 || preg_match('/^[A-Za-z0-9_-]+$/D',$value)!==1) {
            throw new \InvalidArgumentException('Request identifier is invalid.');
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