<?php
declare(strict_types=1);

namespace Talyvane\Application\SEO;

/**
 * Builds a standards-compliant sitemap from local, already-authorized public paths.
 * This class deliberately accepts an origin rather than request headers so Host
 * header values can never influence canonical URLs.
 */
final class SitemapDocument
{
    /**
     * @param list<string> $paths
     */
    public static function render(string $canonicalOrigin, array $paths): string
    {
        $origin = self::canonicalOrigin($canonicalOrigin);
        if (count($paths) > 50000) {
            throw new \LengthException('A sitemap cannot contain more than 50000 URLs.');
        }

        $urls = [];
        foreach ($paths as $path) {
            if (!is_string($path) || preg_match('~^/(?:[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*)?$~D', $path) !== 1) {
                throw new \InvalidArgumentException('Sitemap paths must be safe, canonical local paths.');
            }
            $urls[$path] = $origin . $path;
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach (array_values($urls) as $url) {
            $xml .= "  <url><loc>" . htmlspecialchars($url, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</loc></url>\n";
        }
        $xml .= "</urlset>\n";
        return $xml;
    }

    private static function canonicalOrigin(string $origin): string
    {
        $parts = parse_url($origin);
        if (!is_array($parts) ||
            strtolower((string) ($parts['scheme'] ?? '')) !== 'https' ||
            !isset($parts['host']) ||
            !in_array((string) ($parts['path'] ?? ''), ['', '/'], true) ||
            isset($parts['user']) || isset($parts['pass']) ||
            isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('Sitemap canonical origin must be a bare HTTPS origin.');
        }

        $host = strtolower((string) $parts['host']);
        $isIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $isDomain = filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
        if (!$isIp && !$isDomain) {
            throw new \InvalidArgumentException('Sitemap canonical origin host is invalid.');
        }

        $port = $parts['port'] ?? null;
        if ($port !== null && ($port < 1 || $port > 65535)) {
            throw new \InvalidArgumentException('Sitemap canonical origin port is invalid.');
        }
        return 'https://' . $host . ($port === null || $port === 443 ? '' : ':' . $port);
    }
}
