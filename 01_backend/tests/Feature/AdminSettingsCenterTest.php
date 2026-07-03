<?php

namespace Tests\Feature;

use App\Models\FeeScheme;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * AMIAL-SETTINGS-CENTER-001 — اختبار مركز الإعدادات الموحّد.
 *
 * يغطّي: حماية الصلاحية، SMS (حفظ/تقنيع/عدم الكتابة فوق السرّ المُقنّع)،
 * إشعارات واتساب (تفعيل/أنواع)، بيانات التواصل (حفظ + endpoint عام)،
 * والرسوم (قائمة/إنشاء نسخة/محاكاة/تعطيل).
 */
class AdminSettingsCenterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (!Schema::hasTable('addon_settings')) {
            Schema::create('addon_settings', function (Blueprint $t) {
                $t->string('id', 36)->primary();
                $t->string('key_name', 191)->nullable();
                $t->longText('live_values')->nullable();
                $t->longText('test_values')->nullable();
                $t->string('settings_type', 255)->nullable();
                $t->string('mode', 20)->default('live');
                $t->boolean('is_active')->default(true);
                $t->timestamps();
                $t->longText('additional_data')->nullable();
            });
        }
    }

    private function admin(): User
    {
        $u = User::factory()->create();
        $u->forceFill(['type' => 0, 'role' => 'super_admin'])->save();
        return $u;
    }

    // ---------- الحماية ----------

    public function test_non_admin_blocked_on_all_settings_endpoints(): void
    {
        Passport::actingAs(User::factory()->create());
        foreach (['sms', 'notifications', 'contact', 'fees'] as $section) {
            $this->getJson("/api/v1/amial/admin/settings/{$section}")
                ->assertStatus(403)->assertJsonPath('code', 'FORBIDDEN');
        }
    }

    // ---------- SMS ----------

    public function test_admin_saves_sms_provider_with_masking(): void
    {
        Passport::actingAs($this->admin());

        $this->postJson('/api/v1/amial/admin/settings/sms/provider', [
            'provider' => '2factor', 'status' => true,
            'config' => ['api_key' => 'verysecretkey99'],
        ])->assertOk()
          ->assertJsonPath('meta.enabled', true)
          ->assertJsonPath('meta.config.api_key', '••••ey99');

        // القيمة الحقيقية محفوظة
        $row = Setting::where('key_name', '2factor')->where('settings_type', 'sms_config')->first();
        $this->assertSame('verysecretkey99', $row->live_values['api_key']);

        // إعادة إرسال القيمة المُقنّعة لا تفسد السرّ
        $this->postJson('/api/v1/amial/admin/settings/sms/provider', [
            'provider' => '2factor', 'status' => false,
            'config' => ['api_key' => '••••ey99'],
        ])->assertOk()->assertJsonPath('meta.enabled', false);
        $this->assertSame('verysecretkey99', $row->fresh()->live_values['api_key']);

        // GET يعرض الأربعة بمفاتيح الحقول
        $r = $this->getJson('/api/v1/amial/admin/settings/sms')->assertOk();
        $this->assertCount(4, $r->json('meta.providers'));
    }

    // ---------- إشعارات واتساب ----------

    public function test_admin_toggles_whatsapp_notifications(): void
    {
        Passport::actingAs($this->admin());

        $this->postJson('/api/v1/amial/admin/settings/notifications', [
            'enabled' => true, 'types' => ['transfer_received', 'refund_received'],
        ])->assertOk()->assertJsonPath('meta.enabled', true);

        $r = $this->getJson('/api/v1/amial/admin/settings/notifications')->assertOk();
        $this->assertTrue($r->json('meta.enabled'));
        $this->assertSame(['transfer_received', 'refund_received'], $r->json('meta.types'));
        $this->assertNotEmpty($r->json('meta.known_types'));
    }

    // ---------- بيانات التواصل ----------

    public function test_admin_updates_contact_and_public_endpoint_reflects_it(): void
    {
        Passport::actingAs($this->admin());

        $this->postJson('/api/v1/amial/admin/settings/contact', [
            'whatsapp_number' => '967711222333',
            'support_email' => 'help@amyalpay.com',
        ])->assertOk()->assertJsonPath('meta.contact.whatsapp_number', '967711222333');

        // الـ endpoint العام (بدون auth) يعكس القيم الجديدة + الافتراضي لما لم يُحدَّث
        $this->getJson('/api/v1/amial/support-contact')->assertOk()
            ->assertJsonPath('meta.contact.whatsapp_number', '967711222333')
            ->assertJsonPath('meta.contact.support_email', 'help@amyalpay.com')
            ->assertJsonPath('meta.contact.phone_number', '+967777000000');
    }

    // ---------- الرسوم ----------

    public function test_admin_manages_fee_schemes(): void
    {
        Passport::actingAs($this->admin());

        // محاكاة قبل الحفظ
        $this->postJson('/api/v1/amial/admin/settings/fees/simulate', [
            'amount' => '100',
            'scheme' => ['code' => 'SEND_MONEY', 'fee_type' => 'percent', 'percent_rate' => '2.5'],
        ])->assertOk()->assertJsonPath('meta.simulation.fee', '2.5000');

        // إنشاء نسخة
        $r = $this->postJson('/api/v1/amial/admin/settings/fees', [
            'code' => 'SEND_MONEY', 'fee_type' => 'percent', 'percent_rate' => '2.5',
        ])->assertOk()->assertJsonPath('meta.scheme.version', 1);
        $id = $r->json('meta.scheme.id');

        // القائمة تعرضها
        $list = $this->getJson('/api/v1/amial/admin/settings/fees')->assertOk();
        $this->assertNotEmpty($list->json('meta.schemes'));
        $this->assertContains('SEND_MONEY', $list->json('meta.codes'));

        // نسخة جديدة تلغي القديمة (append-only)
        $this->postJson('/api/v1/amial/admin/settings/fees', [
            'code' => 'SEND_MONEY', 'fee_type' => 'percent', 'percent_rate' => '3',
        ])->assertOk()->assertJsonPath('meta.scheme.version', 2);
        $this->assertFalse((bool) FeeScheme::find($id)->is_active);

        // تعطيل النسخة النشطة
        $activeId = FeeScheme::where('code', 'SEND_MONEY')->where('is_active', true)->value('id');
        $this->postJson("/api/v1/amial/admin/settings/fees/{$activeId}/deactivate")
            ->assertOk()->assertJsonPath('code', 'DEACTIVATED');
        $this->assertFalse((bool) FeeScheme::find($activeId)->is_active);

        // إدخال غير صالح
        $this->postJson('/api/v1/amial/admin/settings/fees', [
            'code' => 'BOGUS', 'fee_type' => 'percent',
        ])->assertStatus(422);
    }
}
