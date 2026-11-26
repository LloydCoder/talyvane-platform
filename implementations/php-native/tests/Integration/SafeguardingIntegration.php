<?php
declare(strict_types=1);
use Talyvane\Application\Safeguarding\LocalSafeguardingCaseKeyProvider;
use Talyvane\Application\Safeguarding\SafeguardingCaseService;
use Talyvane\Application\Safeguarding\SafeguardingBackupPolicyService;
use Talyvane\Application\Safeguarding\SafeguardingRetentionPolicyService;
use Talyvane\Application\Safeguarding\SafeguardingRetentionService;
use Talyvane\Application\Safeguarding\SafeguardingRestoreVerificationService;
use Talyvane\Application\Safeguarding\SafeguardingCryptoService;
use Talyvane\Application\Safeguarding\SafeguardingNotificationOutbox;
use Talyvane\Application\Safeguarding\SafeguardingNotificationDispatcher;
use Talyvane\Infrastructure\Mail\Mailer;
use Talyvane\Security\SecretBox;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Security\Principal;
function runSafeguardingIntegration(PDO $pdo): int {
 $adminId=(string)$pdo->query('SELECT id FROM users WHERE email="native-admin@example.test"')->fetchColumn();
 if($adminId==='')throw new RuntimeException('Missing Super Admin fixture');
 $uuid=static function():string{$b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);$b[8]=chr((ord($b[8])&63)|128);$h=bin2hex($b);return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);};
 $sid=$uuid();$sid2=$uuid();$oid=$uuid();
 $ins=$pdo->prepare('INSERT INTO users (id,email,password_hash,status,email_verified_at,created_at,updated_at) VALUES (:id,:email,:hash,"active",UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())');
 $ins->execute(['id'=>$sid,'email'=>'safeguarding-specialist@example.test','hash'=>password_hash(str_repeat('S',24).'!',PASSWORD_DEFAULT)]);
 $ins->execute(['id'=>$sid2,'email'=>'safeguarding-specialist2@example.test','hash'=>password_hash(str_repeat('T',24).'!',PASSWORD_DEFAULT)]);
 $ins->execute(['id'=>$oid,'email'=>'safeguarding-owner@example.test','hash'=>password_hash(str_repeat('O',24).'!',PASSWORD_DEFAULT)]);
 $role=$pdo->prepare('INSERT INTO user_roles (user_id,role_key,granted_by,granted_at) VALUES (:id,:role,:actor,UTC_TIMESTAMP())');
 $role->execute(['id'=>$sid,'role'=>'safeguarding','actor'=>$adminId]);$role->execute(['id'=>$sid2,'role'=>'safeguarding','actor'=>$adminId]);$role->execute(['id'=>$oid,'role'=>'owner','actor'=>$adminId]);
 $mfaInsert=$pdo->prepare('INSERT INTO admin_mfa_credentials (user_id,encrypted_totp_secret,encryption_key_version,enabled_at,created_at,updated_at) VALUES (:id,"fixture","v1",UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())');
 $mfaInsert->execute(['id'=>$sid]);$mfaInsert->execute(['id'=>$sid2]);$mfaInsert->execute(['id'=>$oid]);
 $admin=new Principal($adminId,['super_admin'],true);$specialist=new Principal($sid,['safeguarding'],true);$specialist2=new Principal($sid2,['safeguarding'],true);$owner=new Principal($oid,['owner'],true);
 $appKey=str_repeat('K',32);$box=new SecretBox($appKey);
 $outbox=new SafeguardingNotificationOutbox($pdo,$box,hash('sha256',$appKey.'|safeguarding-outbox-fingerprint-v1',true));
 $service=new SafeguardingCaseService($pdo,new SafeguardingCryptoService(new LocalSafeguardingCaseKeyProvider($appKey)),new AuditLogger($pdo),$outbox);

 try{$service->create($admin,'coercion','high','Confidential safeguarding concern','A privacy-minimised report for integration testing.','',$sid,bin2hex(random_bytes(16)));throw new RuntimeException('Safeguarding integration failed: legacy category unexpectedly accepted');}catch(InvalidArgumentException){}
 try{$service->create($admin,'consent_concern','high','Too short','This report details a privacy-minimised safeguarding concern.','',$sid,bin2hex(random_bytes(16)));throw new RuntimeException('Safeguarding integration failed: short summary unexpectedly accepted');}catch(InvalidArgumentException){}
 $created=$service->create($admin,'coercion_or_exploitation','high','Confidential safeguarding concern','A privacy-minimised report for integration testing.','opaque-reference:123',$sid,bin2hex(random_bytes(16)));
 $id=(string)$pdo->query('SELECT id FROM safeguarding_cases WHERE case_reference='.$pdo->quote($created['case_reference']))->fetchColumn();
 $n=0;$assert=static function(bool $ok,string $m)use(&$n):void{$n++;if(!$ok)throw new RuntimeException('Safeguarding integration failed: '.$m);};
 $out=$pdo->prepare('SELECT id,recipient_ciphertext,payload_ciphertext,status FROM safeguarding_notification_outbox WHERE case_id=:id');$out->execute(['id'=>$id]);$notification=$out->fetch(PDO::FETCH_ASSOC);
 $assert(is_array($notification)&&$notification['status']==='pending'&&!str_contains($notification['recipient_ciphertext'],'safeguarding-specialist@example.test'),'case creation queues an encrypted notification without plaintext recipient');
 $recipient=$box->decrypt($notification['recipient_ciphertext'],'safeguarding-outbox-v1:recipient:'.$notification['id']);
 $payload=json_decode($box->decrypt($notification['payload_ciphertext'],'safeguarding-outbox-v1:payload:'.$notification['id']),true,16,JSON_THROW_ON_ERROR);
 $assert($recipient==='safeguarding-specialist@example.test'&&$payload['case_reference']===$created['case_reference']&&!isset($payload['details']),'notification payload contains only the minimal case reference and metadata');
 $mailer=new class implements Mailer { public array $messages=[]; public function send(string $recipient,string $subject,string $textBody):void{$this->messages[]=['recipient'=>$recipient,'subject'=>$subject,'body'=>$textBody];} };
 $dispatcher=new SafeguardingNotificationDispatcher($pdo,$box,$mailer);$dispatch=$dispatcher->dispatchBatch(10);
 $assert($dispatch['delivered']===1&&count($mailer->messages)===1&&$mailer->messages[0]['recipient']===$recipient&&!str_contains($mailer->messages[0]['body'],'potential consent issue'),'outbox dispatch delivers a minimal notification without confidential narrative');
 $again=$dispatcher->dispatchBatch(10);$assert($again['claimed']===0&&count($mailer->messages)===1,'delivered safeguarding notification is not sent again');
 $createdRetry=$service->create($admin,'consent_concern','medium','Retry delivery safeguarding case','This case exercises bounded outbox retry behaviour.','',$sid,bin2hex(random_bytes(16)));
 $retryId=(string)$pdo->query('SELECT id FROM safeguarding_cases WHERE case_reference='.$pdo->quote($createdRetry['case_reference']))->fetchColumn();
 $failingMailer=new class implements Mailer { public function send(string $recipient,string $subject,string $textBody):void{throw new RuntimeException('SMTP transport unavailable');} };
 $retryDispatcher=new SafeguardingNotificationDispatcher($pdo,$box,$failingMailer);$retryResult=$retryDispatcher->dispatchBatch(10);
 $retryRow=$pdo->prepare('SELECT status,attempts,last_error_code FROM safeguarding_notification_outbox WHERE case_id=:id');$retryRow->execute(['id'=>$retryId]);$retryState=$retryRow->fetch(PDO::FETCH_ASSOC);
 $assert($retryResult['retried']===1&&is_array($retryState)&&$retryState['status']==='pending'&&(int)$retryState['attempts']===1&&$retryState['last_error_code']==='RuntimeException','failed notification delivery is retried with a bounded attempt count and sanitized error class');
 $pdo->prepare('UPDATE safeguarding_notification_outbox SET available_at=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE case_id=:id')->execute(['id'=>$retryId]);
 $retryMailer=new class implements Mailer { public array $messages=[]; public function send(string $recipient,string $subject,string $textBody):void{$this->messages[]=$textBody;} };
 $retrySuccess=(new SafeguardingNotificationDispatcher($pdo,$box,$retryMailer))->dispatchBatch(10);
 $assert($retrySuccess['delivered']===1&&count($retryMailer->messages)===1,'retry becomes delivered after SMTP recovers');
 
 $detail=$service->assignedCase($specialist,$id,bin2hex(random_bytes(16)));
 $assert($detail['summary']==='Confidential safeguarding concern'&&$detail['subject_reference']==='opaque-reference:123','assigned specialist reads assigned case');
 try{$service->assignedCase(new Principal($oid,['safeguarding'],true),$id,bin2hex(random_bytes(16)));$assert(false,'unassigned specialist denied');}catch(DomainException){$assert(true,'unassigned specialist denied');}
 $service->transition($specialist,$id,'triaged','The report has been triaged by the assigned specialist.',bin2hex(random_bytes(16)));
 $gov=$service->governanceCases($admin);$assert(count(array_filter($gov,static fn(array $r):bool=>$r['id']===$id))===1,'metadata-only governance listing');
 $service->placeLegalHold($admin,$id,'Preserve this case during the independent review.',bin2hex(random_bytes(16)));
 try{$service->releaseLegalHold($admin,$id,'Attempted self-release of the legal hold.',bin2hex(random_bytes(16)));$assert(false,'hold placer cannot release');}catch(DomainException){$assert(true,'hold placer cannot release');}
 $service->releaseLegalHold($owner,$id,'Independent owner reviewed and approved this release.',bin2hex(random_bytes(16)));
 $state=$pdo->prepare('SELECT legal_hold FROM safeguarding_cases WHERE id=:id');$state->execute(['id'=>$id]);$assert((int)$state->fetchColumn()===0,'independent legal hold release');
 $backupPolicies=new SafeguardingBackupPolicyService($pdo,new AuditLogger($pdo));
 $backupPolicies->approve($owner,365,'safeguard-v1','Encrypted backup expiry and restore procedure approved by the accountable Owner.',bin2hex(random_bytes(16)));
 $assert(($backupPolicies->current()['policy_version']??null)==='safeguard-v1','encrypted backup policy is stored with accountable approval');
 try{$backupPolicies->approve($owner,365,'safeguard-v2','The same approver cannot approve a replacement backup policy.',bin2hex(random_bytes(16)));$assert(false,'same backup policy approver rejected');}catch(DomainException){$assert(true,'backup policy changes require a different privileged approver');}
 $retentionPolicies=new SafeguardingRetentionPolicyService($pdo,new AuditLogger($pdo));
 $retentionPolicies->save($admin,'coercion_or_exploitation',365,'Applicable legal retention basis reference','safeguard-v1',true,'Independent Super Admin approved this category retention period.',bin2hex(random_bytes(16)));
 $assert(count(array_filter($retentionPolicies->all(),static fn(array $r):bool=>$r['category']==='coercion_or_exploitation'&&(int)$r['enabled']===1))===1,'category retention policy is versioned and maker-checker approved');
 try{(new SafeguardingRetentionService($pdo,new SafeguardingCryptoService(new LocalSafeguardingCaseKeyProvider($appKey))))->apply();$assert(false,'local key retention must fail closed');}catch(RuntimeException){$assert(true,'local application-key custody cannot claim backup-safe cryptographic erasure');}
 try{(new SafeguardingRestoreVerificationService($pdo,new SafeguardingCryptoService(new LocalSafeguardingCaseKeyProvider($appKey)),new AuditLogger($pdo)))->verify($admin,'verified-backup-reference','a'.str_repeat('b',63),'safeguard-v1',bin2hex(random_bytes(16)));$assert(false,'Vault restore verification must fail with local provider');}catch(RuntimeException){$assert(true,'restore reconciliation requires external Vault Transit');}
 $pdo->prepare('UPDATE admin_mfa_credentials SET enabled_at=NULL WHERE user_id=:id')->execute(['id'=>$sid]);
 try{$service->assignedCases($specialist);$assert(false,'specialist with revoked MFA must be denied');}catch(DomainException){$assert(true,'specialist with revoked MFA is denied even with a stale principal');}
 $pdo->prepare('UPDATE users SET status="disabled" WHERE id=:id')->execute(['id'=>$oid]);
 try{$service->governanceCases($owner);$assert(false,'disabled governance actor must be denied');}catch(DomainException){$assert(true,'disabled governance actor is denied at the service boundary');}
 return $n;
}
