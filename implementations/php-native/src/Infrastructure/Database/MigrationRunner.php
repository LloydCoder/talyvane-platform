<?php
declare(strict_types=1);

namespace Talyvane\Infrastructure\Database;

use PDO;
use RuntimeException;

final class MigrationRunner
{
    public function __construct(private PDO $pdo, private string $migrationDirectory) {}

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
                    $this->pdo->exec($sql);
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
