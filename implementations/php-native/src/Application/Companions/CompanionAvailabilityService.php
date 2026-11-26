<?php
declare(strict_types=1);

namespace Talyvane\Application\Companions;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use PDO;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Security\Authorization;
use Talyvane\Security\Principal;

/** Companion-owned weekly availability, stored as destination-local wall-clock windows. */
final class CompanionAvailabilityService
{
    public function __construct(private PDO $pdo, private AuditLogger $audit, private ?FeatureAvailability $features = null) {}

    /** @return array<string,mixed>|null */
    public function profileForCompanion(Principal $actor): ?array
    {
        $this->assertCompanion($actor);
        $query = $this->pdo->prepare(
            'SELECT p.id,p.display_name,p.status,d.display_name AS destination_name,d.time_zone
             FROM companion_profiles p JOIN destinations d ON d.id = p.destination_id
             WHERE p.user_id = :actor LIMIT 1'
        );
        $query->execute(['actor'=>$actor->id]);
        $profile = $query->fetch();
        return is_array($profile) ? $profile : null;
    }

    /** @return list<array<string,mixed>> */
    public function listForCompanion(Principal $actor): array
    {
        $this->assertCompanion($actor);
        $query = $this->pdo->prepare(
            'SELECT a.id,a.weekday,a.local_start,a.local_end,a.time_zone,a.effective_from,
                    a.effective_until,a.enabled,a.created_at,d.display_name AS destination_name
             FROM companion_availability a
             JOIN companion_profiles p ON p.id = a.profile_id
             JOIN destinations d ON d.id = p.destination_id
             WHERE p.user_id = :actor
             ORDER BY a.weekday ASC,a.local_start ASC,a.effective_from DESC'
        );
        $query->execute(['actor'=>$actor->id]);
        return $query->fetchAll();
    }

