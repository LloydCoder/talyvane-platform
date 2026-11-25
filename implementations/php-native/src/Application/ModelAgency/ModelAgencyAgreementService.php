<?php
declare(strict_types=1);

namespace Talyvane\Application\ModelAgency;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Talyvane\Domain\ModelAgency\AgreementStateMachine;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Security\Authorization;
use Talyvane\Security\Principal;

final class ModelAgencyAgreementService
{
    public function __construct(private PDO $pdo,private AuditLogger $audit) {}

    /** @param array<string,mixed> $terms */
    public function createDraft(Principal $actor,string $applicationId,array $terms,int $commissionBasisPoints,string $requestId): string
    {
        Authorization::require($actor,'model_agreements.manage',true);
        $this->assertAgencyAdministrator($actor);
        if ($commissionBasisPoints < 0 || $commissionBasisPoints > 10000 || $terms === [] ||
            strlen(json_encode($terms,JSON_THROW_ON_ERROR)) > 20000 ||
            strlen($requestId) < 16 || strlen($requestId) > 64) {
            throw new \InvalidArgumentException('Representation agreement terms are invalid.');
        }
        $this->pdo->beginTransaction();
        try {
            $application = $this->pdo->prepare('SELECT status FROM model_talent_applications WHERE id = :id FOR UPDATE');
            $application->execute(['id'=>$applicationId]);
            $row = $application->fetch();
            if (!is_array($row) || $row['status'] !== 'accepted') {
                throw new \DomainException('Only an accepted model applicant can receive a representation agreement.');
            }
            $verification = $this->pdo->prepare('SELECT id FROM model_applicant_verifications WHERE application_id = :application AND outcome = "verified" AND expires_at > UTC_TIMESTAMP() ORDER BY verified_at DESC LIMIT 1 FOR UPDATE');
            $verification->execute(['application'=>$applicationId]);
            if ($verification->fetchColumn() === false) {
                throw new \DomainException('A current independent adult-eligibility verification is required for representation.');
            }
            $id = self::uuid();
            $reference = 'AGR-' . strtoupper(bin2hex(random_bytes(6)));
            $this->pdo->prepare(
                'INSERT INTO model_representation_agreements
                 (id,agreement_reference,application_id,status,terms_snapshot,commission_basis_points,created_by,created_at,updated_at)
                 VALUES (:id,:reference,:application,"draft",:terms,:commission,:actor,UTC_TIMESTAMP(),UTC_TIMESTAMP())'
            )->execute([
                'id'=>$id,'reference'=>$reference,'application'=>$applicationId,
                'terms'=>json_encode($terms,JSON_THROW_ON_ERROR),'commission'=>$commissionBasisPoints,'actor'=>$actor->id,
            ]);
            $this->audit->record($actor->id,'model_agreement.created','model_representation_agreement',$id,
                'Draft representation agreement created',null,[
                    'application_id'=>$applicationId,'commission_basis_points'=>$commissionBasisPoints,
                    'terms_sha256'=>hash('sha256',json_encode($terms,JSON_THROW_ON_ERROR)),
                ],$requestId,'model-agreement-v1');
            $this->pdo->commit();
            return $id;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function transition(
        Principal $actor,string $agreementId,string $toStatus,string $reason,
        ?string $externalExecutionReference,?DateTimeImmutable $effectiveFrom,
        ?DateTimeImmutable $effectiveUntil,string $requestId
    ): string {
        Authorization::require($actor,'model_agreements.manage',true);
        $this->assertAgencyAdministrator($actor);
        if (strlen(trim($reason)) < 8 || strlen($reason) > 500 ||
            strlen($requestId) < 16 || strlen($requestId) > 64) {
            throw new \InvalidArgumentException('Agreement transition requires a reason and request identifier.');
        }
        $this->pdo->beginTransaction();
        try {
            $query = $this->pdo->prepare('SELECT id,application_id,status,external_execution_reference,effective_from,effective_until FROM model_representation_agreements WHERE id = :id FOR UPDATE');
            $query->execute(['id'=>$agreementId]);
            $agreement = $query->fetch();
            if (!is_array($agreement)) {
                throw new \DomainException('Representation agreement does not exist.');
            }
            $from = (string) $agreement['status'];
            $to = AgreementStateMachine::transition($from,$toStatus,$this->actorRole($actor),$externalExecutionReference);
            $updates = [
                'status'=>$to,
                'external_execution_reference'=>$agreement['external_execution_reference'],
                'executed_at'=>null,
                'effective_from'=>$agreement['effective_from'],
                'effective_until'=>$agreement['effective_until'],
                'terminated_at'=>null,
            ];
            if ($to === 'executed') {
                if ($effectiveFrom === null || $effectiveUntil === null) {
                    throw new \InvalidArgumentException('Executed agreements require explicit effective start and end dates.');
                }
                $utc = new DateTimeZone('UTC');
                $start = $effectiveFrom->setTimezone($utc);
                $end = $effectiveUntil->setTimezone($utc);
                if ($end <= $start || $end <= new DateTimeImmutable('now',$utc)) {
                    throw new \InvalidArgumentException('Agreement effective period is invalid.');
                }
                $updates['external_execution_reference'] = trim((string) $externalExecutionReference);
                $updates['executed_at'] = (new DateTimeImmutable('now',$utc))->format('Y-m-d H:i:s');
                $updates['effective_from'] = $start->format('Y-m-d H:i:s');
                $updates['effective_until'] = $end->format('Y-m-d H:i:s');
            } elseif ($to === 'terminated') {
                $updates['terminated_at'] = gmdate('Y-m-d H:i:s');
            }
            $this->pdo->prepare(
                'UPDATE model_representation_agreements SET status = :status,external_execution_reference = :evidence,
                 executed_at = COALESCE(:executed_at,executed_at),effective_from = :effective_from,
                 effective_until = :effective_until,terminated_at = :terminated_at,updated_at = UTC_TIMESTAMP()
                 WHERE id = :id AND status = :from_status'
            )->execute([
                'status'=>$updates['status'],'evidence'=>$updates['external_execution_reference'],
                'executed_at'=>$updates['executed_at'],'effective_from'=>$updates['effective_from'],
                'effective_until'=>$updates['effective_until'],'terminated_at'=>$updates['terminated_at'],
                'id'=>$agreementId,'from_status'=>$from,
            ]);
            $this->audit->record($actor->id,'model_agreement.transitioned','model_representation_agreement',$agreementId,
                trim($reason),['status'=>$from],[
                    'status'=>$to,
                    'external_execution_reference_present'=>$updates['external_execution_reference'] !== null,
                    'effective_from'=>$updates['effective_from'],
                    'effective_until'=>$updates['effective_until'],
                ],$requestId,'model-agreement-v1');
            $this->pdo->commit();
            return $to;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function assertAgencyAdministrator(Principal $actor): void
    {
        if (!$actor->mfaVerified || !array_intersect($actor->roles,['admin','owner','super_admin'])) {
            throw new \DomainException('MFA-verified agency administration is required.');
        }
    }

    private function actorRole(Principal $actor): string
    {
        foreach (['super_admin','owner','admin'] as $role) {
            if ($actor->hasRole($role)) {
                return $role;
            }
        }
        throw new \DomainException('Actor is not authorized to manage representation agreements.');
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }
}
