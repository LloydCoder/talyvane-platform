<?php
declare(strict_types=1);

namespace Talyvane\Application\Bookings;

use PDO;
use Talyvane\Security\SecretBox;

/** Adds encrypted, idempotent booking notifications to the transactional communications outbox. */
final class BookingNotificationOutbox
{
    public function __construct(private PDO $pdo,private SecretBox $box,private string $fingerprintKey)
    {
        if (strlen($fingerprintKey)<32) {
            throw new \InvalidArgumentException('Booking notification fingerprint key must contain at least 256 bits.');
        }
    }

    public function enqueue(string $bookingId,string $recipientUserId,string $eventKey): void
    {
        $allowed=['booking.requested','booking.confirmed','booking.accepted','booking.declined','booking.cancelled','booking.rescheduled'];
        if (!in_array($eventKey,$allowed,true)) {
            throw new \InvalidArgumentException('Unsupported booking notification event.');
        }
        $query=$this->pdo->prepare(
            'SELECT u.email,u.status,u.email_verified_at,b.booking_reference,b.starts_at_utc,b.time_zone,
                    d.display_name AS destination_name
             FROM users u
             JOIN bookings b ON b.id=:booking
             JOIN destinations d ON d.id=b.destination_id
             WHERE u.id=:recipient LIMIT 1'
        );
        $query->execute(['booking'=>$bookingId,'recipient'=>$recipientUserId]);
        $row=$query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || $row['status']!=='active' || $row['email_verified_at']===null ||
            !is_string($row['email']) || !filter_var($row['email'],FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Booking notification recipient is unavailable or unverified.');
        }
        $id=self::uuid();
        $recipient=(string)$row['email'];
        $payload=json_encode([
            'event_key'=>$eventKey,
            'booking_reference'=>(string)$row['booking_reference'],
            'starts_at_utc'=>(string)$row['starts_at_utc'],
            'time_zone'=>(string)$row['time_zone'],
            'destination'=>(string)$row['destination_name'],
        ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        $statement=$this->pdo->prepare(
            'INSERT INTO communication_outbox
             (id,idempotency_key,related_type,related_id,channel,recipient_ciphertext,recipient_fingerprint,
              payload_ciphertext,status,attempts,available_at,created_at)
             VALUES (:id,:dedupe,"booking",:booking,"email",:recipient,:fingerprint,:payload,
                     "pending",0,UTC_TIMESTAMP(),UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE id=id'
        );
        $statement->execute([
            'id'=>$id,
            'dedupe'=>'booking-notification-v1:'.$bookingId.':'.$eventKey.':'.$recipientUserId,
            'booking'=>$bookingId,
            'recipient'=>$this->box->encrypt($recipient,'communication-outbox-v1:recipient:'.$id),
            'fingerprint'=>hash_hmac('sha256',strtolower($recipient),$this->fingerprintKey),
            'payload'=>$this->box->encrypt($payload,'communication-outbox-v1:payload:'.$id),
        ]);
    }

    private static function uuid(): string
    {
        $bytes=random_bytes(16);$bytes[6]=chr((ord($bytes[6])&15)|64);$bytes[8]=chr((ord($bytes[8])&63)|128);$hex=bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }
}
