<?php
declare(strict_types=1);

use Talyvane\Application\Clients\ClientMembershipService;
use Talyvane\Application\Clients\MembershipEntitlementService;
use Talyvane\Features\FeatureAvailability;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Infrastructure\Database\FeatureStateRepository;
use Talyvane\Security\Principal;

function runMembershipIntegration(PDO $pdo): int
{
    $assertions = 0;
    $assert = static function(bool $condition,string $message) use (&$assertions): void {
        $assertions++;
        if (!$condition) {
            throw new RuntimeException('Membership integration failed: ' . $message);
        }
    };
    $uuid = static function(): string {
        $bytes=random_bytes(16); $bytes[6]=chr((ord($bytes[6])&15)|64); $bytes[8]=chr((ord($bytes[8])&63)|128);
        $hex=bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    };
    $ownerId=$uuid(); $clientId=$uuid();
    $insertUser=$pdo->prepare('INSERT INTO users (id,email,password_hash,status,email_verified_at,first_login_required,admin_session_version,created_at,updated_at) VALUES (:id,:email,:password,"active",UTC_TIMESTAMP(),0,1,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
    $insertUser->execute(['id'=>$ownerId,'email'=>'membership-owner-'.$ownerId.'@example.test','password'=>password_hash('not-used',PASSWORD_DEFAULT)]);
    $insertUser->execute(['id'=>$clientId,'email'=>'membership-client-'.$clientId.'@example.test','password'=>password_hash('not-used',PASSWORD_DEFAULT)]);
    $insertRole=$pdo->prepare('INSERT INTO user_roles (user_id,role_key,granted_by,granted_at) VALUES (:id,:role,:actor,UTC_TIMESTAMP())');
    $insertRole->execute(['id'=>$ownerId,'role'=>'owner','actor'=>null]);
    $insertRole->execute(['id'=>$clientId,'role'=>'client','actor'=>null]);
    $actor=new Principal($ownerId,['owner'],true);
    $features=new FeatureStateRepository($pdo);
    $states=$features->enabledStates();
    $states['memberships']=true;
    $states['memberships.private_gallery']=true;
    $availability=new FeatureAvailability($states,static fn(string $dependency): bool => true);
    $audit=new AuditLogger($pdo);
    $memberships=new ClientMembershipService($pdo,$audit,$availability);
    $entitlements=new MembershipEntitlementService($pdo,$availability);

    $membershipId=$memberships->assign(
            $actor,$clientId,'gold','Gold','Approved members-only gallery access',
            new DateTimeImmutable('now -1 minute',new DateTimeZone('UTC')),
            new DateTimeImmutable('now +1 day',new DateTimeZone('UTC')),
            ['media.private_gallery'],'Membership assigned after client eligibility review.',
            bin2hex(random_bytes(16))
        );
    $assert(preg_match('/^[0-9a-f-]{36}$/',$membershipId)===1,'membership assignment returns a stable UUID');
    $assert($entitlements->has($clientId,'media.private_gallery'),'current membership and active entitlement grant authorize the approved entitlement');
    $clientMembership=$memberships->currentForClient(new Principal($clientId,['client'],false));
    $assert(is_array($clientMembership) && $clientMembership['tier_name']==='Gold',
        'verified client can view only their current membership summary');
    $assert(($clientMembership['entitlements']??[])===['media.private_gallery'],
        'client membership summary exposes only currently effective allowlisted entitlement keys');
    $assert(!$entitlements->has($clientId,'unknown.entitlement'),'unknown entitlement keys fail closed');
    $eventCount=$pdo->prepare('SELECT COUNT(*) FROM membership_events WHERE client_user_id=:client');
    $eventCount->execute(['client'=>$clientId]);
    $assert((int) $eventCount->fetchColumn()===1,'membership assignment creates one append-only lifecycle event');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM membership_grants WHERE membership_id='.$pdo->quote($membershipId))->fetchColumn()===1,'membership assignment creates the explicit entitlement grant');

    $secondId=$memberships->assign(
            $actor,$clientId,'silver','Silver','Replacement membership without private gallery',
            new DateTimeImmutable('now -1 minute',new DateTimeZone('UTC')),
            new DateTimeImmutable('now +2 days',new DateTimeZone('UTC')),
            [],'Replacement assignment supersedes the previous active membership.',
            bin2hex(random_bytes(16))
        );
    $assert($secondId!==$membershipId,'replacement membership receives a new identity');
    $old=$pdo->prepare('SELECT status FROM memberships WHERE id=:id');
    $old->execute(['id'=>$membershipId]);
    $assert($old->fetchColumn()==='superseded','new membership supersedes the prior active membership atomically');
        $assert(!$entitlements->has($clientId,'media.private_gallery'),'superseding membership revokes effective entitlement access immediately');
        $eventCount->execute(['client'=>$clientId]);
        $assert((int) $eventCount->fetchColumn()===3,'supersession and reassignment are both recorded');
    $thirdId=$memberships->assign(
            $actor,$clientId,'platinum','Platinum','Membership with private gallery entitlement for cancellation test',
            new DateTimeImmutable('now -1 minute',new DateTimeZone('UTC')),
            new DateTimeImmutable('now +3 days',new DateTimeZone('UTC')),
            ['media.private_gallery'],'Client approved a new membership with a private-gallery entitlement.',
            bin2hex(random_bytes(16))
        );
    $assert($entitlements->has($clientId,'media.private_gallery'),'new active membership grants its explicitly approved entitlement');
    $memberships->cancel($actor,$thirdId,'Client membership cancelled after a documented request.',bin2hex(random_bytes(16)));
    $revoked=$pdo->prepare('SELECT status FROM memberships WHERE id=:id');
    $revoked->execute(['id'=>$thirdId]);
    $assert($revoked->fetchColumn()==='cancelled','explicit membership cancellation persists a terminal status');
    $assert(!$entitlements->has($clientId,'media.private_gallery'),'cancelled membership immediately revokes effective entitlement access');
    $assert($memberships->currentForClient(new Principal($clientId,['client'],false))===null,
        'client portal hides cancelled membership from its current-membership projection');
    $projectionDenied=false;
    try { $memberships->currentForClient($actor); }
    catch (DomainException) { $projectionDenied=true; }
    $assert($projectionDenied,'agency administrators cannot use the client-owned membership projection');
    $eventCount->execute(['client'=>$clientId]);
    $assert((int) $eventCount->fetchColumn()===6,'supersession, assignment and cancellation are recorded as append-only lifecycle events');
    $fourthId=$memberships->assign(
            $actor,$clientId,'gold','Gold','Membership with private gallery entitlement for suspension test',
            new DateTimeImmutable('now -1 minute',new DateTimeZone('UTC')),
            new DateTimeImmutable('now +4 days',new DateTimeZone('UTC')),
            ['media.private_gallery'],'Client approved a new membership with a private-gallery entitlement.',
            bin2hex(random_bytes(16))
        );
    $assert($entitlements->has($clientId,'media.private_gallery'),'active membership grants entitlement before suspension');
    $memberships->suspend($actor,$fourthId,'Membership suspended after an operator-reviewed safety concern.',bin2hex(random_bytes(16)));
    $suspended=$pdo->prepare('SELECT status FROM memberships WHERE id=:id');
    $suspended->execute(['id'=>$fourthId]);
    $assert($suspended->fetchColumn()==='suspended','membership suspension persists a non-active status');
    $directoryMembership=$pdo->prepare(
        'SELECT m.status FROM users u
         LEFT JOIN memberships m ON m.id=(
             SELECT latest.id FROM memberships latest
             WHERE latest.client_user_id=u.id AND latest.status IN ("active","suspended")
             ORDER BY CASE WHEN latest.status="active" THEN 0 ELSE 1 END,
                      latest.updated_at DESC,latest.created_at DESC,latest.id DESC LIMIT 1
         )
         WHERE u.id=:client'
    );
    $directoryMembership->execute(['client'=>$clientId]);
    $assert($directoryMembership->fetchColumn()==='suspended',
        'admin client directory can distinguish a suspended membership from no membership');
    $assert(!$entitlements->has($clientId,'media.private_gallery'),'suspended membership immediately revokes effective entitlement access');
    $assert($memberships->currentForClient(new Principal($clientId,['client'],false))===null,
        'client portal hides a suspended membership from its current-membership projection');
    $eventCount->execute(['client'=>$clientId]);
    $assert((int) $eventCount->fetchColumn()===8,'membership suspension is recorded as an append-only lifecycle event');
    $suspendDenied=false;
    try { $memberships->suspend(new Principal($clientId,['client'],false),$fourthId,'Client principals cannot suspend memberships.',bin2hex(random_bytes(16))); }
    catch (DomainException) { $suspendDenied=true; }
    $assert($suspendDenied,'client principals cannot suspend membership records');
    $superAdminAssignDenied=false;
    try {
        $memberships->assign(
            new Principal($ownerId,['super_admin'],true),$clientId,'gold','Gold','Super Admin is not an agency membership operator',
            new DateTimeImmutable('now -1 minute',new DateTimeZone('UTC')),
            new DateTimeImmutable('now +1 day',new DateTimeZone('UTC')),
            [],'Platform Super Admin role alone must not grant agency membership administration.',
            bin2hex(random_bytes(16))
        );
    } catch (DomainException) { $superAdminAssignDenied=true; }
    $assert($superAdminAssignDenied,'platform Super Admin role alone cannot assign agency memberships');
    $superAdminSuspendDenied=false;
    try { $memberships->suspend(new Principal($ownerId,['super_admin'],true),$fourthId,'Platform Super Admin role alone must not suspend agency membership.',bin2hex(random_bytes(16))); }
    catch (DomainException) { $superAdminSuspendDenied=true; }
    $assert($superAdminSuspendDenied,'platform Super Admin role alone cannot suspend agency memberships');
    $repeatCancelRejected=false;
    try { $memberships->cancel($actor,$thirdId,'Repeated cancellation must not create a second lifecycle transition.',bin2hex(random_bytes(16))); }
    catch (DomainException) { $repeatCancelRejected=true; }
    $assert($repeatCancelRejected,'membership cancellation is not repeatable after a terminal transition');

    $cancelDenied=false;
    try { $memberships->cancel(new Principal($clientId,['client'],false),$thirdId,'A client principal must not cancel agency-managed membership.',bin2hex(random_bytes(16))); }
    catch (DomainException) { $cancelDenied=true; }
    $assert($cancelDenied,'client principals cannot cancel membership records');


    $tamperRejected=false;
    try { $pdo->exec('UPDATE membership_events SET reason="tampered"'); }
    catch (PDOException) { $tamperRejected=true; }
    $assert($tamperRejected,'membership lifecycle evidence rejects direct updates at the database boundary');

    $denied=false;
    try { $memberships->assign(new Principal($clientId,['client'],false),$clientId,'x','x','x',new DateTimeImmutable('now'),null,[],'This should be denied by role policy.',bin2hex(random_bytes(16))); }
    catch (DomainException) { $denied=true; }
    $assert($denied,'client principals cannot assign or modify membership records');
    return $assertions;
}
