<?php
declare(strict_types=1);

use Talyvane\Application\ModelAgency\ModelPortfolioService;
use Talyvane\Application\ModelAgency\ModelAgencyAgreementService;
use Talyvane\Application\ModelAgency\ModelImageLicenceService;
use Talyvane\Application\ModelAgency\ModelCastingWorkflowService;
use Talyvane\Application\ModelAgency\ClientModelAgencyDistributionService;
use Talyvane\Application\ModelAgency\ModelAssignmentService;
use Talyvane\Application\ModelAgency\ModelSettlementService;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Infrastructure\Storage\PrivateMediaStorage;
use Talyvane\Security\Principal;
use Talyvane\Security\SecretBox;
use Talyvane\Application\ModelAgency\ModelApplicantRightsService;
use Talyvane\Application\ModelAgency\ModelAvailabilityService;
use Talyvane\Infrastructure\Security\RateLimiter;

function runModelPortfolioIntegration(PDO $pdo): int
{
    $assertions=0;
    $assert=static function(bool $ok,string $message) use (&$assertions): void {
        $assertions++;
        if(!$ok) throw new RuntimeException('Model portfolio integration failed: '.$message);
    };
    $adminId=(string)$pdo->query('SELECT id FROM users WHERE email="native-admin@example.test"')->fetchColumn();
    if($adminId==='') throw new RuntimeException('Model portfolio integration requires the identity integration administrator.');
    $admin=new Principal($adminId,['super_admin'],true);
    $applicationId=sprintf('%08x-%04x-4%03x-8%03x-%012x',random_int(0,0xffffffff),random_int(0,0xffff),random_int(0,0xfff),random_int(0,0xfff),random_int(0,0xffffffffffff));
    $pdo->prepare(
        'INSERT INTO model_talent_applications
         (id,application_reference,applicant_name,email,phone,country_code,city,portfolio_url,experience_summary,
          adult_eligibility_attested_at,privacy_acknowledged_at,privacy_notice_version,status,legal_hold,created_at,updated_at)
         VALUES (:id,:reference,"Portfolio Test Applicant","portfolio-applicant@example.test",NULL,"GB","London",NULL,
          "Synthetic integration fixture for private model portfolio testing.",UTC_TIMESTAMP(),UTC_TIMESTAMP(),
          "model-privacy-test-v1","accepted",0,UTC_TIMESTAMP(),UTC_TIMESTAMP())'
    )->execute(['id'=>$applicationId,'reference'=>'APP-PORTFOLIO-'.strtoupper(bin2hex(random_bytes(4)))]);
    $pdo->prepare(
        'INSERT INTO model_applicant_verifications
         (id,application_id,verifier_user_id,verification_method,provider_reference_hash,outcome,policy_version,verified_at,expires_at,created_at)
         VALUES (:id,:application,:verifier,"independent_provider_check",:reference,"verified","adult-check-v1",
          UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 365 DAY),UTC_TIMESTAMP())'
    )->execute([
        'id'=>sprintf('%08x-%04x-4%03x-8%03x-%012x',random_int(0,0xffffffff),random_int(0,0xffff),random_int(0,0xfff),random_int(0,0xfff),random_int(0,0xffffffffffff)),
        'application'=>$applicationId,'verifier'=>$adminId,'reference'=>hash('sha256','portfolio-verification-fixture'),
    ]);
    $pdo->prepare(
        'INSERT INTO deployment_settings (setting_key,setting_json,version,updated_by,updated_at)
         VALUES ("model_agency.portfolio",:settings,1,:actor,UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE setting_json=VALUES(setting_json),version=version+1,updated_by=VALUES(updated_by),updated_at=UTC_TIMESTAMP()'
    )->execute([
        'settings'=>json_encode(['enabled'=>true,'policy_approved'=>true,'policy_version'=>'model-portfolio-policy-test-v1'],JSON_THROW_ON_ERROR),
        'actor'=>$adminId,
    ]);

    $base=sys_get_temp_dir().DIRECTORY_SEPARATOR.'talyvane-model-portfolio-'.bin2hex(random_bytes(6));
    $publicRoot=$base.DIRECTORY_SEPARATOR.'public';$privateRoot=$base.DIRECTORY_SEPARATOR.'private';
    mkdir($publicRoot,0700,true);mkdir($privateRoot,0700,true);
    $storage=new PrivateMediaStorage($privateRoot,$publicRoot);
    $secretBox=new SecretBox(str_repeat('P',32));
    $service=new ModelPortfolioService($pdo,$storage,new AuditLogger($pdo),$secretBox,static fn():bool=>true);
    $makeAsset=static function(string $suffix) use ($pdo,$adminId,$applicationId,$privateRoot): array {
        if(!function_exists('imagewebp')) throw new RuntimeException('Model portfolio integration requires GD WebP support.');
        $id=sprintf('%08x-%04x-4%03x-8%03x-%012x',random_int(0,0xffffffff),random_int(0,0xffff),random_int(0,0xfff),random_int(0,0xfff),random_int(0,0xffffffffffff));
        $grantId=sprintf('%08x-%04x-4%03x-8%03x-%012x',random_int(0,0xffffffff),random_int(0,0xffff),random_int(0,0xfff),random_int(0,0xfff),random_int(0,0xffffffffffff));
        $directory=$privateRoot.DIRECTORY_SEPARATOR.'model-portfolio';if(!is_dir($directory)) mkdir($directory,0700,true);
        $key='model-portfolio/'.bin2hex(random_bytes(16)).'.webp';$path=$privateRoot.DIRECTORY_SEPARATOR.$key;
        $image=imagecreatetruecolor(320,320);if($image!==false) imagefill($image,0,0,imagecolorallocate($image,random_int(0,255),random_int(0,255),random_int(0,255)));if($image===false || !imagewebp($image,$path,80)) throw new RuntimeException('Could not create portfolio test image.');imagedestroy($image);chmod($path,0600);
        $hash=hash_file('sha256',$path);$size=filesize($path);
        $pdo->prepare(
            'INSERT INTO consent_grants (id,subject_type,subject_id,purpose,audience,policy_version,granted,granted_at,created_at)
             VALUES (:id,"model_portfolio_asset",:subject,"portfolio_curation","agency_internal","model-portfolio-policy-test-v1",1,UTC_TIMESTAMP(),UTC_TIMESTAMP())'
        )->execute(['id'=>$grantId,'subject'=>$id]);
        $pdo->prepare(
            'INSERT INTO model_portfolio_assets
             (id,application_id,uploaded_by,private_storage_key,mime_type,width,height,file_size,sha256,consent_grant_id,
              review_status,status,source_description,consent_scope,rights_evidence_reference,consent_evidence_reference,
              consent_policy_version,consent_recorded_at,created_at,updated_at)
             VALUES (:id,:application,:actor,:key,"image/webp",320,320,:size,:hash,:grant,
              "pending","pending_review","encrypted source description","encrypted consent scope","encrypted rights reference",
              "encrypted consent reference","model-portfolio-policy-test-v1",UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())'
        )->execute(['id'=>$id,'application'=>$applicationId,'actor'=>$adminId,'key'=>$key,'size'=>$size,'hash'=>$hash,'grant'=>$grantId]);
        return ['id'=>$id,'grant'=>$grantId,'key'=>$key,'path'=>$path];
    };

    $asset=$makeAsset('approved');
    $queue=$service->pendingReview($admin);
    $assert(count($queue)>=1 && in_array($asset['id'],array_column($queue,'id'),true),'MFA-authorized administrators receive the minimal private portfolio review queue');
    $preview=$service->preview($admin,$asset['id']);
    $assert($preview['mime']==='image/webp' && hash('sha256',$preview['bytes'])===hash_file('sha256',$asset['path']),'private portfolio preview verifies stored bytes and MIME type');
    $service->review($admin,$asset['id'],'approved','Asset rights and adult-subject evidence reviewed and approved.',bin2hex(random_bytes(16)));
    $approved=$pdo->prepare('SELECT status,review_status,review_note,private_storage_key FROM model_portfolio_assets WHERE id=:id');
    $approved->execute(['id'=>$asset['id']]);$approvedRow=$approved->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($approvedRow) && $approvedRow['status']==='approved' && $approvedRow['review_status']==='approved' && $approvedRow['private_storage_key']===$asset['key'],'approved portfolio asset remains private and retains its sanitized file');
    $assert(is_array($approvedRow) && $approvedRow['review_note']!=='Asset rights and adult-subject evidence reviewed and approved.','portfolio review note is encrypted at rest');

    $portalAsset=$makeAsset('portal-withdraw');
    $rightsMailer=new class implements \Talyvane\Infrastructure\Mail\Mailer {
        public array $messages=[];
        public function send(string $recipient,string $subject,string $textBody): void {
            $this->messages[]=['recipient'=>$recipient,'subject'=>$subject,'body'=>$textBody];
        }
    };
    $rights=new ModelApplicantRightsService($pdo,$rightsMailer,new RateLimiter($pdo,str_repeat('R',32)),
        new AuditLogger($pdo),str_repeat('S',32));
    $applicationReference=(string)$pdo->query('SELECT application_reference FROM model_talent_applications WHERE id='.$pdo->quote($applicationId))->fetchColumn();
    $rights->request($applicationReference,'portfolio-applicant@example.test','portal_access','203.0.113.41',bin2hex(random_bytes(16)));
    preg_match('/one-time verification code is: ([0-9]{8})/',$rightsMailer->messages[0]['body'],$portalCode);
    $portalRightsId=$rights->verify($applicationReference,'portfolio-applicant@example.test',$portalCode[1]??'','203.0.113.42',bin2hex(random_bytes(16)));
    try {
        $service->storeByVerifiedApplicantPortal((string)$portalRightsId,[],[],false,bin2hex(random_bytes(16)));
        $assert(false,'portfolio upload must require an explicit applicant attestation at the domain boundary');
    } catch(DomainException) {
        $assert(true,'portfolio upload must require an explicit applicant attestation at the domain boundary');
    }
    $portalRecord=$rights->portalRead((string)$portalRightsId,bin2hex(random_bytes(16)));
    $assert($rights->portalApplicationId((string)$portalRightsId)===$applicationId,
        'casting applicant identity is resolved only from the live OTP-verified portal rights session');
    $assert($portalRecord['application_reference']===$applicationReference &&
        count($portalRecord['portfolio_assets'])===2 && $portalRecord['status']==='accepted',
        'OTP-authenticated model workspace exposes only the applicant own profile and portfolio inventory');
    $rights->portalCorrect((string)$portalRightsId,[
        'applicant_name'=>'Portfolio Test Applicant Updated','phone'=>'+442071234567','city'=>'London',
        'portfolio_url'=>'https://portfolio.example.test/model','experience_summary'=>'Updated model experience and portfolio biography for the applicant self-service integration test.',
    ],bin2hex(random_bytes(16)));
    $correctedPortal=$rights->portalRead((string)$portalRightsId,bin2hex(random_bytes(16)));
    $assert($correctedPortal['applicant_name']==='Portfolio Test Applicant Updated' &&
        $correctedPortal['portfolio_url']==='https://portfolio.example.test/model',
        'verified model workspace can correct its own profile without completing the 30-minute portal session');
    $applicantPreview=$service->previewForVerifiedApplicantPortal((string)$portalRightsId,$asset['id'],bin2hex(random_bytes(16)));
    $assert($applicantPreview['mime']==='image/webp' && hash('sha256',$applicantPreview['bytes'])===hash_file('sha256',$asset['path']),
        'model applicant can preview only their own private portfolio asset with byte-integrity verification');
    $service->withdrawByVerifiedApplicantRights((string)$portalRightsId,$portalAsset['id'],bin2hex(random_bytes(16)));
    $portalWithdrawn=$pdo->prepare('SELECT status,private_storage_key,consent_withdrawn_at FROM model_portfolio_assets WHERE id=:id');
    $portalWithdrawn->execute(['id'=>$portalAsset['id']]);$portalWithdrawnRow=$portalWithdrawn->fetch(PDO::FETCH_ASSOC);
    $portalSession=$pdo->prepare('SELECT status FROM model_applicant_rights_requests WHERE id=:id');
    $portalSession->execute(['id'=>$portalRightsId]);
    $assert(is_array($portalWithdrawnRow) && $portalWithdrawnRow['status']==='withdrawn' &&
        $portalWithdrawnRow['private_storage_key']===null && $portalWithdrawnRow['consent_withdrawn_at']!==null &&
        !file_exists($portalAsset['path']) && $portalSession->fetchColumn()==='verified',
        'applicant can withdraw one portfolio image while retaining the verified workspace session for further self-service');
    $rights->logoutPortal((string)$portalRightsId,bin2hex(random_bytes(16)));
    $portalSession->execute(['id'=>$portalRightsId]);
    $assert($portalSession->fetchColumn()==='completed',
        'model workspace logout revokes the verified portal session in the database');
    try {
        $rights->portalApplicationId((string)$portalRightsId);
        $assert(false,'a revoked model workspace session cannot resolve a casting application identity');
    } catch(DomainException) {
        $assert(true,'a revoked model workspace session cannot resolve a casting application identity');
    }
    try {
        $rights->portalRead((string)$portalRightsId,bin2hex(random_bytes(16)));
        $assert(false,'a revoked model workspace session cannot be reused');
    } catch(DomainException) {
        $assert(true,'a revoked model workspace session cannot be reused');
    }



    $pdo->prepare(
        'UPDATE deployment_settings SET setting_json=:settings,version=version+1,updated_by=:actor,updated_at=UTC_TIMESTAMP() WHERE setting_key="model_agency.portfolio"'
    )->execute(['settings'=>json_encode(['enabled'=>false,'policy_approved'=>false,'policy_version'=>''],JSON_THROW_ON_ERROR),'actor'=>$adminId]);
    $service->withdrawConsent($admin,$asset['id'],'Model withdrew consent for this portfolio image and all dependent uses.', 'private/model-consent/withdrawal-evidence-0001',bin2hex(random_bytes(16)));
    $withdrawn=$pdo->prepare('SELECT status,private_storage_key,consent_withdrawn_at,withdrawal_reason FROM model_portfolio_assets WHERE id=:id');
    $withdrawn->execute(['id'=>$asset['id']]);$withdrawnRow=$withdrawn->fetch(PDO::FETCH_ASSOC);
    $grant=$pdo->prepare('SELECT granted,withdrawn_at FROM consent_grants WHERE id=:id');$grant->execute(['id'=>$asset['grant']]);$grantRow=$grant->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($withdrawnRow) && $withdrawnRow['status']==='withdrawn' && $withdrawnRow['private_storage_key']===null && $withdrawnRow['consent_withdrawn_at']!==null,'consent withdrawal disables the asset and clears its private storage pointer');
    $assert(!is_file($asset['path']),'consent withdrawal deletes sanitized image bytes from private storage');
    $assert(is_array($grantRow) && (int)$grantRow['granted']===0 && $grantRow['withdrawn_at']!==null,'consent grant is revoked even when the current feature/policy gate has been disabled');
    $assert($withdrawnRow['withdrawal_reason']!=='Model withdrew consent for this portfolio image and all dependent uses.','withdrawal reason is encrypted at rest');

    $rejectedAsset=$makeAsset('rejected');
    $service->review($admin,$rejectedAsset['id'],'rejected','Asset failed the independent image-rights and consent evidence review.',bin2hex(random_bytes(16)));
    $rejected=$pdo->prepare('SELECT status,private_storage_key FROM model_portfolio_assets WHERE id=:id');$rejected->execute(['id'=>$rejectedAsset['id']]);$rejectedRow=$rejected->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($rejectedRow) && $rejectedRow['status']==='rejected' && $rejectedRow['private_storage_key']===null,'rejected portfolio asset is no longer previewable and its storage pointer is cleared');
    $assert(!is_file($rejectedAsset['path']),'rejection deletes private portfolio image bytes');

    $pdo->prepare('UPDATE deployment_settings SET setting_json=:settings,version=version+1,updated_by=:actor,updated_at=UTC_TIMESTAMP() WHERE setting_key="model_agency.portfolio"')
        ->execute(['settings'=>json_encode(['enabled'=>true,'policy_approved'=>true,'policy_version'=>'model-portfolio-policy-test-v1'],JSON_THROW_ON_ERROR),'actor'=>$adminId]);
    $licensedAsset=$makeAsset('licensed');
    $service->review($admin,$licensedAsset['id'],'approved','Asset rights and model consent evidence reviewed for private usage licensing.',bin2hex(random_bytes(16)));
    $agreementService=new ModelAgencyAgreementService($pdo,new AuditLogger($pdo));
    $agreementId=$agreementService->createDraft($admin,$applicationId,[
        'service_scope'=>['portfolio_development','casting_submissions'],
        'commission_basis_points'=>1500,'term_months'=>12,'exclusivity'=>false,'termination_notice_days'=>30,
    ],1500,bin2hex(random_bytes(16)));
    $agreementService->transition($admin,$agreementId,'offered','Agreement offered for external execution review.',null,null,null,bin2hex(random_bytes(16)));
    $agreementService->transition($admin,$agreementId,'executed','External agreement execution confirmed.','private/model-agreements/execution-reference-0001',
        new DateTimeImmutable('now',new DateTimeZone('UTC')),new DateTimeImmutable('+12 months',new DateTimeZone('UTC')),bin2hex(random_bytes(16)));
    $pdo->prepare(
        'INSERT INTO deployment_settings (setting_key,setting_json,version,updated_by,updated_at)
         VALUES ("model_agency.image_licence",:settings,1,:actor,UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE setting_json=VALUES(setting_json),version=version+1,updated_by=VALUES(updated_by),updated_at=UTC_TIMESTAMP()'
    )->execute(['settings'=>json_encode(['enabled'=>true,'policy_approved'=>true,'policy_version'=>'image-licence-policy-test-v1'],JSON_THROW_ON_ERROR),'actor'=>$adminId]);
    $licences=new ModelImageLicenceService($pdo,new AuditLogger($pdo),$secretBox,$storage,static fn():bool=>true);
    $licenceTerms=[
        'licensee_name'=>'Verified editorial commissioning client',
        'purpose'=>'casting_distribution','channels'=>['casting_platform'],
        'purpose_details'=>'Private casting review for a defined editorial project and territory only.',
        'country_code'=>'GB',
        'starts_on'=>(new DateTimeImmutable('+1 day',new DateTimeZone('UTC')))->format('Y-m-d'),
        'ends_on'=>(new DateTimeImmutable('+14 days',new DateTimeZone('UTC')))->format('Y-m-d'),
        'exclusivity'=>false,'compensation_amount'=>null,'currency_code'=>null,
    ];
    $licenceId=$licences->createDraft($admin,$licensedAsset['id'],$agreementId,$licenceTerms,bin2hex(random_bytes(16)));
    $draft=$pdo->prepare('SELECT status,licensee_name,licence_policy_version,consent_grant_id FROM model_image_licences WHERE id=:id');
    $draft->execute(['id'=>$licenceId]);$draftRow=$draft->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($draftRow) && $draftRow['status']==='draft' && $draftRow['licence_policy_version']==='image-licence-policy-test-v1','image licence draft snapshots the current policy');
    $assert(is_array($draftRow) && $draftRow['licensee_name']!=='Verified editorial commissioning client','licensee identity is encrypted at rest');
    $grant=$pdo->prepare('SELECT granted FROM consent_grants WHERE id=:id');$grant->execute(['id'=>$draftRow['consent_grant_id']]);
    $assert((int)$grant->fetchColumn()===0,'draft licence does not imply model consent');
    $licences->offerForApplicantConsent($admin,$licenceId,'private/model-licences/execution-evidence-0001',bin2hex(random_bytes(16)));
    $notice=$pdo->prepare('SELECT id,idempotency_key,status,recipient_ciphertext,payload_ciphertext FROM communication_outbox WHERE related_type="model_licence" AND related_id=:id');
    $notice->execute(['id'=>$licenceId]);$noticeRow=$notice->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($noticeRow) && $noticeRow['status']==='pending' && str_starts_with((string)$noticeRow['idempotency_key'],'model-licence-consent-offer:'),
        'model consent offer queues one idempotent encrypted notice in the same transaction');
    $noticeRecipient=is_array($noticeRow)?$secretBox->decrypt((string)$noticeRow['recipient_ciphertext'],'communication-outbox-v1:recipient:'.$noticeRow['id']):'';
    $noticePayload=is_array($noticeRow)?json_decode($secretBox->decrypt((string)$noticeRow['payload_ciphertext'],'communication-outbox-v1:payload:'.$noticeRow['id']),true,8,JSON_THROW_ON_ERROR):[];
    $assert($noticeRecipient==='portfolio-applicant@example.test' && is_array($noticePayload) &&
        !str_contains((string)($noticePayload['body']??''),'Verified editorial commissioning client') &&
        !str_contains((string)($noticePayload['body']??''),'private/model-licences/'),
        'model consent notice is encrypted at rest and omits licence terms and internal evidence references');
    $pdo->prepare('UPDATE communication_outbox SET status="delivered",delivered_at=UTC_TIMESTAMP() WHERE id=:id')->execute(['id'=>$noticeRow['id']]);
    $offered=$pdo->prepare('SELECT status,execution_evidence_reference,applicant_consent_terms_hash FROM model_image_licences WHERE id=:id');$offered->execute(['id'=>$licenceId]);$offeredRow=$offered->fetch(PDO::FETCH_ASSOC);
    $grant->execute(['id'=>$draftRow['consent_grant_id']]);
    $portalLicence=array_values(array_filter($licences->applicantPortalLicences($applicationId),static fn(array $item):bool=>$item['id']===$licenceId));
    $assert(is_array($offeredRow) && $offeredRow['status']==='awaiting_model_consent' && $offeredRow['execution_evidence_reference']!=='' &&
        is_array($portalLicence[0]??null) && $portalLicence[0]['licensee_name']==='Verified editorial commissioning client' &&
        $portalLicence[0]['purpose_details']==='Private casting review for a defined editorial project and territory only.' &&
        $portalLicence[0]['terms_hash_valid']===true && (int)$grant->fetchColumn()===0,
        'executed licence is offered with exact model-visible terms and remains inactive until model consent');
    try {
        $licences->respondByApplicant($applicationId,$licenceId,'grant',false,
            'I have reviewed the exact image-usage terms and make this decision voluntarily.',bin2hex(random_bytes(16)));
        $assert(false,'model licence consent requires explicit attestation');
    } catch(DomainException) {
        $assert(true,'model licence consent requires explicit attestation');
    }
    $consentRequestId=bin2hex(random_bytes(16));
    $consentStatement='I reviewed the licensee, purpose, channels, territory, dates and compensation. I voluntarily grant consent for these exact image-usage terms.';
    $licences->respondByApplicant($applicationId,$licenceId,'grant',true,$consentStatement,$consentRequestId);
    $licences->respondByApplicant($applicationId,$licenceId,'grant',true,$consentStatement,$consentRequestId);
    $active=$pdo->prepare('SELECT status,execution_evidence_reference,model_consent_evidence_reference,applicant_consent_decision,applicant_consent_at,applicant_consent_statement,applicant_consent_evidence_hash FROM model_image_licences WHERE id=:id');$active->execute(['id'=>$licenceId]);$activeRow=$active->fetch(PDO::FETCH_ASSOC);
    $grant->execute(['id'=>$draftRow['consent_grant_id']]);
    $assert(is_array($activeRow) && $activeRow['status']==='active' && $activeRow['execution_evidence_reference']!=='' &&
        $activeRow['model_consent_evidence_reference']===null && $activeRow['applicant_consent_decision']==='grant' &&
        $activeRow['applicant_consent_at']!==null && $activeRow['applicant_consent_statement']!==$consentStatement &&
        $activeRow['applicant_consent_evidence_hash']!==null && (int)$grant->fetchColumn()===1,
        'only the applicant grants purpose-specific consent after execution and the statement is encrypted');
    $applicantConsentEvent=$pdo->prepare('SELECT actor_type FROM model_talent_application_events WHERE application_id=:application AND reason LIKE "%Model applicant granted consent%" ORDER BY created_at DESC LIMIT 1');
    $applicantConsentEvent->execute(['application'=>$applicationId]);
    $assert($applicantConsentEvent->fetchColumn()==='applicant','model licence consent is attributed to the applicant without a synthetic user');
    try {
        $licences->createDraft($admin,$licensedAsset['id'],$agreementId,$licenceTerms,bin2hex(random_bytes(16)));
        $assert(false,'overlapping active same-purpose licence is rejected');
    } catch(DomainException) {
        $assert(true,'overlapping active same-purpose licence is rejected');
    }
    $casting=new ModelCastingWorkflowService($pdo,new AuditLogger($pdo),$storage,$secretBox,static fn():bool=>true);
    $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
    $callId=$casting->createDraft($admin,[
        'title'=>'Private editorial casting submission test',
        'client_name'=>'Verified editorial commissioning client',
        'client_reference'=>'client-ref-casting-test',
        'project_purpose'=>'editorial',
        'description'=>'A private editorial project brief for integration verification of casting eligibility and model usage rights.',
        'requirements'=>'Adult eligibility verification, approved private portfolio and current casting distribution consent are mandatory.',
        'country_code'=>'GB',
        'opens_at'=>$now->modify('-1 hour')->format(DATE_ATOM),
        'closes_at'=>$now->modify('+5 days')->format(DATE_ATOM),
        'shoot_starts_at'=>$now->modify('+10 days')->format(DATE_ATOM),
        'shoot_ends_at'=>$now->modify('+11 days')->format(DATE_ATOM),
        'compensation_amount'=>null,'currency_code'=>null,'max_submissions'=>2,
    ],bin2hex(random_bytes(16)));
    $casting->publish($admin,$callId,bin2hex(random_bytes(16)));
    // The earlier portal session was deliberately logged out. Start a fresh OTP session
    // for the later casting self-service assertions rather than reusing revoked credentials.
    $rights->request($applicationReference,'portfolio-applicant@example.test','portal_access','203.0.113.43',bin2hex(random_bytes(16)));
    $latestPortalMessage=$rightsMailer->messages[array_key_last($rightsMailer->messages)];
    preg_match('/one-time verification code is: ([0-9]{8})/',$latestPortalMessage['body'],$nextPortalCode);
    $portalRightsId=$rights->verify($applicationReference,'portfolio-applicant@example.test',$nextPortalCode[1]??'','203.0.113.44',bin2hex(random_bytes(16)));
    if(!is_string($portalRightsId)) throw new RuntimeException('A fresh OTP model workspace session is required for casting self-service integration.');
    $applicantCastingPortal=$rights->portalRead((string)$portalRightsId,bin2hex(random_bytes(16)));
    $opportunities=$applicantCastingPortal['available_casting_calls']??[];
    $opportunity=array_values(array_filter($opportunities,static fn(array $item):bool=>($item['id']??null)===$callId));
    $assert(count($opportunity)===1 && !array_key_exists('description',$opportunity[0]) && !array_key_exists('requirements',$opportunity[0]),
        'applicant opportunity listing never exposes encrypted casting-brief ciphertext as display text');
    $clientId=(string)$pdo->query('SELECT UUID()')->fetchColumn();
    $pdo->prepare(
        'INSERT INTO users (id,email,password_hash,status,email_verified_at,first_login_required,created_at,updated_at)
         VALUES (:id,"native-model-distribution-client@example.test",:hash,"active",UTC_TIMESTAMP(),0,UTC_TIMESTAMP(),UTC_TIMESTAMP())'
    )->execute(['id'=>$clientId,'hash'=>password_hash(bin2hex(random_bytes(24)),PASSWORD_DEFAULT)]);
    $pdo->prepare('INSERT INTO user_roles (user_id,role_key,granted_by,granted_at) VALUES (:id,"client",:actor,UTC_TIMESTAMP())')
        ->execute(['id'=>$clientId,'actor'=>$adminId]);
    $clientDistribution=new ClientModelAgencyDistributionService($pdo,$storage,new AuditLogger($pdo),$secretBox);
    $clientDistribution->linkCastingCall($admin,$callId,$clientId,bin2hex(random_bytes(16)));
    $clientPrincipal=new Principal($clientId,['client'],false);
    $distributedCalls=$clientDistribution->castingCalls($clientPrincipal);
    $distributedCall=array_values(array_filter($distributedCalls,static fn(array $item):bool=>$item['id']===$callId));
    $assert(count($distributedCall)===1 &&
        str_contains($distributedCall[0]['description'],'private editorial project brief') &&
        str_contains($distributedCall[0]['requirements'],'Adult eligibility verification'),
        'client sees only its linked casting brief with encrypted project terms decrypted for the verified tenant');

    $candidates=$casting->eligibleCandidates($admin,$callId);
    $assert(count($candidates)===1 && $candidates[0]['application_id']===$applicationId,'eligible-candidate register requires current adult verification, portfolio consent, executed agreement and active casting licence');
    $submissionId=$casting->submitByApplicant($applicationId,$callId,$licensedAsset['id'],$licenceId,bin2hex(random_bytes(16)));
    $applicantSubmission=$pdo->prepare('SELECT status,submitted_by FROM model_casting_submissions WHERE id=:id');
    $applicantSubmission->execute(['id'=>$submissionId]);$applicantSubmissionRow=$applicantSubmission->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($applicantSubmissionRow) && $applicantSubmissionRow['status']==='submitted' && $applicantSubmissionRow['submitted_by']===null,
        'verified applicant self-service submits without inventing a user account or administrator actor');
    $applicantEvent=$pdo->prepare('SELECT actor_type,actor_id,reason FROM model_casting_events WHERE submission_id=:id AND event_type="casting.applicant_submitted"');
    $applicantEvent->execute(['id'=>$submissionId]);$applicantEventRow=$applicantEvent->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($applicantEventRow) && $applicantEventRow['actor_type']==='applicant' && $applicantEventRow['actor_id']===null &&
        str_contains((string)$applicantEventRow['reason'],'model-casting-voluntary-submission-v1'),
        'applicant-originated casting submission records the voluntary attestation version and applicant-session attribution'); 
    try {
        $casting->submit($admin,$callId,$applicationId,$licensedAsset['id'],$licenceId,bin2hex(random_bytes(16)));
        $assert(false,'duplicate submission is rejected regardless of whether it originated from applicant or agency');
    } catch(DomainException) {
        $assert(true,'duplicate submission is rejected regardless of whether it originated from applicant or agency');
    }
    $casting->decide($admin,$submissionId,'shortlisted','Model shortlisted after human review of the private casting submission.',bin2hex(random_bytes(16)));
    $casting->decide($admin,$submissionId,'selected','Model selected after final human review and confirmation of the defined project scope.',bin2hex(random_bytes(16)));
    $submissionRows=$casting->submissionsForCall($admin,$callId);
    $assert(count($submissionRows)===1 && $submissionRows[0]['status']==='selected','private casting submission supports audited shortlist and selection decisions');
    $clientCandidateRows=$clientDistribution->candidates($clientPrincipal,$callId);
    $assert(count($clientCandidateRows)===1 && $clientCandidateRows[0]['id']===$submissionId &&
        $clientCandidateRows[0]['status']==='selected',
        'client distribution exposes only selected, currently consented candidates whose encrypted licencee matches the casting client');
    $clientImage=$clientDistribution->candidateImage($clientPrincipal,$callId,$submissionId,bin2hex(random_bytes(16)));
    $assert($clientImage['mime']==='image/webp' && strlen($clientImage['bytes'])>100,
        'client candidate image endpoint revalidates rights and serves verified private image bytes');
    $imageAudit=$pdo->prepare('SELECT COUNT(*) FROM audit_events WHERE actor_user_id=:client AND event_key="client_model_casting.image_viewed" AND target_id=:submission');
    $imageAudit->execute(['client'=>$clientId,'submission'=>$submissionId]);
    $assert((int)$imageAudit->fetchColumn()===1,'private casting image views are audit logged without recording personal data');


    foreach([['finance','Finance'],['owner','Owner']] as [$roleKey,$label]) {
        $pdo->prepare('INSERT INTO roles (role_key,label) VALUES (:role,:label) ON DUPLICATE KEY UPDATE label=VALUES(label)')
            ->execute(['role'=>$roleKey,'label'=>$label]);
    }
    $makeRoleUser=static function(string $role) use ($pdo,$adminId): string {
        $id=sprintf('%08x-%04x-4%03x-8%03x-%012x',random_int(0,0xffffffff),random_int(0,0xffff),random_int(0,0xfff),random_int(0,0xfff),random_int(0,0xffffffffffff));
        $email='model-'.$role.'-'.bin2hex(random_bytes(4)).'@example.test';
        $pdo->prepare(
            'INSERT INTO users (id,email,password_hash,status,email_verified_at,first_login_required,created_at,updated_at)
             VALUES (:id,:email,:hash,"active",UTC_TIMESTAMP(),0,UTC_TIMESTAMP(),UTC_TIMESTAMP())'
        )->execute(['id'=>$id,'email'=>$email,'hash'=>password_hash(bin2hex(random_bytes(24)),PASSWORD_DEFAULT)]);
        $pdo->prepare('INSERT INTO user_roles (user_id,role_key,granted_by,granted_at) VALUES (:id,:role,:grantor,UTC_TIMESTAMP())')
            ->execute(['id'=>$id,'role'=>$role,'grantor'=>$adminId]);
        return $id;
    };
    $financeId=$makeRoleUser('finance');$ownerId=$makeRoleUser('owner');
    $finance=new Principal($financeId,['finance'],true);$owner=new Principal($ownerId,['owner'],true);

    foreach([
        'model_agency.assignment'=>['enabled'=>true,'policy_approved'=>true,'policy_version'=>'assignment-policy-test-v1'],
        'model_agency.settlement'=>['enabled'=>true,'policy_approved'=>true,'policy_version'=>'settlement-policy-test-v1'],
    ] as $settingKey=>$settingValue) {
        $pdo->prepare(
            'INSERT INTO deployment_settings (setting_key,setting_json,version,updated_by,updated_at)
             VALUES (:key,:settings,1,:actor,UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE setting_json=VALUES(setting_json),version=version+1,updated_by=VALUES(updated_by),updated_at=UTC_TIMESTAMP()'
        )->execute(['key'=>$settingKey,'settings'=>json_encode($settingValue,JSON_THROW_ON_ERROR),'actor'=>$adminId]);
    }
    $assignments=new ModelAssignmentService($pdo,new AuditLogger($pdo),$secretBox,$storage,static fn():bool=>true);
    $shootStart=$now->modify('+10 days')->format('Y-m-d H:i:s');
    $shootEnd=$now->modify('+11 days')->format('Y-m-d H:i:s');
    $availability=new ModelAvailabilityService($pdo,new AuditLogger($pdo));
    $startIso=(new DateTimeImmutable($shootStart,new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    $endIso=(new DateTimeImmutable($shootEnd,new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    $availabilityRequestId=bin2hex(random_bytes(16));
    $availabilityBlockId=$availability->createByApplicant($applicationId,$startIso,$endIso,'travel',$availabilityRequestId);
    $assert(count(array_filter($availability->listForApplicant($applicationId),static fn(array $block):bool=>$block['id']===$availabilityBlockId && $block['status']==='active' && $block['starts_at']===$shootStart))===1,
        'model availability is stored in UTC and scoped to the applicant application');
    $assert($availability->createByApplicant($applicationId,$startIso,$endIso,'travel',$availabilityRequestId)===$availabilityBlockId,
        'availability create is idempotent for the same request and terms');
    try {
        $availability->createByApplicant($applicationId,
            (new DateTimeImmutable($shootStart,new DateTimeZone('UTC')))->modify('+1 hour')->format('Y-m-d\TH:i:s\Z'),
            (new DateTimeImmutable($shootEnd,new DateTimeZone('UTC')))->modify('+1 hour')->format('Y-m-d\TH:i:s\Z'),
            'personal',bin2hex(random_bytes(16)));
        $assert(false,'overlapping model unavailability blocks are rejected');
    } catch(DomainException) {
        $assert(true,'overlapping model unavailability blocks are rejected');
    }
    $assignmentTerms=[
        'project_purpose'=>'editorial','client_name'=>'Verified editorial commissioning client',
        'client_reference'=>'editorial-client-ref-001',
        'brief'=>'A defined editorial assignment brief for the model and commissioning client.',
        'location_city'=>'London',
        'shoot_starts_at'=>$shootStart,'shoot_ends_at'=>$shootEnd,
        'deliverables'=>'One editorial shoot with the agreed image selection and usage scope.',
        'compensation_amount'=>'800.00','currency_code'=>'GBP','expense_cap_amount'=>'75.00',
    ];
    try {
        $assignments->createDraft($admin,$submissionId,$assignmentTerms,bin2hex(random_bytes(16)));
        $assert(false,'assignment creation is blocked by model-recorded unavailability');
    } catch(DomainException) {
        $assert(true,'assignment creation is blocked by model-recorded unavailability');
    }
    $availability->cancelByApplicant($applicationId,$availabilityBlockId,bin2hex(random_bytes(16)));
    $availability->cancelByApplicant($applicationId,$availabilityBlockId,bin2hex(random_bytes(16)));
    $assignmentId=$assignments->createDraft($admin,$submissionId,$assignmentTerms,bin2hex(random_bytes(16)));
    $assert($assignments->applicantAssignments($applicationId)===[],'unoffered assignment drafts are never exposed in the applicant workspace');
    $assignments->offer($admin,$assignmentId,bin2hex(random_bytes(16)));
    $assignmentNotice=$pdo->prepare('SELECT id,status,recipient_ciphertext,payload_ciphertext FROM communication_outbox WHERE related_type="model_assignment" AND related_id=:id');
    $assignmentNotice->execute(['id'=>$assignmentId]);$assignmentNoticeRow=$assignmentNotice->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($assignmentNoticeRow) && $assignmentNoticeRow['status']==='pending' &&
        $secretBox->decrypt((string)$assignmentNoticeRow['recipient_ciphertext'],'communication-outbox-v1:recipient:'.$assignmentNoticeRow['id'])==='portfolio-applicant@example.test' &&
        str_contains($secretBox->decrypt((string)$assignmentNoticeRow['payload_ciphertext'],'communication-outbox-v1:payload:'.$assignmentNoticeRow['id']),'not confirmed unless you accept'),
        'model assignment offer queues an encrypted, minimal notice in the same transaction');
    $pdo->prepare('UPDATE communication_outbox SET status="delivered",delivered_at=UTC_TIMESTAMP() WHERE id=:id')->execute(['id'=>$assignmentNoticeRow['id']]);
    $modelAssignmentTerms=$assignments->applicantAssignments($applicationId);
    $modelAssignmentTerm=array_values(array_filter($modelAssignmentTerms,static fn(array $item):bool=>$item['id']===$assignmentId));
    $assert(count($modelAssignmentTerm)===1 &&
        $modelAssignmentTerm[0]['client_name']==='Verified editorial commissioning client' &&
        $modelAssignmentTerm[0]['brief']==='A defined editorial assignment brief for the model and commissioning client.' &&
        $modelAssignmentTerm[0]['location_city']==='London' &&
        $modelAssignmentTerm[0]['deliverables']==='One editorial shoot with the agreed image selection and usage scope.' &&
        $modelAssignmentTerm[0]['compensation_amount']==='800.00' && $modelAssignmentTerm[0]['expense_cap_amount']==='75.00' &&
        (int)$modelAssignmentTerm[0]['execution_evidence_ready']===0 && $modelAssignmentTerm[0]['response_enabled']===true &&
        !array_key_exists('commission_basis_points',$modelAssignmentTerm[0]),
        'verified model workspace receives decrypted assignment terms while internal agency commission remains private');
    try {
        $assignments->confirm($admin,$assignmentId,bin2hex(random_bytes(16)));
        $assert(false,'assignment cannot be confirmed before external execution and model-consent evidence are recorded');
    } catch(DomainException) {
        $assert(true,'assignment cannot be confirmed before external execution and model-consent evidence are recorded');
    }
    $applicantApplicationId=$rights->portalApplicationId((string)$portalRightsId);
    try {
        $assignments->respondByApplicant($applicantApplicationId,$assignmentId,'accept',false,
            'I have reviewed the assignment terms and voluntarily accept this assignment.',bin2hex(random_bytes(16)));
        $assert(false,'applicant assignment response requires explicit attestation');
    } catch(DomainException) {
        $assert(true,'applicant assignment response requires explicit attestation');
    }
    try {
        $assignments->respondByApplicant($applicantApplicationId,$assignmentId,'accept',true,
            'I have reviewed the assignment terms, compensation, shoot schedule and image-use scope, and I voluntarily accept this assignment.',
            bin2hex(random_bytes(16)));
        $assert(false,'model cannot accept an assignment before external execution evidence is recorded');
    } catch(DomainException) {
        $assert(true,'model cannot accept an assignment before external execution evidence is recorded');
    }
    $assignments->recordExecutionEvidence($admin,$assignmentId,'private/model-assignments/executed-document-0001',bin2hex(random_bytes(16)));
    $acceptRequestId=bin2hex(random_bytes(16));
    $acceptanceStatement='I have reviewed the assignment terms, compensation, shoot schedule and image-use scope, and I voluntarily accept this assignment.';
    $assignments->respondByApplicant($applicantApplicationId,$assignmentId,'accept',true,$acceptanceStatement,$acceptRequestId);
    $assignments->respondByApplicant($applicantApplicationId,$assignmentId,'accept',true,$acceptanceStatement,$acceptRequestId);
    $acceptedEventCount=(int)$pdo->query('SELECT COUNT(*) FROM model_assignment_events WHERE assignment_id='.$pdo->quote($assignmentId).' AND event_type="assignment.applicant_accepted"')->fetchColumn();
    $assert($acceptedEventCount===1,'replayed applicant acceptance is idempotent and creates only one lifecycle event');
    try {
        $assignments->respondByApplicant($applicantApplicationId,$assignmentId,'decline',true,
            'I voluntarily decline this assignment after reviewing the terms.',$acceptRequestId);
        $assert(false,'an idempotency key cannot be reused for a different applicant decision');
    } catch(DomainException) {
        $assert(true,'an idempotency key cannot be reused for a different applicant decision');
    }
    $acceptedAssignment=$pdo->prepare('SELECT status,acceptance_recorded_by,model_acceptance_evidence_reference,applicant_response_version,applicant_response_request_fingerprint,applicant_response_terms_hash FROM model_assignments WHERE id=:id');
    $acceptedAssignment->execute(['id'=>$assignmentId]);$acceptedAssignmentRow=$acceptedAssignment->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($acceptedAssignmentRow) && $acceptedAssignmentRow['status']==='accepted' &&
        $acceptedAssignmentRow['acceptance_recorded_by']===null &&
        is_string($acceptedAssignmentRow['model_acceptance_evidence_reference']) && $acceptedAssignmentRow['model_acceptance_evidence_reference']!==$acceptanceStatement &&
        $acceptedAssignmentRow['applicant_response_version']==='model-assignment-applicant-response-v1' &&
        preg_match('/^[a-f0-9]{64}$/D',(string)$acceptedAssignmentRow['applicant_response_request_fingerprint'])===1 &&
        preg_match('/^[a-f0-9]{64}$/D',(string)$acceptedAssignmentRow['applicant_response_terms_hash'])===1,
        'OTP-verified model acceptance is recorded as an applicant actor with encrypted consent evidence, not an administrator attestation');
    $applicantAssignmentEvent=$pdo->prepare('SELECT actor_type,actor_user_id FROM model_assignment_events WHERE assignment_id=:id AND event_type="assignment.applicant_accepted"');
    $applicantAssignmentEvent->execute(['id'=>$assignmentId]);$applicantAssignmentEventRow=$applicantAssignmentEvent->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($applicantAssignmentEventRow) && $applicantAssignmentEventRow['actor_type']==='applicant' && $applicantAssignmentEventRow['actor_user_id']===null,
        'model assignment response is attributed to the verified applicant session without a synthetic user account');
    $assignments->confirm($admin,$assignmentId,bin2hex(random_bytes(16)));
    $assignments->start($admin,$assignmentId,bin2hex(random_bytes(16)));
    $pausedReplay=new ModelAssignmentService($pdo,new AuditLogger($pdo),$secretBox,$storage,static fn():bool=>false);
    $pausedReplay->respondByApplicant($applicantApplicationId,$assignmentId,'accept',true,$acceptanceStatement,$acceptRequestId);
    $acceptedEventCountAfterProgression=(int)$pdo->query('SELECT COUNT(*) FROM model_assignment_events WHERE assignment_id='.$pdo->quote($assignmentId).' AND event_type="assignment.applicant_accepted"')->fetchColumn();
    $assert($acceptedEventCountAfterProgression===1,'acceptance replay remains idempotent after the assignment advances to in-progress');
    $assignmentRow=$pdo->prepare('SELECT status,compensation_minor,expense_cap_minor,commission_basis_points,client_name FROM model_assignments WHERE id=:id');
    $assignmentRow->execute(['id'=>$assignmentId]);$assignmentDraft=$assignmentRow->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($assignmentDraft) && $assignmentDraft['status']==='in_progress' && (int)$assignmentDraft['compensation_minor']===80000 &&
        (int)$assignmentDraft['expense_cap_minor']===7500 && (int)$assignmentDraft['commission_basis_points']===1500,'assignment lifecycle snapshots agreed compensation, expense cap and executed-agreement commission');
    $assert(is_array($assignmentDraft) && $assignmentDraft['client_name']!=='Verified editorial commissioning client','assignment client identity is encrypted at rest');
    $clientDistribution->linkAssignmentToCastingClient($admin,$assignmentId,bin2hex(random_bytes(16)));
    $clientAssignments=$clientDistribution->assignments($clientPrincipal);
    $assert(count($clientAssignments)===1 && $clientAssignments[0]['id']===$assignmentId &&
        $clientAssignments[0]['brief']==='A defined editorial assignment brief for the model and commissioning client.' &&
        $clientAssignments[0]['deliverables']==='One editorial shoot with the agreed image selection and usage scope.',
        'client receives only the source-casting client assignment with authorized brief and deliverables; agency commission is withheld');
    $assert(!array_key_exists('commission_basis_points',$clientAssignments[0]),
        'internal agency commission terms are not exposed in the client assignment register');


    $declineCall=$casting->createDraft($admin,[
        'title'=>'Private assignment decline test call','client_name'=>'Verified editorial commissioning client',
        'client_reference'=>'client-ref-casting-decline','project_purpose'=>'editorial',
        'description'=>'A second private editorial project brief for testing applicant assignment decline operations.',
        'requirements'=>'Current portfolio approval, adult verification and purpose-specific consent remain mandatory.',
        'country_code'=>'GB','opens_at'=>$now->modify('-1 hour')->format(DATE_ATOM),
        'closes_at'=>$now->modify('+5 days')->format(DATE_ATOM),
        'shoot_starts_at'=>$now->modify('+12 days')->format(DATE_ATOM),
        'shoot_ends_at'=>$now->modify('+13 days')->format(DATE_ATOM),
        'compensation_amount'=>null,'currency_code'=>null,'max_submissions'=>1,
    ],bin2hex(random_bytes(16)));
    $casting->publish($admin,$declineCall,bin2hex(random_bytes(16)));
    $declineSubmission=$casting->submitByApplicant($applicationId,$declineCall,$licensedAsset['id'],$licenceId,bin2hex(random_bytes(16)));
    $casting->decide($admin,$declineSubmission,'shortlisted','Model shortlisted after human review of the second private casting submission.',bin2hex(random_bytes(16)));
    $casting->decide($admin,$declineSubmission,'selected','Model selected after human review for the second private casting assignment.',bin2hex(random_bytes(16)));
    $declineAssignment=$assignments->createDraft($admin,$declineSubmission,[
        'project_purpose'=>'editorial','client_name'=>'Verified editorial commissioning client',
        'client_reference'=>'editorial-client-ref-decline','brief'=>'A second defined editorial assignment brief for model decline workflow testing.',
        'location_city'=>'London','shoot_starts_at'=>$now->modify('+12 days')->format('Y-m-d H:i:s'),
        'shoot_ends_at'=>$now->modify('+13 days')->format('Y-m-d H:i:s'),
        'deliverables'=>'One editorial shoot with the agreed image selection and usage scope.',
        'compensation_amount'=>'800.00','currency_code'=>'GBP','expense_cap_amount'=>'75.00',
    ],bin2hex(random_bytes(16)));
    $assignments->offer($admin,$declineAssignment,bin2hex(random_bytes(16)));
    $pdo->prepare('UPDATE communication_outbox SET status="delivered",delivered_at=UTC_TIMESTAMP() WHERE related_type="model_assignment" AND related_id=:id')->execute(['id'=>$declineAssignment]);
    $pausedAssignments=new ModelAssignmentService($pdo,new AuditLogger($pdo),$secretBox,$storage,static fn():bool=>false);
    try {
        $pausedAssignments->respondByApplicant($applicantApplicationId,$declineAssignment,'accept',true,
            'I have reviewed the assignment terms and voluntarily accept this assignment.',bin2hex(random_bytes(16)));
        $assert(false,'applicant acceptance is paused when the assignment feature is disabled');
    } catch(DomainException) {
        $assert(true,'applicant acceptance is paused when the assignment feature is disabled');
    }
    $declineRequestId=bin2hex(random_bytes(16));
    $declineStatement='I have reviewed this offer and voluntarily decline because the proposed dates do not fit my current availability.';
    $pausedAssignments->respondByApplicant($applicantApplicationId,$declineAssignment,'decline',true,$declineStatement,$declineRequestId);
    $pausedAssignments->respondByApplicant($applicantApplicationId,$declineAssignment,'decline',true,$declineStatement,$declineRequestId);
    $declinedRow=$pdo->prepare('SELECT status,declined_by,decline_reason,model_decline_evidence_reference,applicant_response_version,applicant_response_request_fingerprint,applicant_response_terms_hash FROM model_assignments WHERE id=:id');
    $declinedRow->execute(['id'=>$declineAssignment]);$declinedAssignmentRow=$declinedRow->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($declinedAssignmentRow) && $declinedAssignmentRow['status']==='declined' &&
        $declinedAssignmentRow['declined_by']===null && is_string($declinedAssignmentRow['decline_reason']) &&
        $declinedAssignmentRow['decline_reason']!==$declineStatement &&
        is_string($declinedAssignmentRow['model_decline_evidence_reference']) && $declinedAssignmentRow['model_decline_evidence_reference']!=='' &&
        $declinedAssignmentRow['applicant_response_version']==='model-assignment-applicant-response-v1' &&
        preg_match('/^[a-f0-9]{64}$/D',(string)$declinedAssignmentRow['applicant_response_request_fingerprint'])===1 &&
        preg_match('/^[a-f0-9]{64}$/D',(string)$declinedAssignmentRow['applicant_response_terms_hash'])===1,
        'model can decline an offered assignment from the OTP portal with encrypted reason and applicant actor attribution');
    $declineEvent=$pdo->prepare('SELECT actor_type,actor_user_id FROM model_assignment_events WHERE assignment_id=:id AND event_type="assignment.applicant_declined"');
    $declineEvent->execute(['id'=>$declineAssignment]);$declineEventRow=$declineEvent->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($declineEventRow) && $declineEventRow['actor_type']==='applicant' && $declineEventRow['actor_user_id']===null,
        'applicant decline event is append-only and does not fabricate a user identity');

    $assignmentEventId=(string)$pdo->query('SELECT id FROM model_assignment_events WHERE assignment_id='.$pdo->quote($assignmentId).' ORDER BY occurred_at LIMIT 1')->fetchColumn();
    try {
        $pdo->prepare('UPDATE model_assignment_events SET reason="tampered" WHERE id=:id')->execute(['id'=>$assignmentEventId]);
        $assert(false,'assignment lifecycle events reject database-level mutation');
    } catch(PDOException) {
        $assert(true,'assignment lifecycle events reject database-level mutation');
    }

    $settlements=new ModelSettlementService($pdo,new AuditLogger($pdo),$secretBox,static fn():bool=>true);
    $expenseRequestId=bin2hex(random_bytes(16));
    $expenseId=$settlements->addExpense($owner,$assignmentId,'25.00','GBP',
        'Pre-approved local transport expense for the defined shoot.','private/model-expenses/expense-evidence-0001',$expenseRequestId);
    $replayedExpenseId=$settlements->addExpense($owner,$assignmentId,'25.00','GBP',
        'Pre-approved local transport expense for the defined shoot.','private/model-expenses/expense-evidence-0001',$expenseRequestId);
    $assert($replayedExpenseId===$expenseId,'replayed expense submission returns the original expense without duplicating the ledger');

    try {
        $settlements->addExpense($owner,$assignmentId,'60.00','GBP',
            'Additional transport and equipment expense exceeding the remaining agreed cap.','private/model-expenses/expense-evidence-over-cap-0001',bin2hex(random_bytes(16)));
        $assert(false,'expense claims exceeding the remaining assignment cap are rejected');
    } catch(DomainException) {
        $assert(true,'expense claims exceeding the remaining assignment cap are rejected');
    }
    $expenseRows=$settlements->expenses($finance,$assignmentId);
    $assert(count($expenseRows)===1 && $expenseRows[0]['description']==='Pre-approved local transport expense for the defined shoot.' &&
        $expenseRows[0]['evidence_reference']==='private/model-expenses/expense-evidence-0001',
        'finance expense register decrypts only authorized expense descriptions and opaque evidence references');
    try {
        $settlements->reviewExpense($owner,$expenseId,'approved','Expense reviewed and approved by the submitting owner.',bin2hex(random_bytes(16)));
        $assert(false,'expense submitter cannot review their own expense claim');
    } catch(DomainException) {
        $assert(true,'expense submitter cannot review their own expense claim');
    }
    $settlements->reviewExpense($finance,$expenseId,'approved','Transport expense evidence and assignment cap independently verified.',bin2hex(random_bytes(16)));
    $assignments->complete($admin,$assignmentId,'private/model-assignments/completion-evidence-0001',bin2hex(random_bytes(16)));
    $settlementId=$settlements->createDraft($finance,$assignmentId,bin2hex(random_bytes(16)));
    $settlementRow=$pdo->prepare('SELECT status,gross_minor,approved_expenses_minor,commission_minor,model_net_minor,created_by,calculation_snapshot FROM model_settlements WHERE id=:id');
    $settlementRow->execute(['id'=>$settlementId]);$settlementDraft=$settlementRow->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($settlementDraft) && $settlementDraft['status']==='draft' && (int)$settlementDraft['gross_minor']===80000 &&
        (int)$settlementDraft['approved_expenses_minor']===2500 && (int)$settlementDraft['commission_minor']===12000 &&
        (int)$settlementDraft['model_net_minor']===70500,'settlement reconciles gross, 15 percent commission and reimbursable approved expenses using integer minor units');

    $settlementEventId=(string)$pdo->query('SELECT id FROM model_settlement_events WHERE settlement_id='.$pdo->quote($settlementId).' ORDER BY occurred_at LIMIT 1')->fetchColumn();
    try {
        $pdo->prepare('DELETE FROM model_settlement_events WHERE id=:id')->execute(['id'=>$settlementEventId]);
        $assert(false,'settlement lifecycle events reject database-level deletion');
    } catch(PDOException) {
        $assert(true,'settlement lifecycle events reject database-level deletion');
    }
    try {
        $settlements->approve($finance,$settlementId,bin2hex(random_bytes(16)));
        $assert(false,'settlement creator cannot approve their own settlement');
    } catch(DomainException) {
        $assert(true,'settlement creator cannot approve their own settlement');
    }
    $settlements->approve($owner,$settlementId,bin2hex(random_bytes(16)));
    try {
        $settlements->recordPayment($finance,$settlementId,'bank_transfer','PAYMENT-TEST-001',
            'private/model-settlements/payment-evidence-0001',bin2hex(random_bytes(16)));
        $assert(false,'settlement creator cannot attest their own payment');
    } catch(DomainException) {
        $assert(true,'settlement creator cannot attest their own payment');
    }
    $settlements->recordPayment($admin,$settlementId,'bank_transfer','PAYMENT-TEST-001',
        'private/model-settlements/payment-evidence-0001',bin2hex(random_bytes(16)));
    $paid=$pdo->prepare('SELECT status,payment_reference,external_payment_evidence,paid_by FROM model_settlements WHERE id=:id');
    $paid->execute(['id'=>$settlementId]);$paidRow=$paid->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($paidRow) && $paidRow['status']==='paid' && $paidRow['paid_by']===$adminId &&
        $paidRow['payment_reference']!=='PAYMENT-TEST-001' && $paidRow['external_payment_evidence']!=='private/model-settlements/payment-evidence-0001',
        'distinct payer records encrypted external payment evidence without initiating a transfer');
    $settlements->reverse($finance,$settlementId,'External payment was reversed by the originating payment institution.','private/model-settlements/reversal-evidence-0001',bin2hex(random_bytes(16)));
    $reversed=$pdo->prepare('SELECT status,reversal_reason,reversal_evidence FROM model_settlements WHERE id=:id');
    $reversed->execute(['id'=>$settlementId]);$reversedRow=$reversed->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($reversedRow) && $reversedRow['status']==='reversed' &&
        $reversedRow['reversal_reason']!=='External payment was reversed by the originating payment institution.' &&
        $reversedRow['reversal_evidence']!=='private/model-settlements/reversal-evidence-0001',
        'settlement reversal is separately evidenced and sensitive reason/reference data is encrypted');

    try {
        $casting->submit($admin,$callId,$applicationId,$licensedAsset['id'],$licenceId,bin2hex(random_bytes(16)));
        $assert(false,'duplicate application submission to one casting call is rejected');
    } catch(DomainException) {
        $assert(true,'duplicate application submission to one casting call is rejected');
    }

    $pdo->prepare('UPDATE deployment_settings SET setting_json=:settings,version=version+1,updated_by=:actor,updated_at=UTC_TIMESTAMP() WHERE setting_key="model_agency.image_licence"')
        ->execute(['settings'=>json_encode(['enabled'=>false,'policy_approved'=>false,'policy_version'=>''],JSON_THROW_ON_ERROR),'actor'=>$adminId]);
    $wrongApplicationRejected=false;
    try {
        $licences->withdrawConsentByApplicant('00000000-0000-4000-8000-000000000001',$licenceId,bin2hex(random_bytes(16)));
    } catch(DomainException) {
        $wrongApplicationRejected=true;
    }
    $assert($wrongApplicationRejected,'applicant cannot withdraw a licence belonging to another application');
    $applicantApplicationForWithdrawal=$rights->portalApplicationIdForConsentWithdrawal((string)$portalRightsId);
    $licences->withdrawConsentByApplicant($applicantApplicationForWithdrawal,$licenceId,bin2hex(random_bytes(16)));
    $revoked=$pdo->prepare('SELECT status,revocation_reason FROM model_image_licences WHERE id=:id');$revoked->execute(['id'=>$licenceId]);$revokedRow=$revoked->fetch(PDO::FETCH_ASSOC);
    $grant->execute(['id'=>$draftRow['consent_grant_id']]);
    $assert(is_array($revokedRow) && $revokedRow['status']==='revoked' && $revokedRow['revocation_reason']!=='Model applicant withdrew consent for this specific image usage.','applicant consent-withdrawal reason is encrypted at rest');
    $assert((int)$grant->fetchColumn()===0,'revocation withdraws model consent even when new licences are disabled');
    $submissionState=$pdo->prepare('SELECT status,invalidation_reason FROM model_casting_submissions WHERE id=:id');$submissionState->execute(['id'=>$submissionId]);$submissionStateRow=$submissionState->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($submissionStateRow) && $submissionStateRow['status']==='invalidated' && $submissionStateRow['invalidation_reason']!=='Underlying image licence was revoked or expired.','licence revocation invalidates selected casting submissions and encrypts the reason');

    try {
        $assignments->createDraft($admin,$submissionId,[
            'project_purpose'=>'editorial','client_name'=>'Verified editorial commissioning client',
            'client_reference'=>'editorial-client-ref-001',
            'brief'=>'A defined editorial assignment brief for the model and commissioning client.',
            'location_city'=>'London',
            'shoot_starts_at'=>$shootStart,'shoot_ends_at'=>$shootEnd,
            'deliverables'=>'One editorial shoot with the agreed image selection and usage scope.',
            'compensation_amount'=>'800.00','currency_code'=>'GBP','expense_cap_amount'=>'75.00',
        ],bin2hex(random_bytes(16)));
        $assert(false,'revoked image-use consent prevents a new assignment from an invalidated casting submission');
    } catch(DomainException) {
        $assert(true,'revoked image-use consent prevents a new assignment from an invalidated casting submission');
    }

    $disabled=new ModelPortfolioService($pdo,$storage,new AuditLogger($pdo),$secretBox,static fn():bool=>false);
    try {
        $disabled->store($admin,$applicationId,[],[
            'source_description'=>'A sufficiently long source description for a test upload.',
            'consent_scope'=>'A sufficiently long consent scope for a test upload.',
            'rights_evidence_reference'=>'private/model-rights/evidence-reference-0001',
            'consent_evidence_reference'=>'private/model-consent/evidence-reference-0001',
        ],bin2hex(random_bytes(16)));
        $assert(false,'portfolio upload is blocked while usage-rights readiness is false');
    } catch(DomainException) {
        $assert(true,'portfolio upload is blocked while usage-rights readiness is false');
    }
    try {
        $service->pendingReview(new Principal($adminId,['client'],false));
        $assert(false,'client cannot read private model portfolio review queue');
    } catch(DomainException) {
        $assert(true,'client cannot read private model portfolio review queue');
    }

    $applicationIdentity=$pdo->prepare('SELECT application_reference,email FROM model_talent_applications WHERE id=:id');
    $applicationIdentity->execute(['id'=>$applicationId]);
    $applicationIdentityRow=$applicationIdentity->fetch(PDO::FETCH_ASSOC);
    $rightsMailer=new NativeTestMailer();
    $applicantRights=new ModelApplicantRightsService(
        $pdo,$rightsMailer,new RateLimiter($pdo,str_repeat('R',32)),new AuditLogger($pdo),str_repeat('A',32)
    );
    $applicantRights->request((string)$applicationIdentityRow['application_reference'],(string)$applicationIdentityRow['email'],
        'portfolio_withdrawal','127.0.0.1',bin2hex(random_bytes(16)),$licensedAsset['id']);
    preg_match('/one-time verification code is: ([0-9]{8})/i',(string)($rightsMailer->messages[0]['body']??''),$rightsCodeMatch);
    $rightsCode=(string)($rightsCodeMatch[1]??'');
    $assert($rightsCode!=='','applicant portfolio withdrawal request sends an OTP only after application and asset ownership match');
    $rightsRequestId=$applicantRights->verify((string)$applicationIdentityRow['application_reference'],(string)$applicationIdentityRow['email'],
        $rightsCode,'127.0.0.1',bin2hex(random_bytes(16)));
    $assert(is_string($rightsRequestId),'applicant portfolio withdrawal requires successful email OTP verification');
    try {
        $service->withdrawByVerifiedApplicantRights((string)$rightsRequestId,$asset['id'],bin2hex(random_bytes(16)));
        $assert(false,'verified portfolio withdrawal cannot be redirected to another applicant asset');
    } catch(DomainException) {
        $assert(true,'verified portfolio withdrawal cannot be redirected to another applicant asset');
    }
    $service->withdrawByVerifiedApplicantRights((string)$rightsRequestId,$licensedAsset['id'],bin2hex(random_bytes(16)));
    $applicantWithdrawal=$pdo->prepare(
        'SELECT status,private_storage_key,consent_withdrawn_at FROM model_portfolio_assets WHERE id=:id'
    );
    $applicantWithdrawal->execute(['id'=>$licensedAsset['id']]);$applicantWithdrawalRow=$applicantWithdrawal->fetch(PDO::FETCH_ASSOC);
    $rightsState=$pdo->prepare('SELECT status,otp_hash,completed_at FROM model_applicant_rights_requests WHERE id=:id');
    $rightsState->execute(['id'=>$rightsRequestId]);$rightsStateRow=$rightsState->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($applicantWithdrawalRow) && $applicantWithdrawalRow['status']==='withdrawn' &&
        $applicantWithdrawalRow['private_storage_key']===null && $applicantWithdrawalRow['consent_withdrawn_at']!==null,
        'OTP-verified applicant can withdraw only their bound portfolio asset and remove its private storage pointer');
    $assert(!is_file($licensedAsset['path']),'applicant portfolio withdrawal deletes the private image bytes');
    $assert(is_array($rightsStateRow) && $rightsStateRow['status']==='completed' &&
        $rightsStateRow['otp_hash']==='' && $rightsStateRow['completed_at']!==null,
        'applicant portfolio withdrawal consumes the verified one-time rights session');
    try {
        $service->withdrawByVerifiedApplicantRights($rightsRequestId,$licensedAsset['id'],bin2hex(random_bytes(16)));
        $assert(false,'applicant portfolio withdrawal session cannot be replayed');
    } catch(DomainException) {
        $assert(true,'applicant portfolio withdrawal session cannot be replayed');
    }

    $storage->delete($licensedAsset['key']);
    rmdir($privateRoot.DIRECTORY_SEPARATOR.'model-portfolio');rmdir($privateRoot);rmdir($publicRoot);rmdir($base);
    return $assertions;
}
