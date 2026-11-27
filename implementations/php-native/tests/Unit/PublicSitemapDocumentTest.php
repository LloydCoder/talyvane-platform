<?php
declare(strict_types=1);

use Talyvane\\Application\\SEO\\SitemapDocument;

final class PublicSitemapDocumentTest
{
    public static function run(callable $assert): void
    {
        $xml = SitemapDocument::render('https://example.test/', [
            '/', '/destinations', '/destinations', '/pages/privacy', '/profiles/alex-example',
        ]);
        $assert(str_starts_with($xml, '<?xml version="1.0" encoding="UTF-8"?>'), 'sitemap is an XML document');
        $assert(substr_count($xml, '<url>') === 4, 'sitemap deduplicates canonical paths');
        $assert(str_contains($xml, '<loc>https://example.test/</loc>'), 'sitemap emits an absolute canonical homepage URL');
        $assert(str_contains($xml, '<loc>https://example.test/pages/privacy</loc>'), 'sitemap emits published page paths on the configured origin');
        $assert(str_contains($xml, 'xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'), 'sitemap uses the standard sitemap namespace');

        foreach ([
            'http://example.test',
            'https://user:pass@example.test',
            'https://example.test/path',
            'https://example.test?host=attacker',
            'https://example.test#fragment',
            '//example.test',
        ] as $invalidOrigin) {
            try {
                SitemapDocument::render($invalidOrigin, ['/']);
                $assert(false, 'sitemap rejects non-canonical or unsafe origin: ' . $invalidOrigin);
            } catch (InvalidArgumentException) {
                $assert(true, 'sitemap rejects non-canonical or unsafe origin');
            }
        }

        foreach ([
            '//attacker.test/path',
            '/../private',
            '/pages/../admin',
            '/pages/privacy?preview=1',
            '/pages/privacy#fragment',
            '/pages/unsafe\\path',
            '/pages/UPPERCASE',
        ] as $invalidPath) {
            try {
                SitemapDocument::render('https://example.test', [$invalidPath]);
                $assert(false, 'sitemap rejects unsafe local path: ' . $invalidPath);
            } catch (InvalidArgumentException) {
                $assert(true, 'sitemap rejects unsafe local path');
            }
        }

        try {
            SitemapDocument::render('https://example.test', array_fill(0, 50001, '/'));
            $assert(false, 'sitemap rejects more than 50000 URLs');
        } catch (LengthException) {
            $assert(true, 'sitemap rejects more than 50000 URLs');
        }
    }
}
