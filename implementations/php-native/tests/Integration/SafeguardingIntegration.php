<?php
declare(strict_types=1);
use Talyvane\Application\Safeguarding\LocalSafeguardingCaseKeyProvider;
use Talyvane\Application\Safeguarding\SafeguardingCaseService;
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
 $sid=$uuid();$oid=$uuid();
 $ins=$pdo->prepare('INSERT INTO users (id,email,password_hash,status,email_verified_at,created_at,updated_at) VALUES (:id,:email,:hash,"active",UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())');
 $ins->execute(['id'=>$sid,'email'=>'safeguarding-specialist@example.test','hash'=>password_hash(str_repeat('S',24).'!',PASSWORD_DEFAULT)]);
 $ins->execute(['id'=>$oid,'email'=>'safeguarding-owner@example.test','hash'=>password_hash(str_repeat('O',24).'!',PASSWORD_DEFAULT)]);
 $role=$pdo->prepare('INSERT INTO user_roles (user_id,role_key,granted_by,granted_at) VALUES (:id,:role,:actor,UTC_TIMESTAMP())');
 $role->execute(['id'=>$sid,'role'=>'safeguarding','actor'=>$adminId]);$role->execute(['id'=>$oid,'role'=>'owner','actor'=>$adminId]);
 $mfaInsert=$pdo->prepare('INSERT INTO admin_mfa_credentials (user_id,encrypted_totp_secret,encryption_key_version,enabled_at,created_at,updated_at) VALUES (:id,"fixture","v1",UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())');
 $mfaInsert->execute(['id'=>$sid]);$mfaInsert->execute(['id'=>$oid]);
 $admin=new Principal($adminId,['super_admin'],true);$specialist=new Principal($sid,['safeguarding'],true);$owner=new Principal($oid,['owner'],true);
 $appKey=str_repeat('K',32);$box=new SecretBox($appKey);
 $outbox=new SafeguardingNotificationOutbox($pdo,$box,hash('sha256',$appKey.'|safeguarding-outbox-fingerprint-v1',true));
 $service=new SafeguardingCaseService($pdo,new SafeguardingCryptoService(new LocalSafeguardingCaseKeyProvider($appKey)),new AuditLogger($pdo),$outbox);
 $created=$service->create($admin,'coercion','high','Confidential safeguarding concern','A privacy-minimised report for integration testing.','opaque-reference:123',$sid,bin2hex(random_bytes(16)));
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
 
 $detail=$service->assignedCase($specialist,$id,bin2hex(random_bytes(16)));
 $assert($detail['summary']==='Confidential safeguarding concern'&&$detail['subject_reference']==='opaque-reference:123','assigned specialist reads assigned case');
 try{$service->assignedCase(new Principal($oid,['safeguarding'],true),$id,bin2hex(random_bytes(16)));$assert(false,'unassigned specialist denied');}catch(DomainException){$assert(true,'unassigned specialist denied');}
 $service->transition($specialist,$id,'triaged','The report has been triaged by the assigned specialist.',bin2hex(random_bytes(16)));
 $gov=$service->governanceCases($admin);$assert(count(array_filter($gov,static fn(array $r):bool=>$r['id']===$id))===1,'metadata-only governance listing');
 $service->placeLegalHold($admin,$id,'Preserve this case during the independent review.',bin2hex(random_bytes(16)));
 try{$service->releaseLegalHold($admin,$id,'Attempted self-release of the legal hold.',bin2hex(random_bytes(16)));$assert(false,'hold placer cannot release');}catch(DomainException){$assert(true,'hold placer cannot release');}
 $service->releaseLegalHold($owner,$id,'Independent owner reviewed and approved this release.',bin2hex(random_bytes(16)));
 $state=$pdo->prepare('SELECT legal_hold FROM safeguarding_cases WHERE id=:id');$state->execute(['id'=>$id]);$assert((int)$state->fetchColumn()===0,'independent legal hold release');
 return $n;
}
