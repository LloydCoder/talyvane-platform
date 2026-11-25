<?php
declare(strict_types=1);

namespace Talyvane\Http;

use PDO;

/** Public CMS endpoints. Content is emitted as text, never trusted HTML. */
final class PublicSiteController
{
    public function __construct(private PDO $pdo, private ViewRenderer $views) {}

    public function home(Request $request): Response
    {
        $brand = $this->publicBrand();
        $sections = $this->pdo->prepare(
            'SELECT section_key,title,content_json,sort_order FROM homepage_sections
             WHERE status = "published" AND locale = :locale ORDER BY sort_order ASC, section_key ASC'
        );
        $sections->execute(['locale'=>'en']);
        $items = [];
        foreach ($sections->fetchAll() as $row) {
            $content = json_decode((string) $row['content_json'], true);
            $items[] = [
                'title' => is_string($row['title']) ? $row['title'] : '',
                'content' => is_array($content) ? $this->flattenContent($content) : '',
            ];
        }

        return Response::html($this->views->render('public/home', [
            'brand' => $brand,
            'sections' => $items,
            'requestId' => $request->requestId,
        ]));
    }

    /** Only published CMS pages are resolvable by the public router. */
    public function page(Request $request, array $params): Response
    {
        $slug = (string) ($params['slug'] ?? '');
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) || strlen($slug) > 190) {
            return Response::html('Not Found', 404);
        }
        $query = $this->pdo->prepare(
            'SELECT p.title,p.locale,p.published_version,v.title AS published_title,
                    v.content AS published_content,v.metadata_json
             FROM site_pages p
             INNER JOIN site_page_versions v
                ON v.page_id = p.id AND v.version = p.published_version
             WHERE p.slug = :slug AND p.status = "published"
               AND p.published_version IS NOT NULL AND p.locale = :locale
             LIMIT 1'
        );
        $query->execute(['slug'=>$slug,'locale'=>'en']);
        $page = $query->fetch();
        if (!is_array($page)) {
            return Response::html('Not Found', 404);
        }

        $metadata = json_decode((string) ($page['metadata_json'] ?? ''), true);
        $metadata = is_array($metadata) ? $metadata : [];
        $brand = $this->publicBrand();
        $seo = $brand['seo_defaults'];
        $publishedTitle = (string) $page['published_title'];
        $metaTitle = is_string($metadata['meta_title'] ?? null) && $metadata['meta_title'] !== ''
            ? $metadata['meta_title']
            : $publishedTitle;
        $titleSuffix = is_string($seo['title_suffix'] ?? null) ? $seo['title_suffix'] : '';
        if ($titleSuffix !== '' && !str_ends_with($metaTitle, $titleSuffix)) {
            $metaTitle .= $titleSuffix;
        }
        $metaDescription = is_string($metadata['meta_description'] ?? null) && $metadata['meta_description'] !== ''
            ? $metadata['meta_description']
            : (is_string($seo['description'] ?? null) ? $seo['description'] : $brand['tagline']);
        $robots = in_array($seo['robots'] ?? 'index,follow', ['index,follow','noindex,nofollow'], true)
            ? $seo['robots']
            : 'index,follow';
        return Response::html($this->views->render('public/page', [
            'brand' => $brand,
            'title' => $publishedTitle,
            'content' => (string) $page['published_content'],
            'metaTitle' => $metaTitle,
            'metaDescription' => $metaDescription,
            'robots' => $robots,
        ]));
    }

    /** @return array{brand_name:string,tagline:string,seo_defaults:array<string,mixed>} */
    private function publicBrand(): array
    {
        $row = $this->pdo->query('SELECT brand_name,tagline,seo_defaults_json FROM brand_settings WHERE singleton_id = 1')->fetch();
        if (!is_array($row)) {
            return ['brand_name'=>'Talyvane','tagline'=>'','seo_defaults'=>['robots'=>'index,follow']];
        }
        $seo = json_decode((string) ($row['seo_defaults_json'] ?? ''), true);
        return [
            'brand_name'=>(string) $row['brand_name'],
            'tagline'=>(string) ($row['tagline'] ?? ''),
            'seo_defaults'=>is_array($seo) ? $seo : ['robots'=>'index,follow'],
        ];
    }

    /** @param array<mixed> $value */
    private function flattenContent(array $value): string
    {
        $parts = [];
        $walk = static function (mixed $item) use (&$walk, &$parts): void {
            if (is_string($item) || is_int($item) || is_float($item)) {
                $parts[] = (string) $item;
            } elseif (is_array($item)) {
                foreach ($item as $child) {
                    $walk($child);
                }
            }
        };
        $walk($value);
        return implode("\n", $parts);
    }
}
