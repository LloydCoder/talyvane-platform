<?php
declare(strict_types=1);

use Talyvane\Application\ModelAgency\ModelAgencyApplicationService;
use Talyvane\Application\ModelAgency\ModelAgencyAgreementService;
use Talyvane\Application\ModelAgency\ModelApplicantRightsService;
use Talyvane\Infrastructure\Security\RateLimiter;
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
        true,
        'model-privacy-v1',
        bin2hex(random_bytes(16))
    );
    $rowQuery = $pdo->prepare('SELECT status,adult_eligibility_attested_at,privacy_acknowledged_at,privacy_notice_version,retention_due_at FROM model_talent_applications WHERE id = :id');
    $rowQuery->execute(['id'=>$applicationId]);
    $application = $rowQuery->fetch();
    $assert(is_array($application) && $application['status'] === 'submitted','public model application enters submitted state');
    $assert(is_array($application) && $application['adult_eligibility_attested_at'] !== null,'explicit adult attestation is timestamped without treating it as verification');
    $assert(is_array($application) && $application['privacy_notice_version'] === 'model-privacy-v1' && $application['privacy_acknowledged_at'] !== null && $application['retention_due_at'] !== null,'privacy acknowledgement, notice version and retention deadline are snapshotted');
    $roleCount = $pdo->prepare('SELECT COUNT(*) FROM user_roles WHERE role_key IN ("client","companion") AND user_id = :id');
    $roleCount->execute(['id'=>$applicationId]);
    $assert((int) $roleCount->fetchColumn() === 0,'model applicant intake does not create a client or companion identity');
    $admin = new Principal($adminId,['super_admin'],true);
    $verifier = new Principal($adminId,['super_admin','verifier'],true);
    $adminQueue = $service->pendingForReview($admin);
    $verifierQueue = $service->verificationQueue($verifier);
    $assert(count($adminQueue) === 1 && $adminQueue[0]['application_reference'] !== '','MFA-authorized administrators receive the model-agency review queue');
    $assert(count($verifierQueue) === 1 && !array_key_exists('email',$verifierQueue[0]) && !array_key_exists('applicant_name',$verifierQueue[0]),'adult verifiers receive a minimal-data queue without applicant contact details');
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

    $secondApplicationId = $service->submit(
        'Second Applicant','second-model-applicant@example.test',null,'GB','Manchester',null,null,
        true,true,'model-privacy-v1',bin2hex(random_bytes(16))
    );
    $service->review($admin,$secondApplicationId,'under_review','Second application entered human review',bin2hex(random_bytes(16)));
    $service->verifyAdultEligibility($verifier,$secondApplicationId,'independent_provider_check','provider-reference-second-verified','verified',365,bin2hex(random_bytes(16)));
    $service->verifyAdultEligibility($verifier,$secondApplicationId,'independent_provider_check','provider-reference-second-failed','failed',365,bin2hex(random_bytes(16)));
    try {
        $service->review($admin,$secondApplicationId,'accepted','Attempt acceptance after latest failed verification',bin2hex(random_bytes(16)));
        $assert(false,'a later failed adult-verification decision overrides an earlier verified decision');
    } catch (DomainException) {
        $assert(true,'a later failed adult-verification decision overrides an earlier verified decision');
    }
    $secondState=$pdo->prepare('SELECT status FROM model_talent_applications WHERE id = :id');
    $secondState->execute(['id'=>$secondApplicationId]);
    $assert($secondState->fetchColumn()==='under_review','failed latest adult verification keeps the application in human review');


    $agreements = new ModelAgencyAgreementService($pdo,new AuditLogger($pdo));
    $agreementId = $agreements->createDraft($admin,$applicationId,[
        'service_scope'=>['portfolio_development','casting_submissions'],
        'commission_basis_points'=>1500,
        'term_months'=>12,
        'exclusivity'=>false,
        'termination_notice_days'=>30,
    ],1500,bin2hex(random_bytes(16)));
    $agreements->transition($admin,$agreementId,'offered','Agreement offered for external review',null,null,null,bin2hex(random_bytes(16)));
    $agreements->transition($admin,$agreementId,'executed','Both parties executed the external agreement','external-signature-evidence-reference-001',
        new DateTimeImmutable('now',new DateTimeZone('UTC')),new DateTimeImmutable('+12 months',new DateTimeZone('UTC')),bin2hex(random_bytes(16)));
    $agreementState = $pdo->prepare('SELECT status,commission_basis_points,external_execution_reference,effective_from,effective_until FROM model_representation_agreements WHERE id = :id');
    $agreementState->execute(['id'=>$agreementId]);
    $agreement = $agreementState->fetch();
    $assert(is_array($agreement) && $agreement['status'] === 'executed' && (int) $agreement['commission_basis_points'] === 1500,'representation agreement execution stores the agreed commission and effective period');
    $assert(is_array($agreement) && $agreement['external_execution_reference'] === 'external-signature-evidence-reference-001','platform records external execution evidence without pretending to provide e-signatures');

    $rightsMailer = new NativeTestMailer();
    $rights = new ModelApplicantRightsService($pdo,$rightsMailer,new RateLimiter($pdo,str_repeat('Q',32)),new AuditLogger($pdo),str_repeat('S',32));
    $applicationReference = (string) $pdo->query('SELECT application_reference FROM model_talent_applications WHERE id = ' . $pdo->quote($applicationId))->fetchColumn();
    $rights->request($applicationReference,'model-applicant@example.test','access','203.0.113.10',bin2hex(random_bytes(16)));
    preg_match('/one-time verification code is: ([0-9]{8})/',$rightsMailer->messages[0]['body'],$accessMatch);
    $accessId = $rights->verify($applicationReference,'model-applicant@example.test',$accessMatch[1] ?? '','203.0.113.11',bin2hex(random_bytes(16)));
    $accessData = $rights->read((string) $accessId);
    $assert(is_string($accessId) && $accessData['applicant_name'] === 'Applicant Example','applicant access request requires a single-use emailed OTP');
    $rights->finish($accessId);

    $rights->request($applicationReference,'model-applicant@example.test','correction','203.0.113.12',bin2hex(random_bytes(16)));
    preg_match('/one-time verification code is: ([0-9]{8})/',$rightsMailer->messages[1]['body'],$correctionMatch);
    $correctionId = $rights->verify($applicationReference,'model-applicant@example.test',$correctionMatch[1] ?? '','203.0.113.13',bin2hex(random_bytes(16)));
    $rights->correct((string) $correctionId,[
        'applicant_name'=>'Corrected Applicant','phone'=>'+441234567891','city'=>'Manchester',
        'portfolio_url'=>'https://portfolio.example.test/updated','experience_summary'=>'Updated professional portfolio and experience summary.',
    ],bin2hex(random_bytes(16)));
    $correctedName = $pdo->prepare('SELECT applicant_name,city FROM model_talent_applications WHERE id = :id');
    $correctedName->execute(['id'=>$applicationId]);
    $corrected = $correctedName->fetch();
    $assert(is_array($corrected) && $corrected['applicant_name'] === 'Corrected Applicant' && $corrected['city'] === 'Manchester',
        'verified correction request updates only applicant-editable fields and records the event');

    $rights->request($applicationReference,'model-applicant@example.test','withdrawal','203.0.113.14',bin2hex(random_bytes(16)));
    preg_match('/one-time verification code is: ([0-9]{8})/',$rightsMailer->messages[2]['body'],$withdrawMatch);
    $withdrawId = $rights->verify($applicationReference,'model-applicant@example.test',$withdrawMatch[1] ?? '','203.0.113.15',bin2hex(random_bytes(16)));
    $rights->withdraw((string) $withdrawId,bin2hex(random_bytes(16)));
    $withdrawn = $pdo->prepare('SELECT status FROM model_talent_applications WHERE id = :id');
    $withdrawn->execute(['id'=>$applicationId]);
    $assert($withdrawn->fetchColumn() === 'withdrawn','verified applicant withdrawal uses the guarded application state machine');

    $service->updateLegalHold($admin,$applicationId,true,'Documented test retention hold for litigation preservation',bin2hex(random_bytes(16)));
    $rights->request($applicationReference,'model-applicant@example.test','erasure','203.0.113.16',bin2hex(random_bytes(16)));
    preg_match('/one-time verification code is: ([0-9]{8})/',$rightsMailer->messages[3]['body'],$holdMatch);
    $holdId = $rights->verify($applicationReference,'model-applicant@example.test',$holdMatch[1] ?? '','203.0.113.17',bin2hex(random_bytes(16)));
    $assert(!$rights->erase((string) $holdId,bin2hex(random_bytes(16))),'documented legal hold defers applicant erasure');
    $service->updateLegalHold($admin,$applicationId,false,'Legal hold release approved after the preservation duty ended',bin2hex(random_bytes(16)));
    $rights->request($applicationReference,'model-applicant@example.test','erasure','203.0.113.18',bin2hex(random_bytes(16)));
    preg_match('/one-time verification code is: ([0-9]{8})/',$rightsMailer->messages[4]['body'],$eraseMatch);
    $eraseId = $rights->verify($applicationReference,'model-applicant@example.test',$eraseMatch[1] ?? '','203.0.113.19',bin2hex(random_bytes(16)));
    $assert($rights->erase((string) $eraseId,bin2hex(random_bytes(16))),'verified erasure proceeds after legal hold is released');
    $erased = $pdo->prepare('SELECT applicant_name,email,phone,country_code,city,portfolio_url,experience_summary,anonymized_at,status FROM model_talent_applications WHERE id = :id');
    $erased->execute(['id'=>$applicationId]);
    $erasedRow = $erased->fetch();
    $assert(is_array($erasedRow) && $erasedRow['status'] === 'anonymized' && $erasedRow['anonymized_at'] !== null &&
        $erasedRow['applicant_name'] === 'Erased applicant' && $erasedRow['country_code'] === null &&
        $erasedRow['phone'] === null && $erasedRow['portfolio_url'] === null && $erasedRow['experience_summary'] === null,
        'applicant erasure removes direct personal and location fields while preserving a minimal non-identifying record');


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
