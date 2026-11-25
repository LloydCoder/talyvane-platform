<?php
declare(strict_types=1);

namespace Talyvane\Features;

final class FeatureRegistry
{
    /** Hard safeguards are invariants, not configurable feature flags. */
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
            'cms.navigation' => ['module'=>'content','default'=>true,'risk'=>'low','dependencies'=>['cms.pages']],
            'crm.enquiries' => ['module'=>'crm','default'=>true,'risk'=>'medium','dependencies'=>[]],
            'bookings.manual' => ['module'=>'booking','default'=>true,'risk'=>'high','dependencies'=>['market.policy']],
            'bookings.automatic' => ['module'=>'booking','default'=>false,'risk'=>'critical','dependencies'=>['bookings.manual','market.policy','verification.age','consent.current','safeguarding.ready']],
            'bookings.calendar_sync' => ['module'=>'booking','default'=>false,'risk'=>'high','dependencies'=>['bookings.manual','provider.approved']],
            'client.portal' => ['module'=>'client-portal','default'=>true,'risk'=>'medium','dependencies'=>[]],
            'client.favorites' => ['module'=>'client-portal','default'=>true,'risk'=>'low','dependencies'=>['client.portal']],
            'memberships' => ['module'=>'memberships','default'=>false,'risk'=>'high','dependencies'=>['client.portal']],
            'memberships.private_gallery' => ['module'=>'memberships','default'=>false,'risk'=>'critical','dependencies'=>['memberships','consent.current','market.policy']],
            'companion.portal' => ['module'=>'companion-portal','default'=>true,'risk'=>'high','dependencies'=>[]],
            'verification.age' => ['module'=>'verification','default'=>false,'risk'=>'critical','dependencies'=>['provider.approved']],
            'safeguarding.cases' => ['module'=>'safeguarding','default'=>true,'risk'=>'critical','dependencies'=>[]],
            'payments.configuration' => ['module'=>'payments','default'=>false,'risk'=>'critical','dependencies'=>['market.policy']],
            'payments.manual' => ['module'=>'payments','default'=>false,'risk'=>'high','dependencies'=>['payments.configuration','market.policy']],
            'payments.crypto' => ['module'=>'payments','default'=>false,'risk'=>'critical','dependencies'=>['payments.configuration','market.policy','provider.approved','legal.approved']],
            'payments.cards' => ['module'=>'payments','default'=>false,'risk'=>'critical','dependencies'=>['payments.configuration','market.policy','provider.approved','legal.approved']],
            'model-agency.applications' => ['module'=>'model-agency','default'=>false,'risk'=>'high','dependencies'=>['market.policy','legal.approved']],
            'model-agency.usage-rights' => ['module'=>'model-agency','default'=>false,'risk'=>'critical','dependencies'=>['model-agency.applications','consent.current']],
            'model-agency.casting' => ['module'=>'model-agency','default'=>false,'risk'=>'high','dependencies'=>['model-agency.usage-rights','market.policy']],
            'model-agency.assignments' => ['module'=>'model-agency','default'=>false,'risk'=>'high','dependencies'=>['model-agency.casting']],
            'model-agency.settlements' => ['module'=>'model-agency','default'=>false,'risk'=>'critical','dependencies'=>['model-agency.assignments']],
            'globalization.configuration' => ['module'=>'globalization','default'=>true,'risk'=>'medium','dependencies'=>[]],
            'reports.operations' => ['module'=>'reporting','default'=>true,'risk'=>'medium','dependencies'=>[]],
            'integrations.email' => ['module'=>'integrations','default'=>false,'risk'=>'high','dependencies'=>['provider.approved']],
            'integrations.exchange_rates' => ['module'=>'integrations','default'=>false,'risk'=>'high','dependencies'=>['provider.approved']],
            'integrations.tax' => ['module'=>'integrations','default'=>false,'risk'=>'critical','dependencies'=>['provider.approved','legal.approved']],
        ];
    }

    public static function known(string $key): bool
    {
        return array_key_exists($key, self::definitions());
    }
}
