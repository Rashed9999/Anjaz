<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Phone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Capture of signature/evidence belongs to Muwathaq's application dossier.
 * Amial must not accept it through a legacy self-registration endpoint.
 */
class SignatureRegistrationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function legacy_registration_rejects_signature_and_creates_no_customer(): void
    {
        $phone = '771555001';

        $this->postJson('/api/v1/customer/auth/register', [
            'email' => 'legacy-signature@example.test',
            'f_name' => 'أحمد',
            'l_name' => 'سالم',
            'gender' => 'male',
            'dial_country_code' => '+967',
            'phone' => $phone,
            'password' => 'Passw0rd!123',
            'signature' => 'data:image/png;base64,ZmFrZQ==',
        ])
            ->assertStatus(410)
            ->assertJsonPath('code', 'ACCOUNT_CREATION_OWNED_BY_MUWATHAQ');

        $this->assertDatabaseMissing('users', ['phone' => Phone::canonical($phone, '+967')]);
    }
}
