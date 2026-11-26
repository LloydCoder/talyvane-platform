<?php
declare(strict_types=1);

namespace Talyvane\Application\Features;

use PDO;
use Talyvane\Features\FeatureAvailability;
use Talyvane\Features\FeatureRegistry;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Infrastructure\Database\FeatureStateRepository;
use Talyvane\Security\Authorization;
use Talyvane\Security\Principal;
use Talyvane\Security\SecretBox;
use Talyvane\Security\Totp;

/** Super Admin feature control with password + one-time TOTP step-up and fail-closed dependency evaluation. */
final class FeatureManagementService
{
    /** @param callable(string):bool $eligibility */
    public function __construct(
        private PDO $pdo,
        private FeatureStateRepository $states,
        private AuditLogger $audit,
        private SecretBox $secrets,
        private $eligibility
    ) {}

    /** @return list<array<string,mixed>> */
    public function catalog(): array
    {
        $states=$this->states->enabledStates();
        $availability=new FeatureAvailability($states,$this->eligibility);
        $items=[];
        foreach(FeatureRegistry::definitions() as $key=>$definition) {
            $enabled=$states[$key]??$definition['default'];
            $effective=$availability->enabled($key);
            $items[]=[
                'key'=>$key,'module'=>$definition['module'],'risk'=>$definition['risk'],
                'dependencies'=>$definition['dependencies'],'enabled'=>$enabled,
                'effective'=>$effective,'hard_safeguard'=>in_array($key,FeatureRegistry::HARD_INVARIANTS,true),
                'blocker'=>($enabled&&!$effective)?'A required feature dependency or independent approval is not satisfied.':null,
            ];
        }
        return $items;
    }

    /** @return array{changed:bool,blockers:list<string>} */
    public function change(
        Principal $actor,string $key,bool $enabled,string $reason,string $password,string $code,string $requestId
    ): array {
        Authorization::require($actor,'features.manage',true);
        if(!$actor->hasOnlyRole('super_admin') || !FeatureRegistry::known($key) ||
            in_array($key,FeatureRegistry::HARD_INVARIANTS,true)) {
            throw new \DomainException('Only configurable platform features may be changed by the deployment Super Admin.');
        }
        $reason=trim($reason);
        if(mb_strlen($reason,'UTF-8')<20 || mb_strlen($reason,'UTF-8')>500 ||
            $password==='' || strlen($password)>200 || !preg_match('/^[0-9]{6}$/D',$code) ||
            strlen($requestId)<16 || strlen($requestId)>190) {
            throw new \InvalidArgumentException('Feature change requires a 20–500 character reason, current password, six-digit authenticator code and valid request reference.');
        }

        $this->pdo->beginTransaction();
        try {
            $user=$this->pdo->prepare(
                'SELECT u.password_hash,u.status,mfa.encrypted_totp_secret,mfa.enabled_at,mfa.last_accepted_counter
                 FROM users u
                 JOIN user_roles ur ON ur.user_id=u.id AND ur.role_key="super_admin"
                 JOIN admin_mfa_credentials mfa ON mfa.user_id=u.id
                 WHERE u.id=:actor LIMIT 1 FOR UPDATE'
            );
            $user->execute(['actor'=>$actor->id]);
            $account=$user->fetch();
            if(!is_array($account) || $account['status']!=='active' || $account['enabled_at']===null ||
                !password_verify($password,(string)$account['password_hash'])) {
                throw new \DomainException('Current Super Admin credentials could not be verified.');
            }
            $secret=$this->secrets->decrypt((string)$account['encrypted_totp_secret'],'totp:'.$actor->id);
            $counter=Totp::verify($secret,$code,time(),(int)$account['last_accepted_counter']);
            if($counter===null) {
                throw new \DomainException('Authenticator code is invalid, expired or already used.');
            }
            $consume=$this->pdo->prepare(
                'UPDATE admin_mfa_credentials SET last_accepted_counter=:counter,updated_at=UTC_TIMESTAMP()
                 WHERE user_id=:actor AND last_accepted_counter<:previous'
            );
            $consume->execute(['counter'=>$counter,'actor'=>$actor->id,'previous'=>$counter]);
            if($consume->rowCount()!==1) {
                throw new \DomainException('Authenticator code has already been consumed.');
            }

            $featureLock=$this->pdo->prepare('SELECT feature_key FROM feature_states WHERE feature_key=:key FOR UPDATE');
            $featureLock->execute(['key'=>$key]);
            $before=$this->states->enabledStates();
            $definition=FeatureRegistry::definitions()[$key];
            $current=$before[$key]??$definition['default'];
            if($current===$enabled) {
                $this->pdo->commit();
                return ['changed'=>false,'blockers'=>[]];
            }
            if($enabled) {
                $candidate=$before;
                $candidate[$key]=true;
                if(!(new FeatureAvailability($candidate,$this->eligibility))->enabled($key)) {
                    // Consume the TOTP step even when activation is blocked, preventing replay.
                    $this->pdo->commit();
                    return ['changed'=>false,'blockers'=>['Activation is blocked by one or more unmet feature dependencies or independent approvals.']];
                }
            }

            $this->states->set($key,$enabled,null,$actor->id);
            $after=$this->states->enabledStates();
            $this->audit->record(
                $actor->id,$enabled?'platform.feature.enabled':'platform.feature.disabled',
                'platform_feature',$key,$reason,
                ['enabled'=>$current,'effective'=>(new FeatureAvailability($before,$this->eligibility))->enabled($key)],
                ['enabled'=>$enabled,'effective'=>(new FeatureAvailability($after,$this->eligibility))->enabled($key)],
                $requestId,'platform-feature-control-v1'
            );
            $this->pdo->commit();
            return ['changed'=>true,'blockers'=>[]];
        } catch (\Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }
}
