<?php
/**
 * AMIAL-RBAC-001 — مصفوفة الصلاحيات الكاملة (#3 من الثلاثة).
 *
 * يختبر كل دور (أدمن/وكيل/تاجر/عميل/غير مُصادَق) ضدّ مسارات كل طبقة، ويبني
 * مصفوفة «من يصل لماذا». الحدّ الأمني المطلوب: الدور الأدنى يجب أن يُحجَب
 * (403/401) عن مسارات الطبقة الأعلى. أيّ تسريب = ثغرة تصعيد صلاحية.
 *
 * يُشغَّل مع DB_DATABASE=amial_conc CACHE_DRIVER=database SESSION_DRIVER=array
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// كاش/جلسة في الذاكرة — نعزل الاختبار عن جدول كاش القاعدة (الـthrottle يستخدمه)
config(['cache.default' => 'array', 'session.driver' => 'array']);

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;

Artisan::call('migrate:fresh', ['--force' => true]);
Artisan::call('passport:install', ['--no-interaction' => true]);

// توكن Passport حقيقي لكل دور (لا حالة عامّة — كل طلب يحمل توكنه)
function tokenFor(array $attrs): string
{
    return User::factory()->create($attrs)->createToken('rbac')->accessToken;
}
$roles = [
    'admin'    => tokenFor(['type' => 0, 'role' => 'super_admin']),
    'agent'    => tokenFor(['type' => 1, 'role' => 'agent']),
    'merchant' => tokenFor(['type' => 3, 'role' => 'merchant']),
    'customer' => tokenFor(['type' => 2, 'role' => 'customer']),
    'GUEST'    => null,
];

// المسارات مصنّفة بالطبقة المقصودة
$endpoints = [
    'ADMIN' => [
        'GET /admin/settlements/dashboard' => '/api/v1/amial/admin/settlements/dashboard',
        'GET /admin/ops/status'            => '/api/v1/amial/admin/ops/status',
        'GET /admin/settings/sms'          => '/api/v1/amial/admin/settings/sms',
        'GET /admin/dashboard'             => '/api/v1/amial/admin/dashboard',
    ],
    'AGENT' => [
        'GET /agent/float-dashboard' => '/api/v1/amial/agent/float-dashboard',
        'GET /agent/settlements'     => '/api/v1/amial/agent/settlements',
    ],
    'MERCHANT' => [
        'GET /merchant/branches'         => '/api/v1/amial/merchant/branches',
        'GET /merchant/cashier/products' => '/api/v1/amial/merchant/cashier/products',
    ],
];

// من يُسمَح له بكل طبقة (الأدمن يمرّ لكل شيء عادةً كسوبر)
$allowedRole = ['ADMIN' => ['admin'], 'AGENT' => ['agent', 'admin'], 'MERCHANT' => ['merchant', 'admin']];

function hit($kernel, $app, ?string $token, string $uri): int
{
    app('auth')->forgetGuards(); // لا حالة عالقة بين الطلبات
    $server = ['HTTP_ACCEPT' => 'application/json'];
    if ($token) $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
    $r = Request::create($uri, 'GET', [], [], [], $server);
    $resp = $kernel->handle($r);
    $kernel->terminate($r, $resp);
    (new ReflectionProperty($app, 'terminatingCallbacks'))->setValue($app, []);
    return $resp->getStatusCode();
}

$leaks = [];
$roleNames = array_keys($roles);

echo "═══ مصفوفة الصلاحيات (الرمز = HTTP status) ═══\n\n";
printf("%-34s", 'المسار \\ الدور');
foreach ($roleNames as $rn) printf("%-10s", $rn);
echo "\n" . str_repeat('─', 34 + 10 * count($roleNames)) . "\n";

foreach ($endpoints as $tier => $routes) {
    echo "── طبقة {$tier} ──\n";
    foreach ($routes as $label => $uri) {
        printf("%-34s", $label);
        foreach ($roles as $roleName => $user) {
            $code = hit($kernel, $app, $user, $uri);
            $blocked = in_array($code, [401, 403], true);
            $isAllowed = in_array($roleName, $allowedRole[$tier], true);
            // تسريب: دور غير مسموح لكنه لم يُحجَب
            if (!$isAllowed && !$blocked) {
                $leaks[] = "{$roleName} → {$label} (code={$code})";
                printf("%-10s", "⚠{$code}");
            } else {
                printf("%-10s", $code);
            }
        }
        echo "\n";
    }
}

echo "\n════════════════════════════════════════\n";
if (empty($leaks)) {
    echo "VERDICT: PASS ✓ لا تسريب صلاحيات — كل دور أدنى محجوب عن الطبقات الأعلى\n";
    exit(0);
} else {
    echo "VERDICT: FAIL ✗ تسريبات صلاحية:\n";
    foreach ($leaks as $l) echo "   ⚠ {$l}\n";
    echo "(الرمز 200/404/422 لدور غير مسموح = وصل خلف الحارس)\n";
    exit(1);
}
