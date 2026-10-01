<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\Amial\PaymentRequestController;
use App\Models\EMoney;
use App\Models\MerchantProfile;
use App\Models\MerchantSale;
use App\Models\PaymentRequest;
use App\Models\PosUser;
use App\Models\User;
use App\Services\Merchant\MerchantPermissionService;
use App\Services\PaymentRequestService;
use App\Services\Vertical\VerticalBootstrapService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * AMIAL-POS-AMIAL-PAY-GOLDEN-001
 *
 * رحلةٌ مالية واحدة تمشي من شاشة المالك إلى صندوق نقطة البيع ثم محفظة
 * المنشأة، بلا إنشاء صفوف المنتج أو طلب الدفع أو البيعة مباشرةً:
 *
 *   1) المالك ينشئ منتجاً من بوابة الويب.
 *   2) موظف POS يرى الصف نفسه من API الكاشير.
 *   3) الموظف ينشئ QR باسم محفظة المنشأة، لا محفظته.
 *   4) العميل يدفع فعلياً من محفظته.
 *   5) رصيد العميل ينقص ورصيد التاجر يزيد مرةً واحدة فقط.
 *   6) الكاشير يسجل البيع بمرجع الدفع الحقيقي.
 *   7) المرجع المدفوع لا يمكن استعماله في بيعة ثانية.
 *
 * هذا الحارس مقصود أن يثبت "الوصلات" بين الأنظمة، لا كل نظام منفرداً.
 */
