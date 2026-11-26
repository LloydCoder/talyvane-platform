<?php
declare(strict_types=1);

namespace Talyvane\Application\Identity;

use PDO;
use Throwable;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Security\Authorization;
use Talyvane\Security\Principal;
use Talyvane\Security\SecretBox;

/** MFA-gated privacy request review. Fulfilment is deliberately not implied by a review decision. */
final class PortalPrivacyRightsAdminService
{
    public function __construct(private PDO $pdo,private SecretBox $box,private AuditLogger $audit) {}

    /** @return list<array<string,mixed>> */
    public function queue(Principal $actor,string $requestId): array
    {
        Authorization::require($actor,'privacy.requests.review');
        if(strlen($requestId)<16 || strlen($requestId)>64) throw new \InvalidArgumentException('Privacy queue request reference is invalid.');
        $query=$this->pdo->query(
            'SELECT r.id,r.user_id,r.account_type,r.request_type,r.status,r.payload_ciphertext,
                    r.verified_at,r.created_at,u.email
             FROM portal_privacy_requests r
             JOIN users u ON u.id=r.user_id
             WHERE r.status="in_review"
             ORDER BY r.created_at ASC LIMIT 200'
        );
        $rows=$query->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as &$row) {
            $row['correction_summary']=null;
            if(is_string($row['payload_ciphertext']) && $row['payload_ciphertext']!=='') {
                try {
                    $payload=json_decode($this->box->decrypt($row['payload_ciphertext'],
                        'portal-privacy-request-v1:payload:'.$row['id']),true,8,JSON_THROW_ON_ERROR);
                    $row['correction_summary']=is_array($payload)?($payload['summary']??null):null;
                } catch(Throwable) {
                    $row['correction_summary']='Encrypted request details are unavailable; investigate key/configuration integrity.';
                }
            }
            unset($row['payload_ciphertext']);
        }
        unset($row);
        $this->audit->record($actor->id,'portal.privacy.queue.viewed','portal_privacy_request',null,
            'Administrator accessed the verified privacy request review queue',null,['request_count'=>count($rows)],$requestId,'portal-privacy-rights-v1');
        return $rows;
    }

    public function review(Principal $actor,string $requestId,string $decision,string $reason,string $correlationId): void
    {
        Authorization::require($actor,'privacy.requests.review');
        $reason=trim($reason);
        if(!self::isUuid($requestId) || !in_array($decision,['rejected','deferred'],true) ||
            strlen($reason)<10 || strlen($reason)>500 || strlen($correlationId)<16 || strlen($correlationId)>64) {
            throw new \InvalidArgumentException('Privacy review decision is invalid.');
        }
        $this->pdo->beginTransaction();
        try {
            $query=$this->pdo->prepare(
                'SELECT id,user_id,request_type,status FROM portal_privacy_requests
                 WHERE id=:id FOR UPDATE'
            );
            $query->execute(['id'=>$requestId]);
            $request=$query->fetch(PDO::FETCH_ASSOC);
            if(!is_array($request) || $request['status']!=='in_review') {
                throw new \DomainException('Privacy request is not awaiting review.');
            }
            $this->pdo->prepare(
                'UPDATE portal_privacy_requests
                 SET status=:status,reviewed_by=:reviewer,reviewed_at=UTC_TIMESTAMP(),
                     decision_reason=:reason,completed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
                 WHERE id=:id'
            )->execute(['status'=>$decision,'reviewer'=>$actor->id,'reason'=>$reason,'id'=>$requestId]);
            $this->audit->record($actor->id,'portal.privacy.reviewed','portal_privacy_request',$requestId,
                'Administrator recorded a privacy-rights review decision',
                ['status'=>'in_review'],['status'=>$decision,'reason'=>$reason,'request_type'=>$request['request_type']],
                $correlationId,'portal-privacy-rights-v1');
            $this->pdo->commit();
        } catch(Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }


    /**
     * Fulfil a verified companion-profile correction after an administrator has
     * independently checked the requested values. Public discovery is suspended
     * until the corrected profile is reviewed and republished.
     *
     * @param list<string> $languages BCP-47 language tags
     */
    public function fulfillCompanionCorrection(
        Principal $actor,
        string $requestId,
        string $displayName,
        string $biography,
        array $languages,
        string $reason,
        string $correlationId
    ): void {
        Authorization::require($actor,'privacy.requests.review');
        $displayName=trim($displayName);
        $biography=trim($biography);
        $reason=trim($reason);
        if (!self::isUuid($requestId) || $displayName==='' || strlen($displayName)>120 ||
            strlen($biography)>8000 || strlen($reason)<10 || strlen($reason)>500 ||
            strlen($correlationId)<16 || strlen($correlationId)>64 ||
            count($languages)>20 || $languages===[]) {
            throw new \InvalidArgumentException('Companion correction details are invalid.');
        }
        $normalized=[];
        foreach($languages as $language) {
            if (!is_string($language) || preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/D',trim($language))!==1) {
                throw new \InvalidArgumentException('Companion languages must be valid BCP-47-style tags.');
            }
            $tag=trim($language);
            if (!in_array(strtolower($tag),array_map('strtolower',$normalized),true)) $normalized[]=$tag;
        }
        if ($normalized===[]) throw new \InvalidArgumentException('At least one valid language is required.');

        $this->pdo->beginTransaction();
        try {
            $query=$this->pdo->prepare(
                'SELECT id,user_id,account_type,request_type,status
                 FROM portal_privacy_requests WHERE id=:id FOR UPDATE'
            );
            $query->execute(['id'=>$requestId]);
            $request=$query->fetch(PDO::FETCH_ASSOC);
            if (!is_array($request) || $request['status']!=='in_review' ||
                $request['request_type']!=='correction' || $request['account_type']!=='companion') {
                throw new \DomainException('Only a verified companion correction request awaiting review can be fulfilled.');
            }
            $user=$this->pdo->prepare('SELECT id,status FROM users WHERE id=:id FOR UPDATE');
            $user->execute(['id'=>$request['user_id']]);
            $account=$user->fetch(PDO::FETCH_ASSOC);
            if (!is_array($account) || $account['status']!=='active') {
                throw new \DomainException('The companion account is not active.');
            }
            $profileQuery=$this->pdo->prepare(
                'SELECT id,status FROM companion_profiles WHERE user_id=:user FOR UPDATE'
            );
            $profileQuery->execute(['user'=>$request['user_id']]);
            $profile=$profileQuery->fetch(PDO::FETCH_ASSOC);
            if (!is_array($profile)) throw new \DomainException('The companion profile is unavailable.');

            $before=$this->pdo->prepare('SELECT display_name,biography,languages_json,status FROM companion_profiles WHERE id=:id');
            $before->execute(['id'=>$profile['id']]);
            $previous=$before->fetch(PDO::FETCH_ASSOC);
            $this->pdo->prepare(
                'UPDATE companion_profiles
                 SET display_name=:name,biography=:biography,languages_json=:languages,status="draft",updated_at=UTC_TIMESTAMP()
                 WHERE id=:id'
            )->execute([
                'name'=>$displayName,'biography'=>$biography,
                'languages'=>json_encode(array_values($normalized),JSON_THROW_ON_ERROR),
                'id'=>$profile['id'],
            ]);
            $this->pdo->prepare(
                'UPDATE portal_privacy_requests SET status="fulfilled",reviewed_by=:reviewer,
                 reviewed_at=UTC_TIMESTAMP(),decision_reason=:reason,payload_ciphertext=NULL,
                 completed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id'
            )->execute(['reviewer'=>$actor->id,'reason'=>$reason,'id'=>$requestId]);
            $this->audit->record($actor->id,'portal.privacy.correction.fulfilled','portal_privacy_request',$requestId,
                'Verified companion profile correction applied; profile returned to draft for independent republication review',
                ['profile_status'=>$previous['status']??null],
                ['profile_status'=>'draft','fields'=>['display_name','biography','languages'],'reason'=>$reason],
                $correlationId,'portal-privacy-rights-v1');
            $this->pdo->commit();
        } catch(Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    /** Fulfil the allowlisted client name correction; contact-email changes require a separate new-address verification flow. */
    public function fulfillClientCorrection(
        Principal $actor,string $requestId,string $name,string $reason,string $correlationId
    ): void {
        Authorization::require($actor,'privacy.requests.review');
        $name=trim($name);
        $reason=trim($reason);
        if(!self::isUuid($requestId) || $name==='' || mb_strlen($name,'UTF-8')>120 ||
            strlen($reason)<10 || strlen($reason)>500 ||
            strlen($correlationId)<16 || strlen($correlationId)>64) {
            throw new \InvalidArgumentException('Client correction details are invalid.');
        }
        $this->pdo->beginTransaction();
        try {
            $query=$this->pdo->prepare(
                'SELECT id,user_id,account_type,request_type,status
                 FROM portal_privacy_requests WHERE id=:id FOR UPDATE'
            );
            $query->execute(['id'=>$requestId]);
            $request=$query->fetch(PDO::FETCH_ASSOC);
            if(!is_array($request) || $request['status']!=='in_review' ||
                $request['request_type']!=='correction' || $request['account_type']!=='client') {
                throw new \DomainException('Only a verified client correction request awaiting review can be fulfilled.');
            }
            $user=$this->pdo->prepare('SELECT id,status,display_name FROM users WHERE id=:id FOR UPDATE');
            $user->execute(['id'=>$request['user_id']]);
            $account=$user->fetch(PDO::FETCH_ASSOC);
            $roles=$this->pdo->prepare('SELECT role_key FROM user_roles WHERE user_id=:id ORDER BY role_key');
            $roles->execute(['id'=>$request['user_id']]);
            $roleList=$roles->fetchAll(PDO::FETCH_COLUMN);
            if(!is_array($account) || $account['status']!=='active' || $roleList!==['client']) {
                throw new \DomainException('The client account is inactive or does not have an exclusive client role.');
            }
            $this->pdo->prepare('UPDATE users SET display_name=:name,updated_at=UTC_TIMESTAMP() WHERE id=:id')
                ->execute(['name'=>$name,'id'=>$request['user_id']]);
            $this->pdo->prepare(
                'UPDATE portal_privacy_requests SET status="fulfilled",reviewed_by=:reviewer,
                 reviewed_at=UTC_TIMESTAMP(),decision_reason=:reason,payload_ciphertext=NULL,
                 completed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id'
            )->execute(['reviewer'=>$actor->id,'reason'=>$reason,'id'=>$requestId]);
            $this->audit->record($actor->id,'portal.privacy.correction.fulfilled','portal_privacy_request',$requestId,
                'Verified client display-name correction applied; contact details were not changed.',
                ['fields'=>['display_name']],
                ['fields'=>['display_name'],'reason'=>$reason],
                $correlationId,'portal-privacy-rights-v1');
            $this->pdo->commit();
        } catch(Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    /**
     * Fulfil a verified client erasure request by anonymizing the account record.
     * Financial, booking, safeguarding, support and enquiry records are retained
     * only when absent; any dependency that requires separate retention review
     * fails closed. This does not claim to erase backups or third-party copies.
     */
    public function fulfillClientErasure(
        Principal $actor,string $requestId,string $reason,string $correlationId
    ): void {
        Authorization::require($actor,'privacy.requests.review');
        $reason=trim($reason);
        if(!self::isUuid($requestId) || strlen($reason)<20 || strlen($reason)>500 ||
            strlen($correlationId)<16 || strlen($correlationId)>64) {
            throw new \InvalidArgumentException('Client erasure review details are invalid.');
        }
        $this->pdo->beginTransaction();
        try {
            $query=$this->pdo->prepare(
                'SELECT id,user_id,account_type,request_type,status
                 FROM portal_privacy_requests WHERE id=:id FOR UPDATE'
            );
            $query->execute(['id'=>$requestId]);
            $request=$query->fetch(PDO::FETCH_ASSOC);
            if(!is_array($request) || $request['status']!=='in_review' ||
                $request['request_type']!=='erasure' || $request['account_type']!=='client') {
                throw new \DomainException('Only a verified client erasure request awaiting review can be fulfilled.');
            }
            $userQuery=$this->pdo->prepare(
                'SELECT id,email,status FROM users WHERE id=:id FOR UPDATE'
            );
            $userQuery->execute(['id'=>$request['user_id']]);
            $user=$userQuery->fetch(PDO::FETCH_ASSOC);
            $rolesQuery=$this->pdo->prepare(
                'SELECT role_key FROM user_roles WHERE user_id=:id ORDER BY role_key'
            );
            $rolesQuery->execute(['id'=>$request['user_id']]);
            $roles=$rolesQuery->fetchAll(PDO::FETCH_COLUMN);
            if(!is_array($user) || $user['status']!=='active' || $roles!==['client']) {
                throw new \DomainException('Only an active account with an exclusive client role can be anonymized.');
            }

            $blockers=[
                ['SELECT COUNT(*) FROM bookings WHERE client_user_id=:id','client_user_id'],
                ['SELECT COUNT(*) FROM memberships WHERE client_user_id=:id','client_user_id'],
                ['SELECT COUNT(*) FROM payment_invoices WHERE client_user_id=:id','client_user_id'],
                ['SELECT COUNT(*) FROM support_tickets WHERE client_user_id=:id','client_user_id'],
                ['SELECT COUNT(*) FROM safeguarding_cases WHERE reporter_user_id=:id','reporter_user_id'],
                ['SELECT COUNT(*) FROM enquiries WHERE email=:email','email'],
            ];
            foreach($blockers as [$sql,$field]) {
                $check=$this->pdo->prepare($sql);
                $check->execute([$field==='email'?'email':'id'=>$field==='email'?$user['email']:$user['id']]);
                if((int)$check->fetchColumn()>0) {
                    throw new \DomainException('Client erasure is blocked by retained booking, membership, financial, support, safeguarding or enquiry records; complete a documented retention review first.');
                }
            }

            $this->pdo->prepare('DELETE FROM client_favorites WHERE client_user_id=:id')
                ->execute(['id'=>$user['id']]);
            $this->pdo->prepare('DELETE FROM auth_challenges WHERE user_id=:id')
                ->execute(['id'=>$user['id']]);
            $this->pdo->prepare(
                'UPDATE portal_privacy_requests
                 SET payload_ciphertext=NULL,otp_hash="",status=IF(id=:request,"fulfilled","superseded"),
                     decision_reason=IF(id=:request,:reason,decision_reason),
                     reviewed_by=IF(id=:request,:reviewer,reviewed_by),
                     reviewed_at=IF(id=:request,UTC_TIMESTAMP(),reviewed_at),
                     completed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
                 WHERE user_id=:user AND status IN ("pending","in_review")'
            )->execute(['request'=>$requestId,'reason'=>$reason,'reviewer'=>$actor->id,'user'=>$user['id']]);

            $pseudonym='erased+'.str_replace('-','',(string)$user['id']).'@privacy.invalid';
            $this->pdo->prepare(
                'UPDATE users SET email=:email,display_name=NULL,password_hash=:password,status="erased",
                    email_verified_at=NULL,first_login_required=0,remember_token_hash=NULL,
                    admin_session_version=admin_session_version+1,updated_at=UTC_TIMESTAMP()
                 WHERE id=:id AND status="active"'
            )->execute([
                'email'=>$pseudonym,'password'=>password_hash(bin2hex(random_bytes(48)),PASSWORD_DEFAULT),
                'id'=>$user['id'],
            ]);
            $this->pdo->prepare('DELETE FROM user_roles WHERE user_id=:id')
                ->execute(['id'=>$user['id']]);
            $this->audit->record($actor->id,'portal.privacy.client.erasure.fulfilled','portal_privacy_request',$requestId,
                'Verified client account anonymized after dependency and retention checks',
                ['account_status'=>'active'],['account_status'=>'erased','fields'=>['email','credentials','roles','favorites'],'reason'=>$reason],
                $correlationId,'portal-privacy-rights-v1');
            $this->pdo->commit();
        } catch(Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    /**
     * Withdraw and anonymize a companion account while retaining the profile
     * row for historical foreign keys. Any booking, private media or safeguarding
     * dependency blocks fulfilment pending a separate retention/media review.
     */
    public function fulfillCompanionErasure(
        Principal $actor,string $requestId,string $reason,string $correlationId
    ): void {
        Authorization::require($actor,'privacy.requests.review');
        $reason=trim($reason);
        if(!self::isUuid($requestId) || strlen($reason)<20 || strlen($reason)>500 ||
            strlen($correlationId)<16 || strlen($correlationId)>64) {
            throw new \InvalidArgumentException('Companion erasure review details are invalid.');
        }
        $this->pdo->beginTransaction();
        try {
            $query=$this->pdo->prepare(
                'SELECT id,user_id,account_type,request_type,status
                 FROM portal_privacy_requests WHERE id=:id FOR UPDATE'
            );
            $query->execute(['id'=>$requestId]);
            $request=$query->fetch(PDO::FETCH_ASSOC);
            if(!is_array($request) || $request['status']!=='in_review' ||
                $request['request_type']!=='erasure' || $request['account_type']!=='companion') {
                throw new \DomainException('Only a verified companion erasure request awaiting review can be fulfilled.');
            }
            $userQuery=$this->pdo->prepare('SELECT id,email,status FROM users WHERE id=:id FOR UPDATE');
            $userQuery->execute(['id'=>$request['user_id']]);
            $user=$userQuery->fetch(PDO::FETCH_ASSOC);
            $rolesQuery=$this->pdo->prepare('SELECT role_key FROM user_roles WHERE user_id=:id ORDER BY role_key');
            $rolesQuery->execute(['id'=>$request['user_id']]);
            $roles=$rolesQuery->fetchAll(PDO::FETCH_COLUMN);
            if(!is_array($user) || $user['status']!=='active' || $roles!==['companion']) {
                throw new \DomainException('Only an active account with an exclusive companion role can be anonymized.');
            }
            $profileQuery=$this->pdo->prepare(
                'SELECT id FROM companion_profiles WHERE user_id=:user FOR UPDATE'
            );
            $profileQuery->execute(['user'=>$user['id']]);
            $profile=$profileQuery->fetch(PDO::FETCH_ASSOC);
            if(!is_array($profile)) throw new \DomainException('Companion profile is unavailable; investigate account linkage before erasure.');

            $checks=[
                ['SELECT COUNT(*) FROM bookings WHERE profile_id=:id','id'=>$profile['id']],
                ['SELECT COUNT(*) FROM profile_media WHERE profile_id=:id','id'=>$profile['id']],
                ['SELECT COUNT(*) FROM safeguarding_cases WHERE reporter_user_id=:id','id'=>$user['id']],
                ['SELECT COUNT(*) FROM enquiries WHERE email=:email','email'=>$user['email']],
            ];
            foreach($checks as [$sql,$parameter=>$value]) {
                $check=$this->pdo->prepare($sql);
                $check->execute([$parameter=>$value]);
                if((int)$check->fetchColumn()>0) {
                    throw new \DomainException('Companion erasure is blocked by retained bookings, private media, safeguarding or enquiry records; complete a documented retention and media review first.');
                }
            }

            $this->pdo->prepare('DELETE FROM companion_availability WHERE profile_id=:id')
                ->execute(['id'=>$profile['id']]);
            $this->pdo->prepare('DELETE FROM client_favorites WHERE profile_id=:id')
                ->execute(['id'=>$profile['id']]);
            $this->pdo->prepare(
                'UPDATE consent_grants SET granted=0,withdrawn_at=COALESCE(withdrawn_at,UTC_TIMESTAMP()),
                    evidence_reference=NULL
                 WHERE subject_type="companion_profile" AND subject_id=:id'
            )->execute(['id'=>$profile['id']]);
            $this->pdo->prepare(
                'UPDATE companion_profiles SET slug=:slug,display_name="Former companion",biography=NULL,
                    languages_json=NULL,experience_category=NULL,status="withdrawn",
                    adult_eligibility_status="unverified",adult_verified_at=NULL,adult_verified_by=NULL,
                    verification_reference_hash=NULL,publication_consent_version=NULL,
                    publication_consent_at=NULL,publication_consent_withdrawn_at=UTC_TIMESTAMP(),
                    auto_booking_opt_in=0,updated_at=UTC_TIMESTAMP() WHERE id=:id'
            )->execute(['slug'=>'former-'.str_replace('-','',(string)$profile['id']),'id'=>$profile['id']]);
            $this->pdo->prepare('DELETE FROM auth_challenges WHERE user_id=:id')->execute(['id'=>$user['id']]);
            $this->pdo->prepare(
                'UPDATE portal_privacy_requests
                 SET payload_ciphertext=NULL,otp_hash="",status=IF(id=:request_status,"fulfilled","superseded"),
                     decision_reason=IF(id=:request_reason,:reason,decision_reason),
                     reviewed_by=IF(id=:request_reviewer,:reviewer,reviewed_by),
                     reviewed_at=IF(id=:request_reviewed,UTC_TIMESTAMP(),reviewed_at),
                     completed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
                 WHERE user_id=:user AND status IN ("pending","in_review")'
            )->execute([
                'request_status'=>$requestId,'request_reason'=>$requestId,'request_reviewer'=>$requestId,
                'request_reviewed'=>$requestId,'reason'=>$reason,'reviewer'=>$actor->id,'user'=>$user['id'],
            ]);
            $pseudonym='erased+'.str_replace('-','',(string)$user['id']).'@privacy.invalid';
            $this->pdo->prepare(
                'UPDATE users SET email=:email,display_name=NULL,password_hash=:password,status="erased",
                    email_verified_at=NULL,first_login_required=0,remember_token_hash=NULL,
                    admin_session_version=admin_session_version+1,updated_at=UTC_TIMESTAMP()
                 WHERE id=:id AND status="active"'
            )->execute([
                'email'=>$pseudonym,'password'=>password_hash(bin2hex(random_bytes(48)),PASSWORD_DEFAULT),
                'id'=>$user['id'],
            ]);
            $this->pdo->prepare('DELETE FROM user_roles WHERE user_id=:id')->execute(['id'=>$user['id']]);
            $this->audit->record($actor->id,'portal.privacy.companion.erasure.fulfilled','portal_privacy_request',$requestId,
                'Verified companion account anonymized and public profile withdrawn after dependency checks',
                ['account_status'=>'active','profile_status'=>'published'],
                ['account_status'=>'erased','profile_status'=>'withdrawn',
                 'fields'=>['email','credentials','roles','profile_identity','availability','consent'],'reason'=>$reason],
                $correlationId,'portal-privacy-rights-v1');
            $this->pdo->commit();
        } catch(Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    private static function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD',$value)===1;
    }
}
