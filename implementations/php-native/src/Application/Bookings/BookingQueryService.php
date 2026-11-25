<?php
declare(strict_types=1);

namespace Talyvane\Application\Bookings;

use PDO;
use Talyvane\Security\Authorization;
use Talyvane\Security\Principal;

/** Tenant-scoped booking read models with strict client/companion separation. */
final class BookingQueryService
{
    public function __construct(private PDO $pdo) {}

    /** @return list<array<string,mixed>> */
    public function clientHistory(Principal $client,int $limit=50): array
    {
        Authorization::require($client,'client.booking.manage',false);
        if (!$client->hasRole('client')) throw new \DomainException('Client role is required.');
        $limit=max(1,min(100,$limit));
        $query=$this->pdo->prepare(
            'SELECT b.id,b.booking_reference,b.status,b.starts_at_utc,b.ends_at_utc,b.time_zone,
                    b.deposit_status,b.created_at,p.display_name AS profile_name,d.display_name AS destination_name
             FROM bookings b JOIN companion_profiles p ON p.id=b.profile_id
             JOIN destinations d ON d.id=b.destination_id
             WHERE b.client_user_id=:client
             ORDER BY b.starts_at_utc DESC LIMIT :limit'
        );
        $query->bindValue(':client',$client->id,PDO::PARAM_STR);
        $query->bindValue(':limit',$limit,PDO::PARAM_INT);
        $query->execute();
        return $query->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function companionHistory(Principal $companion,int $limit=50): array
    {
        Authorization::require($companion,'companion.self.view',false);
        if (!$companion->hasRole('companion')) throw new \DomainException('Companion role is required.');
        $limit=max(1,min(100,$limit));
        $query=$this->pdo->prepare(
            'SELECT b.id,b.booking_reference,b.status,b.starts_at_utc,b.ends_at_utc,b.time_zone,
                    b.deposit_status,b.created_at,p.display_name AS profile_name,d.display_name AS destination_name
             FROM bookings b JOIN companion_profiles p ON p.id=b.profile_id
             JOIN destinations d ON d.id=b.destination_id
             WHERE p.user_id=:companion
             ORDER BY b.starts_at_utc DESC LIMIT :limit'
        );
        $query->bindValue(':companion',$companion->id,PDO::PARAM_STR);
        $query->bindValue(':limit',$limit,PDO::PARAM_INT);
        $query->execute();
        return $query->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function adminQueue(Principal $administrator,int $limit=100): array
    {
        Authorization::require($administrator,'bookings.manage',true);
        if (!$administrator->hasRole('admin') && !$administrator->hasRole('owner') && !$administrator->hasRole('super_admin')) {
            throw new \DomainException('Administrator role is required.');
        }
        $limit=max(1,min(200,$limit));
        $query=$this->pdo->prepare(
            'SELECT b.id,b.booking_reference,b.status,b.starts_at_utc,b.ends_at_utc,b.time_zone,
                    b.deposit_status,b.created_at,p.display_name AS profile_name,d.display_name AS destination_name,
                    b.client_user_id
             FROM bookings b JOIN companion_profiles p ON p.id=b.profile_id
             JOIN destinations d ON d.id=b.destination_id
             ORDER BY b.created_at DESC LIMIT :limit'
        );
        $query->bindValue(':limit',$limit,PDO::PARAM_INT);
        $query->execute();
        return $query->fetchAll();
    }
}
