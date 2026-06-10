<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * AMIAL-WHATSAPP-OTP-001 — اختبار إدارة واتساب من لوحة الأدمن + إشعارات واتساب.
 *
 * يغطّي: حماية الصلاحية (403 لغير الأدمن)، حفظ مزوّد، تقنيع الأسرار وعدم الكتابة
 * فوقها بالقيمة المُقنّعة، ضبط تفضيل القناة، الإرسال التجريبي، ونسخ الإشعارات
 * إلى واتساب (لكل الأنواع أو أنواع محدّدة) دون كسر الإشعار الأساسي.
 */
class WhatsappAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // addon_settings جدول قاعدة 6cash (ليس هجرة) — ننشئه للاختبار.
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
        $u->forceFill(['type' => 1])->save();
        return $u;
    }

    private function customer(): User
    {
        return User::factory()->create();
    }

    // ---------- الحماية ----------

    public function test_non_admin_gets_403(): void
    {
        Passport::actingAs($this->customer());
        $this->getJson('/api/v1/amial/admin/whatsapp/config')
            ->assertStatus(403)
            ->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_guest_gets_401(): void
    {
        $this->getJson('/api/v1/amial/admin/whatsapp/config')->assertStatus(401);
    }

    // ---------- الإعدادات ----------

    public function test_admin_saves_provider_and_secrets_are_masked(): void
    {
        Passport::actingAs($this->admin());

        $this->postJson('/api/v1/amial/admin/whatsapp/provider', [
            'provider' => 'ultramsg',
            'status' => true,
            'config' => ['instance_id' => 'inst99', 'token' => 'supersecret123', 'otp_template' => 'رمزك: #OTP#'],
        ])->assertOk()
          ->assertJsonPath('meta.enabled', true)
          ->assertJsonPath('meta.config.token', '••••t123'); // مُقنّع

        // القيمة الحقيقية محفوظة فعلاً
        $row = Setting::where('key_name', 'ultramsg')->where('settings_type', 'whatsapp_config')->first();
        $this->assertSame('supersecret123', $row->live_values['token']);
        $this->assertSame(1, $row->live_values['status']);

        // GET يعيد كل المزوّدين مع التقنيع
        $r = $this->getJson('/api/v1/amial/admin/whatsapp/config')->assertOk();
        $ultra = collect($r->json('meta.providers'))->firstWhere('provider', 'ultramsg');
        $this->assertTrue($ultra['enabled']);
        $this->assertStringStartsWith('••••', $ultra['config']['token']);
    }

    public function test_masked_secret_does_not_overwrite_real_value(): void
    {
        Passport::actingAs($this->admin());

        // حفظ أوّلي بسرّ حقيقي
        $this->postJson('/api/v1/amial/admin/whatsapp/provider', [
            'provider' => 'ultramsg', 'status' => true,
            'config' => ['instance_id' => 'inst99', 'token' => 'realtoken456'],
        ])->assertOk();

        // تعديل لاحق يعيد القيمة المُقنّعة كما تعرضها الواجهة
        $this->postJson('/api/v1/amial/admin/whatsapp/provider', [
            'provider' => 'ultramsg', 'status' => true,
            'config' => ['instance_id' => 'inst-new', 'token' => '••••n456'],
        ])->assertOk();

        $row = Setting::where('key_name', 'ultramsg')->where('settings_type', 'whatsapp_config')->first();
        $this->assertSame('realtoken456', $row->live_values['token']); // السرّ الحقيقي باقٍ
        $this->assertSame('inst-new', $row->live_values['instance_id']); // وغير السرّي تحدّث
    }

    public function test_admin_sets_channel_preference(): void
    {
        Passport::actingAs($this->admin());

        $this->postJson('/api/v1/amial/admin/whatsapp/channel', ['value' => 'sms_first'])
            ->assertOk()->assertJsonPath('meta.channel_preference', 'sms_first');

        $this->assertSame('sms_first', \App\CentralLogics\OtpDispatcher::channelPreference());

        $this->postJson('/api/v1/amial/admin/whatsapp/channel', ['value' => 'invalid'])
            ->assertStatus(422);
    }

    public function test_admin_test_send_uses_enabled_provider(): void
    {
        Passport::actingAs($this->admin());
        Http::fake(['api.ultramsg.com/*' => Http::response(['sent' => 'true'], 200)]);

        $this->postJson('/api/v1/amial/admin/whatsapp/provider', [
            'provider' => 'ultramsg', 'status' => true,
            'config' => ['instance_id' => 'inst99', 'token' => 'tok'],
        ])->assertOk();

        $this->postJson('/api/v1/amial/admin/whatsapp/test', [
            'phone' => '+967777123456', 'message' => 'رسالة تجريبية',
        ])->assertOk()->assertJsonPath('code', 'SENT');

        Http::assertSent(fn ($req) => str_contains($req->url(), 'api.ultramsg.com'));
    }

    // ---------- إشعارات واتساب ----------

    private function enableUltramsgAndNotifications(string|array $types = 'all'): void
    {
        foreach ([
            ['ultramsg', ['status' => 1, 'instance_id' => 'i', 'token' => 't']],
            ['whatsapp_notifications', ['status' => 1, 'types' => $types]],
        ] as [$key, $values]) {
            Setting::updateOrCreate(
                ['key_name' => $key, 'settings_type' => 'whatsapp_config'],
                ['key_name' => $key, 'settings_type' => 'whatsapp_config',
                 'live_values' => $values, 'mode' => 'live', 'is_active' => 1]
            );
        }
    }

    public function test_notification_is_echoed_to_whatsapp_when_enabled(): void
    {
        $this->enableUltramsgAndNotifications('all');
        Http::fake(['api.ultramsg.com/*' => Http::response(['sent' => 'true'], 200)]);
        $user = $this->customer();

        $n = app(NotificationService::class)->dispatch(
            $user, 'transfer_received', 'حوالة واردة', 'استلمت 5000 ر.ي'
        );

        $this->assertNotNull($n->id); // الإشعار الداخلي أُنشئ
        Http::assertSent(fn ($req) => str_contains($req->url(), 'api.ultramsg.com')
            && str_contains((string) $req['body'], 'حوالة واردة'));
    }

    public function test_notification_type_filter_is_respected(): void
    {
        $this->enableUltramsgAndNotifications(['withdrawal_completed']); // ليس transfer
        Http::fake(['api.ultramsg.com/*' => Http::response(['sent' => 'true'], 200)]);

        app(NotificationService::class)->dispatch(
            $this->customer(), 'transfer_received', 'حوالة', 'نص'
        );

        Http::assertNothingSent(); // النوع غير مُفعّل → لا واتساب
    }

    public function test_whatsapp_failure_never_breaks_notification(): void
    {
        $this->enableUltramsgAndNotifications('all');
        Http::fake(['api.ultramsg.com/*' => Http::response('boom', 500)]);

        $n = app(NotificationService::class)->dispatch(
            $this->customer(), 'transfer_received', 'حوالة', 'نص'
        );

        $this->assertNotNull($n->id); // فشل واتساب لم يكسر الإشعار الأساسي
    }
}
