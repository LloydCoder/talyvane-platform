<?php
declare(strict_types=1);

namespace Talyvane\Application\Clients;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use PDO;
use Talyvane\Features\FeatureAvailability;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Security\Authorization;
use Talyvane\Security\Principal;
use Throwable;

/** Agency-managed memberships and immutable entitlement grants. */
final class ClientMembershipService
{
    public function __construct(
        private PDO $pdo,
        private AuditLogger $audit,
        private FeatureAvailability $features
    ) {}

    /** @param list<string> $entitlements */
    public function assign(
        Principal $actor,
        string $clientId,
        string $planKey,
        string $tierName,
        string $benefitsSummary,
        DateTimeImmutable $startsAt,
        ?DateTimeImmutable $endsAt,
        array $entitlements,
        string $reason,
        string $requestId
    ): string {
        Authorization::require($actor,'clients.manage',true);
        if (!$actor->hasRole('owner') && !$actor->hasRole('admin')) {
            throw new DomainException('Only an MFA-protected agency owner or admin can assign client membership.');
        }
        if (!$this->features->enabled('memberships')) {
            throw new DomainException('Membership operations are disabled until the deployment Super Admin enables the feature.');
        }
        $planKey=trim($planKey);
        $tierName=trim($tierName);
        $benefitsSummary=trim($benefitsSummary);
        $reason=trim($reason);
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{1,79}$/',$planKey) ||
            $tierName==='' || strlen($tierName)>120 || strlen($benefitsSummary)>1000 ||
            strlen($reason)<20 || strlen($reason)>500 ||
            !preg_match('/^[a-f0-9]{16,64}$/',$requestId)) {
            throw new InvalidArgumentException('Membership details, audit reason or request reference are invalid.');
        }
        if ($endsAt !== null && $endsAt <= $startsAt) {
            throw new InvalidArgumentException('Membership end time must be later than its start time.');
        }
        $entitlements=array_values(array_unique($entitlements));
        foreach ($entitlements as $key) {
            if (!is_string($key) || !in_array($key,MembershipEntitlementService::catalogue(),true)) {
                throw new InvalidArgumentException('One or more membership entitlements are not supported.');
            }
        }
        if ($entitlements!==[] && !$this->features->enabled('memberships.private_gallery')) {
            throw new DomainException('Private-gallery entitlements are disabled or their consent and market prerequisites are not satisfied.');
        }
        if ($clientId==='' || strlen($clientId)>36) {
            throw new InvalidArgumentException('Client identity is invalid.');
        }

