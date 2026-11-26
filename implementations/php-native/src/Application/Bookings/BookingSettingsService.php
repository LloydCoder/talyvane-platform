<?php
declare(strict_types=1);

namespace Talyvane\Application\Bookings;

use DomainException;
use InvalidArgumentException;
use PDO;
use Talyvane\Features\FeatureAvailability;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Security\Authorization;
use Talyvane\Security\Principal;

final class BookingSettingsService
{
    public function __construct(private PDO $pdo,private AuditLogger $audit,private FeatureAvailability $features) {}

    /** @return array<string,mixed> */
    public function current(Principal $actor): array
    {
        $this->authorize($actor);
        $query=$this->pdo->prepare('SELECT setting_json,version FROM deployment_settings WHERE setting_key=:key LIMIT 1');
        $query->execute(['key'=>'booking.rules']);
        $row=$query->fetch(PDO::FETCH_ASSOC);
        $data=is_array($row)?json_decode((string)$row['setting_json'],true):[];
        if(!is_array($data)) throw new DomainException('Stored booking settings are corrupt.');
        return [
            'booking_mode'=>!empty($data['automatic_enabled'])?'automatic':'manual',
            'auto_confirm_enabled'=>(bool)($data['auto_confirm_enabled']??false),
            'auto_requires_verified_deposit'=>(bool)($data['deposit_required']??true),
            'minimum_notice_hours'=>(int)ceil((int)($data['minimum_notice_minutes']??720)/60),
            'maximum_advance_days'=>(int)($data['maximum_advance_days']??90),
            'client_cancellation_enabled'=>(bool)($data['client_cancellation_enabled']??false),
            'cancellation_cutoff_hours'=>(int)ceil((int)($data['client_cancellation_cutoff_minutes']??120)/60),
            'minimum_duration_minutes'=>(int)($data['minimum_duration_minutes']??60),
            'maximum_duration_minutes'=>(int)($data['maximum_duration_minutes']??480),
            'version'=>is_array($row)?(int)$row['version']:0,
        ];
    }

    /** @param array<string,mixed> $input */
    public function update(Principal $actor,array $input,string $requestId): void
    {
        $this->authorize($actor);
        $mode=$input['booking_mode']??null;
        if(!in_array($mode,['manual','automatic'],true)) throw new InvalidArgumentException('Booking mode must be manual or automatic.');
        foreach(['auto_confirm_enabled','auto_requires_verified_deposit','client_cancellation_enabled'] as $field) {
            if(!is_bool($input[$field]??null)) throw new InvalidArgumentException('Booking boolean settings are invalid.');
        }
        $minimum=(int)($input['minimum_notice_hours']??-1);
        $advance=(int)($input['maximum_advance_days']??0);
        $cutoff=(int)($input['cancellation_cutoff_hours']??-1);
        if($minimum<0||$minimum>720||$advance<1||$advance>365||$cutoff<0||$cutoff>720||
            strlen($requestId)<16||strlen($requestId)>190) {
            throw new InvalidArgumentException('Booking notice, advance and cancellation limits are outside the supported range.');
        }
        if($mode==='automatic' && !$this->features->enabled('bookings.automatic')) {
            throw new DomainException('Automatic booking cannot be selected until all independent operational feature gates are approved.');
        }
        $this->pdo->beginTransaction();
        try {
            $query=$this->pdo->prepare('SELECT setting_json,version FROM deployment_settings WHERE setting_key=:key LIMIT 1 FOR UPDATE');
            $query->execute(['key'=>'booking.rules']);
            $row=$query->fetch(PDO::FETCH_ASSOC);
            $before=is_array($row)?json_decode((string)$row['setting_json'],true):[];
            if(!is_array($before)) throw new DomainException('Stored booking settings are corrupt.');
            $after=[
                'automatic_enabled'=>$mode==='automatic',
                'auto_confirm_enabled'=>$input['auto_confirm_enabled'],
                'deposit_required'=>$input['auto_requires_verified_deposit'],
                'minimum_notice_minutes'=>$minimum*60,
                'maximum_advance_days'=>$advance,
                'minimum_duration_minutes'=>(int)($before['minimum_duration_minutes']??60),
                'maximum_duration_minutes'=>(int)($before['maximum_duration_minutes']??480),
                'client_cancellation_enabled'=>$input['client_cancellation_enabled'],
                'client_cancellation_cutoff_minutes'=>$cutoff*60,
            ];
            $version=is_array($row)?(int)$row['version']+1:1;
            $upsert=$this->pdo->prepare(
                'INSERT INTO deployment_settings (setting_key,setting_json,version,updated_by,updated_at)
                 VALUES ("booking.rules",:json,:version,:actor,UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE setting_json=VALUES(setting_json),version=VALUES(version),updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)'
            );
            $upsert->execute(['json'=>json_encode($after,JSON_THROW_ON_ERROR),'version'=>$version,'actor'=>$actor->id]);
            $this->audit->record($actor->id,'booking.settings.updated','deployment_settings','booking.rules',
                'Super Admin or booking administrator updated booking policy settings.',
                is_array($before)?$before:null,$after,$requestId,'booking-settings-v1');
            $this->pdo->commit();
        } catch(\Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    private function authorize(Principal $actor): void
    {
        Authorization::require($actor,'bookings.manage',true);
        if(!$actor->hasRole('super_admin')&&!$actor->hasRole('owner')&&!$actor->hasRole('admin')) {
            throw new DomainException('Only an MFA-verified booking administrator can manage booking settings.');
        }
    }
}
