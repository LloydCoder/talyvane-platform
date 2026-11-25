<?php
declare(strict_types=1);

/** Minimal dependency-free PSR-4 loader for the shared-hosting runtime. */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Talyvane\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

date_default_timezone_set('UTC');
