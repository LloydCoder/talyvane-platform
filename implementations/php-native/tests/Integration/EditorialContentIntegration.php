<?php
declare(strict_types=1);

use Talyvane\Application\Content\EditorialContentService;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Security\Principal;

function runEditorialContentIntegration(PDO $pdo): int
{
    $assertions = 0;
    $assert = static function (bool $ok,string $message) use (&$assertions): void {
        $assertions++;
        if (!$ok) {
            throw new RuntimeException('Editorial content integration failed: ' . $message);
        }
    };
    $adminId = (string) $pdo->query('SELECT id FROM users WHERE email = "native-admin@example.test"')->fetchColumn();
    $admin = new Principal($adminId,['super_admin'],true);
    $service = new EditorialContentService($pdo,new AuditLogger($pdo));

    $faqId = $service->saveFaq(
        $admin,null,'How are enquiries handled?','Enquiries are reviewed by an authorised team member.',
        'en','general',10,'Create reviewed FAQ',bin2hex(random_bytes(16))
    );
    $assert($service->publicFaqs('en') === [],'unpublished FAQs are never exposed publicly');
    $service->publishFaq($admin,$faqId,'Approve initial FAQ publication',bin2hex(random_bytes(16)));
    $published = $service->publicFaqs('en');
    $assert(count($published) === 1 && $published[0]['question'] === 'How are enquiries handled?' &&
        $published[0]['answer'] === 'Enquiries are reviewed by an authorised team member.',
        'publication exposes an immutable, locale-scoped FAQ snapshot');

    $service->saveFaq(
        $admin,$faqId,'How are enquiries handled?','Enquiries are reviewed by a designated, authorised team member.',
        'en','general',10,'Clarify enquiry handling',bin2hex(random_bytes(16))
    );
    $stillPublished = $service->publicFaqs('en');
    $assert(count($stillPublished) === 1 &&
        $stillPublished[0]['answer'] === 'Enquiries are reviewed by an authorised team member.',
        'editing an FAQ creates a draft version without changing the current public snapshot');
    $service->publishFaq($admin,$faqId,'Approve clarified FAQ wording',bin2hex(random_bytes(16)));
    $revised = $service->publicFaqs('en');
    $assert(count($revised) === 1 &&
        $revised[0]['answer'] === 'Enquiries are reviewed by a designated, authorised team member.',
        'a reviewed FAQ revision becomes public only after explicit publication');

    try {
        $service->saveFaq($admin,null,'','Answer','en','general',0,'Invalid FAQ',bin2hex(random_bytes(16)));
        $assert(false,'FAQ questions cannot be empty');
    } catch (InvalidArgumentException) {
        $assert(true,'FAQ questions cannot be empty');
    }

    $clientId = (string) $pdo->query('SELECT id FROM users WHERE email = "native-client@example.test"')->fetchColumn();
    $client = new Principal($clientId,['client'],false);
    try {
        $service->saveFaq($client,null,'Client write','Not allowed','en','general',0,'Unauthorized FAQ write',bin2hex(random_bytes(16)));
        $assert(false,'clients cannot create or edit FAQs');
    } catch (DomainException) {
        $assert(true,'clients cannot create or edit FAQs');
    }

    $assert($service->publicFaqs('fr') === [],'FAQ publication is isolated by locale');
    return $assertions;
}
