<?php

namespace Tests\Feature\E2E;

use App\Models\EMoney;
use App\Models\MuwathaqAccountOpeningApplication;
use App\Models\User;
use App\Services\Muwathaq\MuwathaqAccountOpeningService;
use App\Services\Muwathaq\MuwathaqOpeningProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Muwathaq E2E — رحلة فتح الحساب النسائي Tier 1 عبر حدود التطبيقين.
 *
 * التسجيل والمتابعة يصلان عبر HTTP في أميال باي. قرار موثّق يصل عبر قناة
 * HMAC الداخلية. ثم القرار التشغيلي للإدارة وحده ينشئ المحفظة. هذا يمنع
 * أخطر انحدار: إنشاء محفظة أو تفعيل مالي عند رفع الأدلة أو قرار المراجع وحده.
 */
class MuwathaqAccountOpeningE2ETest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function female_tier_one_account_crosses_phone_residence_muwathaq_and_admin_gates_before_wallet_creation(): void
    {
        config([
            'amial.otp.pilot_customer_phone_enabled' => true,
            'amial.otp.pilot_customer_phone_code' => '123456',
            'muwathaq.decision_secret' => 'e2e-muwathaq-to-amial-secret',
        ]);

        $applicant = User::factory()->create([
            'type' => CUSTOMER_TYPE,
            'gender' => 'female',
            'is_phone_verified' => 0,
            'kyc_tier' => 0,
        ]);
        $admin = User::factory()->admin()->create();

        $openingService = app(MuwathaqAccountOpeningService::class);
        $progressService = app(MuwathaqOpeningProgressService::class);
        $opening = $openingService->submitFor($applicant);
        $token = $progressService->issueAccessToken($opening);
        $url = '/api/v1/auth/account-opening/'.$opening->application_ulid;

        // 1) العميلة لا تحصل على دخول أو محفظة؛ تملك فقط رمز متابعة ضيق النطاق.
        $this->getJson($url.'/status', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.verification_path', 'women_progressive')
            ->assertJsonPath('data.requested_tier', 1)
            ->assertJsonPath('data.wallet_created', false)
            ->assertJsonPath('data.next_steps.0', 'confirm_phone');

        // 2) رمز الاختبار التجريبي يثبت الهاتف للقضية فقط، لا لحساب المال.
        $this->postJson($url.'/phone/confirm', ['code' => '123456'], [
            'Authorization' => 'Bearer '.$token,
        ])->assertOk()
            ->assertJsonPath('data.next_steps.0', 'submit_residence');
        $this->assertSame(0, (int) $applicant->fresh()->is_phone_verified);

        // 3) إثبات السكن يحوّل القضية للمراجعة، وما زال لا ينشئ محفظة.
        $this->postJson($url.'/residence', [
            'governorate' => 'YE-AD',
            'district' => 'المنصورة',
            'area' => 'المدينة',
            'evidence_type' => 'self_declaration',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.status', MuwathaqAccountOpeningApplication::STATUS_UNDER_REVIEW)
            ->assertJsonPath('data.next_steps.0', 'await_muwathaq_review');
        $this->assertDatabaseMissing('e_money', ['user_id' => $applicant->id]);

        // 4) تصل موافقة موثّق من القناة الموقعة؛ لا تكفي وحدها لاعتماد المال.
        $decision = json_encode([
            'decision' => 'approved',
            'decision_reference' => 'MWT-E2E-FEMALE-0001',
            'context' => [
                'policy_version' => 'muwathaq-v1',
                'reviewer_reference' => 'reviewer-e2e',
                'verified_phone' => true,
                'verified_residence_governorate' => 'YE-AD',
            ],
        ], JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$decision, 'e2e-muwathaq-to-amial-secret');

        $this->call(
            'POST',
            '/api/v1/internal/muwathaq/applications/'.$opening->application_ulid.'/decision',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_MUWATHAQ_TIMESTAMP' => $timestamp,
                'HTTP_X_MUWATHAQ_SIGNATURE' => $signature,
            ],
            $decision,
        )->assertOk()
            ->assertJsonPath('code', 'MUWATHAQ_DECISION_APPLIED')
            ->assertJsonPath('meta.status', MuwathaqAccountOpeningApplication::STATUS_AWAITING_ADMIN_APPROVAL);
        $this->assertDatabaseMissing('e_money', ['user_id' => $applicant->id]);

        // 5) اعتماد الإدارة هو البوابة النهائية الوحيدة التي تنشئ المحفظة.
        $activated = $openingService->receiveAdminDecision(
            $opening->application_ulid,
            true,
            $admin->id,
            'اعتماد تشغيلي E2E',
        );

        $this->assertSame(MuwathaqAccountOpeningApplication::STATUS_APPROVED, $activated->status);
        $this->assertDatabaseHas('e_money', ['user_id' => $applicant->id]);
        $this->assertSame(1, (int) $applicant->fresh()->is_phone_verified);
        $this->assertSame('YE-AD', $applicant->fresh()->verified_residence_governorate);
        $this->assertSame(1, (int) $applicant->fresh()->kyc_tier);
        $this->assertDatabaseMissing('muwathaq_opening_evidences', [
            'application_id' => $opening->id,
            'kind' => 'identity_document',
        ]);
        // is_kyc_verified حقل توافق قديم يتغير مع تعيين المنطقة؛ مصدر حقيقة
        // الهوية هو أدلة موثّق أعلاه، فلا نخلط بينهما في هذا الاختبار.
    }
}
