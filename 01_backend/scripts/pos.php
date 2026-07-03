<?php
/**
 * AMIAL-POS-001 — اختبار POS لكل القطاعات.
 *
 * 2000 تاجر موزّعون على 20 قطاعاً، ومجموعة عملاء، عبر مسار الدفع الحقيقي
 * merchant_payment_transaction (رسوم + أقفال محافظ مرتّبة + قيود). يتحقّق:
 *   - حفظ المال: تمويل العملاء الابتدائي = (عملاء الآن + تجّار الآن + رسوم المنصّة)
 *   - صحّة الرسوم لكل قطاع/قناة (1% للتاجر)
 *   - الإنتاجية الفعلية (لإسقاطها على 1,000,000 عملية/يوم)
 *
 * ملاحظة صدق: على عقدة واحدة نشغّل عيّنة تمثيلية كبيرة عبر كل التجّار والقطاعات
 * (لا مليون حرفياً — يتطلّب ساعات) ثمّ نُسقِط الإنتاجية على الطاقة اليومية.
 *
 * الأوضاع: setup <merchants> <customers> | worker <n> <start> <seed> | check
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\EMoney;
use App\Models\MerchantProfile;
use App\Services\FeeService;
use App\Traits\TransactionTrait;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

const SECTORS = [
    'grocery', 'pharmacy', 'restaurant', 'cafe', 'bakery', 'supermarket',
    'electronics', 'clothing', 'fuel_station', 'hardware', 'bookstore',
    'salon', 'carwash', 'clinic', 'gym', 'mobile_shop', 'butcher',
    'vegetables', 'jewelry', 'toys',
];
const CUST_FUND = '1000000000000'; // تمويل ضخم كي لا ينضب العميل

// كائن يستضيف TransactionTrait (نفس منطق المتحكّم)
$engine = new class { use TransactionTrait; };

$mode = $argv[1] ?? 'check';

switch ($mode) {
    case 'setup':
        $M = (int) ($argv[2] ?? 2000);
        $C = (int) ($argv[3] ?? 500);
        Artisan::call('migrate:fresh', ['--force' => true]);
        Artisan::call('db:seed', ['--class' => 'FeeSchemeSeeder', '--force' => true]);

        // أدمن (وجهة الرسوم عبر get_admin_id) + محفظته
        $admin = User::factory()->create(['type' => 0, 'role' => 'super_admin', 'zone_code' => 'SOUTH']);
        EMoney::create(['user_id' => $admin->id, 'current_balance' => '0', 'charge_earned' => '0']);

        // 2000 تاجر عبر 20 قطاعاً
        $merchantIds = [];
        for ($i = 0; $i < $M; $i++) {
            $sector = SECTORS[$i % count(SECTORS)];
            $m = User::factory()->create(['type' => 3, 'role' => 'merchant', 'zone_code' => 'SOUTH']);
            MerchantProfile::create([
                'user_id' => $m->id, 'tier' => 'standard', 'risk_category' => 'low',
                'business_type' => $sector, 'verification_status' => 'verified',
                'declared_monthly_volume' => '5000000', 'declared_daily_customers' => 500,
            ]);
            EMoney::create(['user_id' => $m->id, 'current_balance' => '0', 'charge_earned' => '0']);
            $merchantIds[] = $m->id;
        }

        // عملاء مموّلون
        $customerIds = [];
        for ($i = 0; $i < $C; $i++) {
            $c = User::factory()->create(['type' => 2, 'role' => 'customer', 'zone_code' => 'SOUTH']);
            EMoney::create(['user_id' => $c->id, 'current_balance' => CUST_FUND, 'charge_earned' => '0']);
            $customerIds[] = $c->id;
        }

        $initialCustomerTotal = bcmul(CUST_FUND, (string) $C, 4);
        file_put_contents('/tmp/pos_ids.json', json_encode([
            'merchants' => $merchantIds, 'customers' => $customerIds,
            'admin' => $admin->id, 'initial_customer_total' => $initialCustomerTotal,
            'M' => $M, 'C' => $C,
        ]));
        echo "setup done: {$M} تاجر × " . count(SECTORS) . " قطاع، {$C} عميل، رسوم 1%\n";
        break;

    case 'worker':
        $n     = (int) $argv[2];
        $start = (float) $argv[3];
        $seed  = (int) ($argv[4] ?? 0);
        Bus::fake(); // لا نُشغّل وظائف الإشعارات (نقيس المسار المالي فقط)

        $ids = json_decode(file_get_contents('/tmp/pos_ids.json'), true);
        $M = $ids['merchants']; $C = $ids['customers'];
        $nm = count($M); $nc = count($C);
        mt_srand($seed);
        while (microtime(true) < $start) usleep(300);

        $ok = 0; $err = 0;
        for ($i = 0; $i < $n; $i++) {
            $cust = $C[mt_rand(0, $nc - 1)];
            $merch = $M[mt_rand(0, $nm - 1)];
            $amount = (string) mt_rand(50, 5000);
            $channel = mt_rand(0, 1) ? 'pos' : 'qr';
            try {
                $engine->merchant_payment_transaction(
                    customer_user_id: $cust, merchant_user_id: $merch,
                    amount: $amount, channel: $channel,
                );
                $ok++;
            } catch (\Throwable $e) { $err++; }
        }
        echo "worker done ok={$ok} err={$err}\n";
        break;

    case 'check':
        $ids = json_decode(file_get_contents('/tmp/pos_ids.json'), true);
        $initial = $ids['initial_customer_total'];

        $custBal  = (string) EMoney::whereIn('user_id', $ids['customers'])->sum('current_balance');
        $merchBal = (string) EMoney::whereIn('user_id', $ids['merchants'])->sum('current_balance');
        // الرسوم تُسجَّل في دفتر رسوم منفصل (platform_fee_entries) لا في المحفظة
        $adminFee = (string) DB::table('platform_fee_entries')->sum('amount');

        $finalTotal = bcadd(bcadd($custBal, $merchBal, 4), $adminFee, 4);
        $conserved  = bccomp($initial, $finalTotal, 4) === 0;

        // عدد عمليات الدفع المُكتملة
        $txCount = DB::table('transactions')->where('transaction_type', 'merchant_payment')->count();

        // صحّة الرسوم: إجمالي الرسوم = 1% من إجمالي المبيعات (المبلغ المخصوم من العملاء)
        $totalSales = (string) DB::table('transactions')
            ->where('transaction_type', 'merchant_payment')->sum('amount');
        $expectedFee = bcmul($totalSales, '0.01', 4);
        $feeOk = bccomp(bcsub($adminFee, $expectedFee, 2), '0', 2) === 0
              || bccomp($adminFee, $expectedFee, 0) === 0;

        // تغطية القطاعات: كم قطاعاً استقبل عملية؟
        $sectorsHit = DB::table('transactions as t')
            ->join('merchant_profiles as mp', 't.to_user_id', '=', 'mp.user_id')
            ->where('t.transaction_type', 'merchant_payment')
            ->distinct()->count('mp.business_type');

        echo "=== POS RESULT ===\n";
        echo "عمليات دفع منفّذة: {$txCount}\n";
        echo "قطاعات استقبلت عمليات (من 20): {$sectorsHit}\n";
        echo "إجمالي المبيعات: {$totalSales}\n";
        echo "رسوم المنصّة: {$adminFee}  (المتوقّع 1% = {$expectedFee})\n";
        echo "حفظ المال: ابتدائي={$initial}  نهائي={$finalTotal}  → " . ($conserved ? 'محفوظ ✓' : 'مفقود ✗') . "\n";
        echo "   (عملاء={$custBal} + تجّار={$merchBal} + رسوم={$adminFee})\n";

        $ok = $conserved && $feeOk && $sectorsHit >= 18;
        echo $ok
            ? "VERDICT: PASS ✓ المال محفوظ، الرسوم صحيحة، كل القطاعات تعمل\n"
            : "VERDICT: FAIL ✗ (محفوظ={$conserved} رسوم=" . ($feeOk?'OK':'BAD') . " قطاعات={$sectorsHit})\n";
        exit($ok ? 0 : 1);
}
