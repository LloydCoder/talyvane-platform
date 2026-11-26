<?php
declare(strict_types=1);

namespace Talyvane\Application\ModelAgency;

use Closure;
use PDO;
use Throwable;
use Talyvane\Domain\ModelAgency\SettlementCalculator;
use Talyvane\Domain\ModelAgency\SettlementStateMachine;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Security\Authorization;
use Talyvane\Security\Principal;
use Talyvane\Security\SecretBox;

/** Reconciled settlement ledger and external-payment attestations; never transfers or holds funds. */
final class ModelSettlementService
{
    private readonly Closure $settlementsEnabled;

    /** @param callable():bool $settlementsEnabled */
    public function __construct(private PDO $pdo,private AuditLogger $audit,private SecretBox $cipher,callable $settlementsEnabled)
    {
        $this->settlementsEnabled=Closure::fromCallable($settlementsEnabled);
    }

    public function addExpense(Principal $actor,string $assignmentId,string $amount,string $currency,string $description,string $evidenceReference,string $requestId): string
    {
        $this->authorize($actor);$this->requirePermission($actor,'model_settlements.expense.submit');$this->assertFeature();$this->assertRequestId($requestId);
        $description=trim($description);
        if(strlen($description)<12 || strlen($description)>500) throw new \InvalidArgumentException('Expense description must contain 12 to 500 characters.');
        $this->assertEvidenceReference($evidenceReference,'private/model-expenses/');
        $this->pdo->beginTransaction();
        try {
            $assignment=$this->lock('model_assignments',$assignmentId);
            if(!in_array($assignment['status'],['in_progress','completed'],true) ||
                $assignment['currency_code']!==$currency || (int)$assignment['expense_cap_minor']<=0) {
                throw new \DomainException('Expenses require an active or completed assignment with an agreed expense cap and currency.');
            }
            if($this->hasSettlement($assignmentId)) throw new \DomainException('Expenses cannot be added after a settlement draft exists.');
            $precision=$this->currencyPrecision($currency);
            $amountMinor=$this->decimalToMinor($amount,$precision);
            if($amountMinor<=0) throw new \InvalidArgumentException('Expense amount must be greater than zero.');
            $sum=$this->pdo->prepare('SELECT amount_minor FROM model_assignment_expenses WHERE assignment_id=:assignment AND status IN ("pending","approved") FOR UPDATE');
            $sum->execute(['assignment'=>$assignmentId]);$existing=$this->sumRows($sum->fetchAll(PDO::FETCH_COLUMN));
            if($amountMinor>(int)$assignment['expense_cap_minor']-$existing) throw new \DomainException('Expense claim exceeds the remaining assignment expense cap.');
            $id=self::uuid();
            $this->pdo->prepare(
                'INSERT INTO model_assignment_expenses
                 (id,assignment_id,settlement_id,amount_minor,currency_code,description,private_evidence_key,status,
                  created_by,submitted_by,created_at,updated_at)
                 VALUES (:id,:assignment,NULL,:amount,:currency,:description,:evidence,"pending",:actor,:actor,UTC_TIMESTAMP(),UTC_TIMESTAMP())'
            )->execute([
                'id'=>$id,'assignment'=>$assignmentId,'amount'=>$amountMinor,'currency'=>$currency,
                'description'=>$this->encrypt($id,'description',$description),
                'evidence'=>$this->encrypt($id,'private_evidence_key',$evidenceReference),'actor'=>$actor->id,
            ]);
            $this->event($assignmentId,null,$id,$actor->id,$this->actorRole($actor),'expense.submitted','Expense claim submitted for independent finance review.');
            $this->audit->record($actor->id,'model_expense.submitted','model_assignment_expense',$id,
                'Submitted an assignment expense claim',null,
                ['assignment_id'=>$assignmentId,'amount_minor'=>$amountMinor,'currency'=>$currency,'evidence_present'=>true],
                $requestId,'model-settlement-v1');
            $this->pdo->commit();return $id;
        } catch(Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function reviewExpense(Principal $actor,string $expenseId,string $decision,string $note,string $requestId): void
    {
        $this->authorize($actor);$this->requirePermission($actor,'model_settlements.expense.review');$this->assertFeature();$this->assertRequestId($requestId);$note=trim($note);
        if(!in_array($decision,['approved','rejected'],true) || strlen($note)<20 || strlen($note)>1500) {
            throw new \InvalidArgumentException('Choose an expense decision and provide a review note of 20 to 1500 characters.');
        }
        $this->pdo->beginTransaction();
        try {
            $expense=$this->lock('model_assignment_expenses',$expenseId);
            if($expense['status']!=='pending' || $expense['submitted_by']===$actor->id) {
                throw new \DomainException('Expense review requires a pending claim and an independent reviewer.');
            }
            $assignment=$this->lock('model_assignments',(string)$expense['assignment_id']);
            if(!in_array($assignment['status'],['in_progress','completed'],true) || $this->hasSettlement((string)$assignment['id'])) {
                throw new \DomainException('Expense review is closed after assignment completion settlement has been drafted.');
            }
            if($decision==='approved') {
                $sum=$this->pdo->prepare('SELECT amount_minor FROM model_assignment_expenses WHERE assignment_id=:assignment AND status="approved" FOR UPDATE');
                $sum->execute(['assignment'=>$assignment['id']]);$approved=$this->sumRows($sum->fetchAll(PDO::FETCH_COLUMN));
                if((int)$expense['amount_minor']>(int)$assignment['expense_cap_minor']-$approved) {
                    throw new \DomainException('Approved expenses would exceed the assignment cap.');
                }
            }
            $this->pdo->prepare(
                'UPDATE model_assignment_expenses SET status=:status,reviewed_by=:actor,reviewed_at=UTC_TIMESTAMP(),
                 review_note=:note,updated_at=UTC_TIMESTAMP() WHERE id=:id'
            )->execute([
                'status'=>$decision,'actor'=>$actor->id,
                'note'=>$this->encrypt($expenseId,'review_note',$note),'id'=>$expenseId,
            ]);
            $this->event((string)$assignment['id'],null,$expenseId,$actor->id,$this->actorRole($actor),'expense.'.$decision,'Expense claim '.$decision.' by independent finance review.');
            $this->audit->record($actor->id,'model_expense.'.$decision,'model_assignment_expense',$expenseId,
                'Recorded an independent expense review',['status'=>'pending'],['status'=>$decision,'note_sha256'=>hash('sha256',$note)],
                $requestId,'model-settlement-v1');
            $this->pdo->commit();
        } catch(Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function createDraft(Principal $actor,string $assignmentId,string $requestId): string
    {
        $this->authorize($actor);$this->requirePermission($actor,'model_settlements.create');$this->assertFeature();$this->assertRequestId($requestId);
        $this->pdo->beginTransaction();
        try {
            $assignment=$this->lock('model_assignments',$assignmentId);
            if($assignment['status']!=='completed' || $assignment['compensation_minor']===null ||
                (int)$assignment['compensation_minor']<=0 || !is_string($assignment['currency_code']) ||
                $this->hasSettlement($assignmentId)) {
                throw new \DomainException('Settlement requires a completed assignment with agreed positive compensation and no existing settlement.');
            }
            $pending=$this->pdo->prepare('SELECT COUNT(*) FROM model_assignment_expenses WHERE assignment_id=:assignment AND status="pending"');
            $pending->execute(['assignment'=>$assignmentId]);
            if((int)$pending->fetchColumn()>0) throw new \DomainException('All expense claims must be reviewed before settlement calculation.');
            $approved=$this->pdo->prepare('SELECT amount_minor FROM model_assignment_expenses WHERE assignment_id=:assignment AND status="approved" FOR UPDATE');
            $approved->execute(['assignment'=>$assignmentId]);$expenses=$this->sumRows($approved->fetchAll(PDO::FETCH_COLUMN));
            $precision=$this->currencyPrecision((string)$assignment['currency_code']);
            $policy=$this->approvedPolicy();
            $calculation=SettlementCalculator::calculate(
                (int)$assignment['compensation_minor'],(int)$assignment['expense_cap_minor'],$expenses,
                (int)$assignment['commission_basis_points'],(string)$assignment['currency_code']
            );
            $snapshot=$calculation+[
                'currency_precision'=>$precision,'assignment_id'=>$assignmentId,
                'assignment_policy_version'=>$assignment['assignment_policy_version'],
                'settlement_policy_version'=>$policy['policy_version'],
            ];
            $id=self::uuid();$reference='MST-'.gmdate('Y').'-'.strtoupper(bin2hex(random_bytes(6)));
            $this->pdo->prepare(
                'INSERT INTO model_settlements
                 (id,settlement_reference,assignment_id,application_id,currency_code,currency_precision,
                  gross_minor,approved_expenses_minor,commission_minor,commission_basis_points,model_net_minor,
                  calculation_snapshot,settlement_policy_version,status,created_by,created_at,updated_at)
                 VALUES (:id,:reference,:assignment,:application,:currency,:precision,:gross,:expenses,:commission,
                  :basis_points,:net,:snapshot,:policy,"draft",:actor,UTC_TIMESTAMP(),UTC_TIMESTAMP())'
            )->execute([
                'id'=>$id,'reference'=>$reference,'assignment'=>$assignmentId,'application'=>$assignment['application_id'],
                'currency'=>$assignment['currency_code'],'precision'=>$precision,'gross'=>$calculation['gross_minor'],
                'expenses'=>$calculation['approved_expenses_minor'],'commission'=>$calculation['commission_minor'],
                'basis_points'=>$calculation['commission_basis_points'],'net'=>$calculation['model_net_minor'],
                'snapshot'=>json_encode($snapshot,JSON_THROW_ON_ERROR),'policy'=>$policy['policy_version'],'actor'=>$actor->id,
            ]);
            $this->pdo->prepare('UPDATE model_assignment_expenses SET settlement_id=:settlement,updated_at=UTC_TIMESTAMP() WHERE assignment_id=:assignment AND status="approved"')
                ->execute(['settlement'=>$id,'assignment'=>$assignmentId]);
            $this->event($assignmentId,$id,null,$actor->id,$this->actorRole($actor),'settlement.drafted','Settlement calculation snapshot created; no payment has been made.');
            $this->audit->record($actor->id,'model_settlement.created','model_settlement',$id,
                'Created a settlement calculation draft',null,$snapshot,$requestId,'model-settlement-v1');
            $this->pdo->commit();return $id;
        } catch(Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function approve(Principal $actor,string $settlementId,string $requestId): void
    {
        $this->authorize($actor);$this->requirePermission($actor,'model_settlements.approve');$this->assertFeature();$this->assertRequestId($requestId);
        $this->pdo->beginTransaction();
        try {
            $settlement=$this->lock('model_settlements',$settlementId);
            if($settlement['status']!=='draft' || $settlement['created_by']===$actor->id) {
                throw new \DomainException('Settlement approval requires a draft and an independent finance approver.');
            }
            $assignment=$this->lock('model_assignments',(string)$settlement['assignment_id']);
            if($assignment['status']!=='completed') throw new \DomainException('Only completed assignments can be approved for settlement.');
            $policy=$this->approvedPolicy();
            if(!hash_equals((string)$settlement['settlement_policy_version'],$policy['policy_version'])) {
                throw new \DomainException('Settlement policy changed; recalculate the settlement before approval.');
            }
            $precision=$this->currencyPrecision((string)$settlement['currency_code']);
            if($precision!==(int)$settlement['currency_precision']) throw new \DomainException('Currency precision changed; settlement must be recalculated.');
            $approved=$this->pdo->prepare('SELECT COALESCE(SUM(amount_minor),0) FROM model_assignment_expenses WHERE assignment_id=:assignment AND status="approved" FOR UPDATE');
            $approved->execute(['assignment'=>$assignment['id']]);$expenses=$this->sumRows($approved->fetchAll(PDO::FETCH_COLUMN));
            $calculation=SettlementCalculator::calculate((int)$assignment['compensation_minor'],(int)$assignment['expense_cap_minor'],
                $expenses,(int)$assignment['commission_basis_points'],(string)$assignment['currency_code']);
            foreach(['gross_minor','approved_expenses_minor','commission_minor','model_net_minor'] as $field) {
                if((int)$settlement[$field]!==$calculation[$field]) throw new \DomainException('Settlement snapshot no longer matches assignment and approved expense ledger.');
            }
            SettlementStateMachine::transition('draft','approved',$this->actorRole($actor),(string)$settlement['created_by'],$actor->id);
            $this->pdo->prepare('UPDATE model_settlements SET status="approved",approved_by=:actor,approved_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id')
                ->execute(['actor'=>$actor->id,'id'=>$settlementId]);
            $this->event((string)$assignment['id'],$settlementId,null,$actor->id,$this->actorRole($actor),'settlement.approved','Settlement independently approved after recalculation.');
            $this->audit->record($actor->id,'model_settlement.approved','model_settlement',$settlementId,
                'Approved settlement after independent recalculation',['status'=>'draft'],['status'=>'approved','approved_by'=>$actor->id],
                $requestId,'model-settlement-v1');
            $this->pdo->commit();
        } catch(Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    /** Records an external payment attestation only. It never initiates a transfer. */
    public function recordPayment(Principal $actor,string $settlementId,string $method,string $paymentReference,string $evidenceReference,string $requestId): void
    {
        $this->authorize($actor);$this->requirePermission($actor,'model_settlements.payment.attest');$this->assertRequestId($requestId);
        if(!in_array($method,['bank_transfer','cash','other_external'],true) || trim($paymentReference)==='' || strlen($paymentReference)>200) {
            throw new \InvalidArgumentException('External payment method and reference are required.');
        }
        $this->assertEvidenceReference($evidenceReference,'private/model-settlements/');
        $this->pdo->beginTransaction();
        try {
            $settlement=$this->lock('model_settlements',$settlementId);
            SettlementStateMachine::transition((string)$settlement['status'],'paid',$this->actorRole($actor),
                (string)$settlement['created_by'],$actor->id,$settlement['approved_by'],$evidenceReference);
            $this->pdo->prepare(
                'UPDATE model_settlements SET status="paid",paid_at=UTC_TIMESTAMP(),payment_method=:method,
                 payment_reference=:reference,paid_by=:actor,external_payment_evidence=:evidence,updated_at=UTC_TIMESTAMP() WHERE id=:id'
            )->execute([
                'method'=>$method,'reference'=>$this->encrypt($settlementId,'payment_reference',$paymentReference),
                'actor'=>$actor->id,'evidence'=>$this->encrypt($settlementId,'external_payment_evidence',$evidenceReference),'id'=>$settlementId,
            ]);
            $this->event((string)$settlement['assignment_id'],$settlementId,null,$actor->id,$this->actorRole($actor),'settlement.payment_attested',
                'External payment evidence recorded; no transfer was initiated by this platform.');
            $this->audit->record($actor->id,'model_settlement.payment_attested','model_settlement',$settlementId,
                'Recorded external payment attestation',['status'=>'approved'],['status'=>'paid','payment_method'=>$method,'evidence_present'=>true],
                $requestId,'model-settlement-v1');
            $this->pdo->commit();
        } catch(Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function reverse(Principal $actor,string $settlementId,string $reason,string $evidenceReference,string $requestId): void
    {
        $this->authorize($actor);$this->requirePermission($actor,'model_settlements.reverse.attest');$this->assertRequestId($requestId);$reason=trim($reason);
        if(strlen($reason)<20 || strlen($reason)>1500) throw new \InvalidArgumentException('Settlement reversal requires a reason of 20 to 1500 characters.');
        $this->assertEvidenceReference($evidenceReference,'private/model-settlements/');
        $this->pdo->beginTransaction();
        try {
            $settlement=$this->lock('model_settlements',$settlementId);
            SettlementStateMachine::transition((string)$settlement['status'],'reversed',$this->actorRole($actor),
                (string)$settlement['created_by'],$actor->id,$settlement['approved_by'],$evidenceReference);
            $this->pdo->prepare(
                'UPDATE model_settlements SET status="reversed",reversed_at=UTC_TIMESTAMP(),reversed_by=:actor,
                 reversal_reason=:reason,reversal_evidence=:evidence,updated_at=UTC_TIMESTAMP() WHERE id=:id'
            )->execute([
                'actor'=>$actor->id,'reason'=>$this->encrypt($settlementId,'reversal_reason',$reason),
                'evidence'=>$this->encrypt($settlementId,'reversal_evidence',$evidenceReference),'id'=>$settlementId,
            ]);
            $this->event((string)$settlement['assignment_id'],$settlementId,null,$actor->id,$this->actorRole($actor),'settlement.reversed','External settlement reversal evidence recorded.');
            $this->audit->record($actor->id,'model_settlement.reversed','model_settlement',$settlementId,
                'Recorded external settlement reversal',['status'=>$settlement['status']],['status'=>'reversed','reason_sha256'=>hash('sha256',$reason)],
                $requestId,'model-settlement-v1');
            $this->pdo->commit();
        } catch(Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function dispute(Principal $actor,string $settlementId,string $reason,string $evidenceReference,string $requestId): void
    {
        $this->authorize($actor);$this->requirePermission($actor,'model_settlements.dispute');$this->assertRequestId($requestId);
        if(strlen($reason)<20 || strlen($reason)>1500) throw new \InvalidArgumentException('Settlement dispute requires a reason of 20 to 1500 characters.');
        $this->assertEvidenceReference($evidenceReference,'private/model-settlements/');
        $this->pdo->beginTransaction();
        try {
            $settlement=$this->lock('model_settlements',$settlementId);
            SettlementStateMachine::transition((string)$settlement['status'],'disputed',$this->actorRole($actor),
                (string)$settlement['created_by'],$actor->id,$settlement['approved_by'],$evidenceReference);
            $this->pdo->prepare(
                'UPDATE model_settlements SET status="disputed",disputed_at=UTC_TIMESTAMP(),disputed_by=:actor,
                 dispute_reason=:reason,dispute_evidence=:evidence,updated_at=UTC_TIMESTAMP() WHERE id=:id'
            )->execute([
                'actor'=>$actor->id,'reason'=>$this->encrypt($settlementId,'dispute_reason',$reason),
                'evidence'=>$this->encrypt($settlementId,'dispute_evidence',$evidenceReference),'id'=>$settlementId,
            ]);
            $this->event((string)$settlement['assignment_id'],$settlementId,null,$actor->id,$this->actorRole($actor),'settlement.disputed','Settlement dispute evidence recorded for independent resolution.');
            $this->audit->record($actor->id,'model_settlement.disputed','model_settlement',$settlementId,
                'Recorded a settlement dispute',['status'=>$settlement['status']],['status'=>'disputed','reason_sha256'=>hash('sha256',$reason)],
                $requestId,'model-settlement-v1');
            $this->pdo->commit();
        } catch(Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    /** @return list<array<string,mixed>> */
    public function list(Principal $actor,int $limit=100): array
    {
        $this->authorize($actor);$limit=max(1,min(200,$limit));
        $query=$this->pdo->prepare(
            'SELECT id,settlement_reference,assignment_id,application_id,currency_code,currency_precision,
                    gross_minor,approved_expenses_minor,commission_minor,commission_basis_points,model_net_minor,
                    status,created_by,approved_by,approved_at,paid_at,paid_by,reversed_at,reversed_by,disputed_at,disputed_by,created_at
             FROM model_settlements ORDER BY created_at DESC LIMIT :limit'
        );
        $query->bindValue(':limit',$limit,PDO::PARAM_INT);$query->execute();return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    public function expenses(Principal $actor,string $assignmentId): array
    {
        $this->authorize($actor);
        $query=$this->pdo->prepare(
            'SELECT id,assignment_id,settlement_id,amount_minor,currency_code,status,created_by,submitted_by,
                    reviewed_by,reviewed_at,created_at FROM model_assignment_expenses
             WHERE assignment_id=:assignment ORDER BY created_at DESC'
        );
        $query->execute(['assignment'=>$assignmentId]);return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    private function hasSettlement(string $assignmentId): bool
    {
        $query=$this->pdo->prepare('SELECT id FROM model_settlements WHERE assignment_id=:assignment LIMIT 1');
        $query->execute(['assignment'=>$assignmentId]);return $query->fetchColumn()!==false;
    }

    private function currencyPrecision(string $currency): int
    {
        $query=$this->pdo->prepare('SELECT minor_units FROM currencies WHERE code=:currency AND enabled=1 LIMIT 1 FOR UPDATE');
        $query->execute(['currency'=>$currency]);$value=$query->fetchColumn();
        if($value===false) throw new \DomainException('Settlement currency is not enabled.');
        return (int)$value;
    }

    private function decimalToMinor(string $amount,int $precision): int
    {
        if($precision<0 || $precision>4 || preg_match('/^(?:0|[1-9][0-9]{0,13})(?:\\.[0-9]{1,4})?$/D',$amount)!==1) {
            throw new \InvalidArgumentException('Amount is not a valid decimal for this currency.');
        }
        [$whole,$fraction]=array_pad(explode('.',$amount,2),2,'');
        if(strlen($fraction)>$precision && trim(substr($fraction,$precision),'0')!=='') throw new \InvalidArgumentException('Amount exceeds currency minor-unit precision.');
        $scale=10**$precision;$fractionMinor=(int)str_pad(substr($fraction,0,$precision),$precision,'0');
        $whole=(int)$whole;
        if($whole>intdiv(PHP_INT_MAX-$fractionMinor,$scale)) throw new \InvalidArgumentException('Amount exceeds supported integer range.');
        return $whole*$scale+$fractionMinor;
    }

    private function approvedPolicy(): array
    {
        $query=$this->pdo->prepare('SELECT setting_json FROM deployment_settings WHERE setting_key="model_agency.settlement" LIMIT 1');$query->execute();
        $raw=$query->fetchColumn();$policy=is_string($raw)?json_decode($raw,true):null;
        if(!is_array($policy) || !($policy['enabled']??false) || !($policy['policy_approved']??false) ||
            !is_string($policy['policy_version']??null) || trim($policy['policy_version'])==='') {
            throw new \DomainException('A current, versioned settlement policy must be explicitly approved.');
        }
        return ['policy_version'=>trim($policy['policy_version'])];
    }

    private function event(string $assignmentId,?string $settlementId,?string $expenseId,string $actorId,string $actorType,string $type,string $reason): void
    {
        $this->pdo->prepare(
            'INSERT INTO model_settlement_events
             (id,assignment_id,settlement_id,expense_id,actor_user_id,actor_type,event_type,reason,occurred_at,created_at,updated_at)
             VALUES (:id,:assignment,:settlement,:expense,:actor,:actor_type,:event,:reason,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())'
        )->execute([
            'id'=>self::uuid(),'assignment'=>$assignmentId,'settlement'=>$settlementId,'expense'=>$expenseId,
            'actor'=>$actorId,'actor_type'=>$actorType,'event'=>$type,
            'reason'=>$this->cipher->encrypt($reason,'model-settlement-event-v1:'.$assignmentId.':'.$type),
        ]);
    }

    /** @param list<mixed> $values */
    private function sumRows(array $values): int
    {
        $sum=0;
        foreach($values as $value) {
            $amount=(int)$value;
            if($amount<0 || $amount>PHP_INT_MAX-$sum) throw new \DomainException('Expense total exceeds the supported integer range.');
            $sum+=$amount;
        }
        return $sum;
    }

    private function lock(string $table,string $id): array
    {
        $allowed=['model_assignments','model_assignment_expenses','model_settlements'];
        if(!in_array($table,$allowed,true) || !preg_match('/^[0-9a-f-]{36}$/iD',$id)) throw new \InvalidArgumentException('Settlement resource identifier is invalid.');
        $query=$this->pdo->prepare('SELECT * FROM '.$table.' WHERE id=:id LIMIT 1 FOR UPDATE');$query->execute(['id'=>$id]);$row=$query->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row)) throw new \DomainException('Settlement resource does not exist.');return $row;
    }

    private function authorize(Principal $actor): void
    {
        if($actor->hasRole('finance')) {
            Authorization::require($actor,'model_settlements.view',true);
            return;
        }
        if($actor->hasRole('owner') || $actor->hasRole('super_admin')) {
            Authorization::require($actor,'model_settlements.manage',true);
            return;
        }
        throw new \DomainException('MFA-verified finance role is required for settlement operations.');
    }

    private function requirePermission(Principal $actor,string $permission): void
    {
        if($actor->hasRole('owner') || $actor->hasRole('super_admin')) {
            Authorization::require($actor,'model_settlements.manage',true);
            return;
        }
        Authorization::require($actor,$permission,true);
    }

    private function actorRole(Principal $actor): string
    {
        foreach(['finance','owner','super_admin'] as $role) if($actor->hasRole($role)) return $role;
        throw new \DomainException('Finance role is required.');
    }

    private function assertFeature(): void
    {
        if(!(($this->settlementsEnabled)())) throw new \DomainException('Model settlements are disabled until legal, provider, market and safeguarding prerequisites are approved.');
    }

    private function assertRequestId(string $requestId): void
    {
        if(strlen($requestId)<16 || strlen($requestId)>64) throw new \InvalidArgumentException('Request identifier is invalid.');
    }

    private function assertEvidenceReference(string $reference,string $prefix): void
    {
        if(strlen($reference)<20 || strlen($reference)>200 || !str_starts_with($reference,$prefix) ||
            preg_match('#^'.preg_quote($prefix,'#').'[A-Za-z0-9][A-Za-z0-9._/-]{10,180}$#D',$reference)!==1 ||
            str_contains($reference,'..') || str_contains($reference,'\\')) throw new \InvalidArgumentException('Private settlement evidence reference is invalid.');
    }

    private function encrypt(string $id,string $field,string $value): string
    {
        return $this->cipher->encrypt($value,'model-settlement-v1:'.$id.':'.$field);
    }

    private static function uuid(): string
    {
        $bytes=random_bytes(16);$bytes[6]=chr((ord($bytes[6])&15)|64);$bytes[8]=chr((ord($bytes[8])&63)|128);$hex=bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }
}
