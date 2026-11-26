<?php
declare(strict_types=1);

namespace Talyvane\Infrastructure\Database;

use PDO;
use Talyvane\Domain\Booking\BookingPolicy;

final class BookingPolicyRepository
{
    public function __construct(private PDO $pdo) {}

    public function current(): BookingPolicy
    {
        $query = $this->pdo->prepare('SELECT setting_json,version FROM deployment_settings WHERE setting_key = :key LIMIT 1');
        $query->execute(['key'=>'booking.rules']);
        $row = $query->fetch();
        if (!is_array($row)) {
            return new BookingPolicy(false,true,720,90,60,480,'booking-default-v1',false,120);
        }
        $data = json_decode((string) $row['setting_json'],true);
        if (!is_array($data)) {
            throw new \RuntimeException('Stored booking policy is corrupt.');
        }
        return BookingPolicy::fromArray($data,'booking-config-v' . (int) $row['version']);
    }
}
