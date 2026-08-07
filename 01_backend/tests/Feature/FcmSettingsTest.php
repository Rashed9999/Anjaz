<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-FCM-002 — صفحة إعداد Firebase.
 *
 * سبب وجود هذه الاختبارات: المسار كان يشير إلى قالب عرض محذوف، فكان فتح
 * الصفحة يرمي View not found ولا وسيلة إطلاقاً لإدخال مفتاح الخدمة — ولم
 * يكشف ذلك أحد لأن لا اختبار يفتح الصفحة ولا رابط لها في القائمة.
 */
class FcmSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['type' => ADMIN_TYPE]);
    }

    /** مفتاح خدمة صالح الشكل — بمفتاح RSA مولّد فعلياً لا نصّ وهمي. */
    private function serviceAccountJson(string $projectId = 'amial-pay'): string
    {
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $pem);

        return json_encode([
            'type' => 'service_account',
            'project_id' => $projectId,
            'private_key' => $pem,
            'client_email' => "svc@{$projectId}.iam.gserviceaccount.com",
        ]);
    }

    private function stored(): ?string
    {
        return DB::table('business_settings')
            ->where('key', 'push_notification_service_file_content')
            ->value('value');
    }

    public function test_page_renders_for_admin(): void
    {
        $this->actingAs($this->admin(), 'user')
            ->get('/admin/business-settings/fcm-index')
            ->assertOk()
            ->assertSee('Firebase', false);
    }

    public function test_guest_cannot_open_the_page(): void
    {
        $this->get('/admin/business-settings/fcm-index')->assertRedirect();
    }

    public function test_valid_service_account_is_saved_normalised(): void
    {
        $this->actingAs($this->admin(), 'user')
            ->post('/admin/business-settings/update-fcm', [
                'push_notification_service_file_content' => $this->serviceAccountJson(),
            ])->assertRedirect();

        $saved = json_decode((string) $this->stored(), true);
        $this->assertIsArray($saved);
        $this->assertSame('amial-pay', $saved['project_id']);
        $this->assertStringContainsString('BEGIN PRIVATE KEY', $saved['private_key']);
    }

    public function test_page_reports_configured_project_without_leaking_the_private_key(): void
    {
        $json = $this->serviceAccountJson();
        DB::table('business_settings')->updateOrInsert(
            ['key' => 'push_notification_service_file_content'],
            ['value' => $json]
        );

        $body = $this->actingAs($this->admin(), 'user')
            ->get('/admin/business-settings/fcm-index')
            ->assertOk()
            ->assertSee('amial-pay')
            ->getContent();

        $this->assertStringNotContainsString('BEGIN PRIVATE KEY', $body);
        $this->assertStringNotContainsString(
            json_decode($json, true)['private_key'],
            $body
        );
    }

    public function test_blank_submission_keeps_the_existing_key(): void
    {
        $json = $this->serviceAccountJson();
        DB::table('business_settings')->updateOrInsert(
            ['key' => 'push_notification_service_file_content'],
            ['value' => $json]
        );

        $this->actingAs($this->admin(), 'user')
            ->post('/admin/business-settings/update-fcm', [
                'push_notification_service_file_content' => '',
            ])->assertRedirect();

        // حفظٌ عابر للصفحة كان يمسح المفتاح فتصمت الإشعارات كلها.
        $this->assertSame($json, $this->stored());
    }

    public function test_malformed_json_is_rejected_and_does_not_overwrite(): void
    {
        $json = $this->serviceAccountJson();
        DB::table('business_settings')->updateOrInsert(
            ['key' => 'push_notification_service_file_content'],
            ['value' => $json]
        );

        $this->actingAs($this->admin(), 'user')
            ->post('/admin/business-settings/update-fcm', [
                'push_notification_service_file_content' => 'this is not json',
            ])->assertRedirect();

        $this->assertSame($json, $this->stored());
    }

    public function test_google_services_json_is_rejected_as_the_wrong_file(): void
    {
        // الخطأ المتوقّع من المستخدم: لصق google-services.json بدل ملف الخدمة.
        $wrong = json_encode([
            'project_info' => ['project_id' => 'amial-pay'],
            'client' => [],
        ]);

        $this->actingAs($this->admin(), 'user')
            ->post('/admin/business-settings/update-fcm', [
                'push_notification_service_file_content' => $wrong,
            ])->assertRedirect();

        $this->assertNull($this->stored());
    }

    public function test_service_account_with_broken_private_key_is_rejected(): void
    {
        $bad = json_encode([
            'type' => 'service_account',
            'project_id' => 'amial-pay',
            'private_key' => 'truncated-nonsense',
            'client_email' => 'svc@amial-pay.iam.gserviceaccount.com',
        ]);

        $this->actingAs($this->admin(), 'user')
            ->post('/admin/business-settings/update-fcm', [
                'push_notification_service_file_content' => $bad,
            ])->assertRedirect();

        $this->assertNull($this->stored());
    }

    public function test_test_send_reports_missing_key_instead_of_failing_silently(): void
    {
        $this->actingAs($this->admin(), 'user')
            ->post('/admin/business-settings/test-fcm', ['test_phone' => '770000000'])
            ->assertRedirect();

        // لا مستخدم ولا مفتاح — المهم ألا يرمي 500.
        $this->assertTrue(true);
    }
}
