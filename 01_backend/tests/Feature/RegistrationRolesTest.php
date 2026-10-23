<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Account creation belongs to Muwathaq. Amial Pay may consume an approved,
 * signed provisioning event, but public and legacy API endpoints must never
 * create a customer, merchant, or agent directly.
 */
class RegistrationRolesTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function legacy_account_creation_is_closed_for_every_account_type(): void
    {
        foreach (['customer', 'merchant', 'agent'] as $type) {
            $phone = '7715' . random_int(10000, 99999);
            $response = $this->postJson('/api/v1/customer/auth/register', [
                'account_type' => $type,
                'f_name' => 'اختبار',
                'l_name' => 'موثق',
                'phone' => $phone,
                'email' => $type . '-' . $phone . '@example.test',
                'password' => 'not-used-by-amial',
            ]);

            $response->assertStatus(410)
                ->assertJsonPath('code', 'ACCOUNT_CREATION_OWNED_BY_MUWATHAQ');

            $this->assertDatabaseMissing('users', ['phone' => $phone]);
        }
    }
}
