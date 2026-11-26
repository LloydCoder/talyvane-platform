<?php
declare(strict_types=1);

namespace Talyvane\Application\Identity;

use PDO;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Security\Authorization;
use Talyvane\Infrastructure\Security\RateLimiter;
use Talyvane\Security\Principal;

/** Allowlisted, principal-scoped privacy export; excludes credentials and third-party contact data. */
final class PortalPrivacyExportService
{
    public function __construct(private PDO $pdo,private AuditLogger $audit,private RateLimiter $limiter) {}

    /** @return array<string,mixed> */
    public function export(Principal $actor,string $requestId): array
    {
        if (strlen($requestId)<16 || strlen($requestId)>190) throw new \InvalidArgumentException('Privacy export request reference is invalid.');
        $isClient=$actor->hasRole('client') && count($actor->roles)===1;
        $isCompanion=$actor->hasRole('companion') && count($actor->roles)===1;
        if (!$isClient && !$isCompanion) throw new \DomainException('A client or companion portal account is required.');
        Authorization::require($actor,$isClient?'client.booking.manage':'companion.self.view',false);
        if (!$this->limiter->consume('portal.privacy.export',$actor->id,3,3600,3600)) {
            throw new PortalPrivacyExportRateLimitException('Privacy export is temporarily unavailable. Try again later.');
        }

        $account=$this->pdo->prepare(
            'SELECT id,email,email_verified_at,created_at FROM users WHERE id=:id AND status="active" LIMIT 1'
        );
        $account->execute(['id'=>$actor->id]);
        $user=$account->fetch(PDO::FETCH_ASSOC);
        if (!is_array($user)) throw new \DomainException('Portal account is unavailable.');

        $export=[
            'schema'=>'talyvane-portal-data-export-v1',
            'generated_at'=>gmdate('c'),
            'account'=>[
                'id'=>(string)$user['id'],
                'email'=>(string)$user['email'],
                'email_verified_at'=>$user['email_verified_at'],
                'created_at'=>(string)$user['created_at'],
                'account_type'=>$isClient?'client':'companion',
            ],
            'privacy_notice'=>'This export contains allowlisted records associated with your account. It excludes passwords, MFA secrets, recovery credentials, private safeguarding case contents, payment credentials and other users’ contact details. Retention, legal obligations and backup expiry may affect erasure requests.',
        ];

        if ($isClient) {
            $bookings=$this->pdo->prepare(
                'SELECT b.booking_reference,b.status,b.starts_at_utc,b.ends_at_utc,b.time_zone,b.deposit_status,b.created_at,
                        p.display_name AS companion_display_name,d.display_name AS destination_name
                 FROM bookings b JOIN companion_profiles p ON p.id=b.profile_id
                 JOIN destinations d ON d.id=b.destination_id
                 WHERE b.client_user_id=:actor ORDER BY b.created_at ASC'
            );
            $bookings->execute(['actor'=>$actor->id]);
            $favorites=$this->pdo->prepare(
                'SELECT p.slug,p.display_name,d.display_name AS destination_name,f.created_at
                 FROM client_favorites f JOIN companion_profiles p ON p.id=f.profile_id
                 JOIN destinations d ON d.id=p.destination_id
                 WHERE f.client_user_id=:actor ORDER BY f.created_at ASC'
            );
            $favorites->execute(['actor'=>$actor->id]);
            $memberships=$this->pdo->prepare(
                'SELECT m.plan_key,m.status,m.starts_at,m.ends_at,m.created_at
                 FROM memberships m WHERE m.client_user_id=:actor ORDER BY m.created_at ASC'
            );
            $memberships->execute(['actor'=>$actor->id]);
            $entitlements=$this->pdo->prepare(
                'SELECT m.plan_key,g.entitlement_key,g.active,g.starts_at,g.ends_at,g.created_at
                 FROM memberships m JOIN membership_grants g ON g.membership_id=m.id
                 WHERE m.client_user_id=:actor ORDER BY g.created_at ASC'
            );
            $entitlements->execute(['actor'=>$actor->id]);
            $export['bookings']=$bookings->fetchAll(PDO::FETCH_ASSOC);
            $export['favorites']=$favorites->fetchAll(PDO::FETCH_ASSOC);
            $export['memberships']=$memberships->fetchAll(PDO::FETCH_ASSOC);
            $export['membership_entitlements']=$entitlements->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $profile=$this->pdo->prepare(
                'SELECT p.slug,p.display_name,p.biography,p.languages_json,p.experience_category,p.status,
                        p.adult_eligibility_status,p.publication_consent_version,p.publication_consent_at,
                        p.publication_consent_withdrawn_at,d.display_name AS destination_name
                 FROM companion_profiles p JOIN destinations d ON d.id=p.destination_id
                 WHERE p.user_id=:actor LIMIT 1'
            );
            $profile->execute(['actor'=>$actor->id]);
            $profileRow=$profile->fetch(PDO::FETCH_ASSOC);
            $export['profile']=is_array($profileRow)?$profileRow:null;
            $availability=$this->pdo->prepare(
                'SELECT weekday,local_start,local_end,time_zone,effective_from,effective_until,enabled,created_at
                 FROM companion_availability a JOIN companion_profiles p ON p.id=a.profile_id
                 WHERE p.user_id=:actor ORDER BY weekday,local_start'
            );
            $availability->execute(['actor'=>$actor->id]);
            $export['availability']=$availability->fetchAll(PDO::FETCH_ASSOC);
            $bookings=$this->pdo->prepare(
                'SELECT b.booking_reference,b.status,b.starts_at_utc,b.ends_at_utc,b.time_zone,b.deposit_status,b.created_at,
                        d.display_name AS destination_name
                 FROM bookings b JOIN companion_profiles p ON p.id=b.profile_id
                 JOIN destinations d ON d.id=b.destination_id
                 WHERE p.user_id=:actor ORDER BY b.created_at ASC'
            );
            $bookings->execute(['actor'=>$actor->id]);
            $export['bookings']=$bookings->fetchAll(PDO::FETCH_ASSOC);
            $consents=$this->pdo->prepare(
                'SELECT purpose,audience,policy_version,granted,granted_at,withdrawn_at
                 FROM consent_grants cg JOIN companion_profiles p ON p.id=cg.subject_id
                 WHERE cg.subject_type="companion_profile" AND p.user_id=:actor
                 ORDER BY cg.granted_at ASC'
            );
            $consents->execute(['actor'=>$actor->id]);
            $export['consents']=$consents->fetchAll(PDO::FETCH_ASSOC);
        }
        $this->audit->record($actor->id,'portal.privacy.exported','user',$actor->id,
            'Account holder downloaded their own allowlisted privacy export.',null,
            ['account_type'=>$isClient?'client':'companion','schema'=>$export['schema']],
            $requestId,'portal-privacy-export-v1');
        return $export;
    }
}
