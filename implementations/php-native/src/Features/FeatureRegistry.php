<?php
declare(strict_types=1);

namespace Talyvane\Features;

final class FeatureRegistry
{
    /** Hard safeguards are intentionally not configurable feature flags. */
    public const HARD_INVARIANTS = [
        'security.authorization', 'security.audit_integrity', 'security.admin_mfa',
        'privacy.evidence_access', 'safeguarding.adult_only', 'safeguarding.consent',
        'safeguarding.market_approval', 'payments.transaction_integrity',
    ];

    /** @return array<string,array{module:string,default:bool,risk:string,dependencies:list<string>}> */
    public static function definitions(): array
    {
        return [
            'public.discovery' => ['module'=>'discovery','default'=>true,'risk'=>'medium','dependencies'=>[]],
            'cms.pages' => ['module'=>'content','default'=>true,'risk'=>'low','dependencies'=>[]],
            'bookings.manual' => ['module'=>'booking','default'=>true,'risk'=>'high','dependencies'=>['market.policy']],
            'bookings.automatic' => ['module'=>'booking','default'=>false,'risk'=>'critical','dependencies'=>['bookings.manual','market.policy','verification.age','consent.current']],
            'memberships' => ['module'=>'client-portal','default'=>false,'risk'=>'high','dependencies'=>[]],
            'payments.configuration' => ['module'=>'payments','default'=>false,'risk'=>'critical','dependencies'=>['market.policy']],
            'model-agency.applications' => ['module'=>'model-agency','default'=>false,'risk'=>'high','dependencies'=>['market.policy']],
            'model-agency.usage-rights' => ['module'=>'model-agency','default'=>false,'risk'=>'critical','dependencies'=>['model-agency.applications']],
            'model-agency.casting' => ['module'=>'model-agency','default'=>false,'risk'=>'high','dependencies'=>['model-agency.usage-rights']],
            'model-agency.assignments' => ['module'=>'model-agency','default'=>false,'risk'=>'high','dependencies'=>['model-agency.casting']],
            'model-agency.settlements' => ['module'=>'model-agency','default'=>false,'risk'=>'critical','dependencies'=>['model-agency.assignments']],
        ];
    }

    public static function known(string $key): bool
    {
        return array_key_exists($key, self::definitions());
    }
}
