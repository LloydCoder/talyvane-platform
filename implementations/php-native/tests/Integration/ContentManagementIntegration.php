<?php
declare(strict_types=1);

use Talyvane\Application\Content\ContentManagementService;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Security\Principal;

function runContentManagementIntegration(PDO $pdo): int
{
    $assertions = 0;
    $assert = static function (bool $ok,string $message) use (&$assertions): void {
        $assertions++;
        if (!$ok) {
            throw new RuntimeException('Content management integration failed: ' . $message);
        }
    };
    $adminId = (string) $pdo->query('SELECT id FROM users WHERE email = "native-admin@example.test"')->fetchColumn();
    $admin = new Principal($adminId,['super_admin'],true);
    $service = new ContentManagementService($pdo,new AuditLogger($pdo));
    $brand = $service->updateBrand($admin,[
        'brand_name'=>'Talyvane Studio',
        'tagline'=>'Independent representation, considered carefully',
        'color_tokens'=>['primary'=>'#17233B','accent'=>'#C58B43','surface'=>'#F7F5F0'],
        'typography'=>['heading'=>'Cormorant Garamond','body'=>'Inter'],
        'contact_details'=>['email'=>'hello@example.test'],
        'social_links'=>['instagram'=>'https://instagram.com/example'],
        'seo_defaults'=>['title_suffix'=>' | Talyvane Studio','robots'=>'index,follow'],
        'logo_storage_key'=>null,
        'favicon_storage_key'=>null,
    ],bin2hex(random_bytes(16)));
    $assert($brand['brand_name'] === 'Talyvane Studio','Super Admin can update the white-label brand settings');
    $assert($service->publicBrand()['brand_name'] === 'Talyvane Studio','public brand projection reads the current brand snapshot');

    $pageId = $service->savePage($admin,null,'privacy','Privacy Notice','en',
        'This is the first reviewed privacy notice.','Privacy Notice','How this service handles information.',
        'Create first draft',bin2hex(random_bytes(16)));
    $assert($service->publicPage('privacy','en') === null,'draft CMS page is never visible publicly');
    $service->publishPage($admin,$pageId,'Legal reviewer approved first publication',bin2hex(random_bytes(16)));
    $firstPublished = $service->publicPage('privacy','en');
    $assert(is_array($firstPublished) && $firstPublished['content'] === 'This is the first reviewed privacy notice.' && (int) $firstPublished['version'] === 1,
        'initial publication pins an immutable public page revision');
    $service->savePage($admin,$pageId,'privacy','Privacy Notice','en',
        'This is the revised privacy notice.','Privacy Notice','Updated privacy information.',
        'Clarify retention and rights',bin2hex(random_bytes(16)));
    $stillPublished = $service->publicPage('privacy','en');
    $assert(is_array($stillPublished) && $stillPublished['content'] === 'This is the first reviewed privacy notice.' && (int) $stillPublished['version'] === 1,
        'saving a new draft never changes the currently published public snapshot');
    $service->publishPage($admin,$pageId,'Legal reviewer approved revised publication',bin2hex(random_bytes(16)));
    $public = $service->publicPage('privacy','en');
    $assert(is_array($public) && $public['content'] === 'This is the revised privacy notice.' && (int) $public['version'] === 2 && $public['status'] === 'published',
        'publishing explicitly promotes the selected draft revision to public readers');
    $service->rollbackPage($admin,$pageId,1,'Restore the approved first version',bin2hex(random_bytes(16)));
    $rollbackDraft = $service->publicPage('privacy','en');
    $assert(is_array($rollbackDraft) && $rollbackDraft['content'] === 'This is the revised privacy notice.' && (int) $rollbackDraft['version'] === 2,
        'rollback creates a draft and leaves the currently published snapshot unchanged');
    $service->publishPage($admin,$pageId,'Legal reviewer approved restored publication',bin2hex(random_bytes(16)));
    $restored = $service->publicPage('privacy','en');
    $assert(is_array($restored) && $restored['content'] === 'This is the first reviewed privacy notice.' && (int) $restored['version'] === 3,
        'CMS rollback can be explicitly republished as a new immutable version without deleting history');

    $navigationId = $service->saveNavigationItem($admin,null,'header','Discover','route','/companions','en',10,true,bin2hex(random_bytes(16)));
    $navigation = $service->publicNavigation('header','en');
    $assert($navigationId !== '' && count($navigation) === 1 && $navigation[0]['label'] === 'Discover',
        'enabled navigation is published through a locale-scoped public projection');
    $service->saveNavigationItem($admin,$navigationId,'header','Discover','route','/destinations','en',10,false,bin2hex(random_bytes(16)));
    $assert($service->publicNavigation('header','en') === [],'disabled navigation item is never exposed publicly');

    $client = new Principal((string) $pdo->query('SELECT id FROM users WHERE email = "native-client@example.test"')->fetchColumn(),['client'],false);
    try {
        $service->savePage($client,null,'forbidden','Forbidden','en','No','No',null,'Unauthorized attempt',bin2hex(random_bytes(16)));
        $assert(false,'client cannot manage CMS pages');
    } catch (DomainException) {
        $assert(true,'client cannot manage CMS pages');
    }
    return $assertions;
}
