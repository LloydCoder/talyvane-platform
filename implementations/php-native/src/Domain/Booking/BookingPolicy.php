<?php
declare(strict_types=1);

namespace Talyvane\Domain\Booking;

final readonly class BookingPolicy
{
    public function __construct(
        public bool $automaticEnabled,
        public bool $depositRequired,
        public int $minimumNoticeMinutes,
        public int $maximumAdvanceDays,
        public int $minimumDurationMinutes,
        public int $maximumDurationMinutes,
        public string $version,
        public bool $clientCancellationEnabled = false,
        public int $clientCancellationCutoffMinutes = 120,
        public bool $autoConfirmEnabled = false
    ) {
        if ($minimumNoticeMinutes < 0 || $minimumNoticeMinutes > 43200 ||
            $maximumAdvanceDays < 1 || $maximumAdvanceDays > 730 ||
            $minimumDurationMinutes < 15 || $minimumDurationMinutes > 1440 ||
            $maximumDurationMinutes < $minimumDurationMinutes || $maximumDurationMinutes > 10080 ||
            $version === '' || strlen($version) > 64 ||
            $clientCancellationCutoffMinutes < 0 || $clientCancellationCutoffMinutes > 43200) {
            throw new \InvalidArgumentException('Booking policy configuration is invalid.');
        }
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data, string $version): self
    {
        return new self(
            (bool) ($data['automatic_enabled'] ?? false),
            (bool) ($data['deposit_required'] ?? true),
            (int) ($data['minimum_notice_minutes'] ?? 720),
            (int) ($data['maximum_advance_days'] ?? 90),
            (int) ($data['minimum_duration_minutes'] ?? 60),
            (int) ($data['maximum_duration_minutes'] ?? 480),
            $version,
            (bool) ($data['client_cancellation_enabled'] ?? false),
            (int) ($data['client_cancellation_cutoff_minutes'] ?? 120),
            (bool) ($data['auto_confirm_enabled'] ?? false)
        );
    }
}
