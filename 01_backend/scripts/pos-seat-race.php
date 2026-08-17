<?php

/**
 * AMIAL-POS-DEVICES-006 — **سباقٌ حقيقيٌّ على آخر مقعدِ جهاز.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **لماذا خارج PHPUnit:**
 *
 * مجموعةُ الاختبارات تجري في **عمليّةٍ واحدةٍ متتابعة**، ومسارٌ يقرأ
 * العدَّ ثمّ يكتب في خطوتين **يمرّ فيها أبداً** — لا أحدَ يتحرّك بينهما.
 * وهذه هي الطبقةُ التاسعةُ في `verify.sh`، دُفع ثمنُها: ستُّ شبابيكَ من
 * ثمانٍ سقطت حين وُوزيت وكانت تمرّ متتابعة.
 *
 * ومحاولةُ الفرع **داخل** PHPUnit تكذب مرّتين:
 *
 *   ① `RefreshDatabase` يفتح معاملةً — فالتاجرُ المبذورُ غيرُ مثبَّت،
 *      **ولا يراه أيُّ ابنٍ على اتّصالٍ آخر**. فيسقط السباقُ على
 *      «التاجر غير موجود» ويُقرأ ذلك عطلاً.
 *   ② والأبناءُ يرثون مِقبضَ الاتّصال، فخروجُ أوّلِهم يُغلق مقبسَ الأب:
 *      `MySQL server has gone away` — **عطلُ أداةٍ يُقرأ عطلَ منتج**.
 *
 * فالسباقُ هنا: بياناتٌ **مثبَّتة**، ولكلّ عاملٍ اتّصالُه، ويلتقون على
 * حاجزٍ زمنيٍّ واحد.
 *
 * ══════════════════════════════════════════════════════════════════════
 *   php scripts/pos-seat-race.php [--workers=8] [--rounds=5]
 *
 * يخرج بصفرٍ إن كان عددُ المقاعد بعد كلّ جولةٍ **يساوي الحدَّ تماماً**.
 */

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Merchant\PosDevice;
use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\Merchant\PosDeviceRegistrar;
use App\Support\Access\AccessConstants as A;
use Illuminate\Support\Facades\DB;

$opt = getopt('', ['workers::', 'rounds::']);
$workers = max(2, (int) ($opt['workers'] ?? 8));
$rounds = max(1, (int) ($opt['rounds'] ?? 5));

function line(string $s = ''): void
{
    fwrite(STDOUT, $s.PHP_EOL);
}

if (! function_exists('pcntl_fork')) {
    line('⚠ pcntl غيرُ متاح — **السؤالُ يبقى مفتوحاً لا مُجاباً**');
    exit(2);
}

if ((string) config('amial.device_identity.hash_key') === '') {
    line('✗ AMIAL_DEVICE_HASH_KEY غيرُ مضبوط — لا يُشتقّ، فلا سباقَ بلا هويّة');
    exit(2);
}

line('══════════════════════════════════════════════════════');
line(sprintf('سباقُ مقاعد الأجهزة — عمّالٌ متوازون: %d · جولات: %d', $workers, $rounds));
line('══════════════════════════════════════════════════════');

$failures = 0;
$createdMerchants = [];

foreach (range(1, $rounds) as $round) {
    // ── تاجرٌ **مثبَّت** (لا معاملةَ مفتوحة) ومقعدٌ واحدٌ وحده ──────────
    $merchant = User::factory()->create([
        'type' => 3, 'role' => A::ROLE_MERCHANT, 'zone_code' => 'SOUTH',
    ]);

    MerchantProfile::create([
        'user_id' => $merchant->id,
        'verification_status' => 'verified',
        'business_type' => A::BIZ_RETAIL,
        'subscription_plan' => A::PLAN_FREE,
    ]);

    $createdMerchants[] = $merchant->id;
    $merchantId = (int) $merchant->id;
    $max = (int) (A::PLAN_LIMITS[A::PLAN_FREE]['pos_devices'] ?? 1);

    // **الأبُ يُغلق اتّصالَه قبل الفرع** — فلا يرث ابنٌ مقبساً يُغلقه
    // عند خروجه فيقتل الأب.
    DB::disconnect();

    $barrier = microtime(true) + 1.5;
    $pids = [];

    for ($w = 0; $w < $workers; $w++) {
        $pid = pcntl_fork();

        if ($pid === -1) {
            line('✗ تعذّر الفرع');
            exit(2);
        }

        if ($pid === 0) {
            $ok = 0;

            try {
                DB::reconnect();

                $now = microtime(true);

                if ($barrier > $now) {
                    usleep((int) (($barrier - $now) * 1_000_000));
                }

                $result = app(PosDeviceRegistrar::class)->register(
                    User::findOrFail($merchantId),
                    'race-device-'.$round.'-'.$w,
                );

                $ok = $result['result'] === PosDeviceRegistrar::RESULT_REGISTERED ? 1 : 0;
            } catch (\Throwable $e) {
                // **العطلُ يُقال ولا يُبتلع** — تصادمٌ مُلتقَطٌ صامتاً
                // يجعل السباقَ يبدو نظيفاً وهو ينهار.
                fwrite(STDERR, sprintf("  worker %d: %s\n", $w,
                    mb_substr($e->getMessage(), 0, 110)));
                $ok = 0;
            }

            exit($ok);
        }

        $pids[] = $pid;
    }

    $succeeded = 0;

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
        $succeeded += pcntl_wexitstatus($status);
    }

    DB::reconnect();

    $seats = PosDevice::activeSeats($merchantId);

    $ok = $seats === $max && $succeeded === $max;
    $failures += $ok ? 0 : 1;

    line(sprintf('%s جولة %d: نجح %d من %d طلباً · المقاعد %d (الحدّ %d)',
        $ok ? '  ✓' : '  ✗', $round, $succeeded, $workers, $seats, $max));
}

// ── تنظيفٌ: بياناتُ السباق مثبَّتةٌ فلا يمحوها تراجعُ معاملة ──────────
foreach ($createdMerchants as $id) {
    PosDevice::where('merchant_user_id', $id)->delete();
    MerchantProfile::where('user_id', $id)->delete();
    User::where('id', $id)->forceDelete();
}

line('══════════════════════════════════════════════════════');

if ($failures > 0) {
    line(sprintf('✗ %d جولةٍ تجاوزت الحدَّ — **الفحصُ يقع خارج القفل**', $failures));
    exit(1);
}

line('✓ لا جولةَ تجاوزت الحدَّ — القفلُ يحسم السباق');
exit(0);
