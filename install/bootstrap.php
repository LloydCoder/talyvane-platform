<?php

declare(strict_types=1);

use App\Install\BootstrapEnvironment;

if (PHP_SAPI !== 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

try {
    (new BootstrapEnvironment(dirname(__DIR__)))->ensure();
} catch (Throwable $exception) {
    // Never expose environment values, filesystem paths or exception messages.
    error_log('Talyvane first-request bootstrap failed: '.$exception::class);
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store, private');
    exit('Talyvane installation bootstrap is unavailable. Verify the private application files and permissions.');
}
