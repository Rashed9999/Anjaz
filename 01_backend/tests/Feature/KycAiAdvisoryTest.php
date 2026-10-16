<?php

namespace Tests\Feature;

use App\Models\KycAiReview;
use App\Models\KycDocument;
use App\Models\User;
use App\Services\Kyc\KycAiReviewService;
use App\Services\Kyc\KycPrivacyService;
use App\Services\KycDocumentService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** المساعد لا يملك صلاحية الاعتماد ولا يرسل وثيقة قبل التفعيل الصريح. */
class KycAiAdvisoryTest extends TestCase
{
    use RefreshDatabase;

    private function reviewer(): User
    {
        $staff = User::factory()->create(['type' => ADMIN_TYPE, 'role' => 'operator']);
        DB::table('platform_operator_tab_access')->insert([
            'user_id' => $staff->id, 'tab_code' => 'compliance',
            'access_level' => 'write', 'granted_by_user_id' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (['platform.customers.kyc.view', 'platform.customers.freeze'] as $code) {
            $id = DB::table('permissions')->where('code', $code)->value('id');
            $this->assertNotNull($id);
            DB::table('admin_user_permissions')->insert([
                'user_id' => $staff->id, 'permission_id' => $id,
                'granted_by_user_id' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        return $staff->fresh();
    }

    private function subject(): User
    {
        return User::factory()->create(['type' => CUSTOMER_TYPE,
            'is_kyc_verified' => 0, 'kyc_tier' => 0]);
    }

    public function test_external_ai_is_off_until_both_key_and_model_are_configured(): void
    {
        config(['amial.kyc.ai.enabled' => false, 'amial.kyc.ai.key' => 'fake',
            'amial.kyc.ai.model' => 'test/model']);
        Http::fake();
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('المساعد غير مفعل');
        app(KycAiReviewService::class)->run($this->subject(), $this->reviewer());
    }

    public function test_ai_report_is_encrypted_advisory_only_and_requests_strict_privacy(): void
    {
        config([
            'amial.kyc.ai.enabled' => true,
            'amial.kyc.ai.key' => 'test-only-never-real',
            'amial.kyc.ai.model' => 'test/vision',
            'amial.kyc.ai.send_images' => false,
            'amial.kyc.ai.daily_limit' => 10000,
        ]);
        Http::preventStrayRequests();
        Http::fake(['https://openrouter.ai/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode([
                'overview' => 'راجع جودة الصورة مع الموظف',
                'findings' => [['document_id' => 1, 'severity' => 'review',
                    'text' => 'بعض الحقول تحتاج تدقيقاً']],
                'human_review' => ['مطابقة بيانات الهوية بصرياً'],
            ], JSON_UNESCAPED_UNICODE)]]],
            'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 40],
        ], 200)]);

        $subject = $this->subject();
        $reviewer = $this->reviewer();
        app(KycDocumentService::class)->upload(
            $subject, KycDocument::TYPE_ID_FRONT,
            UploadedFile::fake()->image('front.jpg', 500, 300)
        );
        $before = (int) $subject->fresh()->is_kyc_verified;
        $report = app(KycAiReviewService::class)->run($subject->fresh(), $reviewer);

        $this->assertSame('راجع جودة الصورة مع الموظف', $report['report']['overview']);
        $this->assertSame($before, (int) $subject->fresh()->is_kyc_verified);
        $this->assertDatabaseHas('kyc_ai_reviews', ['user_id' => $subject->id, 'status' => 'complete']);
        $stored = KycAiReview::where('user_id', $subject->id)->firstOrFail();
        $this->assertStringNotContainsString('راجع جودة الصورة', (string) $stored->getRawOriginal('report_encrypted'));
        $this->assertSame(120, $stored->prompt_tokens);
        Http::assertSent(function ($request) {
            $payload = $request->data();
            return $request->url() === 'https://openrouter.ai/api/v1/chat/completions'
                && ($payload['provider']['zdr'] ?? null) === true
                && ($payload['provider']['data_collection'] ?? null) === 'deny'
                && count($payload['messages'][1]['content'] ?? []) === 1;
        });
    }

    public function test_restricted_case_never_leaves_the_server_even_with_key(): void
    {
        config(['amial.kyc.ai.enabled' => true, 'amial.kyc.ai.key' => 'fake',
            'amial.kyc.ai.model' => 'test/model']);
        $subject = $this->subject();
        $reviewer = $this->reviewer();
        app(KycPrivacyService::class)->choose($subject, KycPrivacyService::MODE_RESTRICTED);
        Http::fake();
        try {
            app(KycAiReviewService::class)->run($subject, $reviewer);
            $this->fail('Restricted case was exported to AI');
        } catch (DomainException) {
            Http::assertNothingSent();
        }
    }
}
