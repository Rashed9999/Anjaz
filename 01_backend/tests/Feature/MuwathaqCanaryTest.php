<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Muwathaq\MuwathaqCanary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** التفعيل التجريبي لموثّق: بمعرّف المستخدم أو برقم الحساب/المحفظة، ولا يتجاوز المسموح لهم أبداً. */
class MuwathaqCanaryTest extends TestCase
{
    use RefreshDatabase;

    private function customer(string $account): User
    {
        $u = User::factory()->create(['type' => CUSTOMER_TYPE]);
        $u->forceFill(['account_number' => $account])->save();

        return $u->fresh();
    }

    /** @test */
    public function a_canary_account_number_is_allowed_and_others_are_not(): void
    {
        $in = $this->customer('54855507');
        $out = $this->customer('10000009');
        config(['muwathaq.tier_upgrade_mode' => 'canary', 'muwathaq.tier_upgrade_user_ids' => [], 'muwathaq.tier_upgrade_accounts' => ['54855507']]);

        $this->assertTrue(MuwathaqCanary::allows('tier_upgrade', $in));
        $this->assertFalse(MuwathaqCanary::allows('tier_upgrade', $out));
        $this->assertFalse(MuwathaqCanary::allows('residence_review', $in), 'ميزة أخرى لم تُفعَّل لا تتأثر');
    }

    /** @test */
    public function a_canary_user_id_still_works_and_modes_off_and_on_are_absolute(): void
    {
        $u = $this->customer('54855507');
        config(['muwathaq.residence_review_mode' => 'canary', 'muwathaq.residence_review_user_ids' => [(int) $u->id], 'muwathaq.residence_review_accounts' => []]);
        $this->assertTrue(MuwathaqCanary::allows('residence_review', $u));

        config(['muwathaq.residence_review_mode' => 'off']);
        $this->assertFalse(MuwathaqCanary::allows('residence_review', $u), 'off يغلق حتى القائمة');

        config(['muwathaq.residence_review_mode' => 'on']);
        $this->assertTrue(MuwathaqCanary::allows('residence_review', $this->customer('10000009')));
    }

    /** @test */
    public function a_user_without_an_account_number_is_never_matched_by_an_empty_entry(): void
    {
        $u = User::factory()->create(['type' => CUSTOMER_TYPE]);
        $u->forceFill(['account_number' => null])->save();
        config(['muwathaq.profile_change_mode' => 'canary', 'muwathaq.profile_change_user_ids' => [], 'muwathaq.profile_change_accounts' => ['']]);

        $this->assertFalse(MuwathaqCanary::allows('profile_change', $u->fresh()));
    }
}
