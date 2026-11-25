<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Talyvane\Infrastructure\Database\ConnectionFactory;
use Talyvane\Infrastructure\Database\MigrationRunner;

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
if (count($applied) !== 15 || $runner->migrate() !== []) {
    throw new RuntimeException('Native PHP migrations failed or are not idempotent.');
}
require __DIR__ . '/../tests/Integration/IdentityIntegration.php';
require __DIR__ . '/../tests/Integration/ModelAgencyIntegration.php';
require __DIR__ . '/../tests/Integration/ContentManagementIntegration.php';
require __DIR__ . '/../tests/Integration/EnquiryIntegration.php';
$identityAssertions = runIdentityIntegration($pdo);
$modelAgencyAssertions = runModelAgencyIntegration($pdo);
$contentAssertions = runContentManagementIntegration($pdo);
$enquiryAssertions = runEnquiryIntegration($pdo);
fwrite(STDOUT, sprintf("Native PHP MySQL integration: %d migrations, %d identity assertions, %d model-agency/privacy assertions and %d content-management assertions, %d enquiry/CRM assertions passed.\\n", count($applied), $identityAssertions, $modelAgencyAssertions, $contentAssertions, $enquiryAssertions));
