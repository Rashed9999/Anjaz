<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// AMIAL-FIX(DEPLOY): واجهة الويب الأمامية (front controller) — كانت مفقودة تماماً،
// فكان nginx (root .../public) يُعيد 404 لكل شيء ويفشل artisan serve. هذا الملفّ
// القياسي هو نقطة دخول كل طلبات HTTP.

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
