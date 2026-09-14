<?php

namespace Tests\Feature;

use App\Models\KycDocument;
use App\Models\User;
use App\Services\Kyc\GuardedKycDocumentService;
use App\Services\Kyc\KycPrivacyService;
use App\Services\KycDocumentService;
use App\Services\KycOcrService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-KYC-CENTRAL-GUARD-001 — أربعة أبواب فقط:
 *  1) الحاوية تستعمل الحارس فعلاً.
 *  2) اكتمال الصور لا يكفي من دون رقم هوية أقرّه إنسان.
 *  3) الطابور المقيد لا يظهر في العام ولا يقرره موظف عام.
 *  4) التحقق الحضوري لا يُثبت من استدعاء خدمة بلا صلاحية.
 */
class KycOwnershipRestrictedGuardTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function customer(): User
    {
        return User::factory()->create(['zone_code' => 'SOUTH']);
    }

    private function reviewer(): User
    {
        return User::factory()->create(['type' => 0, 'role' => 'operator']);
    }

    private function image(string $name): UploadedFile
    {
        return UploadedFile::fake()->image($name, 760, 500 + (++$this->seq));
    }

    private function grant(User $operator, array $codes): void
    {
        DB::table('platform_operator_tab_access')->insert([
            'user_id' => $operator->id,
            'tab_code' => 'compliance',
            'access_level' => 'write',
            'granted_by_user_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ids = DB::table('permissions')->whereIn('code', $codes)->pluck('id', 'code');
        foreach ($codes as $code) {
            $this->assertArrayHasKey($code, $ids->all(), "الصلاحية {$code} غير مهيأة في قاعدة الاختبار");
            DB::table('admin_user_permissions')->insert([
                'user_id' => $operator->id,
                'permission_id' => $ids[$code],
                'granted_by_user_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** @return array<string,KycDocument> */
    private function uploadTierTwo(KycDocumentService $svc, User $user): array
    {
        return [
            KycDocument::TYPE_ID_FRONT => $svc->upload($user, KycDocument::TYPE_ID_FRONT, $this->image('front.jpg')),
            KycDocument::TYPE_ID_BACK => $svc->upload($user, KycDocument::TYPE_ID_BACK, $this->image('back.jpg')),
            KycDocument::TYPE_SELFIE => $svc->upload($user, KycDocument::TYPE_SELFIE, $this->image('selfie.jpg')),
        ];
    }

    public function test_container_uses_the_guarded_kyc_service(): void
    {
        $this->assertInstanceOf(GuardedKycDocumentService::class, app(KycDocumentService::class));
    }

    public function test_complete_documents_without_confirmed_identity_owner_roll_back_final_approval(): void
    {
        $svc = app(KycDocumentService::class);
        $user = $this->customer();
        $reviewer = $this->reviewer();
        $docs = $this->uploadTierTwo($svc, $user);

        foreach ($docs as $doc) {
            $svc->approve($doc, $reviewer);
        }

        try {
            $svc->decideAccountVerification($user, $reviewer, true);
            $this->fail('اكتملت الصور فاعتمد الحساب بلا إثبات ملكية الهوية.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('KYC_OWNERSHIP_NOT_PROVEN', $e->getMessage());
        }

        $this->assertNotSame(1, (int) $user->fresh()->is_kyc_verified,
            'فشل حارس الملكية بعد اعتماد الأب ولم تُرجع المعاملة قرار الحساب.');
    }

    public function test_reviewer_confirmed_national_id_plus_selfie_allows_final_approval(): void
    {
        $svc = app(KycDocumentService::class);
        $user = $this->customer();
        $reviewer = $this->reviewer();
        $docs = $this->uploadTierTwo($svc, $user);

        app(KycOcrService::class)->confirmFields($docs[KycDocument::TYPE_ID_FRONT], $reviewer, [
            'national_id' => '٠١٢٣٤٥٦٧٨٩٠',
            'full_name' => trim($user->f_name.' '.$user->l_name),
        ]);

        foreach ($docs as $doc) {
            $svc->approve($doc->fresh(), $reviewer);
        }

        $verified = $svc->decideAccountVerification($user->fresh(), $reviewer, true);

        $this->assertSame(1, (int) $verified->is_kyc_verified);
        $this->assertSame('01234567890', preg_replace('/\D/', '', (string) $verified->identification_number));
        $this->assertNotEmpty($verified->national_id_blind_index ?? null,
            'إقرار رقم الهوية لم يصل إلى فهرس البحث المشفّر للحساب.');
    }

    public function test_restricted_case_is_removed_from_general_queue_and_requires_restricted_decider(): void
    {
        $svc = app(KycDocumentService::class);
        $this->assertInstanceOf(GuardedKycDocumentService::class, $svc);
        /** @var GuardedKycDocumentService $svc */
        $user = $this->customer();
        $normal = $this->reviewer();
        $restricted = $this->reviewer();
        $this->grant($restricted, [
            'platform.customers.kyc.restricted.view',
            'platform.customers.kyc.restricted.decide',
        ]);

        app(KycPrivacyService::class)->choose($user, KycPrivacyService::MODE_RESTRICTED);
        $doc = $svc->upload($user, KycDocument::TYPE_ID_FRONT, $this->image('private-front.jpg'));

        $this->assertEmpty(collect($svc->pendingQueue())->where('user_id', $user->id));
        $this->assertNotEmpty(collect($svc->restrictedPendingQueue($restricted))->where('user_id', $user->id));

        try {
            $svc->approve($doc, $normal);
            $this->fail('موظف عام اعتمد مستنداً من طابور الخصوصية.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('KYC_RESTRICTED_DECIDE_REQUIRED', $e->getMessage());
        }

        $approved = $svc->approve($doc->fresh(), $restricted);
        $this->assertSame(KycDocument::STATUS_APPROVED, $approved->status);
    }

    public function test_in_person_proof_cannot_be_recorded_without_restricted_decision_permission(): void
    {
        $privacy = app(KycPrivacyService::class);
        $user = $this->customer();
        $normal = $this->reviewer();
        $restricted = $this->reviewer();
        $this->grant($restricted, ['platform.customers.kyc.restricted.decide']);

        $privacy->choose($user, KycPrivacyService::MODE_IN_PERSON);

        try {
            $privacy->verifyInPerson($user, $normal, 'مرجع مقابلة حضورية 100');
            $this->fail('استدعاء الخدمة مباشرة تجاوز صلاحية التحقق الحضوري.');
        } catch (DomainException $e) {
            $this->assertSame('KYC_RESTRICTED_DECIDE_REQUIRED', $e->getMessage());
        }

        $state = $privacy->verifyInPerson($user, $restricted, 'مرجع مقابلة حضورية 100');
        $this->assertSame('in_person_verified', $state['ownership_method']);
        $this->assertSame('verified', $state['status']);
        $this->assertNotEmpty($state['reviewed_at']);
        $this->assertNotSame(1, (int) $user->fresh()->is_kyc_verified,
            'إثبات المقابلة غيّر حالة الحساب النهائية بدل أن يبقى دليلاً فقط.');
    }
}
