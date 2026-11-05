<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\MuwathaqAccountOpeningApplication;
use App\Models\User;
use App\Services\Muwathaq\MuwathaqAccountOpeningService;
use App\Services\Muwathaq\MuwathaqOpeningProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CustomerOpeningResumeTest extends TestCase
{
    use RefreshDatabase;

    private function applicant(string $gender, string $email): array
    {
        $customer = User::factory()->create([
            'type' => CUSTOMER_TYPE,
            'gender' => $gender,
            'email' => $email,
            'email_canonical' => $email,
            'is_email_verified' => 1,
            'is_active' => 0,
            'password' => Hash::make('ResumePassword9!'),
        ]);
        $application = app(MuwathaqAccountOpeningService::class)->submitFor($customer);
        return [$customer, $application];
    }

    /** @test */
    public function both_genders_can_recover_the_original_application_after_expiry_without_a_wallet(): void
    {
        foreach (['female' => 'women_progressive', 'male' => 'standard_customer'] as $gender => $path) {
            $email = 'customer-'.$gender.'@example.test';
            [$customer, $application] = $this->applicant($gender, $email);
            $progress = app(MuwathaqOpeningProgressService::class);
            $oldToken = $progress->issueAccessToken($application);
            $application->forceFill(['opening_access_expires_at' => now()->subMinute()])->save();

            $data = $this->postJson('/api/v1/auth/account-opening/resume', [
                'email' => $email,
                'password' => 'ResumePassword9!',
            ])->assertOk()
                ->assertJsonPath('data.application_ulid', $application->application_ulid)
                ->assertJsonPath('data.verification_path', $path)
                ->json('data');

            $this->assertNotEmpty($data['account_opening_access_token'] ?? null);
            $this->assertNotSame($oldToken, $data['account_opening_access_token']);
            $url = '/api/v1/auth/account-opening/'.$application->application_ulid.'/status';
            $this->getJson($url, ['Authorization' => 'Bearer '.$oldToken])
                ->assertForbidden();
            $this->getJson($url, [
                'Authorization' => 'Bearer '.$data['account_opening_access_token'],
            ])->assertOk()->assertJsonPath('data.status', 'submitted');

            $this->assertFalse(EMoney::where('user_id', $customer->id)->exists());
            $this->assertDatabaseCount('muwathaq_account_opening_applications',
                $gender === 'female' ? 1 : 2);
        }
    }

    /** @test */
    public function wrong_password_or_unverified_email_never_grants_an_opening_token(): void
    {
        [$customer] = $this->applicant('male', 'resume-guards@example.test');

        $this->postJson('/api/v1/auth/account-opening/resume', [
            'email' => 'resume-guards@example.test',
            'password' => 'invalidPassword',
        ])->assertForbidden()->assertJsonPath('success', false);

        $customer->forceFill(['is_email_verified' => 0])->save();
        $this->postJson('/api/v1/auth/account-opening/resume', [
            'email' => 'resume-guards@example.test',
            'password' => 'ResumePassword9!',
        ])->assertForbidden()->assertJsonPath('success', false);
    }

    /** @test */
    public function a_final_case_cannot_reopen_evidence_collection(): void
    {
        [, $application] = $this->applicant('female', 'final-customer@example.test');
        $application->forceFill([
            'status' => MuwathaqAccountOpeningApplication::STATUS_AWAITING_ADMIN_APPROVAL,
        ])->save();

        $data = $this->postJson('/api/v1/auth/account-opening/resume', [
            'email' => 'final-customer@example.test',
            'password' => 'ResumePassword9!',
        ])->assertOk()
            ->assertJsonPath('data.status', 'awaiting_admin_approval')
            ->json('data');

        $this->assertArrayNotHasKey('account_opening_access_token', $data);
    }
}
