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

function talyvane_is_public_root_directory(string $path): bool
{
    $name = strtolower(basename($path));

    // /var/www is the operating system's conventional web-server directory,
    // not a customer's document root. OpenLiteSpeed's default vhost lives
    // below /var/www/vhosts/<name>/html, so treating this ancestor as a
    // customer web root incorrectly resolves the private parent to /var.
    if ($name === 'www'
        && in_array(strtolower(basename(dirname($path))), ['var', 'usr', 'opt'], true)) {
        return false;
    }

    return in_array($name, ['public_html', 'www', 'htdocs', 'httpdocs', 'public', 'web'], true);
}

function talyvane_contains_public_root_component(string $path): bool
{
    $segments = explode(DIRECTORY_SEPARATOR, trim($path, DIRECTORY_SEPARATOR));
    foreach ($segments as $index => $segment) {
        $name = strtolower($segment);

        // Ignore system-level /var/www, /usr/www and /opt/www components.
        // A user-owned /home/<account>/www remains a public-root component.
        if ($name === 'www' && $index > 0
            && in_array(strtolower($segments[$index - 1]), ['var', 'usr', 'opt'], true)) {
            continue;
        }

        if (in_array($name, ['public_html', 'www', 'htdocs', 'httpdocs', 'public', 'web'], true)) {
            return true;
        }
    }

    return false;
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
            && ! talyvane_contains_public_root_component($home)
            && talyvane_inside($documentRoot, $home)) {
            return $home;
        }
    }

    // cPanel addon domains are commonly nested below public_html. Also
    // recognize the conventional www/htdocs/httpdocs/public/web roots.
    // Select the parent of the outermost public-root component.
    $publicRootParent = null;
    $cursor = $documentRoot;
    while (true) {
        if (talyvane_is_public_root_directory($cursor)) {
            $candidate = realpath(dirname($cursor));
            if ($candidate !== false && is_dir($candidate) && talyvane_inside($documentRoot, $candidate)) {
                // Keep walking so nested document roots cannot cause us to
                // choose a directory still inside an outer public_html.
                $publicRootParent = $candidate;
            }
        }
        $parent = dirname($cursor);
        if ($parent === $cursor) {
            break;
        }
        $cursor = $parent;
    }
    if ($publicRootParent !== null) {
        return $publicRootParent;
    }

    // For custom cPanel document roots without a discoverable HOME or
    // public_html ancestor, use the immediate parent only when it is not itself
    // a conventional public web root.
    $parent = realpath(dirname($documentRoot));
    if ($parent === false || ! is_dir($parent)
        || talyvane_contains_public_root_component($parent)
        || ! talyvane_inside($documentRoot, $parent)) {
        return null;
    }

    return $parent;
}

function talyvane_remove_staging_directory(string $directory): void
{
    if (is_link($directory) || ! is_dir($directory)) {
        return;
    }

    $entries = @scandir($directory);
    if (! is_array($entries)) {
        return;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory.DIRECTORY_SEPARATOR.$entry;
        if (is_link($path) || is_file($path)) {
            @unlink($path);
        } elseif (is_dir($path)) {
            talyvane_remove_staging_directory($path);
        } else {
            @unlink($path);
        }
    }

    @rmdir($directory);
}

