<?php

namespace Tests\Feature;

use App\Models\KycDocument;
use App\Models\User;
use App\Services\KycDocumentService;
use App\Support\Kyc\KycProfileFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-KYC-INTL-003 — حقول اعرف عميلك ومكان إلزامها.
 *
 * التسجيل الأساسي لا يحمل KYC الكامل بعد اعتماد Progressive KYC.
 * الحقول الرقابية تُحفظ إن وصلت، ولا تصبح شرطاً لفتح المحفظة الأساسية
 * أو Tier 2؛ موضع إلزامها هو التوثيق الكامل Tier 3.
 */
class KycRegulatoryFieldsTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $extra = []): array
    {
        return array_merge([
            'f_name' => 'راشد',
            'l_name' => 'محمد عوض معرابي',
            'gender' => 'male',
            'dial_country_code' => '+967',
            'phone' => '783545525',
            'password' => '4321',
            'email' => 'registration-' . bin2hex(random_bytes(8)) . '@example.test',
        ], $extra);
    }

    private function register(array $extra = []): User
    {
        DB::table('business_settings')->updateOrInsert(
            ['key' => 'phone_verification'],
            ['value' => 0, 'created_at' => now(), 'updated_at' => now()],
        );

        $this->postJson('/api/v1/customer/auth/register', $this->payload($extra))
            ->assertSuccessful();

        return User::where('phone', 'like', '%783545525')->firstOrFail();
    }

    /** @test */
    public function every_field_the_api_accepts_is_actually_stored(): void
    {
        $sent = [
            'name_en' => 'RASHED MOHAMMED AWADH MARABE',
            'father_name' => 'محمد',
            'grandfather_name' => 'عوض',
            'country_of_birth' => 'اليمن',
            'dual_nationality' => 'لا',
            'id_place_of_issue' => 'المهرة',
            'marital_status' => 'single',
            'residence_district' => 'سيحوت',
            'residence_area' => '14 أكتوبر',
            'residence_landmark' => 'قرب المسجد',
            'housing_type' => 'owned',
            'employer_name' => 'صياد',
            'job_title' => 'عامل حرّ',
            'work_address' => 'سيحوت',
            'income_source' => 'business',
            'account_purpose' => 'savings',
            'kin2_name' => 'يوسف محمد عوض معرابي',
            'kin2_phone' => '777777777',
            'kin2_relation' => 'أخي',
        ];

        $user = $this->register($sent);

        foreach ($sent as $field => $value) {
            $this->assertSame(
                $value,
                (string) $user->{$field},
                "الحقل «{$field}» وصل للـAPI ولم يُحفظ",
            );
        }

        $this->assertEqualsCanonicalizing(
            KycProfileFields::TEXT_FIELDS,
            array_keys($sent),
            'جرد حقول KYC واختبار الحفظ افترقا',
        );
    }

    /** @test */
    public function income_is_stored_as_a_number_with_its_currency(): void
    {
        $user = $this->register([
            'monthly_income' => '100000',
            'monthly_income_currency' => 'YER',
        ]);

        $this->assertSame(100000.0, (float) $user->monthly_income);
        $this->assertSame('YER', $user->monthly_income_currency);
    }

    /** @test */
    public function a_field_not_sent_never_overwrites_one_already_stored(): void
    {
        $u = User::factory()->create(['type' => 2]);
        $u->forceFill([
            'name_en' => 'OLD NAME',
            'residence_district' => 'سيحوت',
        ])->save();

        $req = \Illuminate\Http\Request::create('/', 'POST', [
            'job_title' => 'مهندس',
        ]);
        KycProfileFields::fill($u, $req);
        $u->save();

        $this->assertSame('OLD NAME', $u->refresh()->name_en);
        $this->assertSame('مهندس', $u->job_title);
    }

    /** @test */
    public function pep_is_null_when_never_asked_not_false(): void
    {
        $user = $this->register();
        $this->assertNull($user->is_pep);
    }

    /** @test */
    public function pep_denied_and_pep_declared_are_told_apart(): void
    {
        $denied = $this->register(['is_pep' => '0']);
        $firstEmail = $denied->email;

        $this->assertFalse((bool) $denied->is_pep);
        $this->assertNotNull($denied->is_pep);

        $this->postJson('/api/v1/customer/auth/register', $this->payload([
            'phone' => '783545526',
            'is_pep' => '1',
            'pep_position' => 'وكيل وزارة',
        ]))->assertSuccessful();

        $declared = User::where('phone', 'like', '%783545526')->firstOrFail();
        $this->assertTrue((bool) $declared->is_pep);
        $this->assertSame('وكيل وزارة', $declared->pep_position);
        $this->assertNotSame($denied->id, $declared->id);
        $this->assertSame($firstEmail, $denied->fresh()->email);
        $this->assertFalse((bool) $denied->fresh()->is_pep);

        foreach ([$denied, $declared] as $registrant) {
            $this->assertSame(
                1,
                \App\Models\EMoney::where('user_id', $registrant->id)->count(),
            );
        }
    }

    /** @test */
    public function a_declared_pep_without_a_position_is_flagged_incomplete(): void
    {
        $u = User::factory()->create(['type' => 2]);
        $u->forceFill([
            'is_pep' => true,
            'pep_position' => null,
            'name_en' => 'X',
            'father_name' => 'أ',
            'grandfather_name' => 'ب',
            'residence_district' => 'ج',
            'income_source' => 'salary',
            'account_purpose' => 'savings',
        ])->save();

        $this->assertContains(
            'المنصب السياسيّ المُقرّ به',
            KycProfileFields::missingFor($u->refresh()),
        );
    }

    /** @test */
    public function never_being_asked_about_pep_is_itself_reported_as_missing(): void
    {
        $u = User::factory()->create(['type' => 2]);

        $this->assertContains(
            'الإفصاح عن المنصب السياسيّ (لم يُسأل بعد)',
            KycProfileFields::missingFor($u),
        );
    }

    /** @test */
    public function registration_still_succeeds_without_full_kyc_fields(): void
    {
        $this->assertNotNull($this->register()->id);
    }

    /** @test */
    public function tier_three_exposes_missing_regulatory_fields(): void
    {
        $completeness = app(KycDocumentService::class)
            ->completenessFor(User::factory()->create(['type' => 2]), 3);

        $this->assertNotEmpty($completeness['missing_fields']);
        $this->assertFalse($completeness['complete']);
    }

    /** @test */
    public function tier_two_requires_identity_document_sides_not_selfie_or_full_profile(): void
    {
        $completeness = app(KycDocumentService::class)
            ->completenessFor(User::factory()->create(['type' => 2]), 2);

        $this->assertSame([
            KycDocument::TYPE_ID_FRONT,
            KycDocument::TYPE_ID_BACK,
        ], $completeness['required']);
        $this->assertNotContains(KycDocument::TYPE_SELFIE, $completeness['required']);
        $this->assertNotEmpty($completeness['missing_fields'],
            'يمكن عرض نواقص Tier 3 مبكراً، لكن لا تُدخل في متطلبات Tier 2');
    }

    /** @test */
    public function the_refusal_names_the_missing_field_not_just_the_file(): void
    {
        $src = file_get_contents(app_path('Services/KycDocumentService.php'));
        $at = strpos($src, "'KYC_PROFILE_INCOMPLETE'");
        $this->assertNotFalse($at);

        $open = strrpos(substr($src, 0, $at), '(');
        $depth = 0;
        $end = $open;
        for ($i = $open; $i < strlen($src); $i++) {
            if ($src[$i] === '(') $depth++;
            if ($src[$i] === ')') {
                $depth--;
                if ($depth === 0) {
                    $end = $i;
                    break;
                }
            }
        }

        $call = substr($src, $open, $end - $open + 1);
        $this->assertStringContainsString('missing_fields', $call);
        $this->assertStringContainsString('KYC_DOCUMENTS_INCOMPLETE', $src);
    }

    /** @test */
    public function an_unknown_income_source_is_refused_not_stored_raw(): void
    {
        DB::table('business_settings')->updateOrInsert(
            ['key' => 'phone_verification'],
            ['value' => 0, 'created_at' => now(), 'updated_at' => now()],
        );

        $this->postJson(
            '/api/v1/customer/auth/register',
            $this->payload(['income_source' => 'من عند الله']),
        )->assertStatus(403);
    }

    /** @test */
    public function a_latin_name_field_refuses_arabic(): void
    {
        DB::table('business_settings')->updateOrInsert(
            ['key' => 'phone_verification'],
            ['value' => 0, 'created_at' => now(), 'updated_at' => now()],
        );

        $this->postJson(
            '/api/v1/customer/auth/register',
            $this->payload(['name_en' => 'راشد معرابي']),
        )->assertStatus(403);
    }

    /** @test */
    public function complete_my_account_has_inputs_for_every_required_tier_three_field(): void
    {
        $screen = base_path(
            '../02_flutter_app/lib/features/kyc_verification/screens/complete_my_account_screen.dart',
        );
        if (! is_file($screen)) {
            $this->markTestSkipped('شاشة إكمال الحساب غير موجودة في هذه البيئة');
        }

        $src = file_get_contents($screen);

        foreach (['_nameEn', '_fatherName', '_grandfatherName', '_district', '_pepPosition'] as $field) {
            $this->assertStringContainsString($field, $src,
                "الحقل {$field} مطلوب في Tier 3 ولا مدخل له في إكمال الحساب");
        }

        foreach (['income_source', 'account_purpose', 'is_pep'] as $code) {
            $this->assertStringContainsString("missing.contains('{$code}')", $src,
                "الحقل {$code} لا يُعرض عند نقصه");
        }

        $this->assertStringContainsString('emptySelectionAllowed: true', $src);
        $this->assertStringContainsString('profile?.type != 2', $src,
            'إكمال KYC الفردي يجب أن يبقى خاصاً بالعميل لا التاجر/الوكيل/الموظف');
    }

    /** @test */
    public function complete_my_account_sends_structured_kyc_fields_and_separates_residence_proof(): void
    {
        $screen = base_path(
            '../02_flutter_app/lib/features/kyc_verification/screens/complete_my_account_screen.dart',
        );
        if (! is_file($screen)) {
            $this->markTestSkipped('شاشة إكمال الحساب غير موجودة في هذه البيئة');
        }

        $src = file_get_contents($screen);

        foreach (['name_en', 'father_name', 'grandfather_name', 'residence_district'] as $key) {
            $this->assertStringContainsString("addText('{$key}'", $src,
                "{$key} لا يُرسل كحقل KYC مهيكل");
        }

        $this->assertStringContainsString("'residence_governorate': governorate", file_get_contents(
            base_path('../02_flutter_app/lib/features/kyc_verification/domain/reposotories/verification_center_repo.dart')
        ));
        $this->assertStringContainsString("'evidence_type': evidenceType", file_get_contents(
            base_path('../02_flutter_app/lib/features/kyc_verification/domain/reposotories/verification_center_repo.dart')
        ));
    }
}
