<?php
declare(strict_types=1);

namespace Talyvane\Infrastructure\Database;

use PDO;
use RuntimeException;

final class ConnectionFactory
{
    /** @param array{host:string,port?:int,database:string,username:string,password:string} $config */
    public static function mysql(array $config): PDO
    {
        foreach (['host', 'database', 'username', 'password'] as $key) {
            if (!array_key_exists($key, $config) || !is_string($config[$key])) {
                throw new RuntimeException('Database configuration is incomplete.');
            }
        }
        $host = $config['host'];
        $database = $config['database'];
        if (!preg_match('/^[a-zA-Z0-9._-]{1,255}$/D', $host) ||
            !preg_match('/^[a-zA-Z0-9_$-]{1,64}$/D', $database)) {
            throw new RuntimeException('Database host or database name is invalid.');
        }
        $port = (int) ($config['port'] ?? 3306);
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('Database port is invalid.');
        }
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database);
        return new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
    }
}
