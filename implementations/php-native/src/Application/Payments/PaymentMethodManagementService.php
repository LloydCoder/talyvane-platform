<?php
declare(strict_types=1);

namespace Talyvane\Application\Payments;

use DomainException;
use InvalidArgumentException;
use PDO;
use Talyvane\Features\FeatureAvailability;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Security\Authorization;
use Talyvane\Security\Principal;

final class PaymentMethodManagementService
{
    public function __construct(private PDO $pdo,private AuditLogger $audit,private FeatureAvailability $features) {}

    /** @return list<array<string,mixed>> */
    public function list(Principal $actor): array
    {
        $this->authorize($actor);
        return $this->pdo->query(
            'SELECT id,method_key,method_type,asset_code,network_code,token_contract,receiving_address,
                    purpose,status,provider_approval_reference,legal_approval_reference,reviewed_by,reviewed_at,created_at
             FROM payment_methods ORDER BY created_at DESC LIMIT 200'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(
        Principal $actor,string $asset,string $network,string $address,?string $contract,string $purpose,string $requestId
    ): string {
        $this->authorize($actor);
        $asset=strtoupper(trim($asset));
        $network=trim($network);
        $address=trim($address);
        $contract=$contract===null?null:trim($contract);
        if(!in_array($asset,['BTC','USDT','USDC'],true) || strlen($network)<2 || strlen($network)>80 ||
            strlen($address)<16 || strlen($address)>500 ||
            (in_array($asset,['USDT','USDC'],true) && ($contract===null || $contract==='')) ||
            ($contract!==null && strlen($contract)>190) ||
            !in_array($purpose,['booking','membership'],true)) {
            throw new InvalidArgumentException('Enter a supported asset, network, receiving address, token contract where required, and payment purpose.');
        }
        $this->assertRequest($requestId);
        $id=self::uuid();
        $this->pdo->beginTransaction();
        try {
            $insert=$this->pdo->prepare(
                'INSERT INTO payment_methods
                 (id,method_key,method_type,asset_code,network_code,token_contract,receiving_address,processor_key,purpose,status,created_at,updated_at)
                 VALUES (:id,:key,"crypto",:asset,:network,:contract,:address,NULL,:purpose,"disabled",UTC_TIMESTAMP(),UTC_TIMESTAMP())'
            );
            $insert->execute([
                'id'=>$id,'key'=>$id,'asset'=>$asset,'network'=>$network,'contract'=>$contract,
                'address'=>$address,'purpose'=>$purpose,
            ]);
            $this->audit->record($actor->id,'payment.method.created','payment_method',$id,
                'Crypto receiving method added disabled pending independent provider and legal review.',
                null,['asset_code'=>$asset,'network_code'=>$network,'purpose'=>$purpose,'status'=>'disabled'],
                $requestId,'payment-method-v1');
            $this->pdo->commit();
            return $id;
        } catch(\Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function review(Principal $actor,string $id,string $providerReference,string $legalReference,string $requestId): void
    {
        $this->authorize($actor);
        $providerReference=trim($providerReference);
        $legalReference=trim($legalReference);
        if(strlen($providerReference)<8 || strlen($providerReference)>500 ||
            strlen($legalReference)<8 || strlen($legalReference)>500) {
            throw new InvalidArgumentException('Separate current provider and legal approval references are required.');
        }
        $this->assertRequest($requestId);
        $this->pdo->beginTransaction();
        try {
            $query=$this->pdo->prepare('SELECT id,status FROM payment_methods WHERE id=:id AND method_type="crypto" LIMIT 1 FOR UPDATE');
            $query->execute(['id'=>$id]);
            $row=$query->fetch(PDO::FETCH_ASSOC);
            if(!is_array($row)) throw new DomainException('Crypto receiving method was not found.');
            if($row['status']==='enabled') throw new DomainException('Disable the receiving method before changing its approval evidence.');
            $update=$this->pdo->prepare(
                'UPDATE payment_methods SET status="reviewed",provider_approval_reference=:provider,
                 legal_approval_reference=:legal,reviewed_by=:actor,reviewed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
                 WHERE id=:id'
            );
            $update->execute(['provider'=>$providerReference,'legal'=>$legalReference,'actor'=>$actor->id,'id'=>$id]);
            $this->audit->record($actor->id,'payment.method.reviewed','payment_method',$id,
                'Receiving method evidence references recorded; this is not transaction verification.',
                ['status'=>$row['status']],['status'=>'reviewed','provider_reference_present'=>true,'legal_reference_present'=>true],
                $requestId,'payment-method-v1');
            $this->pdo->commit();
        } catch(\Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function toggle(Principal $actor,string $id,bool $enabled,string $requestId): void
    {
        $this->authorize($actor);
        $this->assertRequest($requestId);
        $this->pdo->beginTransaction();
        try {
            $query=$this->pdo->prepare(
                'SELECT id,asset_code,purpose,status,provider_approval_reference,legal_approval_reference
                 FROM payment_methods WHERE id=:id AND method_type="crypto" LIMIT 1 FOR UPDATE'
            );
            $query->execute(['id'=>$id]);
            $row=$query->fetch(PDO::FETCH_ASSOC);
            if(!is_array($row)) throw new DomainException('Crypto receiving method was not found.');
            if($enabled) {
                $settings=$this->pdo->query('SELECT payments_enabled,crypto_enabled,booking_payments_enabled,membership_payments_enabled FROM payment_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC);
                if(!is_array($settings) || !(bool)$settings['payments_enabled'] || !(bool)$settings['crypto_enabled']) {
                    throw new DomainException('Global payments and cryptocurrency configuration must be enabled first.');
                }
                $purposeAllowed=$row['purpose']==='booking'?(bool)$settings['booking_payments_enabled']:(bool)$settings['membership_payments_enabled'];
                if(!$purposeAllowed || $row['status']!=='reviewed' ||
                    !is_string($row['provider_approval_reference']) || $row['provider_approval_reference']==='' ||
                    !is_string($row['legal_approval_reference']) || $row['legal_approval_reference']==='' ||
                    !$this->features->enabled('payments.crypto')) {
                    throw new DomainException('Independent provider/legal approval, reviewed method evidence, matching payment purpose and all crypto feature gates must be satisfied before enabling.');
                }
            }
            $next=$enabled?'enabled':(
                $row['provider_approval_reference']!==null && $row['legal_approval_reference']!==null?'reviewed':'disabled'
            );
            $update=$this->pdo->prepare('UPDATE payment_methods SET status=:status,updated_at=UTC_TIMESTAMP() WHERE id=:id');
            $update->execute(['status'=>$next,'id'=>$id]);
            $this->audit->record($actor->id,$enabled?'payment.method.enabled':'payment.method.disabled','payment_method',$id,
                'Super Admin changed receiving-method availability; no funds were moved.',
                ['status'=>$row['status']],['status'=>$next],$requestId,'payment-method-v1');
            $this->pdo->commit();
        } catch(\Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    private function authorize(Principal $actor): void
    {
        Authorization::require($actor,'payments.configuration',true);
        if(!$actor->hasOnlyRole('super_admin') || !$actor->mfaVerified) {
            throw new DomainException('Only the MFA-verified deployment Super Admin can manage payment methods.');
        }
    }

    private function assertRequest(string $requestId): void
    {
        if(strlen($requestId)<16 || strlen($requestId)>190) throw new InvalidArgumentException('Invalid request reference.');
    }

    private static function uuid(): string
    {
        $bytes=random_bytes(16);$bytes[6]=chr((ord($bytes[6])&0x0f)|0x40);$bytes[8]=chr((ord($bytes[8])&0x3f)|0x80);
        $hex=bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }
}
