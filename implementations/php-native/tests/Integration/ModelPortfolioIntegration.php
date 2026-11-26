<?php
declare(strict_types=1);

use Talyvane\Application\ModelAgency\ModelPortfolioService;
use Talyvane\Application\ModelAgency\ModelAgencyAgreementService;
use Talyvane\Application\ModelAgency\ModelImageLicenceService;
use Talyvane\Application\ModelAgency\ModelCastingWorkflowService;
use Talyvane\Application\ModelAgency\ModelAssignmentService;
use Talyvane\Application\ModelAgency\ModelSettlementService;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Infrastructure\Storage\PrivateMediaStorage;
use Talyvane\Security\Principal;
use Talyvane\Security\SecretBox;

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
    $licences->activate($admin,$licenceId,'private/model-licences/execution-evidence-0001','private/model-consent/model-consent-evidence-0001',bin2hex(random_bytes(16)));
    $active=$pdo->prepare('SELECT status,execution_evidence_reference FROM model_image_licences WHERE id=:id');$active->execute(['id'=>$licenceId]);$activeRow=$active->fetch(PDO::FETCH_ASSOC);
    $grant->execute(['id'=>$draftRow['consent_grant_id']]);
    $assert(is_array($activeRow) && $activeRow['status']==='active' && $activeRow['execution_evidence_reference']!=='','activation records external execution evidence');
    $assert((int)$grant->fetchColumn()===1,'activation records separate purpose-specific model consent');
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
    $candidates=$casting->eligibleCandidates($admin,$callId);
    $assert(count($candidates)===1 && $candidates[0]['application_id']===$applicationId,'eligible-candidate register requires current adult verification, portfolio consent, executed agreement and active casting licence');
    $submissionId=$casting->submit($admin,$callId,$applicationId,$licensedAsset['id'],$licenceId,bin2hex(random_bytes(16)));
    $casting->decide($admin,$submissionId,'shortlisted','Model shortlisted after human review of the private casting submission.',bin2hex(random_bytes(16)));
    $casting->decide($admin,$submissionId,'selected','Model selected after final human review and confirmation of the defined project scope.',bin2hex(random_bytes(16)));
    $submissionRows=$casting->submissionsForCall($admin,$callId);
    $assert(count($submissionRows)===1 && $submissionRows[0]['status']==='selected','private casting submission supports audited shortlist and selection decisions');

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
    $assignmentId=$assignments->createDraft($admin,$submissionId,[
        'project_purpose'=>'editorial','client_name'=>'Verified editorial commissioning client',
        'client_reference'=>'editorial-client-ref-001',
        'brief'=>'A defined editorial assignment brief for the model and commissioning client.',
        'location_city'=>'London',
        'shoot_starts_at'=>$shootStart,'shoot_ends_at'=>$shootEnd,
        'deliverables'=>'One editorial shoot with the agreed image selection and usage scope.',
        'compensation_amount'=>'800.00','currency_code'=>'GBP','expense_cap_amount'=>'75.00',
    ],bin2hex(random_bytes(16)));
    $assignments->offer($admin,$assignmentId,bin2hex(random_bytes(16)));
    $assignments->recordAcceptance($admin,$assignmentId,'private/model-assignments/executed-document-0001',
        'private/model-consent/assignment-acceptance-0001',bin2hex(random_bytes(16)));
    $assignments->confirm($admin,$assignmentId,bin2hex(random_bytes(16)));
    $assignments->start($admin,$assignmentId,bin2hex(random_bytes(16)));
    $assignmentRow=$pdo->prepare('SELECT status,compensation_minor,expense_cap_minor,commission_basis_points,client_name FROM model_assignments WHERE id=:id');
    $assignmentRow->execute(['id'=>$assignmentId]);$assignmentDraft=$assignmentRow->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($assignmentDraft) && $assignmentDraft['status']==='in_progress' && (int)$assignmentDraft['compensation_minor']===80000 &&
        (int)$assignmentDraft['expense_cap_minor']===7500 && (int)$assignmentDraft['commission_basis_points']===1500,'assignment lifecycle snapshots agreed compensation, expense cap and executed-agreement commission');
    $assert(is_array($assignmentDraft) && $assignmentDraft['client_name']!=='Verified editorial commissioning client','assignment client identity is encrypted at rest');

    $settlements=new ModelSettlementService($pdo,new AuditLogger($pdo),$secretBox,static fn():bool=>true);
    $expenseId=$settlements->addExpense($owner,$assignmentId,'25.00','GBP',
        'Pre-approved local transport expense for the defined shoot.','private/model-expenses/expense-evidence-0001',bin2hex(random_bytes(16)));
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
    $licences->revoke($admin,$licenceId,'Consent withdrawn for this defined image use and territory.',bin2hex(random_bytes(16)));
    $revoked=$pdo->prepare('SELECT status,revocation_reason FROM model_image_licences WHERE id=:id');$revoked->execute(['id'=>$licenceId]);$revokedRow=$revoked->fetch(PDO::FETCH_ASSOC);
    $grant->execute(['id'=>$draftRow['consent_grant_id']]);
    $assert(is_array($revokedRow) && $revokedRow['status']==='revoked' && $revokedRow['revocation_reason']!=='Consent withdrawn for this defined image use and territory.','revocation reason is encrypted at rest');
    $assert((int)$grant->fetchColumn()===0,'revocation withdraws model consent even when new licences are disabled');
    $submissionState=$pdo->prepare('SELECT status,invalidation_reason FROM model_casting_submissions WHERE id=:id');$submissionState->execute(['id'=>$submissionId]);$submissionStateRow=$submissionState->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($submissionStateRow) && $submissionStateRow['status']==='invalidated' && $submissionStateRow['invalidation_reason']!=='Underlying image licence was revoked or expired.','licence revocation invalidates selected casting submissions and encrypts the reason');

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

    $storage->delete($licensedAsset['key']);
    rmdir($privateRoot.DIRECTORY_SEPARATOR.'model-portfolio');rmdir($privateRoot);rmdir($publicRoot);rmdir($base);
    return $assertions;
}
