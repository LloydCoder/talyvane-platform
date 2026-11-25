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
    $memberships->revoke($actor,$secondId,'Client membership cancelled after a documented request.','cancelled',bin2hex(random_bytes(16)));
    $revoked=$pdo->prepare('SELECT status FROM memberships WHERE id=:id');
    $revoked->execute(['id'=>$secondId]);
    $assert($revoked->fetchColumn()==='cancelled','explicit membership cancellation persists a terminal status');
    $assert(!$entitlements->has($clientId,'media.private_gallery'),'cancelled membership immediately revokes effective entitlement access');
    $eventCount->execute(['client'=>$clientId]);
    $assert((int) $eventCount->fetchColumn()===4,'membership cancellation is recorded as an append-only lifecycle event');


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
