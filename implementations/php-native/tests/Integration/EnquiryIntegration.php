<?php
declare(strict_types=1);

use Talyvane\Application\CRM\EnquiryService;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Infrastructure\Security\RateLimiter;
use Talyvane\Security\Principal;

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
    $service = new EnquiryService($pdo,$limiter,new AuditLogger($pdo));
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
    ],'127.0.0.3','enquiry-submit-'.bin2hex(random_bytes(8)),null,'privacy-v2');
    $assert(is_string($reference) && preg_match('/^TV-[A-Z0-9]{10}$/D',$reference) === 1,
        'valid enquiry receives a non-sequential public reference');
    $row = $pdo->prepare('SELECT name,email,status,privacy_acknowledged_at,privacy_policy_version FROM enquiries WHERE reference = :reference');
    $row->execute(['reference'=>$reference]);
    $saved = $row->fetch();
    $assert(is_array($saved) && $saved['name'] === 'A Visitor' && $saved['email'] === 'visitor@example.test' && $saved['status'] === 'new',
        'enquiry fields are normalized and saved in the new pipeline state');
    $assert(is_array($saved) && $saved['privacy_acknowledged_at'] !== null,
        'privacy acknowledgement timestamp is retained without storing unnecessary acknowledgement text');
$assert(is_array($saved) && $saved['privacy_policy_version'] === 'privacy-v2',
        'the exact privacy notice version acknowledged by the visitor is retained');

    $adminId = (string) $pdo->query('SELECT id FROM users WHERE email = "native-admin@example.test"')->fetchColumn();
    $admin = new Principal($adminId,['admin'],true);
    $service->updateStatus($admin,$reference,'responded','Customer support has replied',bin2hex(random_bytes(16)));
    $status = $pdo->prepare('SELECT status,responded_at,closed_at FROM enquiries WHERE reference = :reference');
    $status->execute(['reference'=>$reference]);
    $updated = $status->fetch();
    $assert(is_array($updated) && $updated['status'] === 'responded' && $updated['responded_at'] !== null && $updated['closed_at'] === null,
        'admin status transition records response time and leaves the enquiry open');

    $clientId = (string) $pdo->query('SELECT id FROM users WHERE email = "native-client@example.test"')->fetchColumn();
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
