<?php
declare(strict_types=1);

namespace Talyvane\Application\Content;

use PDO;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Security\Authorization;
use Talyvane\Security\Principal;

final class ContentManagementService
{
    public function __construct(private PDO $pdo,private AuditLogger $audit) {}

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function updateBrand(Principal $actor,array $input,string $requestId): array
    {
        Authorization::require($actor,'site.content.manage',true);
        if (!$actor->hasRole('super_admin')) {
            throw new \DomainException('Only the Super Admin can change deployment branding.');
        }
        $name = trim((string) ($input['brand_name'] ?? ''));
        $tagline = isset($input['tagline']) ? trim((string) $input['tagline']) : null;
        $colors = $input['color_tokens'] ?? null;
        $typography = $input['typography'] ?? [];
        $contacts = $input['contact_details'] ?? [];
        $social = $input['social_links'] ?? [];
        $seo = $input['seo_defaults'] ?? [];
        if ($name === '' || strlen($name) > 120 || ($tagline !== null && strlen($tagline) > 190) ||
            !is_array($colors) || !is_array($typography) || !is_array($contacts) || !is_array($social) || !is_array($seo) ||
            strlen($requestId) < 16 || strlen($requestId) > 64) {
            throw new \InvalidArgumentException('Brand settings are invalid.');
        }
        foreach ($colors as $key=>$value) {
            if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_-]{1,39}$/D',$key) ||
                !is_string($value) || preg_match('/^#[0-9a-fA-F]{6}$/D',$value) !== 1) {
                throw new \InvalidArgumentException('Brand color tokens must use named six-digit hexadecimal colors.');
            }
        }
        self::assertJsonMap($typography,20,120);
        self::assertJsonMap($contacts,30,500);
        self::assertJsonMap($social,30,500);
        self::assertJsonMap($seo,30,500);
        $logo = self::storageKey($input['logo_storage_key'] ?? null);
        $favicon = self::storageKey($input['favicon_storage_key'] ?? null);
        $this->pdo->beginTransaction();
        try {
            $beforeQuery = $this->pdo->prepare('SELECT brand_name,tagline,color_tokens_json,typography_json,contact_details_json,social_links_json,seo_defaults_json,logo_storage_key,favicon_storage_key,version FROM brand_settings WHERE singleton_id = 1 FOR UPDATE');
            $beforeQuery->execute();
            $before = $beforeQuery->fetch();
            $this->pdo->prepare(
                'INSERT INTO brand_settings
                 (singleton_id,brand_name,tagline,logo_storage_key,favicon_storage_key,color_tokens_json,typography_json,contact_details_json,social_links_json,seo_defaults_json,version,updated_by,updated_at)
                 VALUES (1,:name,:tagline,:logo,:favicon,:colors,:typography,:contacts,:social,:seo,1,:actor,UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE brand_name = VALUES(brand_name),tagline = VALUES(tagline),
                 logo_storage_key = VALUES(logo_storage_key),favicon_storage_key = VALUES(favicon_storage_key),
                 color_tokens_json = VALUES(color_tokens_json),typography_json = VALUES(typography_json),
                 contact_details_json = VALUES(contact_details_json),social_links_json = VALUES(social_links_json),
                 seo_defaults_json = VALUES(seo_defaults_json),version = version + 1,updated_by = VALUES(updated_by),updated_at = UTC_TIMESTAMP()'
            )->execute([
                'name'=>$name,'tagline'=>$tagline,'logo'=>$logo,'favicon'=>$favicon,
                'colors'=>json_encode($colors,JSON_THROW_ON_ERROR),
                'typography'=>json_encode($typography,JSON_THROW_ON_ERROR),
                'contacts'=>json_encode($contacts,JSON_THROW_ON_ERROR),
                'social'=>json_encode($social,JSON_THROW_ON_ERROR),
                'seo'=>json_encode($seo,JSON_THROW_ON_ERROR),'actor'=>$actor->id,
            ]);
            $after = $this->publicBrand();
            $this->audit->record($actor->id,'brand.settings.updated','brand_settings','1','White-label brand settings updated',
                is_array($before) ? ['brand_name'=>$before['brand_name'],'version'=>(int) $before['version']] : null,
                ['brand_name'=>$after['brand_name'],'version'=>$after['version']],$requestId,'brand-settings-v1');
            $this->pdo->commit();
            return $after;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return array<string,mixed> */
    public function publicBrand(): array
    {
        $row = $this->pdo->query('SELECT brand_name,tagline,logo_storage_key,favicon_storage_key,color_tokens_json,typography_json,contact_details_json,social_links_json,seo_defaults_json,version FROM brand_settings WHERE singleton_id = 1')->fetch();
        if (!is_array($row)) {
            return [
                'brand_name'=>'Talyvane','tagline'=>null,'logo_storage_key'=>null,'favicon_storage_key'=>null,
                'color_tokens'=>['primary'=>'#17233B','accent'=>'#C58B43','surface'=>'#F7F5F0'],
                'typography'=>[],'contact_details'=>[],'social_links'=>[],'seo_defaults'=>[],'version'=>0,
            ];
        }
        return [
            'brand_name'=>(string) $row['brand_name'],
            'tagline'=>$row['tagline'],
            'logo_storage_key'=>$row['logo_storage_key'],
            'favicon_storage_key'=>$row['favicon_storage_key'],
            'color_tokens'=>self::decodeMap($row['color_tokens_json']),
            'typography'=>self::decodeMap($row['typography_json']),
            'contact_details'=>self::decodeMap($row['contact_details_json']),
            'social_links'=>self::decodeMap($row['social_links_json']),
            'seo_defaults'=>self::decodeMap($row['seo_defaults_json']),
            'version'=>(int) $row['version'],
        ];
    }

    public function savePage(
        Principal $actor,?string $pageId,string $slug,string $title,string $locale,string $content,
        ?string $metaTitle,?string $metaDescription,string $reason,string $requestId
    ): string {
        Authorization::require($actor,'site.content.manage',true);
        $slug = strtolower(trim($slug));
        $title = trim($title);
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',$slug) || strlen($slug) > 190 ||
            $title === '' || strlen($title) > 190 || !preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/D',$locale) ||
            $content === '' || strlen($content) > 200000 || ($metaTitle !== null && strlen($metaTitle) > 190) ||
            ($metaDescription !== null && strlen($metaDescription) > 320) ||
            strlen(trim($reason)) < 4 || strlen($reason) > 500 ||
            strlen($requestId) < 16 || strlen($requestId) > 64) {
            throw new \InvalidArgumentException('CMS page content or metadata is invalid.');
        }
        $this->pdo->beginTransaction();
        try {
            if ($pageId === null) {
                $id = self::uuid();
                $insert = $this->pdo->prepare(
                    'INSERT INTO site_pages (id,slug,title,locale,content,meta_title,meta_description,status,version,published_at,created_by,updated_by,created_at,updated_at)
                     VALUES (:id,:slug,:title,:locale,:content,:meta_title,:meta_description,"draft",1,NULL,:created_by,:updated_by,UTC_TIMESTAMP(),UTC_TIMESTAMP())'
                );
                $insert->execute(['id'=>$id,'slug'=>$slug,'title'=>$title,'locale'=>$locale,'content'=>$content,'meta_title'=>$metaTitle,'meta_description'=>$metaDescription,'created_by'=>$actor->id,'updated_by'=>$actor->id]);
                $version = 1;
                $this->savePageVersion($id,$version,$title,$content,$metaTitle,$metaDescription,$actor->id,$reason);
                $before = null;
            } else {
                $query = $this->pdo->prepare('SELECT * FROM site_pages WHERE id = :id FOR UPDATE');
                $query->execute(['id'=>$pageId]);
                $current = $query->fetch();
                if (!is_array($current)) {
                    throw new \DomainException('CMS page does not exist.');
                }
                $id = $pageId;
                $version = (int) $current['version'] + 1;
                $before = ['slug'=>$current['slug'],'title'=>$current['title'],'version'=>(int) $current['version'],'published_version'=>$current['published_version'] === null ? null : (int) $current['published_version'],'status'=>$current['status']];
                $this->pdo->prepare(
                    'UPDATE site_pages SET slug = :slug,title = :title,locale = :locale,content = :content,
                     meta_title = :meta_title,meta_description = :meta_description,version = :version,updated_by = :actor,updated_at = UTC_TIMESTAMP()
                     WHERE id = :id'
                )->execute(['slug'=>$slug,'title'=>$title,'locale'=>$locale,'content'=>$content,'meta_title'=>$metaTitle,'meta_description'=>$metaDescription,'version'=>$version,'actor'=>$actor->id,'id'=>$id]);
                $this->savePageVersion($id,$version,$title,$content,$metaTitle,$metaDescription,$actor->id,$reason);
            }
            $this->audit->record($actor->id,'cms.page.saved','site_page',$id,$reason,$before,
                ['slug'=>$slug,'title'=>$title,'version'=>$version],$requestId,'cms-page-v1');
            $this->pdo->commit();
            return $id;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function publishPage(Principal $actor,string $pageId,string $reason,string $requestId): void
    {
        Authorization::require($actor,'site.content.manage',true);
        if (strlen(trim($reason)) < 8 || strlen($reason) > 500 || strlen($requestId) < 16 || strlen($requestId) > 64) {
            throw new \InvalidArgumentException('A publication reason and request identifier are required.');
        }
        $this->pdo->beginTransaction();
        try {
            $query = $this->pdo->prepare('SELECT status,version,published_version FROM site_pages WHERE id = :id FOR UPDATE');
            $query->execute(['id'=>$pageId]);
            $row = $query->fetch();
            if (!is_array($row)) {
                throw new \DomainException('CMS page does not exist.');
            }
            $version = (int) $row['version'];
            $publishedVersion = $row['published_version'] === null ? null : (int) $row['published_version'];
            if ($publishedVersion === $version) {
                throw new \DomainException('There are no unpublished changes to promote.');
            }
            $this->pdo->prepare('UPDATE site_pages SET status = "published",published_version = :version,published_at = COALESCE(published_at,UTC_TIMESTAMP()),updated_by = :actor,updated_at = UTC_TIMESTAMP() WHERE id = :id')
                ->execute(['version'=>$version,'actor'=>$actor->id,'id'=>$pageId]);
            $this->audit->record($actor->id,'cms.page.published','site_page',$pageId,trim($reason),
                ['status'=>$row['status'],'version'=>$version,'published_version'=>$publishedVersion],
                ['status'=>'published','version'=>$version,'published_version'=>$version],
                $requestId,'cms-page-v1');
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function rollbackPage(Principal $actor,string $pageId,int $targetVersion,string $reason,string $requestId): void
    {
        Authorization::require($actor,'site.content.manage',true);
        if ($targetVersion < 1 || strlen(trim($reason)) < 8 || strlen($reason) > 500 || strlen($requestId) < 16 || strlen($requestId) > 64) {
            throw new \InvalidArgumentException('CMS rollback input is invalid.');
        }
        $this->pdo->beginTransaction();
        try {
            $pageQuery = $this->pdo->prepare('SELECT version FROM site_pages WHERE id = :id FOR UPDATE');
            $pageQuery->execute(['id'=>$pageId]);
            $page = $pageQuery->fetch();
            if (!is_array($page)) {
                throw new \DomainException('CMS page does not exist.');
            }
            $versionQuery = $this->pdo->prepare('SELECT title,content,metadata_json FROM site_page_versions WHERE page_id = :page AND version = :version LIMIT 1');
            $versionQuery->execute(['page'=>$pageId,'version'=>$targetVersion]);
            $snapshot = $versionQuery->fetch();
            if (!is_array($snapshot)) {
                throw new \DomainException('Requested CMS page version does not exist.');
            }
            $metadata = self::decodeMap($snapshot['metadata_json']);
            $newVersion = (int) $page['version'] + 1;
            $this->pdo->prepare('UPDATE site_pages SET title = :title,content = :content,meta_title = :meta_title,meta_description = :meta_description,version = :version,updated_by = :actor,updated_at = UTC_TIMESTAMP() WHERE id = :id')
                ->execute(['title'=>$snapshot['title'],'content'=>$snapshot['content'],'meta_title'=>$metadata['meta_title'] ?? null,'meta_description'=>$metadata['meta_description'] ?? null,'version'=>$newVersion,'actor'=>$actor->id,'id'=>$pageId]);
            $this->savePageVersion($pageId,$newVersion,(string) $snapshot['title'],(string) $snapshot['content'],$metadata['meta_title'] ?? null,$metadata['meta_description'] ?? null,$actor->id,'Rollback to version ' . $targetVersion . ': ' . trim($reason));
            $this->audit->record($actor->id,'cms.page.rolled_back','site_page',$pageId,trim($reason),
                ['version'=>(int) $page['version']],['version'=>$newVersion,'restored_version'=>$targetVersion],
                $requestId,'cms-page-v1');
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return array<string,mixed>|null */
    public function publicPage(string $slug,string $locale): ?array
    {
        $query = $this->pdo->prepare(
            'SELECT p.slug,p.locale,p.status,p.published_version AS version,p.published_at,
                    v.title,v.content,v.metadata_json
             FROM site_pages p
             INNER JOIN site_page_versions v
                ON v.page_id = p.id AND v.version = p.published_version
             WHERE p.slug = :slug AND p.locale = :locale
               AND p.status = "published" AND p.published_version IS NOT NULL
             LIMIT 1'
        );
        $query->execute(['slug'=>$slug,'locale'=>$locale]);
        $row = $query->fetch();
        if (!is_array($row)) {
            return null;
        }
        $metadata = self::decodeMap($row['metadata_json'] ?? null);
        return [
            'slug'=>(string) $row['slug'],
            'title'=>(string) $row['title'],
            'locale'=>(string) $row['locale'],
            'content'=>(string) $row['content'],
            'meta_title'=>is_string($metadata['meta_title'] ?? null) ? $metadata['meta_title'] : null,
            'meta_description'=>is_string($metadata['meta_description'] ?? null) ? $metadata['meta_description'] : null,
            'status'=>(string) $row['status'],
            'version'=>(int) $row['version'],
            'published_at'=>$row['published_at'],
        ];
    }

    public function saveNavigationItem(
        Principal $actor,?string $itemId,string $location,string $label,string $targetType,string $targetValue,
        string $locale,int $sortOrder,bool $enabled,string $requestId
    ): string {
        Authorization::require($actor,'site.content.manage',true);
        if (!preg_match('/^[a-z][a-z0-9_-]{1,47}$/D',$location) || trim($label) === '' || strlen($label) > 120 ||
            !in_array($targetType,['route','url','page'],true) || strlen($targetValue) > 500 ||
            !preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/D',$locale) || $sortOrder < -10000 || $sortOrder > 10000 ||
            strlen($requestId) < 16 || strlen($requestId) > 64) {
            throw new \InvalidArgumentException('Navigation item is invalid.');
        }
        if ($targetType === 'url' && (!str_starts_with($targetValue,'https://') || filter_var($targetValue,FILTER_VALIDATE_URL) === false)) {
            throw new \InvalidArgumentException('External navigation targets must be HTTPS URLs.');
        }
        if ($targetType !== 'url' && (!str_starts_with($targetValue,'/') || str_starts_with($targetValue,'//') || str_contains($targetValue,'..'))) {
            throw new \InvalidArgumentException('Internal navigation targets must be safe root-relative paths.');
        }
        $id = $itemId ?? self::uuid();
        if ($itemId === null) {
            $this->pdo->prepare('INSERT INTO navigation_items (id,parent_id,location_key,label,target_type,target_value,locale,sort_order,enabled,created_at,updated_at) VALUES (:id,NULL,:location,:label,:type,:target,:locale,:sort,:enabled,UTC_TIMESTAMP(),UTC_TIMESTAMP())')
                ->execute(['id'=>$id,'location'=>$location,'label'=>trim($label),'type'=>$targetType,'target'=>$targetValue,'locale'=>$locale,'sort'=>$sortOrder,'enabled'=>$enabled ? 1 : 0]);
        } else {
            $this->pdo->prepare('UPDATE navigation_items SET location_key = :location,label = :label,target_type = :type,target_value = :target,locale = :locale,sort_order = :sort,enabled = :enabled,updated_at = UTC_TIMESTAMP() WHERE id = :id')
                ->execute(['location'=>$location,'label'=>trim($label),'type'=>$targetType,'target'=>$targetValue,'locale'=>$locale,'sort'=>$sortOrder,'enabled'=>$enabled ? 1 : 0,'id'=>$id]);
            if ($this->pdo->query('SELECT ROW_COUNT()')->fetchColumn() === '0') {
                throw new \DomainException('Navigation item does not exist.');
            }
        }
        $this->audit->record($actor->id,'cms.navigation.saved','navigation_item',$id,'Navigation item updated',null,
            ['location'=>$location,'target_type'=>$targetType,'enabled'=>$enabled],$requestId,'cms-navigation-v1');
        return $id;
    }

    /** @return list<array<string,mixed>> */
    public function publicNavigation(string $location,string $locale): array
    {
        $query = $this->pdo->prepare('SELECT id,parent_id,label,target_type,target_value,sort_order FROM navigation_items WHERE location_key = :location AND locale = :locale AND enabled = 1 ORDER BY sort_order,label');
        $query->execute(['location'=>$location,'locale'=>$locale]);
        return $query->fetchAll();
    }

    private function savePageVersion(string $pageId,int $version,string $title,string $content,?string $metaTitle,?string $metaDescription,string $actorId,string $reason): void
    {
        $this->pdo->prepare('INSERT INTO site_page_versions (id,page_id,version,title,content,metadata_json,changed_by,change_reason,created_at) VALUES (:id,:page,:version,:title,:content,:metadata,:actor,:reason,UTC_TIMESTAMP())')
            ->execute([
                'id'=>self::uuid(),'page'=>$pageId,'version'=>$version,'title'=>$title,'content'=>$content,
                'metadata'=>json_encode(['meta_title'=>$metaTitle,'meta_description'=>$metaDescription],JSON_THROW_ON_ERROR),
                'actor'=>$actorId,'reason'=>$reason,
            ]);
    }

    /** @param array<mixed> $value */
    private static function assertJsonMap(array $value,int $maxEntries,int $maxValueLength): void
    {
        if (count($value) > $maxEntries) {
            throw new \InvalidArgumentException('Brand settings contain too many entries.');
        }
        foreach ($value as $key=>$item) {
            if (!is_string($key) || strlen($key) > 80 || (!is_string($item) && !is_int($item) && !is_bool($item)) ||
                (is_string($item) && strlen($item) > $maxValueLength)) {
                throw new \InvalidArgumentException('Brand settings contain an unsupported value.');
            }
        }
    }

    private static function storageKey(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || strlen($value) > 500 || str_starts_with($value,'/') ||
            str_contains($value,'..') || preg_match('/^[a-zA-Z0-9_\/-]+$/D',$value) !== 1) {
            throw new \InvalidArgumentException('Brand media must reference a safe private storage key.');
        }
        return $value;
    }

    /** @return array<string,mixed> */
    private static function decodeMap(mixed $json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }
        $value = json_decode($json,true);
        return is_array($value) ? $value : [];
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }
}
