<?php
declare(strict_types=1);

namespace Talyvane\Domain\Booking;

use DomainException;

/** Fail-closed policy for a companion's automatic-booking preference. */
final class CompanionAutomaticBookingPolicy
{
    /** @param array<string,mixed> $profile */
    public static function assertPreference(bool $enabled,array $profile,bool $featureAvailable,bool $consentConfirmed=false): void
    {
        // Opt-out must always remain available, even during an outage or policy suspension.
        if (!$enabled) {
            return;
        }
        if (!$consentConfirmed) {
            throw new DomainException('Explicit companion confirmation is required before enabling automatic booking.');
        }
        if (!$featureAvailable) {
            throw new DomainException('Automatic booking is not operationally enabled for this deployment.');
        }
        if (($profile['status'] ?? null) !== 'published' ||
            ($profile['adult_eligibility_status'] ?? null) !== 'verified' ||
            !is_string($profile['publication_consent_version'] ?? null) ||
            trim((string)$profile['publication_consent_version']) === '' ||
            ($profile['publication_consent_withdrawn_at'] ?? null) !== null ||
            !($profile['market_approved'] ?? false)) {
            throw new DomainException('A published profile, current adult verification, active publication consent and approved market are required.');
        }
    }
}
