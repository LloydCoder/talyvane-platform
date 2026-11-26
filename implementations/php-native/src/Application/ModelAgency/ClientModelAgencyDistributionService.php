<?php
declare(strict_types=1);

namespace Talyvane\Application\ModelAgency;

use PDO;
use Throwable;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Infrastructure\Storage\PrivateMediaStorage;
use Talyvane\Security\Authorization;
use Talyvane\Security\Principal;
use Talyvane\Security\SecretBox;

/**
 * Explicit client distribution for model-agency records.
 *
 * Client access is identity-bound and read-only. Model identities and contact
 * details are never returned; each image request revalidates the complete
 * adult-verification, agreement, licence, market, consent and asset-integrity
 * chain before reading private bytes.
 */
final class ClientModelAgencyDistributionService
{
    public function __construct(
        private PDO $pdo,
        private PrivateMediaStorage $storage,
        private AuditLogger $audit,
        private SecretBox $cipher
    ) {}

    /** @return list<array{id:string,email:string}> */
    public function eligibleClients(Principal $actor): array
    {
        $this->authorizeAgency($actor);
        $query=$this->pdo->query(
            'SELECT u.id,u.email FROM users u
             INNER JOIN user_roles r ON r.user_id=u.id AND r.role_key="client"
             WHERE u.status="active" AND u.email_verified_at IS NOT NULL
               AND (SELECT COUNT(*) FROM user_roles all_roles WHERE all_roles.user_id=u.id)=1
             ORDER BY u.email ASC LIMIT 200'
        );
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function linkCastingCall(Principal $actor,string $callId,string $clientId,string $requestId): void
    {
        $this->authorizeAgency($actor);
        $this->assertRequestId($requestId);
        $this->pdo->beginTransaction();
        try {
            $call=$this->lock('model_casting_calls',$callId);
            if(!in_array($call['status'],['draft','open'],true)) {
                throw new \DomainException('Only a draft or open casting call can be linked to a client.');
            }
            $this->assertVerifiedClient($clientId,true);
            $existing=$call['client_user_id']??null;
            if(is_string($existing) && $existing!=='' && !hash_equals($existing,$clientId)) {
                throw new \DomainException('A casting call cannot be reassigned to a different client after distribution.');
            }
            $this->pdo->prepare(
                'UPDATE model_casting_calls SET client_user_id=:client,updated_at=UTC_TIMESTAMP() WHERE id=:id'
            )->execute(['client'=>$clientId,'id'=>$callId]);
            $this->audit->record($actor->id,'model_casting.client_linked','model_casting_call',$callId,
                'Linked a casting call to one verified client identity',
                ['client_user_id'=>$existing],['client_user_id'=>$clientId],$requestId,'model-client-distribution-v1');
            $this->pdo->commit();
        } catch(Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    public function linkAssignmentToCastingClient(Principal $actor,string $assignmentId,string $requestId): void
    {
        $this->authorizeAgency($actor);
        $this->assertRequestId($requestId);
        $this->pdo->beginTransaction();
        try {
            $assignment=$this->lock('model_assignments',$assignmentId);
            $query=$this->pdo->prepare(
                'SELECT c.client_user_id,c.id AS call_id
                 FROM model_casting_submissions s
                 INNER JOIN model_casting_calls c ON c.id=s.casting_call_id
                 WHERE s.id=:submission LIMIT 1 FOR UPDATE'
            );
            $query->execute(['submission'=>$assignment['submission_id']]);
            $call=$query->fetch(PDO::FETCH_ASSOC);
            if(!is_array($call) || !is_string($call['client_user_id']) || $call['client_user_id']==='') {
                throw new \DomainException('The source casting call must be linked to a verified client before assignment distribution.');
            }
            $this->assertVerifiedClient((string)$call['client_user_id'],true);
            if(in_array((string)$assignment['status'],['declined','cancelled','disputed'],true)) throw new \DomainException('Closed or disputed assignments cannot be newly distributed.');
            if(is_string($assignment['client_user_id']??null) && $assignment['client_user_id']!=='' &&
                !hash_equals((string)$assignment['client_user_id'],(string)$call['client_user_id'])) {
                throw new \DomainException('An assignment cannot be reassigned to a different client.');
            }
            $this->pdo->prepare(
                'UPDATE model_assignments SET client_user_id=:client,updated_at=UTC_TIMESTAMP() WHERE id=:id'
            )->execute(['client'=>$call['client_user_id'],'id'=>$assignmentId]);
            $this->audit->record($actor->id,'model_assignment.client_linked','model_assignment',$assignmentId,
                'Distributed an assignment to the verified client linked to its source casting call',
                ['client_user_id'=>$assignment['client_user_id']??null],
                ['client_user_id'=>$call['client_user_id'],'casting_call_id'=>$call['call_id']],
                $requestId,'model-client-distribution-v1');
            $this->pdo->commit();
        } catch(Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    /** @return list<array<string,mixed>> */
    public function adminCastingCalls(Principal $actor): array
    {
        $this->authorizeAgency($actor);
        return $this->pdo->query(
            'SELECT id,casting_reference,title,status,client_user_id,published_at
             FROM model_casting_calls ORDER BY created_at DESC LIMIT 100'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    public function adminAssignments(Principal $actor): array
    {
        $this->authorizeAgency($actor);
        return $this->pdo->query(
            'SELECT id,assignment_reference,status,client_user_id,submission_id
             FROM model_assignments ORDER BY created_at DESC LIMIT 100'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    public function castingCalls(Principal $client): array
    {
        $this->assertClient($client);
        $query=$this->pdo->prepare(
            'SELECT id,casting_reference,title,project_purpose,country_code,opens_at,closes_at,
                    shoot_starts_at,shoot_ends_at,status,published_at
             FROM model_casting_calls
             WHERE client_user_id=:client AND status IN ("open","closed")
               AND published_at IS NOT NULL
             ORDER BY published_at DESC LIMIT 100'
        );
        $query->execute(['client'=>$client->id]);
        $rows=$query->fetchAll(PDO::FETCH_ASSOC);
        $visible=[];
        foreach($rows as $row) {
            try { $this->assertCallPolicy($row); $visible[]=$row; }
            catch(\DomainException) { continue; }
        }
        return $visible;
    }

    /** @return list<array<string,mixed>> */
    public function assignments(Principal $client): array
    {
        $this->assertClient($client);
        $query=$this->pdo->prepare(
            'SELECT a.id,a.assignment_reference,a.status,a.project_purpose,a.country_code,
                    a.shoot_starts_at,a.shoot_ends_at,a.compensation_minor,a.currency_code,
                    a.created_at,a.updated_at,a.client_name AS assignment_client_name,a.brief,a.location_city,a.deliverables,
                    c.id AS casting_call_id,c.client_name AS casting_client_name,lic.id AS licence_id,lic.licensee_name
             FROM model_assignments a
             INNER JOIN model_casting_submissions s ON s.id=a.submission_id
             INNER JOIN model_casting_calls c ON c.id=s.casting_call_id
             INNER JOIN model_image_licences lic ON lic.id=s.image_licence_id
             WHERE a.client_user_id=:client AND c.client_user_id=:client_call
               AND c.status IN ("open","closed")
             ORDER BY a.created_at DESC LIMIT 100'
        );
        $query->execute(['client'=>$client->id,'client_call'=>$client->id]);
        $rows=$query->fetchAll(PDO::FETCH_ASSOC);
        $visible=[];
        foreach($rows as $row) {
            if(!is_string($row['assignment_client_name']??null) || $row['assignment_client_name']==='' ||
                !is_string($row['casting_client_name']??null) || $row['casting_client_name']==='' ||
                !is_string($row['licensee_name']??null) || $row['licensee_name']==='' ||
                !is_string($row['brief']??null) || $row['brief']==='' ||
                !is_string($row['location_city']??null) || $row['location_city']==='' ||
                !is_string($row['deliverables']??null) || $row['deliverables']==='') continue;
            $assignmentClientName=$this->cipher->decrypt((string)$row['assignment_client_name'],'model-assignment-v1:'.$row['id'].':client_name');
            $castingClientName=$this->cipher->decrypt((string)$row['casting_client_name'],'model-casting-call-v1:'.$row['casting_call_id'].':client_name');
            $licenseeName=$this->cipher->decrypt((string)$row['licensee_name'],'model-image-licence-v1:'.$row['licence_id'].':licensee_name');
            if(!hash_equals(mb_strtolower(trim($castingClientName)),mb_strtolower(trim($assignmentClientName))) ||
                !hash_equals(mb_strtolower(trim($castingClientName)),mb_strtolower(trim($licenseeName)))) continue;
            $row['brief']=$this->cipher->decrypt((string)$row['brief'],'model-assignment-v1:'.$row['id'].':brief');
            $row['location_city']=$this->cipher->decrypt((string)$row['location_city'],'model-assignment-v1:'.$row['id'].':location_city');
            $row['deliverables']=$this->cipher->decrypt((string)$row['deliverables'],'model-assignment-v1:'.$row['id'].':deliverables');
            unset($row['casting_call_id'],$row['assignment_client_name'],$row['casting_client_name'],$row['licence_id'],$row['licensee_name']);
            $visible[]=$row;
        }
        return $visible;
    }

    /** @return list<array{id:string,reference:string,status:string}> */
    public function candidates(Principal $client,string $callId): array
    {
        $this->assertClient($client);
        $call=$this->clientCall($client,$callId);
        if(!in_array($call['status'],['open','closed'],true)) return [];
        $this->assertCandidateWindow($call);
        $this->assertCallPolicy($call);
        $query=$this->pdo->prepare(
            'SELECT s.id,s.submission_reference,s.status,lic.id AS licence_id,lic.licensee_name
             FROM model_casting_submissions s
             INNER JOIN model_talent_applications a ON a.id=s.application_id
             INNER JOIN model_portfolio_assets asset ON asset.id=s.portfolio_asset_id
             INNER JOIN model_image_licences lic ON lic.id=s.image_licence_id
             INNER JOIN model_representation_agreements agreement ON agreement.id=lic.agreement_id
             INNER JOIN model_applicant_verifications v ON v.id=(
                SELECT v2.id FROM model_applicant_verifications v2
                WHERE v2.application_id=a.id ORDER BY v2.decision_order DESC LIMIT 1
             )
             WHERE s.casting_call_id=:call AND s.status IN ("shortlisted","selected")
               AND a.status="accepted" AND a.legal_hold=0 AND a.anonymized_at IS NULL
               AND v.outcome="verified" AND v.expires_at>UTC_TIMESTAMP()
               AND asset.status="approved" AND asset.consent_withdrawn_at IS NULL
               AND lic.status="active" AND lic.purpose="casting_distribution"
               AND lic.model_consent_evidence_reference IS NOT NULL
               AND asset.consent_policy_version=(SELECT JSON_UNQUOTE(JSON_EXTRACT(setting_json,"$.policy_version")) FROM deployment_settings WHERE setting_key="model_agency.portfolio")
               AND lic.licence_policy_version=(SELECT JSON_UNQUOTE(JSON_EXTRACT(setting_json,"$.policy_version")) FROM deployment_settings WHERE setting_key="model_agency.image_licence")
               AND lic.territory_code=:territory AND lic.starts_on<=DATE(:shoot_start)
               AND lic.ends_on>=DATE(:shoot_end)
               AND agreement.status="executed"
               AND agreement.effective_from<=:agreement_start
               AND agreement.effective_until>=:agreement_end
               AND JSON_CONTAINS(lic.channels,JSON_QUOTE("casting_platform"))
               AND JSON_CONTAINS(agreement.terms_snapshot,JSON_QUOTE("casting_submissions"),"$.service_scope")
               AND EXISTS (SELECT 1 FROM consent_grants ag
                           WHERE ag.id=asset.consent_grant_id AND ag.granted=1 AND ag.withdrawn_at IS NULL
                             AND ag.policy_version=asset.consent_policy_version)
               AND EXISTS (SELECT 1 FROM consent_grants lg
                           WHERE lg.id=lic.consent_grant_id AND lg.granted=1 AND lg.withdrawn_at IS NULL
                             AND lg.policy_version=lic.licence_policy_version)
             ORDER BY s.submitted_at DESC LIMIT 100'
        );
        $query->execute([
            'call'=>$callId,'territory'=>$call['country_code'],
            'shoot_start'=>$call['shoot_starts_at'],'shoot_end'=>$call['shoot_ends_at'],
            'agreement_start'=>$call['shoot_starts_at'],'agreement_end'=>$call['shoot_ends_at'],
        ]);
        $rows=$query->fetchAll(PDO::FETCH_ASSOC);
        if(!is_string($call['client_name']??null) || $call['client_name']==='') return [];
        $callClientName=$this->cipher->decrypt((string)$call['client_name'],'model-casting-call-v1:'.$call['id'].':client_name');
        $clientNameKey=mb_strtolower(trim($callClientName));
        $visible=[];
        foreach($rows as $row) {
            if(!is_string($row['licensee_name']??null) || $row['licensee_name']==='') continue;
            $licenseeName=$this->cipher->decrypt((string)$row['licensee_name'],'model-image-licence-v1:'.$row['licence_id'].':licensee_name');
            if(hash_equals($clientNameKey,mb_strtolower(trim($licenseeName)))) {
                $visible[]=['id'=>(string)$row['id'],'reference'=>(string)$row['submission_reference'],'status'=>(string)$row['status']];
            }
        }
        return $visible;
    }

    /** @return array{bytes:string,mime:string} */
    public function candidateImage(Principal $client,string $callId,string $submissionId,string $requestId): array
    {
        $this->assertClient($client);
        $call=$this->clientCall($client,$callId);
        if(!in_array($call['status'],['open','closed'],true)) throw new \DomainException('Casting call is not available for client distribution.');
        $this->assertCandidateWindow($call);
        $this->assertCallPolicy($call);
        $query=$this->pdo->prepare(
            'SELECT asset.private_storage_key,asset.sha256,asset.mime_type,lic.id AS licence_id,lic.licensee_name
             FROM model_casting_submissions s
             INNER JOIN model_talent_applications a ON a.id=s.application_id
             INNER JOIN model_portfolio_assets asset ON asset.id=s.portfolio_asset_id
             INNER JOIN model_image_licences lic ON lic.id=s.image_licence_id
             INNER JOIN model_representation_agreements agreement ON agreement.id=lic.agreement_id
             INNER JOIN model_applicant_verifications v ON v.id=(
                SELECT v2.id FROM model_applicant_verifications v2
                WHERE v2.application_id=a.id ORDER BY v2.decision_order DESC LIMIT 1
             )
             WHERE s.id=:submission AND s.casting_call_id=:call
               AND s.status IN ("shortlisted","selected")
               AND a.status="accepted" AND a.legal_hold=0 AND a.anonymized_at IS NULL
               AND v.outcome="verified" AND v.expires_at>UTC_TIMESTAMP()
               AND asset.status="approved" AND asset.consent_withdrawn_at IS NULL
               AND asset.private_storage_key IS NOT NULL
               AND lic.status="active" AND lic.purpose="casting_distribution"
               AND lic.model_consent_evidence_reference IS NOT NULL
               AND asset.consent_policy_version=(SELECT JSON_UNQUOTE(JSON_EXTRACT(setting_json,"$.policy_version")) FROM deployment_settings WHERE setting_key="model_agency.portfolio")
               AND lic.licence_policy_version=(SELECT JSON_UNQUOTE(JSON_EXTRACT(setting_json,"$.policy_version")) FROM deployment_settings WHERE setting_key="model_agency.image_licence")
               AND lic.territory_code=:territory AND lic.starts_on<=DATE(:shoot_start)
               AND lic.ends_on>=DATE(:shoot_end)
               AND agreement.status="executed"
               AND agreement.effective_from<=:agreement_start
               AND agreement.effective_until>=:agreement_end
               AND JSON_CONTAINS(lic.channels,JSON_QUOTE("casting_platform"))
               AND JSON_CONTAINS(agreement.terms_snapshot,JSON_QUOTE("casting_submissions"),"$.service_scope")
               AND EXISTS (SELECT 1 FROM consent_grants ag
                           WHERE ag.id=asset.consent_grant_id AND ag.granted=1 AND ag.withdrawn_at IS NULL
                             AND ag.policy_version=asset.consent_policy_version)
               AND EXISTS (SELECT 1 FROM consent_grants lg
                           WHERE lg.id=lic.consent_grant_id AND lg.granted=1 AND lg.withdrawn_at IS NULL
                             AND lg.policy_version=lic.licence_policy_version)
             LIMIT 1'
        );
        $query->execute([
            'submission'=>$submissionId,'call'=>$callId,'territory'=>$call['country_code'],
            'shoot_start'=>$call['shoot_starts_at'],'shoot_end'=>$call['shoot_ends_at'],
            'agreement_start'=>$call['shoot_starts_at'],'agreement_end'=>$call['shoot_ends_at'],
        ]);
        $asset=$query->fetch(PDO::FETCH_ASSOC);
        if(!is_array($asset) || !is_string($asset['private_storage_key']) || $asset['private_storage_key']==='' ||
            $asset['mime_type']!=='image/webp') {
            throw new \DomainException('The client-authorized casting image is unavailable.');
        }
        if(!is_string($call['client_name']??null) || $call['client_name']==='' || !is_string($asset['licensee_name']??null) || $asset['licensee_name']==='') {
            throw new \DomainException('The casting client and active image licensee cannot be verified.');
        }
        $callClientName=$this->cipher->decrypt((string)$call['client_name'],'model-casting-call-v1:'.$call['id'].':client_name');
        $licenseeName=$this->cipher->decrypt((string)$asset['licensee_name'],'model-image-licence-v1:'.$asset['licence_id'].':licensee_name');
        if(!hash_equals(mb_strtolower(trim($callClientName)),mb_strtolower(trim($licenseeName)))) {
            throw new \DomainException('The active image licence does not name this casting client as its licensee.');
        }
        $bytes=$this->storage->read((string)$asset['private_storage_key'],8_388_608);
        if(!hash_equals((string)$asset['sha256'],hash('sha256',$bytes))) {
            throw new \DomainException('The client-authorized casting image failed integrity verification.');
        }
        $this->assertRequestId($requestId);
        $this->audit->record($client->id,'client_model_casting.image_viewed','model_casting_submission',$submissionId,
            'Verified client viewed a current rights-cleared casting portfolio preview',null,
            ['casting_call_id'=>$callId,'media_sha256'=>(string)$asset['sha256']],$requestId,'model-client-distribution-v1');
        return ['bytes'=>$bytes,'mime'=>'image/webp'];
    }

    /** @param array<string,mixed> $call */
    private function assertCandidateWindow(array $call): void
    {
        if($call['status']!=='open') return;
        $now=new \DateTimeImmutable('now',new \DateTimeZone('UTC'));
        if(new \DateTimeImmutable((string)$call['opens_at'],new \DateTimeZone('UTC'))>$now ||
            new \DateTimeImmutable((string)$call['closes_at'],new \DateTimeZone('UTC'))<=$now) {
            throw new \DomainException('The casting submission window is not currently open.');
        }
    }

    /** @return array<string,mixed> */
    private function clientCall(Principal $client,string $callId): array
    {
        $query=$this->pdo->prepare(
            'SELECT * FROM model_casting_calls
             WHERE id=:id AND client_user_id=:client AND published_at IS NOT NULL LIMIT 1'
        );
        $query->execute(['id'=>$callId,'client'=>$client->id]);
        $row=$query->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row)) throw new \DomainException('Casting call is unavailable.');
        return $row;
    }

    /** @param array<string,mixed> $call */
    private function assertCallPolicy(array $call): void
    {
        $policy=$this->pdo->prepare('SELECT setting_json FROM deployment_settings WHERE setting_key="model_agency.casting" LIMIT 1');
        $policy->execute();$raw=$policy->fetchColumn();$settings=is_string($raw)?json_decode($raw,true):null;
        if(!is_array($settings) || !($settings['enabled']??false) || !($settings['policy_approved']??false) ||
            !is_string($settings['policy_version']??null) || !hash_equals((string)$call['casting_policy_version'],(string)$settings['policy_version'])) {
            throw new \DomainException('Current casting approval is unavailable.');
        }
        foreach(['model_agency.portfolio','model_agency.image_licence'] as $policyKey) {
            $policyQuery=$this->pdo->prepare('SELECT setting_json FROM deployment_settings WHERE setting_key=:key LIMIT 1');
            $policyQuery->execute(['key'=>$policyKey]);
            $policyRaw=$policyQuery->fetchColumn();
            $policyData=is_string($policyRaw)?json_decode($policyRaw,true):null;
            if(!is_array($policyData) || !($policyData['enabled']??false) || !($policyData['policy_approved']??false) ||
                !is_string($policyData['policy_version']??null) || trim($policyData['policy_version'])==='') {
                throw new \DomainException('Current portfolio and image-licence approvals are unavailable.');
            }
        }
        $market=$this->pdo->prepare(
            'SELECT c.enabled,m.enabled AS market_enabled,m.decision,m.expires_at,m.evidence_reference
             FROM countries c LEFT JOIN market_policies m ON m.country_code=c.alpha2
             WHERE c.alpha2=:country LIMIT 1'
        );
        $market->execute(['country'=>$call['country_code']]);$row=$market->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row) || (int)$row['enabled']!==1 || (int)($row['market_enabled']??0)!==1 ||
            $row['decision']!=='approved' || $row['expires_at']===null || $row['evidence_reference']===null ||
            new \DateTimeImmutable((string)$row['expires_at'],new \DateTimeZone('UTC'))<=new \DateTimeImmutable('now',new \DateTimeZone('UTC'))) {
            throw new \DomainException('The casting market is no longer approved.');
        }
    }

    private function assertClient(Principal $client): void
    {
        if(!$client->hasRole('client') || count($client->roles)!==1) {
            throw new \DomainException('A client-only portal identity is required.');
        }
        $query=$this->pdo->prepare(
            'SELECT COUNT(*) FROM users u
             INNER JOIN user_roles r ON r.user_id=u.id AND r.role_key="client"
             WHERE u.id=:id AND u.status="active" AND u.email_verified_at IS NOT NULL
               AND (SELECT COUNT(*) FROM user_roles all_roles WHERE all_roles.user_id=u.id)=1'
        );
        $query->execute(['id'=>$client->id]);
        if((int)$query->fetchColumn()!==1) throw new \DomainException('The verified client identity is no longer active.');
    }

    private function assertVerifiedClient(string $clientId,bool $lock): void
    {
        $sql='SELECT u.id FROM users u
              INNER JOIN user_roles r ON r.user_id=u.id AND r.role_key="client"
              WHERE u.id=:id AND u.status="active" AND u.email_verified_at IS NOT NULL
                AND (SELECT COUNT(*) FROM user_roles all_roles WHERE all_roles.user_id=u.id)=1 LIMIT 1'.($lock?' FOR UPDATE':'');
        $query=$this->pdo->prepare($sql);$query->execute(['id'=>$clientId]);
        if($query->fetchColumn()===false) throw new \DomainException('Distribution requires one active, email-verified client-only identity.');
    }

    /** @return array<string,mixed> */
    private function lock(string $table,string $id): array
    {
        if(!in_array($table,['model_casting_calls','model_assignments'],true) ||
            preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD',$id)!==1) {
            throw new \InvalidArgumentException('Model distribution identifier is invalid.');
        }
        $query=$this->pdo->prepare('SELECT * FROM '.$table.' WHERE id=:id LIMIT 1 FOR UPDATE');
        $query->execute(['id'=>$id]);$row=$query->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row)) throw new \DomainException('Model distribution record does not exist.');
        return $row;
    }

    private function authorizeAgency(Principal $actor): void
    {
        Authorization::require($actor,'model_castings.manage',true);
        if(!$actor->hasRole('admin') && !$actor->hasRole('owner') && !$actor->hasRole('super_admin')) {
            throw new \DomainException('MFA-verified agency administration is required.');
        }
    }

    private function assertRequestId(string $requestId): void
    {
        if(strlen($requestId)<16 || strlen($requestId)>64) throw new \InvalidArgumentException('Model distribution request reference is invalid.');
    }
}
