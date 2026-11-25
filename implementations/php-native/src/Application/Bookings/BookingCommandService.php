<?php
declare(strict_types=1);

namespace Talyvane\Application\Bookings;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Talyvane\Domain\Booking\BookingStateMachine;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Infrastructure\Database\BookingPolicyRepository;
use Talyvane\Security\Authorization;
use Talyvane\Security\Principal;

final class BookingCommandService
{
    public function __construct(
        private PDO $pdo,
        private BookingPolicyRepository $policies,
        private AuditLogger $audit
    ) {}

    public function transition(Principal $actor, string $bookingId, string $toState, string $reason, string $requestId): string
    {
        $actorRole = $this->actorRole($actor);
        $this->authorizeActor($actor,$actorRole);
        if (in_array($toState,['cancelled','declined','disputed','no_show'],true) &&
            (strlen(trim($reason)) < 10 || strlen($reason) > 500)) {
            throw new \InvalidArgumentException('A concise reason is required for this booking action.');
        }
        $this->pdo->beginTransaction();
        try {
            $query = $this->pdo->prepare(
                'SELECT b.id,b.client_user_id,b.profile_id,b.status,b.deposit_status,p.user_id AS companion_user_id
                 FROM bookings b JOIN companion_profiles p ON p.id = b.profile_id
                 WHERE b.id = :id FOR UPDATE'
            );
            $query->execute(['id'=>$bookingId]);
            $booking = $query->fetch();
            if (!is_array($booking)) {
                throw new \DomainException('Booking does not exist.');
            }
            $this->assertBookingOwnership($actor,$actorRole,$booking);
            $fromState = (string) $booking['status'];
            $newState = BookingStateMachine::transition($fromState,$toState,$actorRole);
            if ($toState === 'confirmed' && !in_array($booking['deposit_status'],['paid','not_required'],true)) {
                throw new \DomainException('Booking cannot be confirmed until the required deposit is verified.');
            }
            $update = $this->pdo->prepare('UPDATE bookings SET status = :status,updated_at = UTC_TIMESTAMP() WHERE id = :id AND status = :from_state');
            $update->execute(['status'=>$newState,'id'=>$bookingId,'from_state'=>$fromState]);
            if ($update->rowCount() !== 1) {
                throw new \DomainException('Booking state changed concurrently; reload and retry.');
            }
            $this->recordEvent($bookingId,$actor->id,$actorRole,$fromState,$newState,trim($reason),$requestId);
            $this->audit->record($actor->id,'booking.state.changed','booking',$bookingId,trim($reason),['status'=>$fromState],['status'=>$newState],$requestId,'booking-lifecycle-v1');
            $this->pdo->commit();
            return $newState;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function reschedule(
        Principal $actor,
        string $bookingId,
        DateTimeImmutable $newStart,
        DateTimeImmutable $newEnd,
        string $reason,
        string $requestId
    ): void {
        $actorRole = $this->actorRole($actor);
        if (!in_array($actorRole,['client','admin','owner','super_admin'],true)) {
            throw new \DomainException('Only the booking client or an authorized administrator can reschedule.');
        }
        if ($actorRole === 'client') {
            Authorization::require($actor,'client.booking.manage',false);
        } else {
            Authorization::require($actor,'bookings.manage',true);
        }
        if (strlen(trim($reason)) < 10 || strlen($reason) > 500) {
            throw new \InvalidArgumentException('A reason is required for rescheduling.');
        }
        $policy = $this->policies->current();
        $utc = new DateTimeZone('UTC');
        $start = $newStart->setTimezone($utc);
        $end = $newEnd->setTimezone($utc);
        $now = new DateTimeImmutable('now',$utc);
        $duration = (int) floor(($end->getTimestamp() - $start->getTimestamp()) / 60);
        if ($start <= $now->modify('+' . $policy->minimumNoticeMinutes . ' minutes') ||
            $start > $now->modify('+' . $policy->maximumAdvanceDays . ' days') ||
            $end <= $start || $duration < $policy->minimumDurationMinutes ||
            $duration > $policy->maximumDurationMinutes) {
            throw new \DomainException('New booking time does not satisfy the current booking policy.');
        }

        $this->pdo->beginTransaction();
        try {
            $query = $this->pdo->prepare(
                'SELECT b.id,b.client_user_id,b.profile_id,b.status,b.starts_at_utc,b.ends_at_utc,p.user_id AS companion_user_id
                 FROM bookings b JOIN companion_profiles p ON p.id = b.profile_id
                 WHERE b.id = :id FOR UPDATE'
            );
            $query->execute(['id'=>$bookingId]);
            $booking = $query->fetch();
            if (!is_array($booking) || !in_array($booking['status'],['accepted','confirmed'],true)) {
                throw new \DomainException('Only accepted or confirmed bookings can be rescheduled.');
            }
            $this->assertBookingOwnership($actor,$actorRole,$booking);
            $overlap = $this->pdo->prepare(
                'SELECT id FROM bookings WHERE profile_id = :profile AND id <> :booking
                 AND status IN ("requested","pending_review","awaiting_automatic_rule","accepted","confirmed")
                 AND starts_at_utc < :end_time AND ends_at_utc > :start_time
                 LIMIT 1 FOR UPDATE'
            );
            $overlap->execute([
                'profile'=>$booking['profile_id'],
                'booking'=>$bookingId,
                'end_time'=>$end->format('Y-m-d H:i:s'),
                'start_time'=>$start->format('Y-m-d H:i:s'),
            ]);
            if ($overlap->fetchColumn() !== false) {
                throw new \DomainException('New booking time overlaps an existing booking or pending request.');
            }
            $update = $this->pdo->prepare('UPDATE bookings SET starts_at_utc = :start_time,ends_at_utc = :end_time,updated_at = UTC_TIMESTAMP() WHERE id = :id');
            $update->execute([
                'start_time'=>$start->format('Y-m-d H:i:s'),
                'end_time'=>$end->format('Y-m-d H:i:s'),
                'id'=>$bookingId,
            ]);
            $this->recordEvent($bookingId,$actor->id,$actorRole,(string) $booking['status'],(string) $booking['status'],'Rescheduled: ' . trim($reason),$requestId);
            $this->audit->record($actor->id,'booking.rescheduled','booking',$bookingId,trim($reason),[
                'starts_at_utc'=>$booking['starts_at_utc'],
                'ends_at_utc'=>$booking['ends_at_utc'],
            ],[
                'starts_at_utc'=>$start->format('Y-m-d H:i:s'),
                'ends_at_utc'=>$end->format('Y-m-d H:i:s'),
            ],$requestId,'booking-lifecycle-v1');
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function actorRole(Principal $actor): string
    {
        foreach (['super_admin','owner','admin','companion','client'] as $role) {
            if ($actor->hasRole($role)) {
                return $role;
            }
        }
        throw new \DomainException('Actor has no booking permissions.');
    }

    /** @param array<string,mixed> $booking */
    private function authorizeActor(Principal $actor, string $role): void
    {
        if (in_array($role,['super_admin','owner','admin'],true)) {
            Authorization::require($actor,'bookings.manage',true);
        } elseif ($role === 'companion') {
            Authorization::require($actor,'companion.booking.decide',false);
        } else {
            Authorization::require($actor,'client.booking.manage',false);
        }
    }

    /** @param array<string,mixed> $booking */
    private function assertBookingOwnership(Principal $actor, string $role, array $booking): void
    {
        if ($role === 'client' && (string) $booking['client_user_id'] !== $actor->id) {
            throw new \DomainException('Client cannot access another client booking.');
        }
        if ($role === 'companion' && (string) $booking['companion_user_id'] !== $actor->id) {
            throw new \DomainException('Companion cannot access another companion booking.');
        }
    }

    private function recordEvent(string $bookingId, string $actorId, string $role, string $from, string $to, string $reason, string $requestId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO booking_events (id,booking_id,actor_user_id,actor_role,from_state,to_state,reason,policy_version,occurred_at)
             VALUES (:id,:booking,:actor,:role,:from_state,:to_state,:reason,:policy,UTC_TIMESTAMP())'
        );
        $statement->execute([
            'id'=>self::uuid(),
            'booking'=>$bookingId,
            'actor'=>$actorId,
            'role'=>$role,
            'from_state'=>$from,
            'to_state'=>$to,
            'reason'=>$reason,
            'policy'=>'booking-lifecycle-v1',
        ]);
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }
}
