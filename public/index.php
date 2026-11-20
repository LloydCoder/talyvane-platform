<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

require __DIR__.'/../vendor/autoload.php';

// Prepare a unique, production-safe APP_KEY before Laravel starts its first
// session-protected installer request. The bootstrap lives outside public/.
$installerBootstrap = __DIR__.'/../install/bootstrap.php';
if (is_file($installerBootstrap)) {
    require $installerBootstrap;
}

/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
