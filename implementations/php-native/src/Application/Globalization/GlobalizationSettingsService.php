<?php
declare(strict_types=1);

namespace Talyvane\Application\Globalization;

use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use PDO;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Security\Authorization;
use Talyvane\Security\Principal;

final class GlobalizationSettingsService
{
    public function __construct(private PDO $pdo,private AuditLogger $audit) {}

    /** @return array{settings:array<string,mixed>,currencies:list<array<string,mixed>>} */
    public function catalog(Principal $actor): array
    {
        $this->authorize($actor);
        $settings=$this->pdo->query(
            'SELECT s.base_currency_code,s.default_locale,s.default_timezone,s.updated_at,
                    c.name AS base_currency_name,c.symbol AS base_currency_symbol
             FROM deployment_globalization_settings s
             LEFT JOIN currencies c ON c.code=s.base_currency_code WHERE s.id=1'
        )->fetch(PDO::FETCH_ASSOC);
        if(!is_array($settings)) throw new DomainException('Deployment globalization settings are not initialized.');
        $currencies=$this->pdo->query(
            'SELECT code,name,symbol,minor_units,enabled,dataset_version
             FROM currencies ORDER BY code'
        )->fetchAll(PDO::FETCH_ASSOC);
        return ['settings'=>$settings,'currencies'=>$currencies];
    }

    public function updateDefaults(
        Principal $actor,?string $baseCurrency,string $locale,string $timezone,string $reason,string $requestId
    ): void {
        $this->authorize($actor);
        $locale=trim($locale);
        $timezone=trim($timezone);
        $reason=trim($reason);
        if($baseCurrency!==null) {
            $baseCurrency=strtoupper(trim($baseCurrency));
            if(!preg_match('/^[A-Z]{3}$/D',$baseCurrency)) throw new InvalidArgumentException('Choose a valid ISO 4217 base currency.');
        }
        if(!preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/D',$locale) || strlen($locale)>35) {
            throw new InvalidArgumentException('Enter a valid BCP 47 default locale.');
        }
        if(!in_array($timezone,DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC),true) && $timezone!=='UTC') {
            throw new InvalidArgumentException('Choose a valid IANA time zone.');
        }
        $this->assertReason($reason,$requestId);
        $this->pdo->beginTransaction();
        try {
            $lock=$this->pdo->query('SELECT base_currency_code,default_locale,default_timezone FROM deployment_globalization_settings WHERE id=1 FOR UPDATE');
            $before=$lock->fetch(PDO::FETCH_ASSOC);
            if(!is_array($before)) throw new DomainException('Deployment globalization settings are not initialized.');
            if($baseCurrency!==null) {
                $currency=$this->pdo->prepare('SELECT enabled FROM currencies WHERE code=:code FOR UPDATE');
                $currency->execute(['code'=>$baseCurrency]);
                if(!(bool)$currency->fetchColumn()) throw new DomainException('The reporting currency must be present in the ISO reference data and enabled before selection.');
            }
            $update=$this->pdo->prepare(
                'UPDATE deployment_globalization_settings
                 SET base_currency_code=:currency,default_locale=:locale,default_timezone=:timezone,
                     updated_by=:actor,updated_at=UTC_TIMESTAMP() WHERE id=1'
            );
            $update->execute(['currency'=>$baseCurrency,'locale'=>$locale,'timezone'=>$timezone,'actor'=>$actor->id]);
            $this->audit->record($actor->id,'globalization.defaults.updated','deployment_globalization_settings','1',$reason,
                $before,['base_currency_code'=>$baseCurrency,'default_locale'=>$locale,'default_timezone'=>$timezone],
                $requestId,'globalization-defaults-v1');
            $this->pdo->commit();
        } catch(\Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function toggleCurrency(Principal $actor,string $code,bool $enabled,string $reason,string $requestId): void
    {
        $this->authorize($actor);
        $code=strtoupper(trim($code));
        $reason=trim($reason);
        if(!preg_match('/^[A-Z]{3}$/D',$code)) throw new InvalidArgumentException('Choose a valid ISO 4217 currency code.');
        $this->assertReason($reason,$requestId);
        $this->pdo->beginTransaction();
        try {
            $settings=$this->pdo->query('SELECT base_currency_code FROM deployment_globalization_settings WHERE id=1 FOR UPDATE')->fetch(PDO::FETCH_ASSOC);
            if(!is_array($settings)) throw new DomainException('Deployment globalization settings are not initialized.');
            $query=$this->pdo->prepare('SELECT enabled FROM currencies WHERE code=:code FOR UPDATE');
            $query->execute(['code'=>$code]);
            $before=$query->fetchColumn();
            if($before===false) throw new DomainException('Currency does not exist in the pinned ISO reference dataset.');
            if(!$enabled && $settings['base_currency_code']===$code) {
                throw new DomainException('Choose another reporting currency before disabling this currency.');
            }
            $update=$this->pdo->prepare('UPDATE currencies SET enabled=:enabled,updated_at=UTC_TIMESTAMP() WHERE code=:code');
            $update->execute(['enabled'=>$enabled?1:0,'code'=>$code]);
            $this->audit->record($actor->id,'globalization.currency.availability_changed','currency',$code,$reason,
                ['enabled'=>(bool)$before],['enabled'=>$enabled],$requestId,'globalization-currency-v1');
            $this->pdo->commit();
        } catch(\Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    private function authorize(Principal $actor): void
    {
        Authorization::require($actor,'globalization.manage',true);
        if(!$actor->hasOnlyRole('super_admin')) throw new DomainException('Only the deployment Super Admin can change globalization defaults.');
    }

    private function assertReason(string $reason,string $requestId): void
    {
        if(mb_strlen($reason,'UTF-8')<20 || mb_strlen($reason,'UTF-8')>500 ||
            strlen($requestId)<16 || strlen($requestId)>190) {
            throw new InvalidArgumentException('A 20–500 character reason and valid request reference are required.');
        }
    }
}
