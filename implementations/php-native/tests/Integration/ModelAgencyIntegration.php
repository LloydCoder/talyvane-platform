<?php
declare(strict_types=1);

use Talyvane\Application\ModelAgency\ModelAgencyApplicationService;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Security\Principal;

function runModelAgencyIntegration(PDO $pdo): int
{
    $assertions = 0;
    $assert = static function (bool $ok, string $message) use (&$assertions): void {
        $assertions++;
        if (!$ok) {
            throw new RuntimeException('Model agency integration failed: ' . $message);
        }
    };
    $adminId = (string) $pdo->query('SELECT id FROM users WHERE email = "native-admin@example.test"')->fetchColumn();
    if ($adminId === '') {
        throw new RuntimeException('Model agency integration requires the identity integration administrator.');
    }
    $pdo->prepare('INSERT INTO deployment_settings (setting_key,setting_json,version,updated_by,updated_at)
        VALUES ("model_agency.intake",:settings,1,:actor,UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE setting_json = VALUES(setting_json),version = version + 1,updated_by = VALUES(updated_by),updated_at = UTC_TIMESTAMP()')
        ->execute([
            'settings'=>json_encode(['enabled'=>true,'privacy_notice_version'=>'model-privacy-v1','retention_days'=>90,'verification_validity_days'=>365],JSON_THROW_ON_ERROR),
            'actor'=>$adminId,
        ]);
    $mailer = new class implements \Talyvane\Infrastructure\Mail\Mailer {
        public array $messages = [];
        public function send(string $recipient,string $subject,string $textBody): void {
            $this->messages[] = ['recipient'=>$recipient,'subject'=>$subject,'body'=>$textBody];
        }
    };
    $audit = new AuditLogger($pdo);
    $service = new ModelAgencyApplicationService($pdo,$audit);
    $applicationId = $service->submit(
        'Applicant Example',
        'model-applicant@example.test',
        '+441234567890',
        'GB',
        'London',
        'https://portfolio.example.test/work',
        'Professional portfolio summary supplied by applicant.',
        true,
        'model-privacy-v1',
        bin2hex(random_bytes(16))
    );
    $rowQuery = $pdo->prepare('SELECT status,adult_eligibility_attested_at,privacy_notice_version,retention_due_at FROM model_talent_applications WHERE id = :id');
    $rowQuery->execute(['id'=>$applicationId]);
    $application = $rowQuery->fetch();
    $assert(is_array($application) && $application['status'] === 'submitted','public model application enters submitted state');
    $assert(is_array($application) && $application['adult_eligibility_attested_at'] !== null,'explicit adult attestation is timestamped without treating it as verification');
    $assert(is_array($application) && $application['privacy_notice_version'] === 'model-privacy-v1' && $application['retention_due_at'] !== null,'privacy notice version and retention deadline are snapshotted');
    $roleCount = $pdo->prepare('SELECT COUNT(*) FROM user_roles WHERE role_key IN ("client","companion") AND user_id = :id');
    $roleCount->execute(['id'=>$applicationId]);
    $assert((int) $roleCount->fetchColumn() === 0,'model applicant intake does not create a client or companion identity');
    $admin = new Principal($adminId,['super_admin'],true);
    $verifier = new Principal($adminId,['super_admin','verifier'],true);
    $service->review($admin,$applicationId,'under_review','Initial human review commenced',bin2hex(random_bytes(16)));
    $service->verifyAdultEligibility($verifier,$applicationId,'independent_provider_check','provider-reference-opaque-001','verified',365,bin2hex(random_bytes(16)));
    $service->review($admin,$applicationId,'accepted','Application accepted after independent eligibility verification',bin2hex(random_bytes(16)));
    $final = $pdo->prepare('SELECT status FROM model_talent_applications WHERE id = :id');
    $final->execute(['id'=>$applicationId]);
    $assert($final->fetchColumn() === 'accepted','application acceptance requires a current independent verifier decision');
    $verification = $pdo->prepare('SELECT provider_reference_hash,outcome FROM model_applicant_verifications WHERE application_id = :id ORDER BY verified_at DESC LIMIT 1');
    $verification->execute(['id'=>$applicationId]);
    $verificationRow = $verification->fetch();
    $assert(is_array($verificationRow) && $verificationRow['outcome'] === 'verified' && $verificationRow['provider_reference_hash'] === hash('sha256','provider-reference-opaque-001'),'only a hash of the external verification reference is retained');
    try {
        $service->review($admin,$applicationId,'withdrawn','Invalid admin transition',bin2hex(random_bytes(16)));
        $assert(false,'admin cannot use applicant-only withdrawal transition');
    } catch (DomainException) {
        $assert(true,'admin cannot use applicant-only withdrawal transition');
    }
    $pdo->prepare('UPDATE deployment_settings SET setting_json = JSON_SET(setting_json,"$.enabled",false),version = version + 1 WHERE setting_key = "model_agency.intake"')->execute();
    try {
        $service->submit('Another Applicant','another-model-applicant@example.test',null,'GB','London',null,null,true,'model-privacy-v1',bin2hex(random_bytes(16)));
        $assert(false,'model application intake fails closed when disabled');
    } catch (DomainException) {
        $assert(true,'model application intake fails closed when disabled');
    }
    return $assertions;
}
