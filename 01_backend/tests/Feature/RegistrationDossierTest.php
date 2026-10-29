<?php

namespace Tests\Feature;

use App\Models\RegistrationDossier;
use App\Models\User;
use App\Services\RegistrationDossierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ProvisionsAccountsFromMuwathaq;
use Tests\TestCase;

/**
 * Muwathaq owns account-opening dossiers.  Amial retains only an immutable
 * audit archive explicitly handed to it after an approved account exists.
 */
class RegistrationDossierTest extends TestCase
{
    use RefreshDatabase;
    use ProvisionsAccountsFromMuwathaq;

    /** @test */
    public function legacy_self_service_registration_cannot_create_or_link_a_dossier(): void
    {
        $phone = '771700001';

        $this->postJson('/api/v1/customer/auth/register', [
            'email' => 'legacy-dossier@example.test',
            'dial_country_code' => '+967',
            'phone' => $phone,
            'password' => 'Passw0rd!123',
        ])
            ->assertStatus(410)
            ->assertJsonPath('code', 'ACCOUNT_CREATION_OWNED_BY_MUWATHAQ');

        $this->assertDatabaseMissing('users', ['phone' => '967'.$phone]);
        $this->assertSame(0, RegistrationDossier::count());
    }

    /** @test */
    public function approved_muwathaq_account_can_have_one_encrypted_immutable_audit_archive(): void
    {
        $staff = User::factory()->create(['type' => ADMIN_TYPE]);
        $customer = $this->provisionCustomerFromMuwathaq('967771700004');

        $dossier = app(RegistrationDossierService::class)->archiveAssistedRegistration(
            $staff,
            $customer,
            'customer',
            '967771700004',
            [
                'schema_version' => 'muwathaq-approved-v1',
                'full_name' => 'سارة أحمد',
                'muwathaq_application' => 'approved',
            ],
        );

        $this->assertSame(RegistrationDossier::SUBMITTED, $dossier->state);
        $this->assertSame($customer->id, $dossier->subject_user_id);
        $this->assertSame('staff_assisted', $dossier->source);
        $this->assertSame('muwathaq-approved-v1', $dossier->payload_encrypted['schema_version']);
        $this->assertDatabaseMissing('registration_dossiers', ['payload_encrypted' => 'سارة أحمد']);
    }
}
