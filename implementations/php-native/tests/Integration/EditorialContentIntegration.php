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

    $destinationId = sprintf('%08x-%04x-4%03x-8%03x-%012x', random_int(1,0xffffffff),random_int(0,0xffff),random_int(0,0xfff),random_int(0,0xfff),random_int(0,0xffffffffffff));
    $pdo->prepare(
        'INSERT INTO destinations (id,slug,country_code,city_name,display_name,currency_code,locale,time_zone,status,created_at,updated_at)
         VALUES (:id,:slug,"NG","Lagos","Editorial Test Destination","NGN","en","Africa/Lagos","draft",UTC_TIMESTAMP(),UTC_TIMESTAMP())'
    )->execute(['id'=>$destinationId,'slug'=>'editorial-test-'.bin2hex(random_bytes(4))]);
    $guideId=$service->saveDestinationGuide(
        $admin,null,$destinationId,'A considered guide to Lagos','Reviewed destination information.','en',
        'Lagos guide','A concise Lagos guide.','Create destination guide',bin2hex(random_bytes(16))
    );
    $assert($service->publicDestinationGuide($destinationId,'en')===null,'draft destination guides are not public');
    $service->publishDestinationGuide($admin,$guideId,'Approve destination guide',bin2hex(random_bytes(16)));
    $publicGuide=$service->publicDestinationGuide($destinationId,'en');
    $assert(is_array($publicGuide) && $publicGuide['title']==='A considered guide to Lagos' &&
        $publicGuide['content']==='Reviewed destination information.' && $publicGuide['meta_title']==='Lagos guide',
        'published destination guides expose only the approved locale snapshot and SEO metadata');
    $service->saveDestinationGuide(
        $admin,$guideId,$destinationId,'A considered guide to Lagos','Updated destination information.','en',
        'Lagos guide','Updated concise Lagos guide.','Update destination guide',bin2hex(random_bytes(16))
    );
    $assert($service->publicDestinationGuide($destinationId,'en')['content']==='Reviewed destination information.',
        'destination guide draft edits do not replace the published snapshot');
    $service->publishDestinationGuide($admin,$guideId,'Approve revised destination guide',bin2hex(random_bytes(16)));
    $assert($service->publicDestinationGuide($destinationId,'en')['content']==='Updated destination information.',
        'destination guide changes become public only after explicit publication');

    $testimonialId=$service->saveTestimonial(
        $admin,null,'A verified client','The team handled our enquiry with care.','en',
        'consent-record-2026-001',10,'Record documented testimonial consent',bin2hex(random_bytes(16))
    );
    $assert($service->publicTestimonials('en')===[],'draft testimonials are not public');
    $service->publishTestimonial($admin,$testimonialId,'Approve consented testimonial',bin2hex(random_bytes(16)));
    $publicTestimonials=$service->publicTestimonials('en');
    $assert(count($publicTestimonials)===1 && $publicTestimonials[0]['display_name']==='A verified client' &&
        !array_key_exists('consent_reference',$publicTestimonials[0]),
        'published testimonials expose the approved quote but never the private consent reference');
    $service->saveTestimonial(
        $admin,$testimonialId,'A verified client','The team responded carefully and promptly.','en',
        'consent-record-2026-002',10,'Update quote under renewed consent',bin2hex(random_bytes(16))
    );
    $assert($service->publicTestimonials('en')[0]['quote_text']==='The team handled our enquiry with care.',
        'testimonial draft edits preserve the prior published snapshot');
    $service->publishTestimonial($admin,$testimonialId,'Approve updated testimonial consent',bin2hex(random_bytes(16)));
    $assert($service->publicTestimonials('en')[0]['quote_text']==='The team responded carefully and promptly.',
        'a testimonial revision is public only after explicit publication');

    try {
        $service->saveTestimonial($admin,null,'No consent','Unapproved quote','en','',0,'Missing consent reference',bin2hex(random_bytes(16)));
        $assert(false,'testimonial publication records require a consent reference');
    } catch (InvalidArgumentException) {
        $assert(true,'testimonial publication records require a consent reference');
    }
    return $assertions;
}
