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
            'SELECT title,content,meta_title,meta_description,locale FROM site_pages
             WHERE slug = :slug AND status = "published" AND locale = :locale LIMIT 1'
        );
        $query->execute(['slug'=>$slug,'locale'=>'en']);
        $page = $query->fetch();
        if (!is_array($page)) {
            return Response::html('Not Found', 404);
        }

        return Response::html($this->views->render('public/page', [
            'brand' => $this->publicBrand(),
            'title' => (string) $page['title'],
            'content' => (string) $page['content'],
            'metaTitle' => is_string($page['meta_title']) && $page['meta_title'] !== '' ? (string) $page['meta_title'] : (string) $page['title'],
            'metaDescription' => is_string($page['meta_description']) ? (string) $page['meta_description'] : '',
        ]));
    }

    /** @return array{brand_name:string,tagline:string} */
    private function publicBrand(): array
    {
        $row = $this->pdo->query('SELECT brand_name,tagline FROM brand_settings WHERE singleton_id = 1')->fetch();
        if (!is_array($row)) {
            return ['brand_name'=>'Talyvane','tagline'=>''];
        }
        return ['brand_name'=>(string) $row['brand_name'],'tagline'=>(string) ($row['tagline'] ?? '')];
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
