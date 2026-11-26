<?php
declare(strict_types=1);

namespace Talyvane\Application\Payments;

use DomainException;
use InvalidArgumentException;
use PDO;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Security\Authorization;
use Talyvane\Security\Principal;

final class PaymentSettingsService
{
    private const FIELDS=[
        'payments_enabled','crypto_enabled','cards_enabled','manual_enabled',
        'booking_payments_enabled','membership_payments_enabled',
    ];

    public function __construct(private PDO $pdo,private AuditLogger $audit) {}

    /** @return array<string,mixed> */
    public function current(Principal $actor): array
    {
        $this->authorize($actor);
        $row=$this->pdo->query('SELECT payments_enabled,crypto_enabled,cards_enabled,manual_enabled,booking_payments_enabled,membership_payments_enabled,updated_at FROM payment_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row)) throw new DomainException('Payment settings are not initialized.');
        foreach(self::FIELDS as $field) $row[$field]=(bool)$row[$field];
        return $row;
    }

    /** @param array<string,bool> $settings */
    public function update(Principal $actor,array $settings,string $requestId): void
    {
        $this->authorize($actor);
        if(array_diff(array_keys($settings),self::FIELDS)!==[] || array_diff(self::FIELDS,array_keys($settings))!==[]) {
            throw new InvalidArgumentException('All six payment configuration flags must be supplied.');
        }
        foreach($settings as $value) if(!is_bool($value)) throw new InvalidArgumentException('Payment settings must be boolean values.');
        if(strlen($requestId)<16 || strlen($requestId)>190) throw new InvalidArgumentException('Invalid request reference.');
        $this->pdo->beginTransaction();
        try {
            $query=$this->pdo->query('SELECT payments_enabled,crypto_enabled,cards_enabled,manual_enabled,booking_payments_enabled,membership_payments_enabled FROM payment_settings WHERE id=1 FOR UPDATE');
            $before=$query->fetch(PDO::FETCH_ASSOC);
            if(!is_array($before)) throw new DomainException('Payment settings are not initialized.');
            foreach($before as $key=>$value) $before[$key]=(bool)$value;
            $update=$this->pdo->prepare(
                'UPDATE payment_settings SET payments_enabled=:payments,crypto_enabled=:crypto,cards_enabled=:cards,
                 manual_enabled=:manual,booking_payments_enabled=:bookings,membership_payments_enabled=:memberships,
                 updated_by=:actor,updated_at=UTC_TIMESTAMP() WHERE id=1'
            );
            $update->execute([
                'payments'=>$settings['payments_enabled']?1:0,'crypto'=>$settings['crypto_enabled']?1:0,
                'cards'=>$settings['cards_enabled']?1:0,'manual'=>$settings['manual_enabled']?1:0,
                'bookings'=>$settings['booking_payments_enabled']?1:0,'memberships'=>$settings['membership_payments_enabled']?1:0,
                'actor'=>$actor->id,
            ]);
            $this->audit->record($actor->id,'payment.configuration.updated','payment_settings','1',
                'Super Admin updated payment feature configuration; no provider or settlement capability is implied.',
                $before,$settings,$requestId,'payment-settings-v1');
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
            throw new DomainException('Only the MFA-verified deployment Super Admin can manage payment settings.');
        }
    }
}
