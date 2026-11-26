<?php
declare(strict_types=1);

use Talyvane\Application\CRM\EnquiryService;
use Talyvane\Application\CRM\CrmCommunicationDispatcher;
use Talyvane\Infrastructure\Mail\Mailer;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Infrastructure\Security\RateLimiter;
use Talyvane\Security\Principal;
use Talyvane\Security\SecretBox;

final class EnquiryIntegrationMailer implements Mailer
{
    public array $messages=[];
    public function send(string $recipient,string $subject,string $textBody): void
    {
        $this->messages[]=['recipient'=>$recipient,'subject'=>$subject,'body'=>$textBody];
    }
}

function runEnquiryIntegration(PDO $pdo): int
{
    $assertions = 0;
    $assert = static function (bool $ok,string $message) use (&$assertions): void {
        $assertions++;
        if (!$ok) {
            throw new RuntimeException('Enquiry integration failed: ' . $message);
        }
    };
    $limiter = new RateLimiter($pdo,hash_hmac('sha256','enquiry-integration-key-'.bin2hex(random_bytes(8)),'integration-pepper'));
    $service = new EnquiryService($pdo,$limiter,new AuditLogger($pdo),new SecretBox(str_repeat('E',32)));
    $before = (int) $pdo->query('SELECT COUNT(*) FROM enquiries')->fetchColumn();

    $honeypot = $service->submit([
        'name'=>'Automated visitor','email'=>'bot@example.test','subject'=>'Spam','message'=>'This should never be stored.','privacy_acknowledged'=>true,'website'=>'bot-filled',
    ],'127.0.0.1','enquiry-honeypot-'.bin2hex(random_bytes(8)),'https://example.test/contact','privacy-v1');
    $assert($honeypot === null && (int) $pdo->query('SELECT COUNT(*) FROM enquiries')->fetchColumn() === $before,
        'honeypot submissions are quietly accepted without storing personal data');

    try {
        $service->submit([
            'name'=>'A Visitor','email'=>'visitor@example.test','subject'=>'A question','message'=>'Please tell me more about your service.','privacy_acknowledged'=>false,
        ],'127.0.0.2','enquiry-invalid-'.bin2hex(random_bytes(8)),null,'privacy-v1');
        $assert(false,'privacy acknowledgement is mandatory');
    } catch (InvalidArgumentException) {
        $assert(true,'privacy acknowledgement is mandatory');
    }

    $reference = $service->submit([
        'name'=>'  A Visitor  ','email'=>'VISITOR@example.test','phone'=>'+1 555 0100','subject'=>'A question',
        'message'=>'Please tell me more about your service.','privacy_acknowledged'=>true,'country_code'=>null,'city'=>'Toronto',
    ],'127.0.0.3','enquiry-submit-'.bin2hex(random_bytes(8)),'https://example.test/contact?token=secret','privacy-v2');
    $assert(is_string($reference) && preg_match('/^TV-[A-Z0-9]{10}$/D',$reference) === 1,
        'valid enquiry receives a non-sequential public reference');
    $row = $pdo->prepare('SELECT name,email,status,privacy_acknowledged_at,privacy_policy_version,attribution_json FROM enquiries WHERE reference = :reference');
    $row->execute(['reference'=>$reference]);
    $saved = $row->fetch();
    $assert(is_array($saved) && $saved['name'] === 'A Visitor' && $saved['email'] === 'visitor@example.test' && $saved['status'] === 'new',
        'enquiry fields are normalized and saved in the new pipeline state');
    $assert(is_array($saved) && $saved['privacy_acknowledged_at'] !== null,
        'privacy acknowledgement timestamp is retained without storing unnecessary acknowledgement text');
$assert(is_array($saved) && $saved['privacy_policy_version'] === 'privacy-v2',
        'the exact privacy notice version acknowledged by the visitor is retained');
$attribution = is_array($saved) ? json_decode((string) $saved['attribution_json'],true) : null;
    $assert(is_array($attribution) && ($attribution['source'] ?? '') === 'https://example.test/contact' && !str_contains((string) $saved['attribution_json'],'token=secret'),
        'attribution retains only the referring origin and path, never query-string tokens');

    $adminId = (string) $pdo->query('SELECT id FROM users WHERE email = "native-admin@example.test"')->fetchColumn();
    $admin = new Principal($adminId,['admin'],true);
    $assert($service->count($admin) >= 1 && count($service->list($admin,1,0)) === 1,
        'CRM inbox exposes a permission-gated total count and bounded offset pagination');
    $service->updateStatus($admin,$reference,'responded','Customer support has replied',bin2hex(random_bytes(16)));
    $status = $pdo->prepare('SELECT status,responded_at,closed_at FROM enquiries WHERE reference = :reference');
    $status->execute(['reference'=>$reference]);
    $updated = $status->fetch();
    $assert(is_array($updated) && $updated['status'] === 'responded' && $updated['responded_at'] !== null && $updated['closed_at'] === null,
        'admin status transition records response time and leaves the enquiry open');

    $service->assign($admin,$reference,$adminId,'Assign enquiry to support owner',bin2hex(random_bytes(16)));
    $assignment=$pdo->prepare('SELECT assigned_to FROM enquiries WHERE reference=:reference');
    $assignment->execute(['reference'=>$reference]);
    $assert($assignment->fetchColumn()===$adminId,'MFA administrator can assign an enquiry to an active administrator');
    $assignees=$service->eligibleAssignees($admin);
    $assert(count(array_filter($assignees,static fn(array $person):bool=>$person['id']===$adminId))===1,
        'eligible assignee directory contains active administrator accounts');
    $service->assign($admin,$reference,null,'Return enquiry to the unassigned queue',bin2hex(random_bytes(16)));
    $assignment->execute(['reference'=>$reference]);
    $assert($assignment->fetchColumn()===null,'administrator can explicitly unassign an enquiry');
    try {
        $service->assign($admin,$reference,$ineligibleId='00000000-0000-4000-8000-000000000001','Assign to ineligible user',bin2hex(random_bytes(16)));
        $assert(false,'assignment rejects users without an eligible admin role');
    } catch (InvalidArgumentException) {
        $assert(true,'assignment rejects users without an eligible admin role');
    }

    $clientId = (string) $pdo->query('SELECT id FROM users WHERE email = "native-client@example.test"')->fetchColumn();
    $privateNote='Customer requested a callback after reviewing the destination details.';
    $service->addInternalNote($admin,$reference,$privateNote,bin2hex(random_bytes(16)));
    $storedNote=$pdo->prepare('SELECT id,ciphertext FROM crm_enquiry_notes WHERE enquiry_id=(SELECT id FROM enquiries WHERE reference=:reference)');
    $storedNote->execute(['reference'=>$reference]);
    $storedNoteRow=$storedNote->fetch(PDO::FETCH_ASSOC);
    $listedNote=$service->list($admin,100,0);
    $matchingEnquiry=array_values(array_filter($listedNote,static fn(array $item):bool=>$item['reference']===$reference))[0]??null;
    $assert(is_array($storedNoteRow) && !str_contains((string)$storedNoteRow['ciphertext'],$privateNote),
        'CRM internal note content is encrypted at rest and not stored in plaintext');
    $assert(is_array($matchingEnquiry) && count($matchingEnquiry['internal_notes']??[])===1 &&
        $matchingEnquiry['internal_notes'][0]['body']===$privateNote,
        'authorized CRM inbox decrypts and displays its own private internal notes');
    $triggerCount=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema=DATABASE() AND trigger_name IN ('crm_enquiry_notes_no_update','crm_enquiry_notes_no_delete')")->fetchColumn();
    if($triggerCount===2) {
        $immutable=false;
        try{$pdo->prepare('UPDATE crm_enquiry_notes SET ciphertext=:value WHERE id=:id')->execute(['value'=>'tamper','id'=>$storedNoteRow['id']]);}
        catch(PDOException){$immutable=true;}
        $assert($immutable,'database trigger rejects CRM internal-note mutation when trigger privileges are available');
    }

    $reminderDue = (new DateTimeImmutable('+2 days',new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    $reminderId = $service->scheduleReminder($admin,$reference,$reminderDue,'Follow up after sending the requested information',bin2hex(random_bytes(16)));
    $reminders = $service->list($admin,100,0);
    $reminderEnquiry = array_values(array_filter($reminders,static fn(array $item):bool=>$item['reference']===$reference))[0]??null;
    $assert(is_string($reminderId) && count($reminderEnquiry['reminders']??[])===1 &&
        $reminderEnquiry['reminders'][0]['due_at']===$reminderDue,
        'CRM follow-up reminder is persisted and visible to authorized administrators');
    $service->completeReminder($admin,$reminderId,'Follow-up completed by support',bin2hex(random_bytes(16)));
    $reminders = $service->list($admin,100,0);
    $reminderEnquiry = array_values(array_filter($reminders,static fn(array $item):bool=>$item['reference']===$reference))[0]??null;
    $assert(($reminderEnquiry['reminders'][0]['completed_at']??null)!==null,
        'CRM reminder completion is stateful and auditable');

    $templateReason='Approved customer-response wording for the CRM team';
    $service->saveResponseTemplate(
        $admin,'en','Hello {{ customer_name }} — enquiry {{ enquiry_reference }}',
        "Dear {{ customer_name }},\n\n{{ reply_text }}\n\nRegards,\n{{ agency_name }}",
        $templateReason,bin2hex(random_bytes(16))
    );
    $templates=$service->responseTemplates($admin);
    $activeTemplate=array_values(array_filter($templates,static fn(array $template):bool=>$template['locale']==='en' && (int)$template['active']===1))[0]??null;
    $assert(is_array($activeTemplate) && (int)$activeTemplate['version']===1,
        'CRM response template is versioned and activated through the authorized admin workflow');
    try {
        $service->saveResponseTemplate($admin,'en','Invalid {{ recipient_email }}',
            'Body {{ customer_name }} {{ reply_text }}','Reject unknown placeholder',bin2hex(random_bytes(16)));
        $assert(false,'CRM templates reject non-allowlisted placeholders');
    } catch(InvalidArgumentException) {
        $assert(true,'CRM templates reject non-allowlisted placeholders');
    }
    $replyRequest=bin2hex(random_bytes(16));
    $queued=$service->queueResponse($admin,$reference,
        "Thank you for your enquiry. We can provide the details you requested.",'en','Talyvane',$replyRequest);
    $assert($queued===true,'authorized administrator can queue a templated CRM response');
    $enquiryIdQuery=$pdo->prepare('SELECT id FROM enquiries WHERE reference=:reference');
    $enquiryIdQuery->execute(['reference'=>$reference]);
    $enquiryId=(string)$enquiryIdQuery->fetchColumn();
    $outbox=$pdo->prepare('SELECT id,recipient_ciphertext,payload_ciphertext,status,related_type FROM communication_outbox WHERE related_type="crm_enquiry" AND idempotency_key=:key');
    $outbox->execute(['key'=>'crm-enquiry-response-v1:'.hash('sha256',$enquiryId."\0".$replyRequest)]);
    $outboxRow=$outbox->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($outboxRow) && $outboxRow['status']==='pending' &&
        !str_contains((string)$outboxRow['recipient_ciphertext'],'visitor@example.test') &&
        !str_contains((string)$outboxRow['payload_ciphertext'],'Thank you for your enquiry'),
        'CRM recipient and rendered response are encrypted in the transactional outbox');
    $duplicate=$service->queueResponse($admin,$reference,
        "Thank you for your enquiry. We can provide the details you requested.",'en','Talyvane',$replyRequest);
    $assert($duplicate===false,'CRM response idempotency key prevents duplicate outbound messages');
    $crmMailer=new EnquiryIntegrationMailer();
    $crmDispatch=(new CrmCommunicationDispatcher($pdo,new SecretBox(str_repeat('E',32)),$crmMailer))->dispatchBatch(10);
    $assert($crmDispatch['claimed']===1 && $crmDispatch['delivered']===1 && count($crmMailer->messages)===1 &&
        $crmMailer->messages[0]['recipient']==='visitor@example.test' &&
        str_contains($crmMailer->messages[0]['body'],'Thank you for your enquiry.') &&
        str_contains($crmMailer->messages[0]['body'],'Talyvane'),
        'CRM dispatcher renders the approved template and delivers the encrypted outbox message');
    $responseState=$pdo->prepare('SELECT status,responded_at FROM enquiries WHERE reference=:reference');
    $responseState->execute(['reference'=>$reference]);
    $responseRow=$responseState->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($responseRow) && $responseRow['status']==='responded' && $responseRow['responded_at']!==null,
        'queueing a CRM response advances the enquiry to responded within the same transaction');

    try {
        $service->updateStatus(new Principal($clientId,['client'],false),$reference,'closed','Forbidden',bin2hex(random_bytes(16)));
        $assert(false,'client cannot change enquiry pipeline state');
    } catch (DomainException) {
        $assert(true,'client cannot change enquiry pipeline state');
    }
    try {
        $service->list(new Principal($clientId,['client'],false),10,0);
        $assert(false,'client cannot read the enquiry inbox');
    } catch (DomainException) {
        $assert(true,'client cannot read the enquiry inbox');
    }
    return $assertions;
}
