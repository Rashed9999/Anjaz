<?php

namespace Tests\Feature;

use App\Models\RegistrationDossier;
use App\Models\User;
use App\Services\RegistrationDossierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** التسجيل المساعد يُسرّع الإدخال، ولا يملك طريقاً جانبياً حول OTP أو KYC. */
class RegistrationDossierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('business_settings')->updateOrInsert(
            ['key' => 'phone_verification'],
            ['value' => 0, 'created_at' => now(), 'updated_at' => now()],
        );
    }

    /** @test */
    public function staff_dossier_prefills_then_links_only_after_the_normal_registration(): void
    {
        $staff = User::factory()->create(['type' => ADMIN_TYPE]);
        $dossier = app(RegistrationDossierService::class)->create(
            $staff, 'customer', 'staff_assisted', '967771700001', [
                'full_name' => 'مها سالم', 'gender' => 'female', 'phone' => '771700001',
                'identification_number' => 'ID-701', 'identification_type' => 'nid',
                'address' => 'عدن',
            ],
        );

        $this->assertSame(RegistrationDossier::AWAITING_CONFIRMATION, $dossier->state);
        $this->assertDatabaseMissing('registration_dossiers', ['payload_encrypted' => 'مها سالم']);

        $this->postJson('/api/v1/customer/auth/register', [
            'dial_country_code' => '+967', 'phone' => '771700001', 'password' => '1234',
        ])->assertOk();

        $customer = User::where('phone', '967771700001')->firstOrFail();
        $dossier->refresh();
        $this->assertSame($customer->id, $dossier->subject_user_id);
        $this->assertSame(RegistrationDossier::SUBMITTED, $dossier->state);
        $this->assertSame('مها', $customer->f_name);
        $this->assertSame('سالم', $customer->l_name);
        $this->assertSame('ID-701', $customer->identification_number);
        $this->assertSame(0, (int) $customer->is_kyc_verified, 'ربط الملف لا يعتمد KYC');
    }

    /** @test */
    public function merchant_dossier_keeps_merchant_identity_separate_from_owner_name(): void
    {
        $staff = User::factory()->create(['type' => ADMIN_TYPE]);
        $dossier = app(RegistrationDossierService::class)->create(
            $staff, 'merchant', 'staff_assisted', '967771700002', [
                'full_name' => 'مالك المنشأة', 'gender' => 'male',
                'business_name' => 'متجر أميال', 'business_type' => 'retail',
            ],
        );

        $this->postJson('/api/v1/customer/auth/register', [
            'account_type' => 'merchant', 'dial_country_code' => '+967',
            'phone' => '771700002', 'password' => '1234',
        ])->assertOk();

        $merchant = User::where('phone', '967771700002')->firstOrFail();
        $this->assertSame('مالك', $merchant->f_name);
        $this->assertSame('متجر أميال', \App\Models\Merchant::where('user_id', $merchant->id)->value('store_name'));
        $this->assertSame(RegistrationDossier::SUBMITTED, $dossier->fresh()->state);
    }

    /** @test */
    public function self_service_registration_has_its_own_printable_archive_record(): void
    {
        $this->postJson('/api/v1/customer/auth/register', [
            'f_name' => 'تسجيل', 'l_name' => 'ذاتي', 'gender' => 'male',
            'dial_country_code' => '+967', 'phone' => '771700003', 'password' => '1234',
        ])->assertOk();

        $this->assertDatabaseHas('registration_dossiers', [
            'subject_type' => 'customer', 'source' => 'self_service',
            'state' => RegistrationDossier::SUBMITTED,
        ]);
    }

    /** @test */
    public function assisted_opening_archives_one_immutable_snapshot_linked_to_the_account(): void
    {
        $staff = User::factory()->create(['type' => ADMIN_TYPE]);
        $customer = User::factory()->create(['type' => CUSTOMER_TYPE]);

        $dossier = app(RegistrationDossierService::class)->archiveAssistedRegistration(
            $staff, $customer, 'customer', '967771700004', [
                'schema_version' => 'opening-dossier-v1', 'full_name' => 'سارة أحمد',
                'identification_number' => 'ID-704', 'declaration_accepted' => '1',
            ],
        );

        $this->assertSame(RegistrationDossier::SUBMITTED, $dossier->state);
        $this->assertSame($customer->id, $dossier->subject_user_id);
        $this->assertSame('staff_assisted', $dossier->source);
        $this->assertSame('opening-dossier-v1', $dossier->payload_encrypted['schema_version']);
        $this->assertDatabaseMissing('registration_dossiers', ['payload_encrypted' => 'سارة أحمد']);
    }
}