class MerchantProductPosAmialPayGoldenJourneyTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;
    private User $staff;
    private PosUser $pos;

    protected function setUp(): void
    {
        parent::setUp();

        // ربط جلسة Passport بالجهاز له حارسه الشامل المستقل. هذا الاختبار
        // يقيس من المنتج إلى المال، فلا نجعل عطل جهاز يحجب حقيقة المحفظة.
        config(['amial.pos_devices.enforce_session_binding' => false]);

        $this->merchant = User::factory()->create([
            'type' => MERCHANT_TYPE,
            'role' => A::ROLE_MERCHANT,
            'is_active' => 1,
            'zone_code' => 'SOUTH',
        ]);

        MerchantProfile::create([
            'user_id' => $this->merchant->id,
            'business_type' => A::BIZ_RETAIL,
            'subscription_plan' => A::PLAN_BUSINESS,
            'verification_status' => 'verified',
            // حدود استقبال صريحة حتى يختبر الحارس حركة المال المقصودة،
            // لا قيمة افتراضية مختلفة بين بيئات الاختبار.
            'tier' => 'small',
            'single_receive_limit' => '5000000',
            'daily_receive_limit' => '50000000',
        ]);

        EMoney::create([
            'user_id' => $this->merchant->id,
            'current_balance' => '250.0000',
            'pending_balance' => '0.0000',
            'held_balance' => '0.0000',
            'charge_earned' => '0.0000',
            'zone_code' => 'SOUTH',
        ]);

        app(VerticalBootstrapService::class)->ensureFor($this->merchant);

        $this->staff = User::factory()->create([
            'type' => 4,
            'role' => 'pos',
            'is_active' => 1,
            'zone_code' => 'SOUTH',
        ]);

        $this->pos = PosUser::create([
            'user_id' => $this->staff->id,
            'merchant_user_id' => $this->merchant->id,
            'pos_number' => 'PAY-GOLDEN-01',
            'display_name' => 'كاشير الدفع التجريبي',
            'is_active' => true,
            'permissions' => [],
        ]);

        $permissions = app(MerchantPermissionService::class);
        $cashierRole = collect($permissions->seedRetailRoles($this->merchant))
            ->first(fn ($role) => $role->code === 'cashier');

        $this->assertNotNull($cashierRole, 'لم يُزرع دور الكاشير للتاجر');
        $permissions->assign($this->merchant, $this->staff, $cashierRole);
    }

    /** @test */
    public function owner_product_reaches_pos_and_real_amial_payment_reaches_merchant_wallet_once(): void
    {
        // ① المالك ينشئ المنتج من بوابته الحقيقية.
        $this->actingAs($this->merchant, 'merchant_web');

        $created = $this->postJson('/merchant/data/sector/products', [
            'name' => 'قهوة رحلة أميال',
            'price' => '750',
            'cost_price' => '500',
            'quantity' => 10,
            'barcode' => '6291000999001',
        ])->assertOk();

        $productId = (int) $created->json('meta.result.product.id');
        $this->assertGreaterThan(0, $productId, 'بوابة التاجر لم تُرجع المنتج المنشأ');

        // ② الموظف يفتح ورديته ثم يرى المنتج نفسه، لا نسخةً في جدول آخر.
        Passport::actingAs($this->staff, [], 'api');

        $shift = $this->postJson('/api/v1/amial/cashier/shift/open', [
            'opening_float' => '0',
        ]);
        $this->assertContains($shift->status(), [200, 201], json_encode(
            $shift->json(), JSON_UNESCAPED_UNICODE
        ));

        $products = $this->getJson('/api/v1/amial/merchant/cashier/products')
            ->assertOk()
            ->json('meta.products');

        $row = collect($products)->firstWhere('id', $productId);
        $this->assertNotNull($row,
            'المنتج الذي أنشأه المالك لم يصل إلى قائمة كاشير POS');
        $this->assertSame('قهوة رحلة أميال', $row['name'] ?? null);
        $this->assertSame('750.0000', (string) ($row['price'] ?? ''));

        // ③ شاشة QR في POS تنادي PaymentRequestController. ننادي المتحكم
        // نفسه بطلبٍ يحمل هوية الموظف، كي نثبت commerceRequester(): الطلب
        // المالي يجب أن يُنشأ باسم التاجر المالك لا باسم حساب الموظف.
        $qrHttp = Request::create('/api/v1/amial/payment-requests', 'POST', [
            'amount' => '1500',
            'note' => 'دفع مشتريات — قهوة رحلة أميال',
            'share_method' => 'qr',
        ]);
        $qrHttp->setUserResolver(fn () => $this->staff);

        $qrResponse = app(PaymentRequestController::class)->create($qrHttp);
        $this->assertSame(201, $qrResponse->getStatusCode());

        $qrBody = $qrResponse->getData(true);
        $requestId = (int) ($qrBody['meta']['request']['id'] ?? 0);
        $this->assertGreaterThan(0, $requestId, 'POS لم يُنشئ طلب دفع QR');

        $paymentRequest = PaymentRequest::findOrFail($requestId);
        $this->assertSame($this->merchant->id, (int) $paymentRequest->requester_user_id,
            'QR نقطة البيع وُجّه إلى محفظة الموظف بدل محفظة المنشأة');
        $this->assertSame('1500.0000', (string) $paymentRequest->amount);
        $this->assertSame(PaymentRequest::SHARE_QR, $paymentRequest->share_method);

        // ④ عميل حقيقي مالياً يدفع من محفظته إلى محفظة التاجر.
        $customer = User::factory()->create([
            'type' => CUSTOMER_TYPE,
            'role' => 'customer',
            'is_active' => 1,
            'zone_code' => 'SOUTH',
        ]);

        EMoney::create([
            'user_id' => $customer->id,
            'current_balance' => '5000.0000',
            'pending_balance' => '0.0000',
            'held_balance' => '0.0000',
            'charge_earned' => '0.0000',
            'zone_code' => 'SOUTH',
        ]);

        $merchantBefore = (string) EMoney::where('user_id', $this->merchant->id)
            ->value('current_balance');
        $customerBefore = (string) EMoney::where('user_id', $customer->id)
            ->value('current_balance');

        $paid = app(PaymentRequestService::class)->pay($customer, $paymentRequest);
        $paidTxId = (string) ($paid['transaction_id'] ?? '');

        $this->assertNotSame('', $paidTxId, 'دفع العميل لم يُنتج مرجع حركة');
        $this->assertSame('paid', $paymentRequest->fresh()->status);
        $this->assertSame($paidTxId, (string) $paymentRequest->fresh()->paid_transaction_id);

        $merchantAfterPayment = (string) EMoney::where('user_id', $this->merchant->id)
            ->value('current_balance');
        $customerAfterPayment = (string) EMoney::where('user_id', $customer->id)
            ->value('current_balance');

        $this->assertSame(
            bcadd($merchantBefore, '1500.0000', 4),
            $merchantAfterPayment,
            'المبلغ لم يصل كاملاً إلى محفظة التاجر'
        );
        $this->assertSame(
            bcsub($customerBefore, '1500.0000', 4),
            $customerAfterPayment,
            'محفظة العميل لم تُخصم بالمبلغ المدفوع'
        );

        // ⑤ بعد أن يرى POS أن QR صار paid، يسجل البيع بمرجع الحركة نفسه.
        Passport::actingAs($this->staff, [], 'api');

        $saleResponse = $this->postJson('/api/v1/amial/merchant/cashier/sales', [
            'total' => '1500',
            'payment_method' => 'amial_pay',
            'paid_transaction_id' => $paidTxId,
            'items' => [[
                'product_id' => $productId,
                'name' => 'قهوة رحلة أميال',
                'qty' => 2,
                'price' => '750',
            ]],
        ])->assertOk()
          ->assertJsonPath('code', 'SALE_RECORDED');

        $saleUlid = (string) $saleResponse->json('meta.sale.sale_ulid');
        $sale = MerchantSale::where('sale_ulid', $saleUlid)->firstOrFail();

        $this->assertSame($this->merchant->id, (int) $sale->merchant_user_id);
        $this->assertSame($this->pos->id, (int) $sale->pos_user_id);
        $this->assertSame('amial_pay', $sale->payment_method);
        $this->assertSame('completed', $sale->status);
        $this->assertSame('1500.0000', (string) $sale->total_amount);
        $this->assertSame($paidTxId, (string) $sale->paid_transaction_id);
        $this->assertNotNull($sale->shift_id,
            'بيع أميال باي لم يُربط بالوردية المفتوحة');

        // والبيع ليس رقماً فقط: الكميّة التي أنشأها المالك (10) يجب أن
        // تنقص بصنف الكاشير نفسه بعد بيع وحدتين.
        $this->assertSame(
            '8.000',
            (string) \App\Models\MerchantProduct::whereKey($productId)->value('quantity'),
            'البيعة سُجّلت لكن مخزون المنتج المشترك بين الويب وPOS لم ينقص'
        );

        // تسجيل الفاتورة لا يحرّك المال ثانية؛ الدفع هو الذي حرّكه فعلاً.
        $this->assertSame(
            $merchantAfterPayment,
            (string) EMoney::where('user_id', $this->merchant->id)->value('current_balance'),
            'تسجيل البيع أضاف المبلغ إلى محفظة التاجر مرة ثانية'
        );

        // ⑥ المرتجع الحقيقي لنفس بيع QR: الفاتورة لم تحمل هاتف العميل
        // عمداً، كما يفعل التطبيق. يجب مع ذلك أن يعرف الخادم الدافع من
        // PaymentRequest.paid_by_user_id ويعرض «إلى محفظة العميل».
        Passport::actingAs($this->merchant, [], 'api');

        $refundable = $this->getJson(
            '/api/v1/amial/merchant/cashier/sales/'.$saleUlid.'/refundable'
        )->assertOk();

        $this->assertContains(
            'wallet',
            (array) $refundable->json('meta.available_methods'),
            'بيع أميال الحقيقي لا يعرض الاسترداد إلى محفظة الدافع'
        );

        $refund = $this->postJson(
            '/api/v1/amial/merchant/cashier/sales/'.$saleUlid.'/refund',
            [
                'amount' => '1500',
                'refund_method' => 'wallet',
                'reason' => 'مرتجع كامل لاختبار السلسلة',
            ]
        )->assertStatus(201)
         ->assertJsonPath('code', 'REFUNDED');

        $this->assertSame(
            $customer->id,
            (int) $refund->json('meta.refund.customer_user_id'),
            'المرتجع لم يتعرّف على العميل الذي دفع QR فعلياً'
        );
        $this->assertSame(
            $merchantBefore,
            (string) EMoney::where('user_id', $this->merchant->id)->value('current_balance'),
            'المرتجع لم يخصم المبلغ من محفظة التاجر'
        );
        $this->assertSame(
            $customerBefore,
            (string) EMoney::where('user_id', $customer->id)->value('current_balance'),
            'المرتجع لم يُعد المبلغ إلى محفظة العميل'
        );

        // ⑦ المرجع المالي أحادي الاستعمال: فاتورة ثانية بنفس الدفع تُرفض.
        // بعد فحص المرتجع كمالك نعيد هوية الكاشير؛ وإلا يفشل الطلب قبل
        // مرجع الدفع عند بوابة الوردية، وهو ليس ما يقيسه هذا الجزء.
        Passport::actingAs($this->staff, [], 'api');

        $second = $this->postJson('/api/v1/amial/merchant/cashier/sales', [
            'total' => '1500',
            'payment_method' => 'amial_pay',
            'paid_transaction_id' => $paidTxId,
            'items' => [[
                'product_id' => $productId,
                'name' => 'قهوة رحلة أميال',
                'qty' => 2,
                'price' => '750',
            ]],
        ]);

        $second->assertStatus(422)
            ->assertJsonPath('code', 'SALE_FAILED');

        $this->assertStringContainsString(
            'تم استخدام رمز الدفع هذا',
            (string) $second->json('message')
        );

        $this->assertSame(
            $merchantBefore,
            (string) EMoney::where('user_id', $this->merchant->id)->value('current_balance'),
            'إعادة استخدام مرجع الدفع غيّرت رصيد التاجر بعد الاسترداد'
        );

        $this->assertSame(1, MerchantSale::where('paid_transaction_id', $paidTxId)->count(),
            'مرجع دفع واحد أنشأ أكثر من بيعة');
    }

    /** @test */
    public function the_same_web_product_can_be_sold_for_cash_or_credit_without_touching_the_merchant_wallet(): void
    {
        // منتجٌ واحد يخرجه المالك من الويب ثم يبيعه POS بالطريقتين.
        $this->actingAs($this->merchant, 'merchant_web');
        $created = $this->postJson('/merchant/data/sector/products', [
            'name' => 'ماء اختبار النقد والآجل',
            'price' => '500',
            'cost_price' => '300',
            'quantity' => 10,
            'barcode' => '6291000999002',
        ])->assertOk();

        $productId = (int) $created->json('meta.result.product.id');
        $this->assertGreaterThan(0, $productId);

        Passport::actingAs($this->staff, [], 'api');
        $shift = $this->postJson('/api/v1/amial/cashier/shift/open', [
            'opening_float' => '1000',
        ]);
        $this->assertContains($shift->status(), [200, 201], json_encode(
            $shift->json(), JSON_UNESCAPED_UNICODE
        ));

        $walletBefore = (string) EMoney::where('user_id', $this->merchant->id)
            ->value('current_balance');

        // ① نقد: الفاتورة مكتملة والمخزون ينقص، لكن محفظة أميال لا تتحرك.
        $cash = $this->postJson('/api/v1/amial/merchant/cashier/sales', [
            'total' => '500',
            'payment_method' => 'cash',
            'amount_received' => '500',
            'items' => [[
                'product_id' => $productId,
                'name' => 'ماء اختبار النقد والآجل',
                'qty' => 1,
                'price' => '500',
            ]],
        ])->assertOk()->assertJsonPath('code', 'SALE_RECORDED');

        $cashSale = MerchantSale::where(
            'sale_ulid', (string) $cash->json('meta.sale.sale_ulid')
        )->firstOrFail();

        $this->assertSame('cash', $cashSale->payment_method);
        $this->assertSame('completed', $cashSale->status);
        $this->assertNotNull($cashSale->shift_id);
        $this->assertSame(
            $walletBefore,
            (string) EMoney::where('user_id', $this->merchant->id)->value('current_balance'),
            'البيع النقدي حرّك محفظة أميال للتاجر'
        );
        $this->assertSame(
            '9.000',
            (string) \App\Models\MerchantProduct::whereKey($productId)->value('quantity')
        );

        $xBeforeRefund = $this->getJson('/api/v1/amial/cashier/shift/x')
            ->assertOk();
        $this->assertSame('1500.0000',
            (string) $xBeforeRefund->json('meta.report.expected_cash'));

        // مرتجع نقدي من الكاشير نفسه: لا يلمس المحفظة، لكنه يخرج من
        // الدرج ويجب أن يهبط «المتوقع» في تقرير X فوراً.
        $this->postJson(
            '/api/v1/amial/merchant/cashier/sales/'.$cashSale->sale_ulid.'/refund',
            [
                'amount' => '500',
                'refund_method' => 'cash',
                'reason' => 'مرتجع نقدي تجريبي',
            ]
        )->assertStatus(201)
         ->assertJsonPath('code', 'REFUNDED');

        $xAfterRefund = $this->getJson('/api/v1/amial/cashier/shift/x')
            ->assertOk();
        $this->assertSame('500.0000',
            (string) $xAfterRefund->json('meta.report.cash_sales'));
        $this->assertSame('500.0000',
            (string) $xAfterRefund->json('meta.report.cash_movements_out'));
        $this->assertSame('-500.0000',
            (string) $xAfterRefund->json('meta.report.cash_movements_net'));
        $this->assertSame('1000.0000',
            (string) $xAfterRefund->json('meta.report.expected_cash'));
        $this->assertSame(
            $walletBefore,
            (string) EMoney::where('user_id', $this->merchant->id)->value('current_balance'),
            'المرتجع النقدي حرّك محفظة أميال بدل درج الوردية'
        );

        // ② آجل: لا مال إلكتروني يتحرك؛ الذي يزيد هو دفتر دين العميل.
        $customer = User::factory()->create([
            'type' => CUSTOMER_TYPE,
            'role' => 'customer',
            'phone' => '+967700444555',
            'is_active' => 1,
            'zone_code' => 'SOUTH',
        ]);

        $credit = $this->postJson('/api/v1/amial/merchant/cashier/sales', [
            'total' => '1000',
            'payment_method' => 'credit',
            'credit_due_date' => '2026-12-31',
            'customer' => [
                'name' => 'عميل الآجل التجريبي',
                'phone' => $customer->phone,
            ],
            'items' => [[
                'product_id' => $productId,
                'name' => 'ماء اختبار النقد والآجل',
                'qty' => 2,
                'price' => '500',
            ]],
        ])->assertOk()->assertJsonPath('code', 'SALE_RECORDED');

        $creditSale = MerchantSale::where(
            'sale_ulid', (string) $credit->json('meta.sale.sale_ulid')
        )->firstOrFail();

        $this->assertSame('credit', $creditSale->payment_method);
        $this->assertSame('credit_unpaid', $creditSale->status);
        $this->assertNotNull($creditSale->shift_id);
        $this->assertSame(
            $walletBefore,
            (string) EMoney::where('user_id', $this->merchant->id)->value('current_balance'),
            'البيع الآجل حرّك محفظة التاجر قبل التحصيل'
        );
        $this->assertSame(
            '7.000',
            (string) \App\Models\MerchantProduct::whereKey($productId)->value('quantity')
        );

        $account = \App\Models\CustomerCreditAccount::where(
            'merchant_user_id', $this->merchant->id
        )->whereIn(
            'customer_phone', \App\Support\Phone::variants((string) $customer->phone)
        )->firstOrFail();

        $this->assertSame($customer->id, (int) $account->customer_user_id);
        $this->assertSame('1000.0000', (string) $account->current_balance);

        $movement = $account->movements()->where('type', 'sale')->latest('id')->firstOrFail();
        $this->assertSame('1000.0000', (string) $movement->amount);
        $this->assertSame(
            '2026-12-31',
            \Illuminate\Support\Carbon::parse($movement->due_date)->format('Y-m-d')
        );

        // ③ مرتجع بيع آجل غير مسدد: لا نخلق مالاً ولا نخصم محفظة؛ نزيل
        // الالتزام من دفتر العميل نفسه.
        Passport::actingAs($this->merchant, [], 'api');
        $this->postJson(
            '/api/v1/amial/merchant/cashier/sales/'.$creditSale->sale_ulid.'/refund',
            [
                'amount' => '1000',
                'refund_method' => 'credit_account',
                'reason' => 'مرتجع بيع آجل تجريبي',
            ]
        )->assertStatus(201)
         ->assertJsonPath('code', 'REFUNDED');

        $this->assertSame('0.0000', (string) $account->fresh()->current_balance);
        $this->assertSame(
            $walletBefore,
            (string) EMoney::where('user_id', $this->merchant->id)->value('current_balance'),
            'مرتجع الآجل حرّك محفظة رغم أن البيع لم يُحصّل إلكترونياً'
        );
    }
}
