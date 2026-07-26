<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-AUDIT-ORPHAN-001 — شاشات الإعدادات في لوحة الأدمن.
 *
 * كانت ترمي 500 لأن جدول `addon_settings` الذي يقرأ منه نموذج Setting لم
 * يُنشأ قطّ (بقي في قالب 6cash ولم تُنقل هجرته).
 *
 * سبب بقائه مخفيّاً: هذه المسارات بلا أي اختبار، والخطأ يقع في شاشة
 * إعدادات لا في مسار مالي — فيُقرأ كعطل عابر لا كخلل بنيوي. اختبارٌ واحد
 * يفتح الصفحة كان كافياً لكشفه.
 */
class AdminSettingsEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['type' => ADMIN_TYPE]);
    }

    public function test_the_settings_screens_load_instead_of_erroring(): void
    {
        $admin = $this->admin();

        foreach ([
            '/api/v1/amial/admin/settings/sms',
            '/api/v1/amial/admin/settings/notifications',
            '/api/v1/amial/admin/whatsapp/config',
            '/api/v1/amial/admin/ops/status',
        ] as $url) {
            $this->actingAs($admin, 'api')->getJson($url)
                ->assertStatus(200, "المسار {$url} لا يزال يفشل");
        }
    }

    public function test_a_provider_setting_survives_a_round_trip(): void
    {
        // لا يكفي ألّا تتعطّل الصفحة: الإعداد يجب أن يُحفظ ويُقرأ فعلاً.
        // النموذج يحوّل live_values إلى مصفوفة — تُمرَّر مصفوفةً لا نصّاً
        // مُرمَّزاً، وإلا رُمِّز مرّتين وعاد نصّاً بدل مصفوفة.
        Setting::updateOrCreate(
            ['key_name' => 'twilio_sms', 'settings_type' => 'sms_config'],
            ['live_values' => ['sid' => 'AC-test'], 'mode' => 'live', 'is_active' => 1],
        );

        $row = Setting::where('key_name', 'twilio_sms')
            ->where('settings_type', 'sms_config')->first();

        $this->assertNotNull($row);
        $this->assertSame('AC-test', $row->live_values['sid']);
    }

    /** المفتاح الفريد يمنع صفّين متطابقين يجعلان first() يُرجع أحدهما عشوائياً. */
    public function test_the_same_key_cannot_be_stored_twice(): void
    {
        Setting::create(['key_name' => 'k', 'settings_type' => 't', 'mode' => 'live']);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        Setting::create(['key_name' => 'k', 'settings_type' => 't', 'mode' => 'live']);
    }
}
