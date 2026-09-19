<?php

namespace Tests\Feature;

use App\Models\KycDocument;
use App\Models\User;
use App\Services\Kyc\LegalNameService;
use App\Services\Kyc\ProfileChangeRequestService;
use App\Services\KycDocumentService;
use App\Services\KycOcrService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CustomerLegalNameLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): User
    {
        $user = User::factory()->create([
            'type' => 2,
            'f_name' => 'محمد',
            'l_name' => 'القحطاني',
        ]);

        app(LegalNameService::class)->registerDeclaredName(
            $user,
            'محمد',
            'أحمد',
            'علي',
            'القحطاني',
        );

        return $user->fresh();
    }

    private function reviewer(): User
    {
        return User::factory()->create([
            'type' => 0,
            'role' => 'super_admin',
        ]);
    }

    public function test_declared_name_is_four_part_and_history_is_encrypted(): void
    {
        $customer = $this->customer();

        $this->assertSame('محمد أحمد علي القحطاني', $customer->declared_legal_name);
        $this->assertSame('declared', $customer->legal_name_status);

        $event = DB::table('legal_name_events')
            ->where('user_id', $customer->id)
            ->where('event_type', 'DECLARED_AT_REGISTRATION')
            ->latest('id')
            ->first();

        $this->assertNotNull($event);
        $this->assertStringNotContainsString(
            'محمد أحمد علي القحطاني',
            (string) $event->new_name_encrypted,
            'تاريخ الاسم مخزن بنص صريح.'
        );
    }

    public function test_residence_name_match_is_required_before_tier_one_approval(): void
    {
        $customer = $this->customer();
        $reviewer = $this->reviewer();

        $verificationId = DB::table('residence_verifications')->insertGetId([
            'user_id' => $customer->id,
            'kyc_document_id' => null,
            'declared_governorate' => 'YE-AD',
            'evidence_type' => 'government_residence_document',
            'evidence_strength' => 'strong',
            'status' => 'pending',
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(LegalNameService::class)->assertResidenceNameReady($verificationId);
            $this->fail('إثبات السكن أصبح جاهزاً بلا اسم مؤكد من المستند.');
        } catch (DomainException $e) {
            $this->assertSame('RESIDENCE_DOCUMENT_NAME_REQUIRED', $e->getMessage());
        }

        $comparison = app(LegalNameService::class)->confirmResidenceDocumentName(
            $verificationId,
            $reviewer,
            'محمد أحمد علي القحطاني',
        );

        $this->assertSame('exact', $comparison['status']);
        app(LegalNameService::class)->assertResidenceNameReady($verificationId);
        $this->assertTrue(true);
    }

    public function test_fake_registration_name_cannot_pass_different_residence_proof(): void
    {
        $customer = $this->customer();
        $reviewer = $this->reviewer();

        $customer->forceFill([
            'f_name' => 'النور',
            'father_name' => 'الساطع',
            'grandfather_name' => 'اسم',
            'family_name' => 'مستعار',
            'l_name' => 'مستعار',
            'declared_legal_name' => 'النور الساطع اسم مستعار',
        ])->save();

        $verificationId = DB::table('residence_verifications')->insertGetId([
            'user_id' => $customer->id,
            'kyc_document_id' => null,
            'declared_governorate' => 'YE-AD',
            'evidence_type' => 'government_residence_document',
            'evidence_strength' => 'strong',
            'status' => 'pending',
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $comparison = app(LegalNameService::class)->confirmResidenceDocumentName(
            $verificationId,
            $reviewer,
            'محمد أحمد علي القحطاني',
        );

        $this->assertSame('mismatch', $comparison['status']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('RESIDENCE_NAME_MISMATCH');
        app(LegalNameService::class)->assertResidenceNameReady($verificationId);
    }

    public function test_partial_residence_match_requires_reviewer_note(): void
    {
        $customer = $this->customer();
        $reviewer = $this->reviewer();

        $verificationId = DB::table('residence_verifications')->insertGetId([
            'user_id' => $customer->id,
            'kyc_document_id' => null,
            'declared_governorate' => 'YE-AD',
            'evidence_type' => 'government_residence_document',
            'evidence_strength' => 'strong',
            'status' => 'pending',
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('LEGAL_NAME_PARTIAL_MATCH_REQUIRES_NOTE');

        app(LegalNameService::class)->confirmResidenceDocumentName(
            $verificationId,
            $reviewer,
            'محمد أحمد القحطاني',
        );
    }

    public function test_identity_name_mismatch_blocks_verified_legal_name(): void
    {
        $customer = $this->customer();
        $reviewer = $this->reviewer();

        $doc = app(KycDocumentService::class)->upload(
            $customer,
            KycDocument::TYPE_ID_FRONT,
            UploadedFile::fake()->image('id-front.jpg', 760, 520),
        );

        app(KycOcrService::class)->confirmFields($doc, $reviewer, [
            'national_id' => '01234567890',
            'full_name' => 'سالم عبدالله صالح باوزير',
        ]);
        app(KycDocumentService::class)->approve($doc->fresh(), $reviewer);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('IDENTITY_NAME_MISMATCH');

        app(LegalNameService::class)->assertIdentityNameReady($customer->fresh());
    }

    public function test_approved_identity_name_becomes_locked_verified_legal_name(): void
    {
        $customer = $this->customer();
        $reviewer = $this->reviewer();

        $doc = app(KycDocumentService::class)->upload(
            $customer,
            KycDocument::TYPE_ID_FRONT,
            UploadedFile::fake()->image('id-front.jpg', 760, 520),
        );

        app(KycOcrService::class)->confirmFields($doc, $reviewer, [
            'national_id' => '01234567890',
            'full_name' => 'محمد أحمد علي القحطاني',
        ]);
        app(KycDocumentService::class)->approve($doc->fresh(), $reviewer);

        $name = app(LegalNameService::class)
            ->verifyIdentityName($customer->fresh(), $reviewer);

        $customer->refresh();
        $this->assertSame('محمد أحمد علي القحطاني', $name);
        $this->assertSame($name, $customer->verified_legal_name);
        $this->assertSame('identity_verified', $customer->legal_name_status);
        $this->assertNotNull($customer->legal_name_verified_at);
        $this->assertNotNull($customer->legal_name_locked_at);
    }

    public function test_approved_legal_name_change_rebuilds_declared_name_and_redacts_audit_values(): void
    {
        $customer = $this->customer();
        $customer->forceFill([
            'verified_legal_name' => 'محمد أحمد علي القحطاني',
            'legal_name_status' => 'identity_verified',
            'kyc_tier' => 2,
            'is_kyc_verified' => 1,
        ])->save();

        $reviewer = $this->reviewer();
        $supporting = KycDocument::query()->create([
            'user_id' => $customer->id,
            'doc_type' => KycDocument::TYPE_ID_FRONT,
            'status' => KycDocument::STATUS_APPROVED,
            'encrypted_path' => 'kyc/test/legal-name-change.enc',
            'original_mime' => 'image/jpeg',
            'size_bytes' => 2048,
            'content_sha256' => hash('sha256', 'legal-name-change-'.$customer->id),
            'reviewed_at' => now(),
        ]);

        $svc = app(ProfileChangeRequestService::class);
        $requestId = $svc->open(
            $customer,
            'family_name',
            $customer->id,
            'customer',
            'تصحيح اللقب وفق الهوية الجديدة',
        );

        $svc->submit($requestId, $customer, 'الحضرمي', $supporting->id);
        $svc->decide($requestId, $reviewer, true, 'تمت مراجعة المستند الداعم');

        $customer->refresh();
        $this->assertSame('الحضرمي', $customer->family_name);
        $this->assertSame('الحضرمي', $customer->l_name);
        $this->assertSame('محمد أحمد علي الحضرمي', $customer->declared_legal_name);
        $this->assertSame('change_pending_reverification', $customer->legal_name_status);
        $this->assertTrue((bool) $customer->kyc_update_required);

        $audit = DB::table('audit_decisions')
            ->where('action', 'PROFILE_CHANGE_DECIDED')
            ->where('subject_id', (string) $customer->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($audit);
        $context = json_decode((string) $audit->context, true) ?: [];
        $this->assertTrue((bool) ($context['values_redacted'] ?? false));
        $this->assertNull($context['old_value'] ?? null);
        $this->assertNull($context['new_value'] ?? null);
        $this->assertStringNotContainsString('الحضرمي', (string) $audit->context);
    }

    public function test_registration_sources_require_explicit_four_name_parts(): void
    {
        $quick = (string) file_get_contents(
            app_path('Http/Controllers/Api/V1/Auth/ProgressiveRegistrationController.php')
        );
        $legacy = (string) file_get_contents(
            app_path('Http/Controllers/Api/V1/RegisterController.php')
        );
        $flutter = (string) file_get_contents(
            dirname(base_path()) . '/02_flutter_app/lib/features/auth/screens/quick_registration_screen.dart'
        );

        foreach (['given_name', 'father_name', 'grandfather_name', 'family_name'] as $field) {
            $this->assertStringContainsString("'{$field}'", $quick);
        }
        foreach (['f_name', 'father_name', 'grandfather_name', 'family_name'] as $field) {
            $this->assertStringContainsString("'{$field}'", $legacy);
        }

        $this->assertStringContainsString('_givenName', $flutter);
        $this->assertStringContainsString('_fatherName', $flutter);
        $this->assertStringContainsString('_grandfatherName', $flutter);
        $this->assertStringContainsString('_familyName', $flutter);
        $this->assertStringContainsString('اكتب اسمك الحقيقي', $flutter);
    }
}
