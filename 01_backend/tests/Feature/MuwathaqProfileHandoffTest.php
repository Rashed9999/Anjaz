<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Muwathaq\MuwathaqProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AMIAL-MUWATHAQ-PROFILE-HANDOFF-001 — ملفُّ «اعرف عميلك» المصرَّح به يصل أميال باي مع التهيئة.
 *
 * **ما ظهر في لوحة الإدارة:** حسابٌ فتحه موثّق عرض «٧ حقول ناقصة» (الاسم الإنجليزي، المديرية، مصدر الدخل، الغرض…) مع أنّ
 * موثّق جمعها كلّها — لأنّه كان يُرسل الهويّة وحدَها. ولا فحصَ عقوباتٍ بلا اسمٍ إنجليزي.
 */
class MuwathaqProfileHandoffTest extends TestCase
{
    use RefreshDatabase;

    private function provision(array $profile): User
    {
        Queue::fake();
        $ulid = (string) Str::ulid();
        $event = [
            'event_version' => 1, 'event_type' => 'account.approved', 'application_ulid' => $ulid,
            'decision_reference' => 'MWT-' . strtoupper(substr(md5($ulid), 0, 12)),
            'subject_kind' => 'customer', 'verification_path' => 'standard_customer', 'business_type' => null, 'requested_tier' => null,
            'applicant' => ['f_name' => 'سالم', 'l_name' => 'باوزير', 'phone' => '+9677' . random_int(10000000, 99999999), 'dial_country_code' => '+967',
                'email' => 'h-' . strtolower(Str::random(8)) . '@example.test', 'gender' => 'male'],
            'subject' => [],
            'decision_context' => ['policy_version' => 'standard-customer-v1', 'verified_phone' => true, 'verified_residence_governorate' => 'YE-AD', 'verified_identity' => true],
            'profile' => $profile,
        ];

        return User::query()->findOrFail((int) app(MuwathaqProvisioningService::class)->provisionApproved($event)['user_id']);
    }

    /** @test */
    public function the_declared_profile_reaches_the_amial_kyc_file(): void
    {
        $u = $this->provision([
            'name_en' => "Salem Ahmed Ali Ba-Wazir", 'occupation' => 'مهندس', 'income_source' => 'freelance', 'account_purpose' => 'daily',
            'residence_district' => 'كريتر', 'residence_area' => 'الحي الأول', 'birth_date' => '1992-02-03',
        ]);

        $this->assertSame('Salem Ahmed Ali Ba-Wazir', $u->name_en);
        $this->assertSame('مهندس', $u->occupation);
        $this->assertSame('business', $u->income_source, 'العمل الحرّ يُطابق «نشاط تجاري» في قائمة أميال باي');
        $this->assertSame('payments', $u->account_purpose, 'مدفوعات يومية ⇒ payments');
        $this->assertSame('كريتر', $u->residence_district);
        $this->assertSame('1992-02-03', substr((string) $u->date_of_birth, 0, 10));
    }

    /** @test */
    public function the_fields_that_used_to_be_reported_missing_no_longer_are(): void
    {
        $u = $this->provision(['name_en' => 'Salem Ba-Wazir', 'occupation' => 'مهندس', 'income_source' => 'salary', 'account_purpose' => 'savings', 'residence_district' => 'كريتر']);

        $missing = implode(' | ', \App\Support\Kyc\KycProfileFields::missingFor($u->fresh()));

        $this->assertStringNotContainsString('الاسم بالإنجليزيّة', $missing, 'الاسم الإنجليزي ما زال «ناقصاً»');
        $this->assertStringNotContainsString('مصدر الدخل', $missing);
    }

    /** @test */
    public function bad_or_unknown_values_are_dropped_not_guessed_and_never_break_provisioning(): void
    {
        $u = $this->provision(['name_en' => 'سالم', 'income_source' => 'lottery', 'account_purpose' => '???', 'birth_date' => 'غدا', 'residence_district' => str_repeat('م', 500)]);

        $this->assertNotNull($u->id, 'فشلت التهيئة بسبب ملفٍّ مكمِّل');
        $this->assertEmpty($u->name_en, 'اسمٌ غير لاتينيّ كُتب في حقل الاسم الإنجليزي');
        $this->assertEmpty($u->income_source, 'مصدرُ دخلٍ مجهولٌ خُمِّن');
        $this->assertEmpty($u->account_purpose);
        $this->assertLessThanOrEqual(80, mb_strlen((string) $u->residence_district), 'قيمةٌ بلا سقف');
    }

    /** @test */
    public function an_event_without_a_profile_provisions_exactly_as_before(): void
    {
        $u = $this->provision([]);

        $this->assertNotNull($u->id);
        $this->assertEmpty($u->name_en);
    }
}
