<?php
declare(strict_types=1);

namespace Talyvane\Infrastructure\Database;

use PDO;
use RuntimeException;

final class MigrationRunner
{
    public function __construct(private PDO $pdo, private string $migrationDirectory) {}

    /**
     * Shared MySQL/MariaDB accounts may be unable to create triggers because
     * binary-log policy requires elevated privileges. Only known trigger
     * privilege errors are deferred; every other SQL error remains fatal.
     */
    public static function isSharedHostingTriggerRestriction(\PDOException $error,string $sql): bool
    {
        if (preg_match('/^CREATE\\s+TRIGGER\\b/i',ltrim($sql)) !== 1 || !is_array($error->errorInfo ?? null)) {
            return false;
        }
        $state = $error->errorInfo[0] ?? null;
        $driverCode = $error->errorInfo[1] ?? null;
        return $state === 'HY000' && in_array((int) $driverCode,[1142,1227,1419],true);
    }

    private static function triggerName(string $sql): string
    {
        return preg_match('/^CREATE\\s+TRIGGER\\s+([a-zA-Z0-9_]+)/i',ltrim($sql),$match) === 1
            ? $match[1]
            : 'trigger-enforcement';
    }

    /** @return list<array{migration_key:string,capability_key:string,reason:string}> */
    public function warnings(): array
    {
        $statement = $this->pdo->query(
            "SELECT migration_key,capability_key,reason FROM schema_migration_capabilities WHERE status='unavailable' ORDER BY migration_key,capability_key"
        );
        return $statement->fetchAll();
    }

    /** @return list<string> */
    public function migrate(): array
    {
        if (!is_dir($this->migrationDirectory) || !is_readable($this->migrationDirectory)) {
            throw new RuntimeException('Migration directory is unavailable.');
        }
        $lock = $this->pdo->query("SELECT GET_LOCK('talyvane_native_schema_migrations', 15)")->fetchColumn();
        if ((int) $lock !== 1) {
            throw new RuntimeException('Could not obtain the schema migration lock.');
        }
        $applied = [];
        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
                migration_key VARCHAR(190) NOT NULL PRIMARY KEY,
                checksum CHAR(64) NOT NULL,
                applied_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS schema_migration_capabilities (
                migration_key VARCHAR(190) NOT NULL,
                capability_key VARCHAR(120) NOT NULL,
                status VARCHAR(32) NOT NULL,
                reason VARCHAR(255) NOT NULL,
                observed_at DATETIME NOT NULL,
                PRIMARY KEY (migration_key,capability_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $files = glob(rtrim($this->migrationDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.php') ?: [];
            sort($files, SORT_STRING);
            foreach ($files as $file) {
                $key = basename($file);
                $checksum = hash_file('sha256', $file);
                if (!is_string($checksum)) {
                    throw new RuntimeException('Could not checksum migration ' . $key);
                }
                $query = $this->pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration_key = :key');
                $query->execute(['key' => $key]);
                $existing = $query->fetchColumn();
                if (is_string($existing)) {
                    if (!hash_equals($existing, $checksum)) {
                        throw new RuntimeException('Applied migration checksum changed: ' . $key);
                    }
                    continue;
                }
                $statements = require $file;
                if (!is_array($statements) || $statements === []) {
                    throw new RuntimeException('Migration must return a non-empty SQL statement list: ' . $key);
                }
                foreach ($statements as $sql) {
                    if (!is_string($sql) || trim($sql) === '') {
                        throw new RuntimeException('Invalid SQL statement in migration: ' . $key);
                    }
                    try {
                        $this->pdo->exec($sql);
                    } catch (\PDOException $error) {
                        if (!self::isSharedHostingTriggerRestriction($error,$sql)) {
                            throw $error;
                        }
                        $triggerName = self::triggerName($sql);
                        $capability = $this->pdo->prepare(
                            'INSERT INTO schema_migration_capabilities (migration_key,capability_key,status,reason,observed_at)
                             VALUES (:migration,:capability,"unavailable",:reason,UTC_TIMESTAMP())
                             ON DUPLICATE KEY UPDATE status=VALUES(status),reason=VALUES(reason),observed_at=VALUES(observed_at)'
                        );
                        $capability->execute([
                            'migration'=>$key,
                            'capability'=>$triggerName,
                            'reason'=>'Database server policy does not permit application-user trigger creation; application-level append-only controls remain active.',
                        ]);
                    }
                }
                $insert = $this->pdo->prepare('INSERT INTO schema_migrations (migration_key, checksum, applied_at) VALUES (:key, :checksum, UTC_TIMESTAMP())');
                $insert->execute(['key' => $key, 'checksum' => $checksum]);
                $applied[] = $key;
            }
            return $applied;
        } finally {
            $this->pdo->query("SELECT RELEASE_LOCK('talyvane_native_schema_migrations')");
        }
    }
}
