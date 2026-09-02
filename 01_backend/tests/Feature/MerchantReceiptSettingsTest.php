<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\MerchantProfile;
use App\Models\PosUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * AMIAL-RECEIPT-SETTINGS-001 — إعدادات الفاتورة/الطباعة لكل تاجر.
 */
class MerchantReceiptSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->merchant = User::factory()->create(['type' => 3, 'role' => 'merchant', 'zone_code' => 'SOUTH']);
        MerchantProfile::create(['user_id' => $this->merchant->id, 'verification_status' => 'verified']);
        $mr = new Merchant();
        $mr->user_id = $this->merchant->id;
        $mr->store_name = 'محطة الأمل';
        $mr->merchant_number = 'M-70001';
        $mr->address = '—';
        $mr->save();
    }

    /** @test الإعدادات الافتراضية تُعاد مع اسم المتجر. */
    public function defaults_are_returned_with_store_name(): void
    {
        Passport::actingAs($this->merchant->fresh(), [], 'api');
        $this->getJson('/api/v1/amial/merchant/receipt-settings')
            ->assertOk()
            ->assertJsonPath('meta.settings.paper_width', 80)
            ->assertJsonPath('meta.settings.store_name', 'محطة الأمل')
            ->assertJsonPath('meta.settings.footer_note', 'شكراً لتعاملكم معنا');
    }

    /** @test حفظ الإعدادات ثم قراءتها. */
    public function save_and_read_back_settings(): void
    {
        Passport::actingAs($this->merchant->fresh(), [], 'api');

        $this->postJson('/api/v1/amial/merchant/receipt-settings', [
            'header_note' => 'أفضل الأسعار',
            'footer_note' => 'زورونا مجدداً',
            'phone' => '777200004',
            'paper_width' => 58,
            'show_logo' => false,
            'store_name' => 'محطة الأمل الجديدة',
        ])->assertOk()->assertJsonPath('meta.settings.paper_width', 58);

        $this->getJson('/api/v1/amial/merchant/receipt-settings')
            ->assertOk()
            ->assertJsonPath('meta.settings.header_note', 'أفضل الأسعار')
            ->assertJsonPath('meta.settings.phone', '777200004')
            ->assertJsonPath('meta.settings.show_logo', false)
            ->assertJsonPath('meta.settings.store_name', 'محطة الأمل الجديدة');
    }

    /** @test غير التاجر ممنوع. */
    public function non_merchant_is_forbidden(): void
    {
        $customer = User::factory()->create(['type' => 2, 'role' => 'user', 'zone_code' => 'SOUTH']);
        Passport::actingAs($customer->fresh(), [], 'api');
        $this->getJson('/api/v1/amial/merchant/receipt-settings')->assertStatus(403);
    }

    /** @test موظف الـ POS يقرأ هوية فاتورة مالكه ولا يستطيع تعديلها. */
    public function pos_employee_reads_owner_receipt_identity_but_cannot_change_it(): void
    {
        $posLogin = User::factory()->create(['type' => 4, 'role' => 'pos', 'zone_code' => 'SOUTH']);
        PosUser::create([
            'user_id' => $posLogin->id,
            'merchant_user_id' => $this->merchant->id,
            'pos_number' => 'POS-1',
            'display_name' => 'كاشير الاختبار',
            'is_active' => true,
        ]);

        // **المقيسُ هنا نطاقُ القراءة، لا ربطُ المقعد.**
        // `EnsurePosDevice` يردّ جلسةَ نقطةِ بيعٍ بلا جهازٍ مربوطٍ قبل
        // المتحكّم، و`Passport::actingAs` لا يُصدر رمزاً حقيقيّاً يُربَط
        // به مقعد. وإنفاذُ المقعد محروسٌ في موضعه
        // (`PosDeviceSessionBindingGuardTest` · `PosDeviceBypassMatrixTest`)،
        // فلا يُترك بلا قياس — ويُطفَأ هنا وحدَه ليصل الطلبُ إلى المفحوص.
        config(['amial.pos_devices.enforce_session_binding' => false]);

        Passport::actingAs($posLogin, [], 'api');
        $this->getJson('/api/v1/amial/merchant/receipt-settings')
            ->assertOk()
            ->assertJsonPath('meta.settings.store_name', 'محطة الأمل');

        $this->postJson('/api/v1/amial/merchant/receipt-settings', ['store_name' => 'اسم غير مسموح'])
            ->assertStatus(403);
        $this->assertSame('محطة الأمل', Merchant::where('user_id', $this->merchant->id)->value('store_name'));
    }
}
