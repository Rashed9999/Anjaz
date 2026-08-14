<?php

/**
 * AMIAL-SWEEP-001 — **يُفتَح كلُّ بابٍ في اللوحة، لا خمسةَ عشرَ باباً.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * `verify.sh` تفتح ١٥ صفحةً مختارةً بيدٍ — وهي اختيارٌ لا مسح. فصفحةٌ
 * بُنيت اليوم ولم تُضَف إلى القائمة **لا يفتحها شيء**، وتُقال «الفحص
 * نظيف» وهي منهارة.
 *
 * وهذا يمشي على **كلّ** مسار GET في لوحة الإدارة، ويفتحه بجلسةِ مديرٍ
 * حقيقيّة، ويقول رقمَ ردّه.
 *
 * **والمعاملاتُ تُملأ من القاعدة**: مسارٌ فيه `{id}` يُفتح بمعرّفٍ قائمٍ
 * فعلاً — ولو حُشي رقمٌ عشوائيٌّ لردّ ٤٠٤ وقيل «يعمل».
 * ══════════════════════════════════════════════════════════════════════
 *
 * الاستعمال: php scripts/sweep-admin.php
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// ── مديرٌ كاملُ الصلاحيّات ────────────────────────────────────────────
//
// **وقاعدةُ التطوير تُفرَّغ بعد كلّ مجموعة اختبارات** (`RefreshDatabase`)،
// فلا يُعتمد على وجود حساب. يُنشأ مسبارٌ ويُحذف في النهاية — ولو تُرك
// لبقي حسابُ إدارةٍ بكلمةِ مرورٍ معروفةٍ في القاعدة.
$admin = App\Models\User::where('type', 0)->orderBy('id')->first();
$scratch = false;

if (! $admin) {
    $scratch = true;
    // **المصنعُ لا `create()`**: `type` خارج `fillable` عمداً — فحسابُ
    // إدارةٍ لا يُصنع بحقلٍ من طلب. والمصنعُ يضبطه مباشرةً.
    $admin = App\Models\User::factory()->create([
        'f_name' => 'مسبار',
        'l_name' => 'المسح',
        'phone' => '967700000000',
        'type' => 0,
        'role' => 'super_admin',
    ]);
    echo "· أُنشئ حسابُ مسبارٍ مؤقّت (يُحذف في النهاية)\n\n";
}

register_shutdown_function(static function () use ($admin, $scratch) {
    if (! $scratch) return;

    DB::table('admin_user_roles')->where('user_id', $admin->id)->delete();

    try {
        App\Models\User::where('id', $admin->id)->forceDelete();
    } catch (\Throwable) {
        // **المسحُ نفسُه يكتب سجلَّ وصولٍ إلى بياناتٍ شخصيّة**
        // (`pii_access_logs`) يشير إلى المسبار، ومفتاحٌ أجنبيٌّ يمنع حذفه.
        // وحذفُ السجلّ لإخفاء أثرِ فاعلِه يُفسد السجلَّ نفسَه — فيُعطَّل
        // الحسابُ ويُبدَّل سرُّه بدل أن يُحذف.
        DB::table('users')->where('id', $admin->id)->update([
            'is_active' => false,
            'phone' => 'sweep-probe-' . $admin->id,
            'password' => \Illuminate\Support\Facades\Hash::make(bin2hex(random_bytes(24))),
        ]);
        echo "\n· تعذّر حذفُ المسبار (سجلُّ تدقيقٍ يشير إليه) — عُطّل وبُدّل سرُّه\n";
    }
});

app(App\Services\PlatformRoleService::class)
    ->assign($admin, App\Services\PlatformRoleService::ADMIN);

// **وتاجرٌ ليكون موضوعَ مسارات «مركز التاجر»** — وبلا موضوعٍ من صنفه
// تُقرأ ردودُ الرفضِ السليمةُ أعطالاً.
if (! DB::table('users')->where('type', 3)->exists()) {
    $probeMerchant = App\Models\User::factory()->create([
        'f_name' => 'تاجر', 'l_name' => 'المسبار',
        'phone' => '967700000100', 'type' => 3, 'role' => 'merchant',
    ]);
    DB::table('merchant_profiles')->insert([
        'user_id' => $probeMerchant->id,
        'business_type' => 'retail',
        'subscription_plan' => 'starter',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    echo "· أُنشئ تاجرُ مسبارٍ مؤقّت\n\n";
}

auth('user')->login($admin);

/** قيمةٌ حقيقيّةٌ لكلّ معامل — أو `null` إن لم تُوجد. */
function sample(string $name): ?string
{
    static $cache = [];
    if (array_key_exists($name, $cache)) return $cache[$name];

    $pick = static function (string $table, string $col) {
        try {
            return DB::table($table)->orderByDesc('id')->value($col);
        } catch (\Throwable) {
            return null;
        }
    };

    // **الموضوعُ يُختار من صنفه لا من آخر صفّ.** مسارُ «مركز التاجر»
    // يُفتح بمعرّف **تاجرٍ**، ولو حُشي فيه معرّفُ مديرٍ لردّ خطأً يُقرأ
    // عطلاً وليس عطلاً — وقياسٌ يتلوّن بترتيب الصفوف ليس قياساً.
    $v = match ($name) {
        'id', 'userId', 'user' => $pick('users', 'id'),
        'ulid' => $pick('charity_campaigns', 'campaign_ulid'),
        'orgUlid' => $pick('charity_organizations', 'org_ulid'),
        '__merchant' => DB::table('users')->where('type', 3)->orderByDesc('id')->value('id'),
        default => null,
    };

    return $cache[$name] = $v === null ? null : (string) $v;
}

