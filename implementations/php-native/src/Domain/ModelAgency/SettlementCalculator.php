<?php
declare(strict_types=1);

namespace Talyvane\Domain\ModelAgency;

final class SettlementCalculator
{
    /** @return array{gross_minor:int,approved_expenses_minor:int,commission_minor:int,model_net_minor:int,currency:string,commission_basis_points:int} */
    public static function calculate(int $grossMinor,int $expenseCapMinor,int $approvedExpensesMinor,int $commissionBasisPoints,string $currency): array
    {
        if($grossMinor<0 || $expenseCapMinor<0 || $approvedExpensesMinor<0 ||
            $commissionBasisPoints<0 || $commissionBasisPoints>10000 ||
            preg_match('/^[A-Z]{3}$/D',$currency)!==1) {
            throw new \InvalidArgumentException('Settlement inputs are invalid.');
        }
        if($approvedExpensesMinor>$expenseCapMinor) {
            throw new \DomainException('Approved expenses exceed the assignment expense cap.');
        }
        // Integer-only basis-point calculation with half-up rounding; no binary floats.
        $whole=intdiv($grossMinor,10000)*$commissionBasisPoints;
        $fraction=intdiv((($grossMinor%10000)*$commissionBasisPoints)+5000,10000);
        if($fraction>PHP_INT_MAX-$whole) throw new \DomainException('Commission calculation exceeds the supported integer range.');
        $commission=$whole+$fraction;
        if($commission>$grossMinor) throw new \DomainException('Commission exceeds gross compensation.');
        $netBeforeReimbursement=$grossMinor-$commission;
        if($approvedExpensesMinor>PHP_INT_MAX-$netBeforeReimbursement) {
            throw new \DomainException('Settlement reimbursement total exceeds the supported integer range.');
        }
        // Approved expenses are reimbursed separately from compensation, after agency commission.
        $net=$netBeforeReimbursement+$approvedExpensesMinor;
        return [
            'gross_minor'=>$grossMinor,
            'approved_expenses_minor'=>$approvedExpensesMinor,
            'commission_minor'=>$commission,
            'model_net_minor'=>$net,
            'currency'=>$currency,
            'commission_basis_points'=>$commissionBasisPoints,
        ];
    }
}
