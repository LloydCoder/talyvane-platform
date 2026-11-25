<?php
declare(strict_types=1);

namespace Talyvane\Application\Clients;

use PDO;
use Talyvane\Features\FeatureAvailability;

final class MembershipEntitlementService
{
    public function __construct(private PDO $pdo, private FeatureAvailability $features) {}

    public static function catalogue(): array
    {
        return MembershipEntitlementCatalog::keys();
    }

    public function has(string $clientId, string $entitlementKey): bool
    {
        if ($clientId === '' || !in_array($entitlementKey, self::catalogue(), true) ||
            !$this->features->enabled('memberships.private_gallery')) {
            return false;
        }
        $query = $this->pdo->prepare(
            'SELECT 1 FROM users u
             JOIN user_roles r ON r.user_id=u.id AND r.role_key="client"
             JOIN memberships m ON m.client_user_id=u.id AND m.status="active"
             JOIN membership_grants g ON g.membership_id=m.id AND g.entitlement_key=:entitlement AND g.active=1
             WHERE u.id=:client AND u.status="active" AND u.email_verified_at IS NOT NULL
               AND m.starts_at<=UTC_TIMESTAMP() AND (m.ends_at IS NULL OR m.ends_at>UTC_TIMESTAMP())
               AND g.starts_at<=UTC_TIMESTAMP() AND (g.ends_at IS NULL OR g.ends_at>UTC_TIMESTAMP())
             LIMIT 1'
        );
        $query->execute(['entitlement'=>$entitlementKey,'client'=>$clientId]);
        return $query->fetchColumn() !== false;
    }
}
