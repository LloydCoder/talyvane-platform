<?php
declare(strict_types=1);

use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Infrastructure\Audit\AuditQueryService;

function runAuditQueryIntegration(PDO $pdo): int
{
    $assertions = 0;
    $assert = static function (bool $ok, string $message) use (&$assertions): void {
        $assertions++;
        if (!$ok) {
            throw new RuntimeException('Audit query integration failed: ' . $message);
        }
    };

    $actorId = (string) $pdo->query("SELECT id FROM users WHERE email = 'native-admin@example.test'")->fetchColumn();
    $logger = new AuditLogger($pdo);
    for ($i = 0; $i < 55; $i++) {
        $logger->record(
            $actorId,
            $i % 2 === 0 ? 'audit.test.even' : 'audit.test.odd',
            'integration_fixture',
            'fixture-' . $i,
            'Pagination fixture',
            ['email'=>'person@example.test','password'=>'must-not-be-returned'],
            ['status'=>'recorded'],
            bin2hex(random_bytes(16)),
            'integration-v1'
        );
    }

    $queries = new AuditQueryService($pdo);
    $first = $queries->search(['event_key'=>'audit.test.even'], 1);
    $second = $queries->search(['event_key'=>'audit.test.even'], 2);
    $assert(count($first) === 28, 'filtered first page returns no more than the 50-row cap and only matching records');
    $assert(count($second) === 0, 'filtered pagination does not leak records from another event type');

    $allFirst = $queries->search(['target_type'=>'integration_fixture'], 1);
    $allSecond = $queries->search(['target_type'=>'integration_fixture'], 2);
    $assert(count($allFirst) === 50, 'audit listing enforces a maximum page size of 50');
    $assert(count($allSecond) === 5, 'audit listing returns the remaining rows on the next page');
    $assert(!array_key_exists('before_json', $allFirst[0]) && !array_key_exists('after_json', $allFirst[0]), 'audit viewer never exposes before/after payload snapshots');
    $assert(!array_key_exists('password', $allFirst[0]), 'audit viewer returns event metadata only, never credential fields');

    $filtered = $queries->search(['event_key'=>'audit.test.odd','target_type'=>'integration_fixture'], 1);
    $assert(count($filtered) === 27, 'audit filters combine event and target type with parameterized exact matching');
    $assert($filtered[0]['event_key'] === 'audit.test.odd', 'audit rows are ordered newest first');
    return $assertions;
}