        $this->pdo->beginTransaction();
        try {
            $operator=$this->pdo->prepare(
                'SELECT u.id FROM users u JOIN user_roles r ON r.user_id=u.id
                 WHERE u.id=:id AND u.status="active" AND r.role_key IN ("owner","admin") LIMIT 1 FOR UPDATE'
            );
            $operator->execute(['id'=>$actor->id]);
            if ($operator->fetchColumn()===false) {
                throw new DomainException('The acting administrator is no longer active or authorized.');
            }
            $client=$this->pdo->prepare(
                'SELECT u.id FROM users u JOIN user_roles r ON r.user_id=u.id
                 WHERE u.id=:id AND u.status="active" AND u.email_verified_at IS NOT NULL AND r.role_key="client" LIMIT 1 FOR UPDATE'
            );
            $client->execute(['id'=>$clientId]);
            if ($client->fetchColumn()===false) {
                throw new DomainException('An active, email-verified client account is required.');
            }

            $existing=$this->pdo->prepare(
                'SELECT id,plan_key,tier_name,status,starts_at,ends_at
                 FROM memberships WHERE client_user_id=:client AND status="active" FOR UPDATE'
            );
            $existing->execute(['client'=>$clientId]);
            foreach ($existing->fetchAll(PDO::FETCH_ASSOC) as $previous) {
                $before=$this->snapshot($previous);
                $update=$this->pdo->prepare('UPDATE memberships SET status="superseded",updated_at=UTC_TIMESTAMP() WHERE id=:id AND status="active"');
                $update->execute(['id'=>$previous['id']]);
                if ($update->rowCount()!==1) {
                    throw new DomainException('Membership state changed concurrently; retry the operation.');
                }
                $after=$before; $after['status']='superseded';
                $this->event((string)$previous['id'],$clientId,$actor,'membership.superseded',$before,$after,$reason,$requestId);
            }

            $membershipId=self::uuid();
            $insert=$this->pdo->prepare(
                'INSERT INTO memberships
                 (id,client_user_id,plan_key,tier_name,benefits_summary,assigned_by,status,starts_at,ends_at,created_at,updated_at)
                 VALUES (:id,:client,:plan,:tier,:benefits,:actor,"active",:starts,:ends,UTC_TIMESTAMP(),UTC_TIMESTAMP())'
            );
            $insert->execute([
                'id'=>$membershipId,'client'=>$clientId,'plan'=>$planKey,'tier'=>$tierName,
                'benefits'=>$benefitsSummary,'actor'=>$actor->id,
                'starts'=>$startsAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                'ends'=>$endsAt?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            ]);
            foreach ($entitlements as $key) {
                $grant=$this->pdo->prepare(
                    'INSERT INTO membership_grants
                     (id,membership_id,entitlement_key,active,starts_at,ends_at,granted_by,created_at)
                     VALUES (:id,:membership,:key,1,:starts,:ends,:actor,UTC_TIMESTAMP())'
                );
                $grant->execute([
                    'id'=>self::uuid(),'membership'=>$membershipId,'key'=>$key,
                    'starts'=>$startsAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                    'ends'=>$endsAt?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                    'actor'=>$actor->id,
                ]);
            }
            $after=[
                'plan_key'=>$planKey,'tier_name'=>$tierName,'benefits_summary'=>$benefitsSummary,
                'status'=>'active','starts_at'=>$startsAt->format(DateTimeImmutable::ATOM),
                'ends_at'=>$endsAt?->format(DateTimeImmutable::ATOM),'entitlements'=>$entitlements,
            ];
            $this->event($membershipId,$clientId,$actor,'membership.assigned',null,$after,$reason,$requestId);
            $this->audit->record(
                $actor->id,'client.membership.assigned','client_membership',$membershipId,$reason,
                null,['client_user_id'=>$clientId,'plan_key'=>$planKey,'tier_name'=>$tierName,'status'=>'active','entitlements'=>$entitlements],
                $requestId,'client-membership-v1'
            );
            $this->pdo->commit();
            return $membershipId;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $error;
        }
    }

    /** @return array<string,mixed>|null Safe, client-owned projection of the current membership. */
    public function currentForClient(Principal $actor): ?array
    {
        if (count($actor->roles)!==1 || !$actor->hasRole('client')) {
            throw new DomainException('Only a client account may view its own membership summary.');
        }
        if (!$this->features->enabled('memberships')) {
            return null;
        }
        $client=$this->pdo->prepare(
            'SELECT u.id FROM users u
             JOIN user_roles r ON r.user_id=u.id AND r.role_key="client"
             WHERE u.id=:id AND u.status="active" AND u.email_verified_at IS NOT NULL LIMIT 1'
        );
        $client->execute(['id'=>$actor->id]);
        if ($client->fetchColumn()===false) {
            throw new DomainException('An active, email-verified client account is required.');
        }
        $query=$this->pdo->prepare(
            'SELECT id,plan_key,tier_name,benefits_summary,status,starts_at,ends_at
             FROM memberships
             WHERE client_user_id=:client AND status="active"
               AND starts_at<=UTC_TIMESTAMP() AND (ends_at IS NULL OR ends_at>UTC_TIMESTAMP())
             ORDER BY starts_at DESC,created_at DESC LIMIT 1'
        );
        $query->execute(['client'=>$actor->id]);
        $membership=$query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($membership)) {
            return null;
        }
        $grants=$this->pdo->prepare(
            'SELECT entitlement_key FROM membership_grants
             WHERE membership_id=:membership AND active=1
               AND starts_at<=UTC_TIMESTAMP() AND (ends_at IS NULL OR ends_at>UTC_TIMESTAMP())
             ORDER BY entitlement_key ASC'
        );
        $grants->execute(['membership'=>$membership['id']]);
        $effective=[];
        $entitlements=new MembershipEntitlementService($this->pdo,$this->features);
        foreach ($grants->fetchAll(PDO::FETCH_COLUMN) as $key) {
            if (is_string($key) && in_array($key,MembershipEntitlementService::catalogue(),true) &&
                $entitlements->has($actor->id,$key)) {
                $effective[]=$key;
            }
        }
        return [
            'plan_key'=>(string)$membership['plan_key'],
            'tier_name'=>(string)$membership['tier_name'],
            'benefits_summary'=>(string)$membership['benefits_summary'],
            'status'=>'active',
            'starts_at'=>(string)$membership['starts_at'],
            'ends_at'=>$membership['ends_at']===null?null:(string)$membership['ends_at'],
            'entitlements'=>$effective,
        ];
    }

    /** Cancel an active membership immediately; immutable grants remain as historical evidence. */
    public function cancel(Principal $actor,string $membershipId,string $reason,string $requestId): void
    {
        $this->transitionStatus($actor,$membershipId,$reason,$requestId,'cancelled');
    }

    /** Suspend an active membership immediately without deleting historical grants. */
    public function suspend(Principal $actor,string $membershipId,string $reason,string $requestId): void
    {
        $this->transitionStatus($actor,$membershipId,$reason,$requestId,'suspended');
    }

    private function transitionStatus(Principal $actor,string $membershipId,string $reason,string $requestId,string $status): void
    {
        if (!in_array($status,['cancelled','suspended'],true)) {
            throw new InvalidArgumentException('Membership status transition is invalid.');
        }
        Authorization::require($actor,'clients.manage',true);
        if (!$actor->hasRole('owner') && !$actor->hasRole('admin')) {
            throw new DomainException('Only an MFA-protected agency owner or admin can change a client membership.');
        }
        if (!$this->features->enabled('memberships')) {
            throw new DomainException('Membership operations are disabled until the deployment Super Admin enables the feature.');
        }
        $reason=trim($reason);
        if (!preg_match('/^[a-f0-9-]{36}$/D',$membershipId) ||
            strlen($reason)<20 || strlen($reason)>500 ||
            !preg_match('/^[a-f0-9]{16,64}$/D',$requestId)) {
            throw new InvalidArgumentException('Membership identity, reason or request reference is invalid.');
        }

        $this->pdo->beginTransaction();
        try {
            $operator=$this->pdo->prepare(
                'SELECT u.id FROM users u JOIN user_roles r ON r.user_id=u.id
                 WHERE u.id=:id AND u.status="active" AND u.email_verified_at IS NOT NULL
                   AND r.role_key IN ("owner","admin") LIMIT 1 FOR UPDATE'
            );
            $operator->execute(['id'=>$actor->id]);
            if ($operator->fetchColumn()===false) {
                throw new DomainException('The acting administrator is no longer active or authorized.');
            }
            $query=$this->pdo->prepare(
                'SELECT id,client_user_id,plan_key,tier_name,status,starts_at,ends_at
                 FROM memberships WHERE id=:id FOR UPDATE'
            );
            $query->execute(['id'=>$membershipId]);
            $membership=$query->fetch(PDO::FETCH_ASSOC);
            if (!is_array($membership) || $membership['status']!=='active') {
                throw new DomainException('Only an existing active membership can be changed.');
            }
            $before=$this->snapshot($membership);
            $update=$this->pdo->prepare(
                'UPDATE memberships SET status=:status,updated_at=UTC_TIMESTAMP()
                 WHERE id=:id AND status="active"'
            );
            $update->execute(['status'=>$status,'id'=>$membershipId]);
            if ($update->rowCount()!==1) {
                throw new DomainException('Membership state changed concurrently; retry the operation.');
            }
            $after=$before;
            $after['status']=$status;
            $eventKey='membership.'.$status;
            $this->event($membershipId,(string)$membership['client_user_id'],$actor,$eventKey,$before,$after,$reason,$requestId);
            $this->audit->record(
                $actor->id,'client.'.$eventKey,'client_membership',$membershipId,$reason,
                $before,$after,$requestId,'client-membership-v1'
            );
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $error;
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function snapshot(array $row): array
    {
        return [
            'plan_key'=>(string)$row['plan_key'],'tier_name'=>(string)$row['tier_name'],
            'status'=>(string)$row['status'],'starts_at'=>$row['starts_at'],
            'ends_at'=>$row['ends_at'],
        ];
    }

    /** @param array<string,mixed>|null $before @param array<string,mixed>|null $after */
    private function event(string $membershipId,string $clientId,Principal $actor,string $eventKey,?array $before,?array $after,string $reason,string $requestId): void
    {
        $statement=$this->pdo->prepare(
            'INSERT INTO membership_events
             (id,membership_id,client_user_id,actor_user_id,actor_role,event_key,before_json,after_json,reason,correlation_id,occurred_at)
             VALUES (:id,:membership,:client,:actor,:role,:event,:before,:after,:reason,:correlation,UTC_TIMESTAMP())'
        );
        $statement->execute([
            'id'=>self::uuid(),'membership'=>$membershipId,'client'=>$clientId,'actor'=>$actor->id,
            'role'=>$actor->roles[0] ?? 'unknown','event'=>$eventKey,
            'before'=>$before===null?null:json_encode($before,JSON_THROW_ON_ERROR),
            'after'=>$after===null?null:json_encode($after,JSON_THROW_ON_ERROR),
            'reason'=>$reason,'correlation'=>$requestId,
        ]);
    }

    private static function uuid(): string
    {
        $bytes=random_bytes(16); $bytes[6]=chr((ord($bytes[6])&15)|64); $bytes[8]=chr((ord($bytes[8])&63)|128);
        $hex=bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }
}
