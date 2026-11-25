<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Talyvane\Infrastructure\Database\ConnectionFactory;
use Talyvane\Infrastructure\Database\MigrationRunner;
use Talyvane\Infrastructure\Database\ReferenceDataImporter;

$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('DB_PORT') ?: 3306);
$name = 'talyvane_native_test';
$user = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASSWORD') ?: '';
$server = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port), $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$server->exec('CREATE DATABASE IF NOT EXISTS talyvane_native_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo = ConnectionFactory::mysql(['host'=>$host,'port'=>$port,'database'=>$name,'username'=>$user,'password'=>$password]);
$runner = new MigrationRunner($pdo, dirname(__DIR__) . '/database/migrations');
$applied = $runner->migrate();
if (count($applied) !== 24 || $runner->migrate() !== []) {
    throw new RuntimeException('Native PHP migrations failed or are not idempotent.');
}
$referenceImporter = new ReferenceDataImporter($pdo);
$countryReferencePath = dirname(__DIR__) . '/database/reference-data/countries.json';
$currencyReferencePath = dirname(__DIR__) . '/database/reference-data/currencies.json';
$referenceImport = $referenceImporter->importIfNeeded($countryReferencePath,$currencyReferencePath);
if ($referenceImport['countries'] !== 249 || $referenceImport['currencies'] !== 155) {
    throw new RuntimeException('Native PHP ISO reference dataset counts do not match the pinned snapshot.');
}
$referenceRepeat = $referenceImporter->importIfNeeded($countryReferencePath,$currencyReferencePath);
if ($referenceRepeat['imported'] !== false) {
    throw new RuntimeException('Native PHP ISO reference import is not idempotent.');
}
$pdo->exec('UPDATE countries SET enabled=0 WHERE alpha2="NG"');
$pdo->exec('UPDATE currencies SET enabled=0 WHERE code="NGN"');
$tempReferenceDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'talyvane-reference-' . bin2hex(random_bytes(6));
mkdir($tempReferenceDirectory,0700,true);
$countryDocument = json_decode((string) file_get_contents($countryReferencePath),true,64,JSON_THROW_ON_ERROR);
$currencyDocument = json_decode((string) file_get_contents($currencyReferencePath),true,64,JSON_THROW_ON_ERROR);
$countryDocument['metadata']['version'] = '2026-11-25.2';
$currencyDocument['metadata']['version'] = '2026-11-25.2';
$tempCountryPath = $tempReferenceDirectory . DIRECTORY_SEPARATOR . 'countries.json';
$tempCurrencyPath = $tempReferenceDirectory . DIRECTORY_SEPARATOR . 'currencies.json';
file_put_contents($tempCountryPath,json_encode($countryDocument,JSON_THROW_ON_ERROR));
file_put_contents($tempCurrencyPath,json_encode($currencyDocument,JSON_THROW_ON_ERROR));
$referenceRefresh = $referenceImporter->importIfNeeded($tempCountryPath,$tempCurrencyPath);
$ngEnabled = $pdo->query('SELECT enabled FROM countries WHERE alpha2="NG"')->fetchColumn();
$ngnEnabled = $pdo->query('SELECT enabled FROM currencies WHERE code="NGN"')->fetchColumn();
if ($referenceRefresh['imported'] !== true || (int) $ngEnabled !== 0 || (int) $ngnEnabled !== 0) {
    throw new RuntimeException('Reference dataset refresh did not preserve administrator enablement decisions.');
}
$pdo->exec('UPDATE countries SET enabled=1 WHERE alpha2="NG"');
$pdo->exec('UPDATE currencies SET enabled=1 WHERE code="NGN"');
unlink($tempCountryPath);
unlink($tempCurrencyPath);
rmdir($tempReferenceDirectory);

require __DIR__ . '/../tests/Integration/InstallerBootstrapIntegration.php';
require __DIR__ . '/../tests/Integration/SafeguardingIntegration.php';
require __DIR__ . '/../tests/Integration/MembershipIntegration.php';
require __DIR__ . '/../tests/Integration/IdentityIntegration.php';
require __DIR__ . '/../tests/Integration/ProfileMediaIntegration.php';
require __DIR__ . '/../tests/Integration/ModelAgencyIntegration.php';
require __DIR__ . '/../tests/Integration/ContentManagementIntegration.php';
require __DIR__ . '/../tests/Integration/EnquiryIntegration.php';
require __DIR__ . '/../tests/Integration/AuditQueryIntegration.php';
$installerAssertions = runInstallerBootstrapIntegration($pdo);
$membershipAssertions = runMembershipIntegration($pdo);
$identityAssertions = runIdentityIntegration($pdo);
$safeguardingAssertions = runSafeguardingIntegration($pdo);
$mediaAssertions = runProfileMediaIntegration($pdo);
$modelAgencyAssertions = runModelAgencyIntegration($pdo);
$contentAssertions = runContentManagementIntegration($pdo);
$enquiryAssertions = runEnquiryIntegration($pdo);
$auditAssertions = runAuditQueryIntegration($pdo);
fwrite(STDOUT, sprintf("Native PHP MySQL integration: %d migrations, %d installer-bootstrap assertions, %d membership assertions, %d safeguarding assertions, %d identity assertions, %d profile-media assertions, %d model-agency/privacy assertions, %d content-management assertions, %d enquiry/CRM assertions and %d audit-query assertions passed.\n", count($applied), $installerAssertions, $membershipAssertions, $safeguardingAssertions, $identityAssertions, $mediaAssertions, $modelAgencyAssertions, $contentAssertions, $enquiryAssertions, $auditAssertions));