    public function save(
        Principal $actor,
        string $profileId,
        int $weekday,
        string $localStart,
        string $localEnd,
        string $effectiveFrom,
        ?string $effectiveUntil,
        string $requestId
    ): string {
        $this->assertCompanion($actor);
        if ($weekday < 0 || $weekday > 6) {
            throw new InvalidArgumentException('Choose a weekday between Sunday and Saturday.');
        }
        $start = self::parseTime($localStart);
        $end = self::parseTime($localEnd);
        if ($start >= $end) {
            throw new InvalidArgumentException('Availability must end after it starts on the same local day.');
        }
        self::parseDate($effectiveFrom);
        if ($effectiveUntil !== null && $effectiveUntil !== '') {
            self::parseDate($effectiveUntil);
            if ($effectiveUntil < $effectiveFrom) {
                throw new InvalidArgumentException('The availability end date cannot precede its start date.');
            }
        } else {
            $effectiveUntil = null;
        }

        $this->pdo->beginTransaction();
        try {
            $profile = $this->ownedProfile($actor,$profileId,true);
            $timeZone = (string) $profile['time_zone'];
            try {
                new DateTimeZone($timeZone);
            } catch (\Throwable) {
                throw new DomainException('The profile destination has no valid IANA time zone.');
            }

            $existingQuery = $this->pdo->prepare(
                'SELECT id,local_end,effective_until FROM companion_availability
                 WHERE profile_id = :profile AND weekday = :weekday AND local_start = :start
                   AND effective_from = :from LIMIT 1 FOR UPDATE'
            );
            $existingQuery->execute([
                'profile'=>$profileId,'weekday'=>$weekday,'start'=>$start,'from'=>$effectiveFrom,
            ]);
            $existing = $existingQuery->fetch();
            $ruleId = is_array($existing) ? (string) $existing['id'] : self::uuid();

            $otherRules = $this->pdo->prepare(
                'SELECT id,local_start,local_end,effective_from,effective_until
                 FROM companion_availability
                 WHERE profile_id = :profile AND weekday = :weekday AND enabled = 1
                   AND id <> :id FOR UPDATE'
            );
            $otherRules->execute(['profile'=>$profileId,'weekday'=>$weekday,'id'=>$ruleId]);
            foreach ($otherRules->fetchAll() as $other) {
                $dateRangesOverlap = ($other['effective_until'] === null || (string) $other['effective_until'] >= $effectiveFrom)
                    && ($effectiveUntil === null || (string) $other['effective_from'] <= $effectiveUntil);
                $timeRangesOverlap = $start < (string) $other['local_end'] && $end > (string) $other['local_start'];
                if ($dateRangesOverlap && $timeRangesOverlap) {
                    throw new DomainException('This window overlaps another active availability window for that weekday.');
                }
            }

            if (is_array($existing)) {
                $before = [
                    'local_end'=>(string) $existing['local_end'],
                    'effective_until'=>$existing['effective_until'] === null ? null : (string) $existing['effective_until'],
                ];
                $update = $this->pdo->prepare(
                    'UPDATE companion_availability SET local_end = :end,time_zone = :zone,
                     effective_until = :until,enabled = 1 WHERE id = :id AND profile_id = :profile'
                );
                $update->execute([
                    'end'=>$end,'zone'=>$timeZone,'until'=>$effectiveUntil,'id'=>$ruleId,'profile'=>$profileId,
                ]);
                $after = ['local_end'=>$end,'effective_until'=>$effectiveUntil,'time_zone'=>$timeZone,'enabled'=>true];
                $event = 'companion.availability.updated';
            } else {
                $insert = $this->pdo->prepare(
                    'INSERT INTO companion_availability
                     (id,profile_id,weekday,local_start,local_end,time_zone,effective_from,effective_until,enabled,created_at)
                     VALUES (:id,:profile,:weekday,:start,:end,:zone,:from,:until,1,UTC_TIMESTAMP())'
                );
                $insert->execute([
                    'id'=>$ruleId,'profile'=>$profileId,'weekday'=>$weekday,'start'=>$start,'end'=>$end,
                    'zone'=>$timeZone,'from'=>$effectiveFrom,'until'=>$effectiveUntil,
                ]);
                $before = null;
                $after = [
                    'weekday'=>$weekday,'local_start'=>$start,'local_end'=>$end,'time_zone'=>$timeZone,
                    'effective_from'=>$effectiveFrom,'effective_until'=>$effectiveUntil,'enabled'=>true,
                ];
                $event = 'companion.availability.created';
            }

            $this->audit->record($actor->id,$event,'companion_availability',$ruleId,
                'Companion changed their own weekly availability.',$before,$after,$requestId,'companion-availability-v1');
            $this->pdo->commit();
            return $ruleId;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function delete(Principal $actor,string $profileId,string $availabilityId,string $requestId): void
    {
        $this->assertCompanion($actor);
        $this->pdo->beginTransaction();
        try {
            $this->ownedProfile($actor,$profileId,true);
            $query = $this->pdo->prepare(
                'SELECT id,weekday,local_start,local_end,time_zone,effective_from,effective_until,enabled
                 FROM companion_availability WHERE id = :id AND profile_id = :profile LIMIT 1 FOR UPDATE'
            );
            $query->execute(['id'=>$availabilityId,'profile'=>$profileId]);
            $row = $query->fetch();
            if (!is_array($row)) {
                throw new DomainException('Availability window was not found for this profile.');
            }
            $delete = $this->pdo->prepare('DELETE FROM companion_availability WHERE id = :id AND profile_id = :profile');
            $delete->execute(['id'=>$availabilityId,'profile'=>$profileId]);
            if ($delete->rowCount() !== 1) {
                throw new DomainException('Availability changed during deletion; reload and retry.');
            }
            $this->audit->record($actor->id,'companion.availability.deleted','companion_availability',$availabilityId,
                'Companion removed their own weekly availability.', $row, null, $requestId,'companion-availability-v1');
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** Companion may always opt out; opt-in is fail-closed behind every deployment and profile gate. */
    public function setAutomaticBookingPreference(Principal $actor,string $profileId,bool $enabled,string $requestId): void
    {
        $this->assertCompanion($actor);
        $this->pdo->beginTransaction();
        try {
            $query=$this->pdo->prepare(
                'SELECT p.id,p.status,p.adult_eligibility_status,p.publication_consent_version,
                        p.publication_consent_withdrawn_at,p.auto_booking_opt_in,d.country_code,
                        m.enabled AS market_enabled,m.decision AS market_decision,m.expires_at,m.reviewer_id,
                        CASE WHEN EXISTS (
                            SELECT 1 FROM user_roles ur
                            JOIN admin_mfa_credentials mfa ON mfa.user_id=ur.user_id
                            WHERE ur.user_id=m.reviewer_id AND ur.role_key IN ("owner","admin","super_admin")
                              AND mfa.enabled_at IS NOT NULL
                        ) THEN 1 ELSE 0 END AS reviewer_mfa_valid
                 FROM companion_profiles p
                 JOIN destinations d ON d.id=p.destination_id
                 LEFT JOIN market_policies m ON m.country_code=d.country_code
                 WHERE p.id=:profile AND p.user_id=:actor LIMIT 1 FOR UPDATE'
            );
            $query->execute(['profile'=>$profileId,'actor'=>$actor->id]);
            $profile=$query->fetch();
            if(!is_array($profile)) {
                throw new DomainException('You can change automatic-booking preferences only for your own profile.');
            }
            $consent=false;
            if($profile['publication_consent_version']!==null) {
                $grant=$this->pdo->prepare(
                    'SELECT id FROM consent_grants
                     WHERE subject_type="companion_profile" AND subject_id=:profile
                       AND purpose="profile.publication" AND audience="public"
                       AND policy_version=:version AND granted=1 AND withdrawn_at IS NULL
                     LIMIT 1 FOR UPDATE'
                );
                $grant->execute(['profile'=>$profileId,'version'=>$profile['publication_consent_version']]);
                $consent=$grant->fetchColumn()!==false;
            }
            $marketApproved=(bool)$profile['market_enabled'] &&
                $profile['market_decision']==='approved' &&
                $profile['expires_at']!==null &&
                strtotime((string)$profile['expires_at'].' UTC')>time() &&
                (bool)$profile['reviewer_mfa_valid'];
            $policyProfile=[
                'status'=>(string)$profile['status'],
                'adult_eligibility_status'=>(string)$profile['adult_eligibility_status'],
                'publication_consent_version'=>$consent ? (string)$profile['publication_consent_version'] : null,
                'publication_consent_withdrawn_at'=>$profile['publication_consent_withdrawn_at'],
                'market_approved'=>$marketApproved,
            ];
            CompanionAutomaticBookingPolicy::assertPreference(
                $enabled,$policyProfile,$this->features?->enabled('bookings.automatic') ?? false
            );
            $before=['auto_booking_opt_in'=>(bool)$profile['auto_booking_opt_in']];
            $update=$this->pdo->prepare(
                'UPDATE companion_profiles SET auto_booking_opt_in=:enabled,updated_at=UTC_TIMESTAMP()
                 WHERE id=:profile AND user_id=:actor'
            );
            $update->execute(['enabled'=>$enabled?1:0,'profile'=>$profileId,'actor'=>$actor->id]);
            if($update->rowCount()!==1 && $before['auto_booking_opt_in']!==$enabled) {
                throw new DomainException('Automatic-booking preference changed concurrently; reload and retry.');
            }
            $this->audit->record(
                $actor->id,$enabled?'companion.automatic_booking.opted_in':'companion.automatic_booking.opted_out',
                'companion_profile',$profileId,'Companion changed their automatic-booking preference.',
                $before,['auto_booking_opt_in'=>$enabled],$requestId,'companion-automatic-booking-v1'
            );
            $this->pdo->commit();
        } catch(\Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    private function ownedProfile(Principal $actor,string $profileId,bool $lock): array
    {
        $sql = 'SELECT p.id,d.time_zone FROM companion_profiles p
                JOIN destinations d ON d.id = p.destination_id
                WHERE p.id = :profile AND p.user_id = :actor LIMIT 1' . ($lock ? ' FOR UPDATE' : '');
        $query = $this->pdo->prepare($sql);
        $query->execute(['profile'=>$profileId,'actor'=>$actor->id]);
        $row = $query->fetch();
        if (!is_array($row)) {
            throw new DomainException('You can manage availability only for your own profile.');
        }
        return $row;
    }

    private function assertCompanion(Principal $actor): void
    {
        Authorization::require($actor,'companion.availability.manage',false);
        if (!$actor->hasRole('companion') || count($actor->roles) !== 1) {
            throw new DomainException('Only a companion account can manage companion availability.');
        }
    }

    private static function parseTime(string $value): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!H:i',$value);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$parsed instanceof DateTimeImmutable || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $parsed->format('H:i') !== $value) {
            throw new InvalidArgumentException('Enter availability times in 24-hour HH:MM format.');
        }
        return $parsed->format('H:i:s');
    }

    private static function parseDate(string $value): void
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if (!$parsed instanceof DateTimeImmutable || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $parsed->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Enter a valid availability effective date.');
        }
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
