<?php

namespace App\Install;

final class PublicPathResolver
{
    public static function resolve(string $applicationPublicPath, string $documentRoot): ?string
    {
        if ($documentRoot === '') {
            return null;
        }

        $resolvedDocumentRoot = realpath($documentRoot);
        if ($resolvedDocumentRoot === false || ! is_dir($resolvedDocumentRoot)) {
            return null;
        }

        $resolvedApplicationPublicPath = realpath($applicationPublicPath);
        if ($resolvedApplicationPublicPath === false
            || ! is_dir($resolvedApplicationPublicPath)
            || is_link($applicationPublicPath)
            || $resolvedApplicationPublicPath !== $applicationPublicPath) {
            return null;
        }

        $privateApplicationRoot = dirname($resolvedApplicationPublicPath);

        // The document root may be the application's public/ directory or a
        // separate cPanel public_html directory. It must never be the private
        // project root, an ancestor of that root, or another private subtree.
        if ($resolvedDocumentRoot === $privateApplicationRoot
            || str_starts_with($privateApplicationRoot, $resolvedDocumentRoot.DIRECTORY_SEPARATOR)
            || (str_starts_with($resolvedDocumentRoot, $privateApplicationRoot.DIRECTORY_SEPARATOR)
                && $resolvedDocumentRoot !== $resolvedApplicationPublicPath)) {
            return null;
        }

        return $resolvedDocumentRoot;
    }
}
