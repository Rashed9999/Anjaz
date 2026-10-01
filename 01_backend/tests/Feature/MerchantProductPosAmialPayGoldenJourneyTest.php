<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePosDevice;
use App\Models\EMoney;
use App\Models\Merchant;
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
use Illuminate\Support\Facades\Hash;
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
    private string $deviceUuid;
    private string $staffToken;

    private const STAFF_PASSWORD = 'GoldenPOS@2026';
    private const MERCHANT_NUMBER = 'M-PAY-GOLDEN-01';

    protected function setUp(): void
    {
        parent::setUp();

        // الاختبار المالي نفسه يمرّ الآن عبر Access Token حقيقي مربوط
        // بجهاز POS مفعّل. لا استثناءً أمنياً داخل الرحلة الذهبية.
        \Laravel\Passport\Client::unguarded(function () {
            $client = \Laravel\Passport\Client::create([
                'name' => 'amial-golden-pos-personal',
                'secret' => \Illuminate\Support\Str::random(40),
                'redirect' => 'http://localhost',
                'personal_access_client' => true,
                'password_client' => false,
                'revoked' => false,
            ]);

            \Laravel\Passport\PersonalAccessClient::unguarded(
                fn () => \Laravel\Passport\PersonalAccessClient::create(['client_id' => $client->id])
            );
        });

        $this->merchant = User::factory()->create([
            'type' => MERCHANT_TYPE,
            'role' => A::ROLE_MERCHANT,
            'is_active' => 1,
            'zone_code' => 'SOUTH',
            'phone' => '967700880011',
            'password' => Hash::make('OwnerGolden@2026'),
        ]);

        $merchantRow = new Merchant();
        $merchantRow->user_id = $this->merchant->id;
        $merchantRow->merchant_number = self::MERCHANT_NUMBER;
        $merchantRow->store_name = 'متجر الرحلة الذهبية';
        $merchantRow->save();

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
            'password' => Hash::make(self::STAFF_PASSWORD),
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

        [$this->deviceUuid, $this->staffToken] = $this->activateDeviceAndLoginStaff();
    }

    /** @return array{0:string,1:string} */
    private function activateDeviceAndLoginStaff(): array
    {
        Passport::actingAs($this->merchant);

        $activation = $this->postJson(
            '/api/v1/amial/merchant/pos-devices/activation-codes',
            ['display_name' => 'جهاز الرحلة الذهبية']
        )->assertOk();

        $code = null;
        $activationBody = (array) $activation->json();
        array_walk_recursive($activationBody, function ($value) use (&$code) {
            if ($code === null && is_string($value) && preg_match('/^\d{8}$/', $value)) {
                $code = $value;
            }
        });

        $this->assertNotNull($code, 'إنشاء جهاز POS لم يُرجع رمز تفعيل من 8 أرقام');

        $uuid = 'golden-pos-' . \Illuminate\Support\Str::random(12);

        $this->postJson('/api/v1/amial/pos-devices/activate', [
            'activation_code' => $code,
            'device_uuid' => $uuid,
            'platform' => 'android',
        ])->assertOk();

        $login = $this->withHeader(EnsurePosDevice::HEADER, $uuid)
            ->postJson('/api/v1/auth/login', [
                'role' => 'merchant',
                'employee_code' => 'PAY-GOLDEN-01',
                'password' => self::STAFF_PASSWORD,
            ])->assertOk();

        $token = null;
        $loginBody = (array) $login->json();
        array_walk_recursive($loginBody, function ($value, $key) use (&$token) {
            if ($token === null
                && in_array($key, ['access_token', 'token'], true)
                && is_string($value)
                && strlen($value) > 40) {
                $token = $value;
            }
        });

        $this->assertNotNull($token, 'دخول موظف POS نجح بلا access token قابل للاستخدام');

        return [$uuid, $token];
    }

    /** @return array<string,string> */
    private function posHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->staffToken,
            EnsurePosDevice::HEADER => $this->deviceUuid,
        ];
    }

    private function refundThroughPosWithOwnerApproval(
        string $saleUlid,
        array $payload,
        string $idempotencyBase,
    ): \Illuminate\Testing\TestResponse {
        $first = $this->withHeaders(array_merge($this->posHeaders(), [
            'Idempotency-Key' => $idempotencyBase . '-request',
        ]))->postJson(
            '/api/v1/amial/merchant/cashier/sales/' . $saleUlid . '/refund',
            $payload,
        );

        if ($first->status() === 202 && $first->json('code') === 'APPROVAL_PENDING') {
            $approvalId = (int) $first->json('meta.approval.request_id');
            $this->assertGreaterThan(0, $approvalId, 'طلب اعتماد المرتجع لم يحمل معرّفاً صالحاً');

            $this->actingAs($this->merchant, 'merchant_web')
                ->postJson('/merchant/data/approvals/' . $approvalId . '/grant', [
                    'note' => 'اعتماد الرحلة الذهبية',
                ])->assertOk()
                  ->assertJsonPath('code', 'APPROVAL_GRANTED');

            return $this->withHeaders(array_merge($this->posHeaders(), [
                'Idempotency-Key' => $idempotencyBase . '-approved',
            ]))->postJson(
                '/api/v1/amial/merchant/cashier/sales/' . $saleUlid . '/refund',
                $payload,
            )->assertStatus(201)
              ->assertJsonPath('code', 'REFUNDED');
        }

        return $first->assertStatus(201)
            ->assertJsonPath('code', 'REFUNDED');
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

        // ② الموظف يفتح ورديته من الجلسة الحقيقية المربوطة بالجهاز ثم يرى
        // المنتج نفسه، لا نسخةً في جدول آخر.
        $shift = $this->withHeaders($this->posHeaders())
            ->postJson('/api/v1/amial/cashier/shift/open', [
            'opening_float' => '0',
        ]);
        $this->assertContains($shift->status(), [200, 201], json_encode(
            $shift->json(), JSON_UNESCAPED_UNICODE
        ));

        $products = $this->withHeaders($this->posHeaders())
            ->getJson('/api/v1/amial/merchant/cashier/products')
            ->assertOk()
            ->json('meta.products');

        $row = collect($products)->firstWhere('id', $productId);
        $this->assertNotNull($row,
            'المنتج الذي أنشأه المالك لم يصل إلى قائمة كاشير POS');
        $this->assertSame('قهوة رحلة أميال', $row['name'] ?? null);
        $this->assertSame('750.0000', (string) ($row['price'] ?? ''));

        // ③ QR يخرج من مسار HTTP نفسه الذي يستخدمه تطبيق POS، وبنفس
        // Access Token وترويسة الجهاز. يجب أن يُسجّل الطلب باسم التاجر
        // المالك، لا باسم حساب الموظف.
        $qrResponse = $this->withHeaders($this->posHeaders())
            ->postJson('/api/v1/amial/payment-requests', [
                'amount' => '1500',
                'note' => 'دفع مشتريات — قهوة رحلة أميال',
                'share_method' => 'qr',
            ])->assertStatus(201);

        $requestId = (int) $qrResponse->json('meta.request.id');
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

        // ⑤ بعد أن يرى POS أن QR صار paid، يسجل البيع بمرجع الحركة نفسه
        // من الجهاز المفعّل نفسه.
        $saleResponse = $this->withHeaders($this->posHeaders())
            ->postJson('/api/v1/amial/merchant/cashier/sales', [
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

        // المالك لا يكفيه أن يرى زيادة الرصيد: يجب أن يرى البيعة نفسها
        // ومرجع التحصيل الذي حرّك محفظته حتى يستطيع المطابقة والدعم.
        $ownerDetail = $this->actingAs($this->merchant, 'merchant_web')
            ->withHeader('Authorization', '')
            ->getJson('/merchant/data/sector/sales/'.$saleUlid)
            ->assertOk();

        $this->assertSame(
            $paidTxId,
            (string) $ownerDetail->json('meta.result.sale.paid_transaction_id'),
            'المال وصل للمحفظة لكن مرجع دفع أميال لم يظهر في تفاصيل بيع المالك'
        );
        $this->assertSame(
            'amial_pay',
            (string) $ownerDetail->json('meta.result.sale.payment_method'),
            'تفاصيل المالك فقدت طريقة الدفع الأصلية لبيع POS'
        );

        // ⑥ المرتجع الحقيقي لنفس بيع QR: الكاشير يطلبه من جهاز POS،
        // وإذا احتاج اعتماداً يمنحه المالك من الويب ثم يعيد الكاشير
        // العملية. الفاتورة لا تحمل هاتف العميل عمداً؛ الخادم يستخرج
        // الدافع من PaymentRequest.paid_by_user_id.
        $refundable = $this->withHeaders($this->posHeaders())->getJson(
            '/api/v1/amial/merchant/cashier/sales/'.$saleUlid.'/refundable'
        )->assertOk();

        $this->assertContains(
            'wallet',
            (array) $refundable->json('meta.available_methods'),
            'بيع أميال الحقيقي لا يعرض الاسترداد إلى محفظة الدافع'
        );

        $saleLine = $sale->lines()->firstOrFail();

        $refund = $this->refundThroughPosWithOwnerApproval(
            $saleUlid,
            [
                'amount' => '1500',
                'refund_method' => 'wallet',
                'items' => [[
                    'sale_item_id' => $saleLine->id,
                    'quantity' => 2,
                    'condition' => 'good',
                    'restock' => true,
                ]],
                'reason' => 'مرتجع كامل لاختبار السلسلة',
            ],
            'golden-wallet-refund-'.$saleUlid,
        );

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
        $this->assertSame(
            '10.000',
            (string) \App\Models\MerchantProduct::whereKey($productId)->value('quantity'),
            'المرتجع المالي نجح لكن الصنف السليم لم يعد إلى المخزون'
        );
        $this->assertSame(
            '2.000',
            (string) $saleLine->fresh()->returned_quantity,
            'سطر البيع لم يُقفل بكمية المرتجع المعتمدة'
        );

        // ⑦ المرجع المالي أحادي الاستعمال: فاتورة ثانية بنفس الدفع تُرفض
        // من الجلسة المربوطة بالجهاز نفسها.
        $second = $this->withHeaders($this->posHeaders())
            ->postJson('/api/v1/amial/merchant/cashier/sales', [
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

        $shift = $this->withHeaders($this->posHeaders())
            ->postJson('/api/v1/amial/cashier/shift/open', [
            'opening_float' => '1000',
        ]);
        $this->assertContains($shift->status(), [200, 201], json_encode(
            $shift->json(), JSON_UNESCAPED_UNICODE
        ));

        $walletBefore = (string) EMoney::where('user_id', $this->merchant->id)
            ->value('current_balance');

        // ① نقد: الفاتورة مكتملة والمخزون ينقص، لكن محفظة أميال لا تتحرك.
        $cash = $this->withHeaders($this->posHeaders())
            ->postJson('/api/v1/amial/merchant/cashier/sales', [
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

        $xBeforeRefund = $this->withHeaders($this->posHeaders())
            ->getJson('/api/v1/amial/cashier/shift/x')
            ->assertOk();
        $this->assertSame('1500.0000',
            (string) $xBeforeRefund->json('meta.report.expected_cash'));

        // مرتجع نقدي من الكاشير نفسه: لا يلمس المحفظة، لكنه يخرج من
        // الدرج ويجب أن يهبط «المتوقع» في تقرير X فوراً.
        $cashLine = $cashSale->lines()->firstOrFail();
        $this->refundThroughPosWithOwnerApproval(
            $cashSale->sale_ulid,
            [
                'amount' => '500',
                'refund_method' => 'cash',
                'items' => [[
                    'sale_item_id' => $cashLine->id,
                    'quantity' => 1,
                    'condition' => 'good',
                    'restock' => true,
                ]],
                'reason' => 'مرتجع نقدي تجريبي',
            ],
            'golden-cash-refund-'.$cashSale->sale_ulid,
        );

        $xAfterRefund = $this->withHeaders($this->posHeaders())
            ->getJson('/api/v1/amial/cashier/shift/x')
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
        $this->assertSame(
            '10.000',
            (string) \App\Models\MerchantProduct::whereKey($productId)->value('quantity'),
            'الصنف السليم المرتجع نقدياً لم يعد إلى المخزون'
        );

        // ② آجل: لا مال إلكتروني يتحرك؛ الذي يزيد هو دفتر دين العميل.
        $customer = User::factory()->create([
            'type' => CUSTOMER_TYPE,
            'role' => 'customer',
            'phone' => '+967700444555',
            'is_active' => 1,
            'zone_code' => 'SOUTH',
        ]);

        $credit = $this->withHeaders($this->posHeaders())
            ->postJson('/api/v1/amial/merchant/cashier/sales', [
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
            '8.000',
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
        // الالتزام من دفتر العميل نفسه. الطلب يخرج من جهاز POS نفسه،
        // والمالك يعتمد عند الحاجة.
        $this->refundThroughPosWithOwnerApproval(
            $creditSale->sale_ulid,
            [
                'amount' => '1000',
                'refund_method' => 'credit_account',
                'reason' => 'مرتجع بيع آجل تجريبي',
            ],
            'golden-credit-refund-'.$creditSale->sale_ulid,
        );

        $this->assertSame('0.0000', (string) $account->fresh()->current_balance);
        $this->assertSame(
            $walletBefore,
            (string) EMoney::where('user_id', $this->merchant->id)->value('current_balance'),
            'مرتجع الآجل حرّك محفظة رغم أن البيع لم يُحصّل إلكترونياً'
        );
    }
}
