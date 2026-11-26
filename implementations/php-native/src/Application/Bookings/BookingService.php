<?php
declare(strict_types=1);

namespace Talyvane\Application\Bookings;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Talyvane\Domain\Booking\BookingStateMachine;
use Talyvane\Domain\Consent\ConsentGrant;
use Talyvane\Domain\Market\MarketPolicy;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Infrastructure\Database\BookingPolicyRepository;
use Talyvane\Features\FeatureAvailability;
use Talyvane\Security\Authorization;
use Talyvane\Security\Principal;

final class BookingService
{
    public function __construct(
        private PDO $pdo,
        private BookingPolicyRepository $policies,
        private AuditLogger $audit,
        private ?BookingNotificationOutbox $notifications = null
    ) {}

    public function request(
        Principal $client,
        string $profileId,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $endsAt,
        ?string $quoteSnapshotId,
        ?string $requestNote,
        string $requestId
    ): string {
        Authorization::require($client,'client.booking.create',false);
        if (!$client->hasRole('client')) {
            throw new \DomainException('Only client accounts can create bookings.');
        }
        if ($requestNote !== null && strlen($requestNote) > 2000) {
            throw new \InvalidArgumentException('Booking note exceeds the permitted length.');
        }
        $policy = $this->policies->current();
        $utc = new DateTimeZone('UTC');
        $start = $startsAt->setTimezone($utc);
        $end = $endsAt->setTimezone($utc);
        $now = new DateTimeImmutable('now',$utc);
        $durationMinutes = (int) floor(($end->getTimestamp() - $start->getTimestamp()) / 60);
        if ($start <= $now->modify('+' . $policy->minimumNoticeMinutes . ' minutes') ||
            $start > $now->modify('+' . $policy->maximumAdvanceDays . ' days') ||
            $end <= $start ||
            $durationMinutes < $policy->minimumDurationMinutes ||
            $durationMinutes > $policy->maximumDurationMinutes) {
            throw new \DomainException('Requested booking time does not satisfy the current booking policy.');
        }

        $bookingId = self::uuid();
        $bookingReference = 'TVN-' . strtoupper(bin2hex(random_bytes(6)));
        $note = $requestNote === null ? null : trim($requestNote);
        $this->pdo->beginTransaction();
        try {
            $profileQuery = $this->pdo->prepare(
                'SELECT p.id,p.user_id AS companion_user_id,p.status,p.adult_eligibility_status,p.adult_verified_at,p.adult_verified_by,
                        p.publication_consent_version,p.publication_consent_withdrawn_at,p.auto_booking_opt_in,
                        d.id AS destination_id,d.country_code,d.time_zone,d.status AS destination_status,
                        c.enabled AS country_enabled,m.enabled AS market_enabled,m.decision AS market_decision,
                        m.reviewer_id AS market_reviewer_id,m.reviewed_at AS market_reviewed_at,
                        m.expires_at AS market_expires_at,m.evidence_reference AS market_evidence,
                        m.review_summary AS market_summary
                 FROM companion_profiles p
                 JOIN destinations d ON d.id = p.destination_id
                 JOIN countries c ON c.alpha2 = d.country_code
                 LEFT JOIN market_policies m ON m.country_code = d.country_code
                 WHERE p.id = :profile FOR UPDATE'
            );
            $profileQuery->execute(['profile'=>$profileId]);
            $profile = $profileQuery->fetch();
            if (!is_array($profile) || $profile['status'] !== 'published' ||
                $profile['adult_eligibility_status'] !== 'verified' || $profile['adult_verified_at'] === null ||
                $profile['adult_verified_by'] === null || $profile['publication_consent_version'] === null ||
                $profile['publication_consent_withdrawn_at'] !== null ||
                $profile['destination_status'] !== 'published' || !(bool) $profile['country_enabled'] ||
                $profile['market_decision'] === null) {
                throw new \DomainException('Profile is not eligible for booking or has incomplete safeguarding/consent controls.');
            }
            $market = new MarketPolicy(
                (string) $profile['country_code'],
                (bool) $profile['market_enabled'],
                (string) $profile['market_decision'],
                $profile['market_reviewer_id'] === null ? null : (string) $profile['market_reviewer_id'],
                $profile['market_reviewed_at'] === null ? null : new DateTimeImmutable((string) $profile['market_reviewed_at'],$utc),
                $profile['market_expires_at'] === null ? null : new DateTimeImmutable((string) $profile['market_expires_at'],$utc),
                $profile['market_evidence'] === null ? null : (string) $profile['market_evidence'],
                $profile['market_summary'] === null ? null : (string) $profile['market_summary']
            );
            if (!$market->operationalAt($now)) {
                throw new \DomainException('Booking is disabled in this market.');
            }
            $reviewer = $this->pdo->prepare('SELECT 1 FROM user_roles ur JOIN admin_mfa_credentials mfa ON mfa.user_id = ur.user_id WHERE ur.user_id = :reviewer AND ur.role_key IN ("owner","admin","super_admin") AND mfa.enabled_at IS NOT NULL LIMIT 1');
            $reviewer->execute(['reviewer'=>$market->reviewerId]);
            if ($reviewer->fetchColumn() === false) {
                throw new \DomainException('Market approval is not backed by an MFA-protected compliance administrator.');
            }

            $consentQuery = $this->pdo->prepare(
                'SELECT subject_id,purpose,audience,policy_version,granted,granted_at,withdrawn_at
                 FROM consent_grants WHERE subject_type = "companion_profile" AND subject_id = :profile
                 AND purpose = "profile.publication" AND audience = "public"
                 ORDER BY granted_at DESC LIMIT 1 FOR UPDATE'
            );
            $consentQuery->execute(['profile'=>$profileId]);
            $consentRow = $consentQuery->fetch();
            if (!is_array($consentRow)) {
                throw new \DomainException('Current public publication consent is missing.');
            }
            $consent = new ConsentGrant(
                (string) $consentRow['subject_id'],
                (string) $consentRow['purpose'],
                (string) $consentRow['audience'],
                (string) $consentRow['policy_version'],
                (bool) $consentRow['granted'],
                new DateTimeImmutable((string) $consentRow['granted_at'],$utc),
                $consentRow['withdrawn_at'] === null ? null : new DateTimeImmutable((string) $consentRow['withdrawn_at'],$utc)
            );
            if (!$consent->validFor($profileId,'profile.publication','public',(string) $profile['publication_consent_version'],$now)) {
                throw new \DomainException('Public publication consent is withdrawn, stale or invalid.');
            }

            $clientQuery = $this->pdo->prepare('SELECT status,email_verified_at FROM users WHERE id = :user FOR UPDATE');
            $clientQuery->execute(['user'=>$client->id]);
            $clientUser = $clientQuery->fetch();
            $clientRole = $this->pdo->prepare('SELECT 1 FROM user_roles WHERE user_id = :user AND role_key = "client" LIMIT 1');
            $clientRole->execute(['user'=>$client->id]);
            if (!is_array($clientUser) || $clientUser['status'] !== 'active' ||
                $clientUser['email_verified_at'] === null || $clientRole->fetchColumn() === false) {
                throw new \DomainException('An active, email-verified client account is required.');
            }

            if ($quoteSnapshotId !== null || $policy->depositRequired) {
                if ($quoteSnapshotId === null) {
                    throw new \DomainException('An accepted quote is required by the deposit policy.');
                }
                $quoteQuery = $this->pdo->prepare('SELECT country_code,expires_at,accepted_at FROM quote_snapshots WHERE id = :id FOR UPDATE');
                $quoteQuery->execute(['id'=>$quoteSnapshotId]);
                $quote = $quoteQuery->fetch();
                if (!is_array($quote) || $quote['country_code'] !== $profile['country_code'] ||
                    $quote['accepted_at'] === null ||
                    new DateTimeImmutable((string) $quote['expires_at'],$utc) <= $now) {
                    throw new \DomainException('Quote is missing, expired, unaccepted or belongs to another market.');
                }
            }

            $overlap = $this->pdo->prepare(
                'SELECT id FROM bookings WHERE profile_id = :profile
                 AND status IN ("requested","pending_review","awaiting_automatic_rule","accepted","confirmed")
                 AND starts_at_utc < :end_time AND ends_at_utc > :start_time
                 LIMIT 1 FOR UPDATE'
            );
            $overlap->execute([
                'profile'=>$profileId,
                'end_time'=>$end->format('Y-m-d H:i:s'),
                'start_time'=>$start->format('Y-m-d H:i:s'),
            ]);
            if ($overlap->fetchColumn() !== false) {
                throw new \DomainException('Requested time overlaps an existing booking or pending request.');
            }

            $autoEligible = $policy->automaticEnabled && (bool) $profile['auto_booking_opt_in'] &&
                !$policy->depositRequired && $this->isWithinCompanionAvailability($profileId,$start,$end) &&
                $this->automaticFeatureAvailable($market,$consent,$profileId,$now);
            $mode = $autoEligible ? 'automatic' : 'manual';
            $status = BookingStateMachine::transition('draft','requested','client');
            $depositStatus = $policy->depositRequired ? 'required' : 'not_required';
            $insert = $this->pdo->prepare(
                'INSERT INTO bookings
                 (id,booking_reference,client_user_id,profile_id,destination_id,status,booking_mode,starts_at_utc,ends_at_utc,time_zone,quote_snapshot_id,deposit_status,companion_opt_in_snapshot,policy_version,request_note,created_at,updated_at)
                 VALUES (:id,:reference,:client,:profile,:destination,:status,:mode,:starts,:ends,:timezone,:quote,:deposit,:optin,:policy,:note,UTC_TIMESTAMP(),UTC_TIMESTAMP())'
            );
            $insert->execute([
                'id'=>$bookingId,
                'reference'=>$bookingReference,
                'client'=>$client->id,
                'profile'=>$profileId,
                'destination'=>$profile['destination_id'],
                'status'=>$status,
                'mode'=>$mode,
                'starts'=>$start->format('Y-m-d H:i:s'),
                'ends'=>$end->format('Y-m-d H:i:s'),
                'timezone'=>$profile['time_zone'],
                'quote'=>$quoteSnapshotId,
                'deposit'=>$depositStatus,
                'optin'=>(bool) $profile['auto_booking_opt_in'] ? 1 : 0,
                'policy'=>$policy->version,
                'note'=>$note,
            ]);
            $this->recordEvent($bookingId,$client->id,'client','draft','requested','Client submitted booking request',$policy->version);
            if ($autoEligible) {
                BookingStateMachine::transition('requested','confirmed','system');
                $confirm = $this->pdo->prepare('UPDATE bookings SET status = "confirmed", updated_at = UTC_TIMESTAMP() WHERE id = :id AND status = "requested"');
                $confirm->execute(['id'=>$bookingId]);
                $this->recordEvent($bookingId,null,'system','requested','confirmed','Automatic rules and companion opt-in satisfied',$policy->version);
            }
            if ($this->notifications !== null) {
                $eventKey=$autoEligible?'booking.confirmed':'booking.requested';
                $this->notifications->enqueue($bookingId,(string)$profile['companion_user_id'],$eventKey);
                if ($autoEligible) {
                    $this->notifications->enqueue($bookingId,$client->id,'booking.confirmed');
                }
            }
            $this->audit->record($client->id,'booking.requested','booking',$bookingId,'Client booking request created',null,[
                'status'=>$autoEligible ? 'confirmed' : 'requested',
                'booking_mode'=>$mode,
                'deposit_status'=>$depositStatus,
                'profile_id'=>$profileId,
            ],$requestId,$policy->version);
            $this->pdo->commit();
            return $bookingId;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function automaticFeatureAvailable(MarketPolicy $market, ConsentGrant $consent, string $profileId, DateTimeImmutable $now): bool
    {
        $query = $this->pdo->query('SELECT feature_key,enabled FROM feature_states');
        $states = [];
        foreach ($query->fetchAll() as $row) {
            if (is_string($row['feature_key'] ?? null)) {
                $states[(string) $row['feature_key']] = (bool) $row['enabled'];
            }
        }
        $eligibility = static function (string $dependency) use ($market,$consent,$profileId,$now): bool {
            return match ($dependency) {
                'market.policy' => $market->operationalAt($now),
                'consent.current' => $consent->validFor($profileId,'profile.publication','public',$consent->policyVersion,$now),
                // Provider underwriting, jurisdictional legal approval and safeguarding readiness
                // require separately implemented and evidenced control-plane approvals.
                'provider.approved','legal.approved','safeguarding.ready' => false,
                default => false,
            };
        };
        return (new FeatureAvailability($states,$eligibility))->enabled('bookings.automatic');
    }

    private function isWithinCompanionAvailability(string $profileId, DateTimeImmutable $startUtc, DateTimeImmutable $endUtc): bool
    {
        $query = $this->pdo->prepare(
            'SELECT weekday,local_start,local_end,time_zone,effective_from,effective_until
             FROM companion_availability WHERE profile_id = :profile AND enabled = 1'
        );
        $query->execute(['profile'=>$profileId]);
        foreach ($query->fetchAll() as $row) {
            try {
                $zone = new DateTimeZone((string) $row['time_zone']);
            } catch (\Throwable) {
                continue;
            }
            $localStart = $startUtc->setTimezone($zone);
            $localEnd = $endUtc->setTimezone($zone);
            $localDate = $localStart->format('Y-m-d');
            if ($localDate < (string) $row['effective_from'] ||
                ($row['effective_until'] !== null && $localDate > (string) $row['effective_until']) ||
                $localStart->format('Y-m-d') !== $localEnd->format('Y-m-d') ||
                (int) $localStart->format('w') !== (int) $row['weekday']) {
                continue;
            }
            if ($localStart->format('H:i:s') >= (string) $row['local_start'] &&
                $localEnd->format('H:i:s') <= (string) $row['local_end']) {
                return true;
            }
        }
        return false;
    }

    private function recordEvent(string $bookingId, ?string $actorId, string $actorRole, string $from, string $to, string $reason, string $policyVersion): void
    {
        $event = $this->pdo->prepare(
            'INSERT INTO booking_events (id,booking_id,actor_user_id,actor_role,from_state,to_state,reason,policy_version,occurred_at)
             VALUES (:id,:booking,:actor,:role,:from_state,:to_state,:reason,:policy,UTC_TIMESTAMP())'
        );
        $event->execute([
            'id'=>self::uuid(),
            'booking'=>$bookingId,
            'actor'=>$actorId,
            'role'=>$actorRole,
            'from_state'=>$from,
            'to_state'=>$to,
            'reason'=>$reason,
            'policy'=>$policyVersion,
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
