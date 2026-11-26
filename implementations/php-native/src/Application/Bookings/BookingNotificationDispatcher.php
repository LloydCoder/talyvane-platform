<?php
declare(strict_types=1);

namespace Talyvane\Application\Bookings;

use PDO;
use Throwable;
use Talyvane\Infrastructure\Mail\Mailer;
use Talyvane\Security\SecretBox;

/** At-least-once SMTP delivery with atomic claims, deduplication and bounded exponential retry. */
final class BookingNotificationDispatcher
{
    public function __construct(private PDO $pdo,private SecretBox $box,private Mailer $mailer) {}

    /** @return array{claimed:int,delivered:int,retried:int,dead_lettered:int} */
    public function dispatchBatch(int $limit=25): array
    {
        if ($limit<1 || $limit>100) {
            throw new \InvalidArgumentException('Notification batch size must be between 1 and 100.');
        }
        $stale=$this->pdo->prepare('UPDATE communication_outbox SET status="failed",claim_token=NULL,claimed_at=NULL,last_error_code="retry_limit_after_stale_claim" WHERE related_type="booking" AND status="processing" AND attempts>=8 AND claimed_at<UTC_TIMESTAMP()-INTERVAL 10 MINUTE');
        $stale->execute();
        $this->pdo->beginTransaction();
        try {
            $query=$this->pdo->prepare(
                'SELECT id,related_id,recipient_ciphertext,payload_ciphertext,attempts
                 FROM communication_outbox
                 WHERE related_type="booking" AND channel="email"
                   AND (((status="pending" OR status="failed") AND available_at<=UTC_TIMESTAMP())
                     OR (status="processing" AND claimed_at<UTC_TIMESTAMP()-INTERVAL 10 MINUTE))
                   AND attempts<8
                 ORDER BY available_at ASC,created_at ASC
                 LIMIT :limit FOR UPDATE'
            );
            $query->bindValue(':limit',$limit,PDO::PARAM_INT);
            $query->execute();
            $rows=$query->fetchAll(PDO::FETCH_ASSOC);
            $claimed=[];
            foreach($rows as $row) {
                $token=self::uuid();
                $update=$this->pdo->prepare(
                    'UPDATE communication_outbox
                     SET status="processing",claim_token=:token,claimed_at=UTC_TIMESTAMP(),
                         attempts=attempts+1,last_attempted_at=UTC_TIMESTAMP()
                     WHERE id=:id AND attempts<8'
                );
                $update->execute(['token'=>$token,'id'=>$row['id']]);
                if($update->rowCount()===1) {
                    $row['claim_token']=$token;
                    $row['attempts']=(int)$row['attempts']+1;
                    $claimed[]=$row;
                }
            }
            $this->pdo->commit();
        } catch(Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }

        $result=['claimed'=>count($claimed),'delivered'=>0,'retried'=>0,'dead_lettered'=>0];
        foreach($claimed as $row) {
            $id=(string)$row['id'];
            $attemptId=self::uuid();
            try {
                $recipient=$this->box->decrypt((string)$row['recipient_ciphertext'],'communication-outbox-v1:recipient:'.$id);
                $payload=json_decode($this->box->decrypt((string)$row['payload_ciphertext'],'communication-outbox-v1:payload:'.$id),true,16,JSON_THROW_ON_ERROR);
                if(!filter_var($recipient,FILTER_VALIDATE_EMAIL) || !is_array($payload)) {
                    throw new \RuntimeException('Booking notification envelope is invalid.');
                }
                [$subject,$body]=self::render($payload);
                $this->mailer->send($recipient,$subject,$body);
                $this->pdo->beginTransaction();
                try {
                    $done=$this->pdo->prepare(
                        'UPDATE communication_outbox
                         SET status="delivered",delivered_at=UTC_TIMESTAMP(),claim_token=NULL,claimed_at=NULL,last_error_code=NULL
                         WHERE id=:id AND status="processing" AND claim_token=:token'
                    );
                    $done->execute(['id'=>$id,'token'=>$row['claim_token']]);
                    $wasDelivered=$done->rowCount()===1;
                    if($wasDelivered) {
                        $this->attempt($attemptId,$id,'delivered',null);
                    }
                    $this->pdo->commit();
                    if($wasDelivered) {
                        $result['delivered']++;
                    }
                } catch(Throwable $error) {
                    if($this->pdo->inTransaction()) $this->pdo->rollBack();
                    throw $error;
                }
            } catch(Throwable $error) {
                $attempts=(int)$row['attempts'];
                $dead=$attempts>=8;
                $delay=min(21600,60*(2**min(8,$attempts-1)));
                $this->pdo->beginTransaction();
                try {
                    $update=$this->pdo->prepare(
                        'UPDATE communication_outbox
                         SET status=:status,available_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL :delay SECOND),
                             claim_token=NULL,claimed_at=NULL,last_error_code=:error
                         WHERE id=:id AND status="processing" AND claim_token=:token'
                    );
                    $update->execute([
                        'status'=>$dead?'failed':'pending','delay'=>$delay,
                        'error'=>substr($error::class,0,80),'id'=>$id,'token'=>$row['claim_token'],
                    ]);
                    if($update->rowCount()===1) {
                        $this->attempt($attemptId,$id,$dead?'failed':'retry_scheduled',substr($error::class,0,80));
                        if($dead) $result['dead_lettered']++; else $result['retried']++;
                    }
                    $this->pdo->commit();
                } catch(Throwable $storageError) {
                    if($this->pdo->inTransaction()) $this->pdo->rollBack();
                    throw $storageError;
                }
            }
        }
        return $result;
    }

    /** @param array<string,mixed> $payload @return array{string,string} */
    private static function render(array $payload): array
    {
        $event=(string)($payload['event_key']??'');
        $reference=(string)($payload['booking_reference']??'');
        $destination=(string)($payload['destination']??'');
        $start=(string)($payload['starts_at_utc']??'');
        $zone=(string)($payload['time_zone']??'UTC');
        $labels=[
            'booking.requested'=>['New booking request','A new booking request has been submitted.'],
            'booking.confirmed'=>['Booking confirmed','Your booking has been confirmed.'],
            'booking.accepted'=>['Booking accepted','The companion has accepted your booking.'],
            'booking.declined'=>['Booking update','The companion has declined your booking request.'],
            'booking.cancelled'=>['Booking cancelled','A booking has been cancelled.'],
            'booking.rescheduled'=>['Booking rescheduled','A booking schedule has changed.'],
        ];
        if(!isset($labels[$event]) || $reference==='' || $destination==='' || $start==='') {
            throw new \RuntimeException('Booking notification payload is incomplete.');
        }
        try {
            $local=(new \DateTimeImmutable($start,new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone($zone))->format('D, d M Y H:i T');
        } catch(Throwable) {
            throw new \RuntimeException('Booking notification time zone is invalid.');
        }
        $body=$labels[$event][1]."\n\n".
            'Booking reference: '.$reference."\n".
            'Destination: '.$destination."\n".
            'Scheduled time: '.$local."\n\n".
            'Sign in to your Talyvane account to review the booking. For your security, do not forward this message.';
        return [$labels[$event][0],$body];
    }

    private function attempt(string $id,string $outboxId,string $status,?string $error): void
    {
        $query=$this->pdo->prepare(
            'INSERT INTO communication_delivery_attempts
             (id,outbox_id,provider_key,status,provider_reference,error_code,attempted_at)
             VALUES (:id,:outbox,"smtp",:status,NULL,:error,UTC_TIMESTAMP())'
        );
        $query->execute(['id'=>$id,'outbox'=>$outboxId,'status'=>$status,'error'=>$error]);
    }

    private static function uuid(): string
    {
        $bytes=random_bytes(16);$bytes[6]=chr((ord($bytes[6])&15)|64);$bytes[8]=chr((ord($bytes[8])&63)|128);$hex=bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }
}
