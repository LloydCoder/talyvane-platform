<?php

declare(strict_types=1);

/**
 * Talyvane single-ZIP cPanel bootstrap.
 *
 * The customer extracts one archive into the domain document root. This
 * bootstrap moves the bundled private application to the account directory
 * above the document root, then hands the request to Laravel's public entry.
 */

const TALYVANE_APP_DIRECTORY = 'talyvane-app';
const TALYVANE_PAYLOAD_FILE = '.talyvane-payload.zip';
const TALYVANE_MAX_ARCHIVE_ENTRIES = 20000;
const TALYVANE_MAX_EXPANDED_BYTES = 1073741824; // 1 GiB

function talyvane_fail(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Talyvane setup</title><body style="font:16px/1.6 system-ui;max-width:720px;margin:8vh auto;padding:24px;color:#172033"><h1>Talyvane setup could not continue</h1><p>'.htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</p><p>Correct the stated hosting or file-permission issue and reload this page. Your existing files have not been overwritten.</p></body></html>';
    exit;
}

function talyvane_inside(string $path, string $directory): bool
{
    return $path === $directory || str_starts_with($path, rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
}

function talyvane_resolve_private_parent(string $documentRoot): ?string
{
    $homeCandidates = [
        (string) ($_SERVER['HOME'] ?? ''),
        (string) (getenv('HOME') ?: ''),
    ];

    foreach (array_unique($homeCandidates) as $candidate) {
        if ($candidate === '') {
            continue;
        }
        $home = realpath($candidate);
        if ($home !== false && is_dir($home) && $home !== $documentRoot
            && talyvane_inside($documentRoot, $home)) {
            return $home;
        }
    }

    // cPanel addon domains are commonly nested below /home/account/public_html.
    // Place the private application beside public_html, never inside it.
    $cursor = $documentRoot;
    while (true) {
        if (strtolower(basename($cursor)) === 'public_html') {
            $home = realpath(dirname($cursor));
            return $home !== false && is_dir($home) && talyvane_inside($documentRoot, $home)
                ? $home
                : null;
        }
        $parent = dirname($cursor);
        if ($parent === $cursor) {
            break;
        }
        $cursor = $parent;
    }

    // For custom cPanel document roots without a discoverable HOME or
    // public_html ancestor, use the immediate parent only when it is not itself
    // a conventional public web root.
    $parent = realpath(dirname($documentRoot));
    if ($parent === false || ! is_dir($parent)
        || in_array(strtolower(basename($parent)), ['public_html', 'www', 'htdocs', 'httpdocs'], true)
        || ! talyvane_inside($documentRoot, $parent)) {
        return null;
    }

    return $parent;
}

function talyvane_extract_private_application(string $documentRoot, string $accountDirectory): string
{
    if (! class_exists(ZipArchive::class)) {
        talyvane_fail(500, 'This hosting account does not have PHP ZipArchive enabled. Enable the PHP ZIP extension and reload.');
    }

    $payload = $documentRoot.DIRECTORY_SEPARATOR.TALYVANE_PAYLOAD_FILE;
    if (! is_file($payload) || is_link($payload) || ! is_readable($payload)) {
        talyvane_fail(500, 'The bundled application archive is missing or unreadable. Re-upload the original Talyvane installation ZIP and extract it again.');
    }

    $target = $accountDirectory.DIRECTORY_SEPARATOR.TALYVANE_APP_DIRECTORY;
    if (file_exists($target) || is_link($target)) {
        talyvane_fail(409, 'A talyvane-app directory already exists beside this domain document root. Nothing was overwritten. Rename or back up that directory before retrying.');
    }

    if (! is_writable($accountDirectory)) {
        talyvane_fail(500, 'The directory one level above the domain document root is not writable by PHP. This hosting layout cannot safely install Talyvane.');
    }

    $archive = new ZipArchive();
    if ($archive->open($payload) !== true) {
        talyvane_fail(500, 'The bundled application archive is damaged. Re-upload the original installation ZIP.');
    }

    $entryCount = $archive->numFiles;
    if ($entryCount < 1 || $entryCount > TALYVANE_MAX_ARCHIVE_ENTRIES) {
        $archive->close();
        talyvane_fail(500, 'The application archive has an invalid number of files.');
    }

    $expandedBytes = 0;
    for ($i = 0; $i < $entryCount; $i++) {
        $stat = $archive->statIndex($i);
        $name = is_array($stat) ? (string) ($stat['name'] ?? '') : '';
        $normalized = str_replace('\\', '/', $name);
        $parts = explode('/', $normalized);
        $mode = 0;
        $opsys = 0;
        $attributes = 0;
        if (! $archive->getExternalAttributesIndex($i, $opsys, $attributes)) {
            $archive->close();
            talyvane_fail(500, 'The application archive contains unreadable file metadata and was rejected.');
        }
        if ($opsys === ZipArchive::OPSYS_UNIX) {
            $mode = ($attributes >> 16) & 0170000;
        }
        if ($normalized === '' || str_contains($normalized, "\0") || str_starts_with($normalized, '/') || preg_match('/^[A-Za-z]:/', $normalized)
            || in_array('..', $parts, true) || $parts[0] !== 'talyvane'
            || ($mode === 0120000)) {
            $archive->close();
            talyvane_fail(500, 'The application archive contains an unsafe path or symbolic link and was rejected.');
        }
        $expandedBytes += (int) ($stat['size'] ?? 0);
        if ($expandedBytes > TALYVANE_MAX_EXPANDED_BYTES) {
            $archive->close();
            talyvane_fail(500, 'The application archive exceeds the supported extraction size.');
        }
    }

    $staging = $accountDirectory.DIRECTORY_SEPARATOR.'.talyvane-stage-'.bin2hex(random_bytes(12));
    if (! mkdir($staging, 0700)) {
        $archive->close();
        talyvane_fail(500, 'A private staging directory could not be created. Check the account directory permissions.');
    }

    $extracted = $archive->extractTo($staging);
    $archive->close();
    $stagedApplication = $staging.DIRECTORY_SEPARATOR.'talyvane';
    $resolvedStaging = realpath($staging);
    $resolvedApplication = realpath($stagedApplication);

    if (! $extracted || $resolvedStaging === false || $resolvedApplication === false
        || ! talyvane_inside($resolvedApplication, $resolvedStaging)
        || ! is_file($resolvedApplication.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'index.php')
        || ! is_file($resolvedApplication.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php')) {
        talyvane_fail(500, 'The private application could not be safely extracted or is incomplete. The original archive has been preserved for retry.');
    }

    if (! rename($resolvedApplication, $target)) {
        talyvane_fail(500, 'The private application was extracted but could not be moved into its final private directory. Check parent-directory permissions and reload after resolving the issue.');
    }

    @rmdir($staging);
    @chmod($target, 0750);

    if (! is_file($target.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'index.php')) {
        talyvane_fail(500, 'The private application move could not be verified. Contact hosting support before retrying.');
    }

    if (! unlink($payload)) {
        talyvane_fail(500, 'The application is in place, but the temporary private payload archive could not be removed. Remove the hidden .talyvane-payload.zip file from the document root immediately, then reload.');
    }

    return $target;
}

$documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
$scriptDirectory = realpath(__DIR__);
if ($documentRoot === false || $scriptDirectory === false || $documentRoot !== $scriptDirectory) {
    talyvane_fail(500, 'The domain document root does not match the extracted installation directory. Set this domain to the directory containing the Talyvane ZIP contents.');
}

$accountDirectory = talyvane_resolve_private_parent($documentRoot);
if ($accountDirectory === null || $accountDirectory === $documentRoot) {
    talyvane_fail(500, 'The account home directory could not be resolved safely. Configure the domain beneath the cPanel account home and ensure PHP can identify that private directory.');
}

$privateApplication = $accountDirectory.DIRECTORY_SEPARATOR.TALYVANE_APP_DIRECTORY;
if (! is_dir($privateApplication)) {
    $privateApplication = talyvane_extract_private_application($documentRoot, $accountDirectory);
} else {
    $leftoverPayload = $documentRoot.DIRECTORY_SEPARATOR.TALYVANE_PAYLOAD_FILE;
    if (is_file($leftoverPayload) && ! is_link($leftoverPayload)) {
        @unlink($leftoverPayload);
    }
}

$resolvedPrivateApplication = realpath($privateApplication);
$frontController = $privateApplication.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'index.php';
if ($resolvedPrivateApplication === false || ! talyvane_inside($resolvedPrivateApplication, $accountDirectory)
    || talyvane_inside($resolvedPrivateApplication, $documentRoot)
    || is_link($privateApplication) || ! is_file($frontController) || ! is_readable($frontController)) {
    talyvane_fail(500, 'The private application directory is not in the required secure location. No application files were served.');
}

require $frontController;
