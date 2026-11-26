<?php
declare(strict_types=1);

namespace Talyvane\Security;

final class Authorization
{
    private const GRANTS = [
        'super_admin' => ['*'],
        'owner' => ['dashboard.view','site.content.manage','profiles.manage','media.review','enquiries.manage','clients.manage','bookings.manage','model_applications.manage','model_agreements.manage','model_portfolio.manage','model_castings.manage','model_assignments.manage','model_settlements.manage','safeguarding.report','safeguarding.governance','privacy.requests.review'],
        'admin' => ['dashboard.view','site.content.manage','profiles.manage','media.review','enquiries.manage','clients.manage','bookings.manage','model_applications.manage','model_agreements.manage','model_portfolio.manage','model_castings.manage','model_assignments.manage','safeguarding.report','privacy.requests.review'],
        'finance' => ['model_settlements.view','model_settlements.expense.review','model_settlements.approve','model_settlements.payment.attest','model_settlements.reverse.attest','model_settlements.dispute'],
        'verifier' => ['model_applications.adult_eligibility.verify','companion.adult_eligibility.verify'],
        'safeguarding' => ['safeguarding.cases.assigned.view','safeguarding.cases.assigned.update'],
        'client' => ['client.self.view','client.self.update','client.booking.create','client.booking.manage','client.favorites.manage','client.privacy.manage'],
        'companion' => ['companion.self.view','companion.booking.decide','companion.consent.manage','companion.availability.manage'],
    ];

    private const NEVER_GRANT = [
        'security.authorization.bypass',
        'security.audit.disable',
        'safeguarding.adult_only.disable',
        'safeguarding.consent.bypass',
        'market.approval.bypass',
        'payments.verification.bypass',
        'privacy.evidence.unrestricted',
    ];

    public static function allows(Principal $principal, string $permission, bool $requiresMfa = true): bool
    {
        if (in_array($permission, self::NEVER_GRANT, true)) {
            return false;
        }
        $privileged = array_intersect($principal->roles, ['super_admin','owner','admin','finance','verifier','safeguarding']) !== [];
        if ($requiresMfa && $privileged && !$principal->mfaVerified) {
            return false;
        }
        foreach ($principal->roles as $role) {
            $grants = self::GRANTS[$role] ?? [];
            if (in_array('*', $grants, true) || in_array($permission, $grants, true)) {
                return true;
            }
        }
        return false;
    }

    public static function require(Principal $principal, string $permission, bool $requiresMfa = true): void
    {
        if (!self::allows($principal, $permission, $requiresMfa)) {
            throw new \DomainException('Forbidden');
        }
    }
}
