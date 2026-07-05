<?php

namespace Tests\Feature;

use App\Models\FeatureFlag;
use App\Models\User;
use App\Services\FeatureFlagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * AMIAL-MAINT-001 — لوحة «الصيانة الأولية» (Feature Flags + الحارس المالي).
 *
 * الأهم: قاعدة الأمان — لا تُغلق ميزة إلا إذا كان تراكمها المالي = صفر.
 */
class FeatureFlagMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['type' => 0, 'phone' => '967770009001']);
        $this->seed(\Database\Seeders\FeatureFlagsSeeder::class);
    }

    private function svc(): FeatureFlagService
    {
        return app(FeatureFlagService::class);
    }

    // ============ البوابة ============

    public function test_non_admin_cannot_access_panel(): void
    {
        $customer = User::factory()->create(['type' => 2, 'phone' => '967770009099']);
        Passport::actingAs($customer, [], 'api');

        $this->getJson('/api/v1/amial/admin/maintenance/list')->assertStatus(403);
    }

    // ============ التشغيل/الإيقاف الأساسي ============

    public function test_feature_with_no_money_can_be_toggled_freely(): void
    {
        $this->assertTrue($this->svc()->isEnabled('receipts'));

        $flag = $this->svc()->disable($this->admin, 'receipts', 'صيانة الطباعة');
        $this->assertFalse($flag->enabled);
        $this->assertFalse($this->svc()->isEnabled('receipts'));

        $this->svc()->enable($this->admin, 'receipts');
        $this->assertTrue($this->svc()->isEnabled('receipts'));
    }

    // ============ الحارس المالي (جوهر الميزة) ============

    public function test_cannot_disable_feature_holding_money(): void
    {
        // دفعة آمنة مموّلة (المال محتجز)
        $this->seedSafePayment('funded', '5000.0000');

        $this->expectException(\App\Exceptions\FeatureHasFundsException::class);
        $this->svc()->disable($this->admin, 'safe_payment', 'محاولة إيقاف');
    }

    public function test_disable_blocked_shows_outstanding_amount_via_api(): void
    {
        $this->seedSafePayment('in_delivery', '7500.0000');
        Passport::actingAs($this->admin, [], 'api');

        $this->postJson('/api/v1/amial/admin/maintenance/safe_payment/disable', ['note' => 'x'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'FEATURE_HAS_FUNDS')
            ->assertJsonPath('meta.outstanding_amount', '7500.0000')
            ->assertJsonPath('meta.items_count', 1);

        // بقيت مفعّلة (لم تُغلق)
        $this->assertTrue($this->svc()->isEnabled('safe_payment'));
    }

    public function test_can_disable_once_money_is_zero(): void
    {
        // دفعة آمنة أُفرِج عنها (لم تعد تحتجز مالاً)
        $this->seedSafePayment('released_to_seller', '5000.0000', heldAmount: '0.0000');

        $flag = $this->svc()->disable($this->admin, 'safe_payment', 'انتهت كل الدفعات');
        $this->assertFalse($flag->enabled);
    }

    public function test_outstanding_balance_sums_only_active_holds(): void
    {
        $this->seedSafePayment('funded', '3000.0000');            // محتجز
        $this->seedSafePayment('in_delivery', '2000.0000');      // محتجز
        $this->seedSafePayment('released_to_seller', '9999.0000', heldAmount: '0.0000'); // مُفرَج

        $out = $this->svc()->outstandingBalance('safe_payment');
        $this->assertSame('5000.0000', $out['amount']);
        $this->assertSame(2, $out['count']);
    }

    // ============ الحارس على المسارات ============

    public function test_middleware_blocks_disabled_feature_for_customer(): void
    {
        $this->svc()->disable($this->admin, 'donations'); // بلا مال → يُغلق
        $customer = User::factory()->create(['type' => 2, 'phone' => '967770009050']);
        Passport::actingAs($customer, [], 'api');

        // مسار وهمي محمي بالحارس لغرض الاختبار
        \Illuminate\Support\Facades\Route::middleware(['auth:api', 'feature:donations'])
            ->get('/api/test/donations-probe', fn () => response()->json(['ok' => true]));

        $this->getJson('/api/test/donations-probe')
            ->assertStatus(503)
            ->assertJsonPath('code', 'FEATURE_UNDER_MAINTENANCE');
    }

    public function test_admin_bypasses_maintenance_block(): void
    {
        $this->svc()->disable($this->admin, 'donations');
        Passport::actingAs($this->admin, [], 'api');

        \Illuminate\Support\Facades\Route::middleware(['auth:api', 'feature:donations'])
            ->get('/api/test/donations-probe2', fn () => response()->json(['ok' => true]));

        $this->getJson('/api/test/donations-probe2')->assertOk();
    }

    // ============ نظرة اللوحة ============

    public function test_overview_reports_can_disable_flag(): void
    {
        $this->seedSafePayment('funded', '1000.0000');
        Passport::actingAs($this->admin, [], 'api');

        $r = $this->getJson('/api/v1/amial/admin/maintenance/list')->assertOk();
        $features = collect($r->json('meta.features'));

        $safe = $features->firstWhere('key', 'safe_payment');
        $this->assertFalse($safe['can_disable']);           // فيها مال
        $this->assertSame('1000.0000', $safe['outstanding_amount']);

        $receipts = $features->firstWhere('key', 'receipts');
        $this->assertTrue($receipts['can_disable']);        // بلا مال
    }

    // ============ Helpers ============

    private function seedSafePayment(string $status, string $amount, ?string $heldAmount = null): void
    {
        $held = $heldAmount ?? $amount;
        // إدراج مباشر بالحدّ الأدنى من الأعمدة المطلوبة
        $cols = \Illuminate\Support\Facades\Schema::getColumnListing('safe_payments');
        $row = [
            'amount' => $amount, 'held_amount' => $held, 'status' => $status,
            'created_at' => now(), 'updated_at' => now(),
        ];
        // املأ أي أعمدة NOT NULL بلا default بقيم آمنة
        foreach (['buyer_user_id' => $this->admin->id, 'seller_user_id' => $this->admin->id,
                  'payment_ulid' => (string) \Illuminate\Support\Str::ulid(),
                  'title' => 'اختبار', 'description' => 'اختبار حجز',
                  'zone_code' => 'SOUTH', 'fee_amount' => '0'] as $c => $v) {
            if (in_array($c, $cols, true)) $row[$c] = $v;
        }
        DB::table('safe_payments')->insert($row);
    }
}
