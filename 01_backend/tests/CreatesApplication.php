<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

/**
 * CreatesApplication — تمهيد تطبيق Laravel لكل اختبار.
 *
 * كان هذا الملف مفقوداً (انظر FIXES.md). بدونه يفشل `Tests\TestCase` ومن ثمّ
 * **كامل** طاقم الاختبارات (66+ ملف) لأن جميعها ترث منه.
 */
trait CreatesApplication
{
    public function createApplication(): Application
    {
        /** @var Application $app */
        $app = require __DIR__ . '/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
