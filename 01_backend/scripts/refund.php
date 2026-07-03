<?php
/**
 * AMIAL-REFUND-001 — استرداد/إلغاء مبيعات POS تحت تزامن.
 *
 * أخطر مسار عكسي: عدّة طلبات استرداد جزئي متزامنة على *نفس* البيع. الحارس
 * (lockForUpdate على البيع + جمع refundedSoFar) يجب أن يمنع تجاوز إجمالي
 * الاسترداد لقيمة البيع (over-refund = تسريب مال)، ويحفظ المال (تاجر→عميل).
 *
 * الأوضاع: setup | worker <amount> <start> | check
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\EMoney;
use App\Models\MerchantProfile;
use App\Models\MerchantRefund;
use App\Services\CashierService;
use App\Services\MerchantSaleRefundService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;

const SALE_TOTAL   = '10000';
const MERCH_FUND   = '1000000';
const CUST_PHONE   = '+967700111222';

$mode = $argv[1] ?? 'check';

switch ($mode) {
    case 'setup':
        Artisan::call('migrate:fresh', ['--force' => true]);
        Bus::fake();

        $merchant = User::factory()->create(['type' => 3, 'role' => 'merchant', 'zone_code' => 'SOUTH']);
        MerchantProfile::create(['user_id' => $merchant->id, 'verification_status' => 'verified']);
        EMoney::create(['user_id' => $merchant->id, 'current_balance' => MERCH_FUND,
            'held_balance' => '0', 'pending_balance' => '0', 'charge_earned' => '0', 'zone_code' => 'SOUTH']);

        $customer = User::factory()->create(['type' => 2, 'role' => 'customer',
            'phone' => CUST_PHONE, 'zone_code' => 'SOUTH']);
        EMoney::create(['user_id' => $customer->id, 'current_balance' => '0',
            'held_balance' => '0', 'pending_balance' => '0', 'charge_earned' => '0', 'zone_code' => 'SOUTH']);

        $sale = app(CashierService::class)->recordSale(
            merchant: $merchant, total: SALE_TOTAL, paymentMethod: 'amial_pay',
            items: [['name' => 'جهاز', 'qty' => 1, 'price' => SALE_TOTAL]],
            customer: ['name' => 'عميل', 'phone' => CUST_PHONE],
            paidTransactionId: 'TX-REFUND-TEST',
        );

        file_put_contents('/tmp/refund_ids.json', json_encode([
            'merchant' => $merchant->id, 'customer' => $customer->id,
            'sale' => $sale->sale_ulid,
            'merch_init' => MERCH_FUND, 'cust_init' => '0',
        ]));
        echo "setup done: بيع={$sale->sale_ulid} total=" . SALE_TOTAL . " تاجر مموّل=" . MERCH_FUND . "\n";
        break;

    case 'worker':
        $amount = (string) $argv[2];
        $start  = (float) $argv[3];
        Bus::fake();
        $ids = json_decode(file_get_contents('/tmp/refund_ids.json'), true);
        $merchant = User::find($ids['merchant']);
        while (microtime(true) < $start) usleep(300);
        try {
            $r = app(MerchantSaleRefundService::class)->refund(
                merchant: $merchant, originalSaleUlid: $ids['sale'],
                refundAmount: $amount, refundMethod: 'wallet', reason: 'اختبار',
            );
            echo "OK {$amount} status={$r->status}\n";
        } catch (\Throwable $e) {
            echo "REJ {$amount} " . mb_substr($e->getMessage(), 0, 60) . "\n";
        }
        break;

    case 'check':
        $ids = json_decode(file_get_contents('/tmp/refund_ids.json'), true);
        $totalRefunded = (string) MerchantRefund::where('status', '!=', 'rejected')->sum('refund_amount');
        $merchNow = (string) EMoney::where('user_id', $ids['merchant'])->value('current_balance');
        $custNow  = (string) EMoney::where('user_id', $ids['customer'])->value('current_balance');

        $merchDelta = bcsub($ids['merch_init'], $merchNow, 4);   // كم خُصم من التاجر
        $custDelta  = bcsub($custNow, $ids['cust_init'], 4);     // كم أُضيف للعميل

        $noOverRefund = bccomp($totalRefunded, SALE_TOTAL, 4) <= 0;
        $movesMatch   = bccomp($merchDelta, $custDelta, 4) === 0
                     && bccomp($merchDelta, $totalRefunded, 4) === 0;
        $conserved    = bccomp(
            bcadd($merchNow, $custNow, 4),
            bcadd($ids['merch_init'], $ids['cust_init'], 4), 4
        ) === 0;

        echo "=== REFUND RESULT ===\n";
        echo "قيمة البيع: " . SALE_TOTAL . "\n";
        echo "إجمالي المُسترَد (غير المرفوض): {$totalRefunded}\n";
        echo "خُصم من التاجر: {$merchDelta} | أُضيف للعميل: {$custDelta}\n";
        echo "لا تجاوز لقيمة البيع: " . ($noOverRefund ? '✓' : "✗ ({$totalRefunded} > " . SALE_TOTAL . ')') . "\n";
        echo "حركة التاجر = حركة العميل = المُسترَد: " . ($movesMatch ? '✓' : '✗') . "\n";
        echo "حفظ المال (تاجر+عميل ثابت): " . ($conserved ? '✓' : '✗') . "\n";

        $ok = $noOverRefund && $movesMatch && $conserved;
        echo $ok
            ? "VERDICT: PASS ✓ لا استرداد زائد، المال محفوظ تحت التزامن\n"
            : "VERDICT: FAIL ✗\n";
        exit($ok ? 0 : 1);
}
