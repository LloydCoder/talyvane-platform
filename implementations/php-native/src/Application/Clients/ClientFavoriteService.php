<?php
declare(strict_types=1);

namespace Talyvane\Application\Clients;

use DomainException;
use PDO;
use Talyvane\Application\Discovery\DiscoveryService;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Security\Authorization;
use Talyvane\Security\Principal;

/** Private client shortlist; it never grants media or membership entitlements. */
final class ClientFavoriteService
{
    public function __construct(
        private PDO $pdo,
        private AuditLogger $audit,
        private DiscoveryService $discovery
    ) {}

    public function add(Principal $actor,string $profileSlug,string $requestId): void
    {
        $this->assertClient($actor);
        $profile = $this->discovery->profile($profileSlug);
        if ($profile === null) {
            throw new DomainException('This profile is not currently available to save.');
        }

        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT id FROM companion_profiles WHERE id = :id FOR UPDATE');
            $lock->execute(['id'=>$profile['id']]);
            if (!is_string($lock->fetchColumn()) || $this->discovery->profile($profileSlug) === null) {
                throw new DomainException('This profile is no longer available to save.');
            }
            $insert = $this->pdo->prepare(
                'INSERT IGNORE INTO client_favorites (client_user_id,profile_id,created_at)
                 VALUES (:client,:profile,UTC_TIMESTAMP())'
            );
            $insert->execute(['client'=>$actor->id,'profile'=>$profile['id']]);
            if ($insert->rowCount() === 1) {
                $this->audit->record($actor->id,'client.favorite.added','companion_profile',(string) $profile['id'],
                    'Client added a currently public profile to their private shortlist.',null,['slug'=>$profileSlug],
                    $requestId,'client-favorites-v1');
            }
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function remove(Principal $actor,string $profileSlug,string $requestId): void
    {
        $this->assertClient($actor);
        $query = $this->pdo->prepare(
            'SELECT f.profile_id,p.slug FROM client_favorites f
             JOIN companion_profiles p ON p.id = f.profile_id
             WHERE f.client_user_id = :client AND p.slug = :slug LIMIT 1'
        );
        $query->execute(['client'=>$actor->id,'slug'=>$profileSlug]);
        $row = $query->fetch();
        if (!is_array($row)) {
            return;
        }
        $this->pdo->beginTransaction();
        try {
            $delete = $this->pdo->prepare('DELETE FROM client_favorites WHERE client_user_id = :client AND profile_id = :profile');
            $delete->execute(['client'=>$actor->id,'profile'=>$row['profile_id']]);
            if ($delete->rowCount() === 1) {
                $this->audit->record($actor->id,'client.favorite.removed','companion_profile',(string) $row['profile_id'],
                    'Client removed a profile from their private shortlist.',['saved'=>true],['saved'=>false],
                    $requestId,'client-favorites-v1');
            }
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return list<array<string,mixed>> */
    public function list(Principal $actor): array
    {
        $this->assertClient($actor);
        $query = $this->pdo->prepare(
            'SELECT p.slug FROM client_favorites f
             JOIN companion_profiles p ON p.id = f.profile_id
             WHERE f.client_user_id = :client ORDER BY f.created_at DESC'
        );
        $query->execute(['client'=>$actor->id]);
        $items = [];
        foreach ($query->fetchAll() as $row) {
            $profile = $this->discovery->profile((string) $row['slug']);
            if ($profile !== null) {
                $items[] = $profile;
            }
        }
        return $items;
    }

    private function assertClient(Principal $actor): void
    {
        Authorization::require($actor,'client.favorites.manage',false);
        if (!$actor->hasRole('client') || count($actor->roles) !== 1) {
            throw new DomainException('Only a client account can manage a private shortlist.');
        }
    }
}
