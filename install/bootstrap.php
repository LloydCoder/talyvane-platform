<?php

declare(strict_types=1);

use App\Install\BootstrapEnvironment;

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