function talyvane_extract_private_application(string $documentRoot, string $privateApplicationParent): string
{
    if (! class_exists(ZipArchive::class)) {
        talyvane_fail(500, 'This hosting account does not have PHP ZipArchive enabled. Enable the PHP ZIP extension and reload.');
    }

    $payload = $documentRoot.DIRECTORY_SEPARATOR.TALYVANE_PAYLOAD_FILE;
    if (! is_file($payload) || is_link($payload) || ! is_readable($payload)) {
        talyvane_fail(500, 'The bundled application archive is missing or unreadable. Re-upload the original Talyvane installation ZIP and extract it again.');
    }

    $target = $privateApplicationParent.DIRECTORY_SEPARATOR.TALYVANE_APP_DIRECTORY;
    if (file_exists($target) || is_link($target)) {
        $resolvedTarget = realpath($target);

        if ($resolvedTarget !== false && talyvane_inside($documentRoot, $resolvedTarget)) {
            talyvane_fail(409, 'The domain document root is inside an existing talyvane-app directory. This single-ZIP installer requires a separate public document root and a private talyvane-app directory outside it. Do not extract the installer into talyvane-app/public. Preserve the existing application and have the domain document root configured to a separate public directory before using this package.');
        }

        if ($resolvedTarget !== false && talyvane_inside($resolvedTarget, $documentRoot)) {
            talyvane_fail(409, 'The existing talyvane-app directory resolves inside the public document root. This is not a safe installation layout. Preserve the files and configure the private application outside the public document root before retrying.');
        }

        talyvane_fail(409, 'A talyvane-app directory already exists in the private account location. Nothing was overwritten. Back up and inspect that directory before retrying; the installer will not replace an existing application.');
    }

    if (! is_writable($privateApplicationParent)) {
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
    $seenEntries = [];
    for ($i = 0; $i < $entryCount; $i++) {
        $stat = $archive->statIndex($i);
        $name = is_array($stat) ? (string) ($stat['name'] ?? '') : '';
        $normalized = str_replace('\\', '/', $name);
        $parts = explode('/', $normalized);
        $pathSegments = $parts;
        $isDirectoryEntry = str_ends_with($normalized, '/');
        if ($isDirectoryEntry) {
            array_pop($pathSegments);
        }
        $canonicalName = rtrim($normalized, '/');
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
        $allowedFileTypes = [0, 0100000, 0040000];
        if ($normalized === '' || str_contains($normalized, "\0") || str_starts_with($normalized, '/')
            || preg_match('/^[A-Za-z]:/', $normalized) || in_array('..', $pathSegments, true)
            || in_array('.', $pathSegments, true) || in_array('', $pathSegments, true)
            || ($pathSegments[0] ?? null) !== 'talyvane'
            || (count($pathSegments) < 2 && ! $isDirectoryEntry)
            || isset($seenEntries[$canonicalName])
            || ! in_array($mode, $allowedFileTypes, true)) {
            $archive->close();
            talyvane_fail(500, 'The application archive contains an unsafe path, duplicate entry or unsupported file type and was rejected.');
        }
        $seenEntries[$canonicalName] = true;
        $expandedBytes += (int) ($stat['size'] ?? 0);
        if ($expandedBytes > TALYVANE_MAX_EXPANDED_BYTES) {
            $archive->close();
            talyvane_fail(500, 'The application archive exceeds the supported extraction size.');
        }
    }

    $staging = $privateApplicationParent.DIRECTORY_SEPARATOR.'.talyvane-stage-'.bin2hex(random_bytes(12));
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
        talyvane_remove_staging_directory($staging);
        talyvane_fail(500, 'The private application could not be safely extracted or is incomplete. The original archive has been preserved for retry.');
    }

    if (! rename($resolvedApplication, $target)) {
        talyvane_remove_staging_directory($staging);
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

// Give every domain its own private application directory. This avoids collisions
// with existing Laravel checkouts and other domains on the same cPanel account.
// The deterministic key is derived from the vhost name and its actual document
// root; no customer path editing or document-root changes are required.
$serverName = strtolower(trim((string) ($_SERVER['SERVER_NAME'] ?? $_SERVER['HTTP_HOST'] ?? '')));
$serverName = preg_replace('/:\\d+$/', '', $serverName) ?? '';
$siteIdentity = ($serverName !== '' ? $serverName : 'default')."\\0".$documentRoot;
$siteKey = substr(hash('sha256', $siteIdentity), 0, 32);
$privateRoot = $accountDirectory.DIRECTORY_SEPARATOR.'.talyvane';
$privateAppsRoot = $privateRoot.DIRECTORY_SEPARATOR.'apps';
$privateApplicationParent = $privateAppsRoot.DIRECTORY_SEPARATOR.$siteKey;

foreach ([$privateRoot, $privateAppsRoot, $privateApplicationParent] as $privateDirectory) {
    if (is_link($privateDirectory) || (file_exists($privateDirectory) && ! is_dir($privateDirectory))) {
        talyvane_fail(500, 'The private Talyvane storage path contains an unsafe file or symbolic link. No existing files were changed.');
    }
    if (! is_dir($privateDirectory) && ! @mkdir($privateDirectory, 0700)) {
        talyvane_fail(500, 'The hosting account does not allow PHP to create a private application directory outside the document root. Ask the hosting provider to correct the account ownership or PHP execution permissions.');
    }
    @chmod($privateDirectory, 0700);
    $resolvedDirectory = realpath($privateDirectory);
    if ($resolvedDirectory === false || ! talyvane_inside($resolvedDirectory, $accountDirectory)
        || talyvane_inside($resolvedDirectory, $documentRoot)) {
        talyvane_fail(500, 'The private application directory could not be verified as outside the public document root.');
    }
}

$privateApplication = $privateApplicationParent.DIRECTORY_SEPARATOR.TALYVANE_APP_DIRECTORY;

// Detect a public document root nested in an existing private application
// before deciding whether extraction is needed. This must run for both fresh
// and already-populated private directories.
if (file_exists($privateApplication) || is_link($privateApplication)) {
    $resolvedExistingApplication = realpath($privateApplication);
    if ($resolvedExistingApplication !== false && talyvane_inside($documentRoot, $resolvedExistingApplication)) {
        talyvane_fail(409, 'The domain document root is inside an existing talyvane-app directory. This single-ZIP installer requires a separate public document root and a private talyvane-app directory outside it. Do not extract the installer into talyvane-app/public. Preserve the existing application and have the domain document root configured to a separate public directory before using this package.');
    }
    if ($resolvedExistingApplication !== false && talyvane_inside($resolvedExistingApplication, $documentRoot)) {
        talyvane_fail(409, 'The existing talyvane-app directory resolves inside the public document root. This is not a safe installation layout. Preserve the files and configure the private application outside the public document root before retrying.');
    }
}

if (! is_dir($privateApplication)) {
    $privateApplication = talyvane_extract_private_application($documentRoot, $privateApplicationParent);
} else {
    $leftoverPayload = $documentRoot.DIRECTORY_SEPARATOR.TALYVANE_PAYLOAD_FILE;

    // Never follow a stale symlink from the public document root. Remove the
    // link itself, and fail closed if any leftover entry cannot be removed.
    if (is_link($leftoverPayload)) {
        if (! @unlink($leftoverPayload) || file_exists($leftoverPayload) || is_link($leftoverPayload)) {
            talyvane_fail(500, 'A leftover private payload entry could not be removed safely. Remove the hidden .talyvane-payload.zip entry from the document root and reload.');
        }
    } elseif (file_exists($leftoverPayload)) {
        if (! is_file($leftoverPayload) || ! @unlink($leftoverPayload)
            || file_exists($leftoverPayload) || is_link($leftoverPayload)) {
            talyvane_fail(500, 'A leftover private payload entry could not be removed safely. Remove the hidden .talyvane-payload.zip entry from the document root and reload.');
        }
    }
}

$resolvedPrivateApplication = realpath($privateApplication);
$publicDirectory = $privateApplication.DIRECTORY_SEPARATOR.'public';
$frontController = $publicDirectory.DIRECTORY_SEPARATOR.'index.php';
$vendorDirectory = $privateApplication.DIRECTORY_SEPARATOR.'vendor';
$autoload = $vendorDirectory.DIRECTORY_SEPARATOR.'autoload.php';
$resolvedPublicDirectory = realpath($publicDirectory);
$resolvedFrontController = realpath($frontController);
$resolvedVendorDirectory = realpath($vendorDirectory);
$resolvedAutoload = realpath($autoload);
if ($resolvedPrivateApplication === false || ! talyvane_inside($resolvedPrivateApplication, $accountDirectory)
    || talyvane_inside($resolvedPrivateApplication, $documentRoot)
    || is_link($privateApplication) || is_link($publicDirectory) || is_link($frontController)
    || is_link($vendorDirectory) || is_link($autoload)
    || $resolvedPublicDirectory === false || ! talyvane_inside($resolvedPublicDirectory, $resolvedPrivateApplication)
    || $resolvedFrontController === false || ! talyvane_inside($resolvedFrontController, $resolvedPrivateApplication)
    || $resolvedVendorDirectory === false || ! talyvane_inside($resolvedVendorDirectory, $resolvedPrivateApplication)
    || $resolvedAutoload === false || ! talyvane_inside($resolvedAutoload, $resolvedPrivateApplication)
    || ! is_file($frontController) || ! is_readable($frontController)
    || ! is_file($autoload) || ! is_readable($autoload)) {
    talyvane_fail(500, 'The private application directory is not in the required secure location or is incomplete. No application files were served.');
}

require $frontController;
