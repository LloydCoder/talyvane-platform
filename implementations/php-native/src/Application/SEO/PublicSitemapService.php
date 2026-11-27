<?php
declare(strict_types=1);

namespace Talyvane\Application\SEO;

use PDO;
use Talyvane\Application\Discovery\DiscoveryService;

/** Builds a sitemap from the exact public discovery and CMS publication boundaries. */
final class PublicSitemapService
{
    public function __construct(
        private PDO $pdo,
        private DiscoveryService $discovery,
        private string $canonicalOrigin
    ) {}

    public function canonicalUrl(string $path): string
    {
        return SitemapDocument::url($this->canonicalOrigin,$path);
    }

    public function xml(): string
    {
        $paths = ['/', '/destinations', '/companions', '/contact'];
        $pages = $this->pdo->query(
            'SELECT slug FROM site_pages
             WHERE locale="en" AND status="published" AND published_version IS NOT NULL
             ORDER BY slug LIMIT 50000'
        );
        foreach ($pages->fetchAll(PDO::FETCH_COLUMN) as $slug) {
            if (is_string($slug) && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',$slug) === 1) {
                $paths[] = '/pages/' . $slug;
            }
        }

        foreach ($this->discovery->destinations() as $destination) {
            $slug = $destination['slug'] ?? null;
            if (is_string($slug) && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',$slug) === 1) {
                $paths[] = '/destinations/' . $slug;
            }
        }

        $remaining = 50000 - count($paths);
        if ($remaining < 0) {
            throw new \LengthException('Published public content exceeds the sitemap URL limit.');
        }
        if ($remaining > 0) {
            foreach ($this->discovery->publicProfileSlugs($remaining) as $slug) {
                $paths[] = '/profiles/' . $slug;
            }
        }

        return SitemapDocument::render($this->canonicalOrigin,$paths);
    }
}