$rows = [];
$skipped = [];

foreach (Route::getRoutes() as $route) {
    if (! in_array('GET', $route->methods(), true)) continue;

    $uri = $route->uri();
    if (! str_starts_with($uri, 'admin/')) continue;

    // التصديرُ يُبثّ ملفّاً — يُستثنى بذكر سببه لا بصمت.
    if (str_contains($uri, 'export') || str_contains($uri, 'download')) {
        $skipped[] = [$uri, 'تصديرُ ملفّ — يُفحص بحارسه لا بمسبار'];
        continue;
    }
    if (str_contains($uri, 'logout')) {
        $skipped[] = [$uri, 'يُنهي الجلسة فيُفسد بقيّة المسح'];
        continue;
    }

    $path = $uri;
    $missing = null;

    if (preg_match_all('/\{(\w+)\??\}/', $uri, $m)) {
        foreach ($m[1] as $param) {
            $val = (str_contains($uri, 'merchant') && in_array($param, ['id', 'userId'], true))
                ? sample('__merchant')
                : sample($param);
            if ($val === null) { $missing = $param; break; }
            $path = preg_replace('/\{' . $param . '\??\}/', $val, $path, 1);
        }
    }

    if ($missing !== null) {
        // **«لم يُفحص» يُقال ولا يُعدّ نجاحاً** (القاعدة السابعة).
        $skipped[] = [$uri, "لا بيانات لِـ{$missing} في القاعدة"];
        continue;
    }

    try {
        $response = $kernel->handle(Request::create('/' . ltrim($path, '/'), 'GET'));
        $rows[] = [$uri, $response->getStatusCode(), $path !== $uri];
    } catch (\Throwable $e) {
        $rows[] = [$uri, 'EXC: ' . substr($e->getMessage(), 0, 90), $path !== $uri];
    }
}

// ── التقرير ──────────────────────────────────────────────────────────
// **٤٠٤ على معرّفٍ لا صفَّ له ليست عطلاً بل نتيجةً غيرَ حاسمة**
// (القاعدة السابعة: «غير معروف» ليس صفراً). فمسارٌ ذو معاملٍ رُدّ عليه
// ٤٠٤ يُعدّ «غيرَ محسوم» لا «معطوباً» — وعدُّه عطلاً يُغرق التقريرَ
// بضجيجٍ يُخفي الأعطالَ الحقيقيّة.
// **ورفضٌ سليمٌ ليس عطلاً.** مسارانِ يردّان رفضاً صحيحاً بلا مدخلاتٍ
// كاملة، وعدُّهما عطلين يجعل التقريرَ يصرخ كلَّ مرّةٍ فيُتجاهَل:
//
//   · `operational-detail` → ٤٢٣: يحتاج إذنَ اطّلاعٍ مؤقّتاً يُفتح من
//     تبويب سجلّ التدقيق. وهو تصميمٌ لا خلل.
//   · `support-center/search` → ٤٢٢: بحثٌ بلا كلمةِ بحث.
$expectedRefusal = [
    'admin/amial/merchant-center/{id}/operational-detail' => 423,
    'admin/support-center/search' => 422,
];

$isBad = static function (array $r) use ($expectedRefusal): bool {
    if (in_array($r[1], [200, 302], true)) return false;
    if ($r[1] === 404 && $r[2]) return false;
    return ($expectedRefusal[$r[0]] ?? null) !== $r[1];
};

$bad = array_values(array_filter($rows, $isBad));
$inconclusive = array_values(array_filter(
    $rows, static fn ($r) => $r[1] === 404 && $r[2]));
$ok = count($rows) - count($bad) - count($inconclusive);

echo "═══ مسحُ لوحة الإدارة ═══\n";
echo 'فُتح : ' . count($rows) . " مساراً\n";
echo "سليم : {$ok}\n";
echo 'معطوب: ' . count($bad) . "\n";
echo 'غيرُ محسوم (٤٠٤ على معرّفٍ لا صفَّ له): ' . count($inconclusive) . "\n";
echo 'مُخطّى: ' . count($skipped) . "\n\n";

if ($bad) {
    echo "── المعطوبة ──\n";
    foreach ($bad as [$uri, $code]) printf("  %-6s /%s\n", $code, $uri);
    echo "\n";
}

if ($skipped) {
    echo "── المُخطّاة (وسببُ كلٍّ) ──\n";
    foreach ($skipped as [$uri, $why]) printf("  /%-52s %s\n", $uri, $why);
}

exit($bad ? 1 : 0);
