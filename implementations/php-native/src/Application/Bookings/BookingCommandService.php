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
                'SELECT b.id,b.client_user_id,b.profile_id,b.status,b.booking_mode,b.quote_snapshot_id,b.deposit_status,b.starts_at_utc,b.ends_at_utc,
                        p.user_id AS companion_user_id,p.status AS profile_status,p.adult_eligibility_status,p.adult_verified_at,
                        p.publication_consent_version,p.publication_consent_withdrawn_at,
                        d.country_code,d.time_zone,d.status AS destination_status,c.enabled AS country_enabled,
                        m.enabled AS market_enabled,m.decision AS market_decision,m.reviewer_id AS market_reviewer_id,
                        m.reviewed_at AS market_reviewed_at,m.expires_at AS market_expires_at,
                        m.evidence_reference AS market_evidence,m.review_summary AS market_summary
                 FROM bookings b JOIN companion_profiles p ON p.id = b.profile_id
                 JOIN destinations d ON d.id = p.destination_id JOIN countries c ON c.alpha2 = d.country_code
                 LEFT JOIN market_policies m ON m.country_code = d.country_code
                 WHERE b.id = :id FOR UPDATE'
            );
            $query->execute(['id'=>$bookingId]);
            $booking = $query->fetch();
            if (!is_array($booking) || !in_array($booking['status'],['accepted','confirmed'],true)) {
                throw new \DomainException('Only accepted or confirmed bookings can be rescheduled.');
            }
            $this->assertBookingOwnership($actor,$actorRole,$booking);
            if ($booking['profile_status'] !== 'published' || $booking['adult_eligibility_status'] !== 'verified' ||
                $booking['adult_verified_at'] === null || $booking['publication_consent_version'] === null ||
                $booking['publication_consent_withdrawn_at'] !== null || $booking['destination_status'] !== 'published' ||
                !(bool) $booking['country_enabled'] || $booking['market_decision'] === null) {
                throw new \\DomainException('Profile safeguarding, consent or market eligibility no longer permits rescheduling.');
            }
            if ($actorRole === 'client' && $booking['booking_mode'] === 'automatic') {
                throw new \\DomainException('Automatic bookings require administrator review before rescheduling.');
            }
            $market = new MarketPolicy(
                (string) $booking['country_code'],
                (bool) $booking['market_enabled'],
                (string) $booking['market_decision'],
                $booking['market_reviewer_id'] === null ? null : (string) $booking['market_reviewer_id'],
                $booking['market_reviewed_at'] === null ? null : new DateTimeImmutable((string) $booking['market_reviewed_at'],$utc),
                $booking['market_expires_at'] === null ? null : new DateTimeImmutable((string) $booking['market_expires_at'],$utc),
                $booking['market_evidence'] === null ? null : (string) $booking['market_evidence'],
                $booking['market_summary'] === null ? null : (string) $booking['market_summary']
            );
            if (!$market->operationalAt($now)) {
                throw new \\DomainException('Market approval is no longer current; rescheduling is blocked.');
            }
            $marketReviewer = $this->pdo->prepare('SELECT 1 FROM user_roles ur JOIN admin_mfa_credentials mfa ON mfa.user_id = ur.user_id WHERE ur.user_id = :reviewer AND ur.role_key IN ("owner","admin","super_admin") AND mfa.enabled_at IS NOT NULL LIMIT 1');
            $marketReviewer->execute(['reviewer'=>$market->reviewerId]);
            if ($marketReviewer->fetchColumn() === false) {
                throw new \\DomainException('Market approval is not backed by an MFA-protected compliance administrator.');
            }
            $consentQuery = $this->pdo->prepare('SELECT subject_id,purpose,audience,policy_version,granted,granted_at,withdrawn_at FROM consent_grants WHERE subject_type = "companion_profile" AND subject_id = :profile AND purpose = "profile.publication" AND audience = "public" ORDER BY granted_at DESC LIMIT 1 FOR UPDATE');
            $consentQuery->execute(['profile'=>$booking['profile_id']]);
            $consentRow = $consentQuery->fetch();
            $consent = is_array($consentRow) ? new ConsentGrant(
                (string) $consentRow['subject_id'],
                (string) $consentRow['purpose'],
                (string) $consentRow['audience'],
                (string) $consentRow['policy_version'],
                (bool) $consentRow['granted'],
                new DateTimeImmutable((string) $consentRow['granted_at'],$utc),
                $consentRow['withdrawn_at'] === null ? null : new DateTimeImmutable((string) $consentRow['withdrawn_at'],$utc)
            ) : null;
            if ($consent === null || !$consent->validFor((string) $booking['profile_id'],'profile.publication','public',(string) $booking['publication_consent_version'],$now)) {
                throw new \\DomainException('Current publication consent is not valid for rescheduling.');
            }
            $oldDuration = (int) floor((strtotime((string) $booking['ends_at_utc'] . ' UTC') - strtotime((string) $booking['starts_at_utc'] . ' UTC')) / 60);
            if ($duration !== $oldDuration) {
                throw new \\DomainException('Changing booking duration requires a new quote and a separate booking request.');
            }
            if ($booking['quote_snapshot_id'] !== null) {
                $quote = $this->pdo->prepare('SELECT expires_at,accepted_at FROM quote_snapshots WHERE id = :id FOR UPDATE');
                $quote->execute(['id'=>$booking['quote_snapshot_id']]);
                $quoteRow = $quote->fetch();
                if (!is_array($quoteRow) || $quoteRow['accepted_at'] === null || new DateTimeImmutable((string) $quoteRow['expires_at'],$utc) <= $now) {
                    throw new \\DomainException('Booking quote is no longer valid for rescheduling.');
                }
            }
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
