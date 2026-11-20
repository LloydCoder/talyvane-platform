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
        if ($resolvedApplicationPublicPath === false || ! is_dir($resolvedApplicationPublicPath)) {
            return null;
        }

        return $resolvedDocumentRoot;
    }
}
