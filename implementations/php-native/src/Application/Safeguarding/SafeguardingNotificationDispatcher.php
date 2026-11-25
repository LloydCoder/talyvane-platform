<?php
declare(strict_types=1);

namespace Talyvane\Application\Safeguarding;

use PDO;
use RuntimeException;
use Throwable;
use Talyvane\Infrastructure\Mail\Mailer;
use Talyvane\Security\SecretBox;

/** At-least-once delivery with encrypted payloads, atomic claims and bounded retry. */
final class SafeguardingNotificationDispatcher
{
    public function __construct(private PDO $pdo,private SecretBox $box,private Mailer $mailer) {}

    /** @return array{claimed:int,delivered:int,retried:int,dead_lettered:int} */
    public function dispatchBatch(int $limit=25): array
    {
        if ($limit<1 || $limit>100) {
            throw new \InvalidArgumentException('Notification batch size must be between 1 and 100.');
        }
        $this->pdo->beginTransaction();
        try {
            $query=$this->pdo->prepare(
                'SELECT id,case_id,event_key,recipient_ciphertext,payload_ciphertext,attempts
                 FROM safeguarding_notification_outbox
                 WHERE ((status="pending" AND available_at<=UTC_TIMESTAMP())
                    OR (status="processing" AND claimed_at<UTC_TIMESTAMP()-INTERVAL 10 MINUTE))
                   AND attempts<8
                 ORDER BY available_at ASC,created_at ASC LIMIT :limit FOR UPDATE'
            );
            $query->bindValue(':limit',$limit,PDO::PARAM_INT);
            $query->execute();
            $rows=$query->fetchAll(PDO::FETCH_ASSOC);
            $claimed=[];
            foreach ($rows as $row) {
                $token=self::uuid();
                $update=$this->pdo->prepare(
                    'UPDATE safeguarding_notification_outbox
                     SET status="processing",claim_token=:token,claimed_at=UTC_TIMESTAMP(),
                         attempts=attempts+1,last_attempted_at=UTC_TIMESTAMP()
                     WHERE id=:id AND attempts<8'
                );
                $update->execute(['token'=>$token,'id'=>$row['id']]);
                if ($update->rowCount()===1) {
                    $row['claim_token']=$token;
                    $row['attempts']=(int)$row['attempts']+1;
                    $claimed[]=$row;
                }
            }
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $error;
        }

        $result=['claimed'=>count($claimed),'delivered'=>0,'retried'=>0,'dead_lettered'=>0];
        foreach ($claimed as $row) {
            try {
                $id=(string)$row['id'];
                $recipient=$this->box->decrypt((string)$row['recipient_ciphertext'],'safeguarding-outbox-v1:recipient:'.$id);
                $payloadJson=$this->box->decrypt((string)$row['payload_ciphertext'],'safeguarding-outbox-v1:payload:'.$id);
                $payload=json_decode($payloadJson,true,16,JSON_THROW_ON_ERROR);
                if (!filter_var($recipient,FILTER_VALIDATE_EMAIL) || !is_array($payload) ||
                    !is_string($payload['case_reference']??null) || !is_string($payload['category']??null) ||
                    !is_string($payload['severity']??null) || ($row['event_key']??'')!=='case.created') {
                    throw new RuntimeException('Safeguarding notification envelope failed validation.');
                }
                $body="A confidential safeguarding case has been assigned to your specialist queue.\n\n".
                    "Reference: ".$payload['case_reference']."\n".
                    "Category: ".$payload['category']."\n".
                    "Severity: ".$payload['severity']."\n\n".
                    "Sign in to Talyvane and open your assigned safeguarding cases. Do not forward this notification.";
                $this->mailer->send($recipient,'Confidential safeguarding case assigned',$body);
                $done=$this->pdo->prepare(
                    'UPDATE safeguarding_notification_outbox
                     SET status="delivered",delivered_at=UTC_TIMESTAMP(),claim_token=NULL,claimed_at=NULL,last_error_code=NULL
                     WHERE id=:id AND status="processing" AND claim_token=:token'
                );
                $done->execute(['id'=>$id,'token'=>$row['claim_token']]);
                if ($done->rowCount()===1) { $result['delivered']++; }
            } catch (Throwable $error) {
                $attempts=(int)$row['attempts'];
                $dead=$attempts>=8;
                $delay=min(21600,60*(2**min(8,$attempts-1)));
                $update=$this->pdo->prepare(
                    'UPDATE safeguarding_notification_outbox
                     SET status=:status,available_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL :delay SECOND),
                         claim_token=NULL,claimed_at=NULL,last_error_code=:error
                     WHERE id=:id AND status="processing" AND claim_token=:token'
                );
                $update->execute([
                    'status'=>$dead?'failed':'pending','delay'=>$delay,
                    'error'=>substr($error::class,0,80),'id'=>$row['id'],'token'=>$row['claim_token'],
                ]);
                if ($update->rowCount()===1) {
                    if ($dead) { $result['dead_lettered']++; } else { $result['retried']++; }
                }
            }
        }
        return $result;
    }

    private static function uuid(): string
    {
        $bytes=random_bytes(16);$bytes[6]=chr((ord($bytes[6])&15)|64);$bytes[8]=chr((ord($bytes[8])&63)|128);$hex=bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }
}
