<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\EstablishesKycEvidence;
use Tests\TestCase;

/**
 * AMIAL-ADMIN-KYC-001 — الحساب المُنشأ من اللوحة يمرّ بالمراجعة.
 *
 * سبب وجود هذه الاختبارات: storeUser كان يضع is_kyc_verified = 1 و
 * kyc_tier = 3 وverification_status = 'verified' لحظة الإنشاء، بتعليق
 * يقول «نفس وصفة الحسابات التجريبية». أي أن كل حساب يُنشأ من اللوحة كان
 * يخرج موثّقاً بلا وثيقة واحدة، فتُفرَّغ لوحة التحقّق من معناها.
 */
class AdminCreatedAccountReviewTest extends TestCase
{
    use RefreshDatabase;
    use EstablishesKycEvidence;

    private function admin(): User
    {
        // AMIAL-ADMIN-DOORS-002 — **نوعُ الحساب لم يعد يفتح الباب.**
        //
        // ويُمنَح الدورُ ولا يُبدَّل التأكيد: هذه الاختباراتُ تُثبت
        // **قواعدَ عمل** (٤٢٢/٢٠٠)، فلو قُرئ ردُّها ٤٠٣ لمرّت لسببٍ
        // خاطئ — وتوقّفت عن حراسة القاعدة التي كُتبت لها.
        $u = User::factory()->create(['type' => ADMIN_TYPE]);
        app(\App\Services\PlatformRoleService::class)
            ->assign($u, \App\Services\PlatformRoleService::ADMIN);

        return $u->refresh();
    }

    private function create(string $slug, array $extra = []): array
    {
        $r = $this->actingAs($this->admin(), 'user')
            ->postJson("/admin/amial/hub/{$slug}/users", array_merge([
                'f_name' => 'اختبار',
                'l_name' => 'حساب',
                'phone' => '77' . random_int(1000000, 9999999),
                'password' => 'Passw0rd!123',
            ], $extra));

        return [$r, User::latest('id')->first()];
    }

    public function test_customer_created_by_admin_awaits_review(): void
    {
        [$r, $user] = $this->create('customers');

        $r->assertSuccessful();
        $this->assertSame(0, (int) $user->is_kyc_verified, 'يجب أن يخرج بانتظار المراجعة لا موثّقاً');
        $this->assertSame(0, (int) $user->kyc_tier);
        // يستطيع الدخول ليرى شاشة «بانتظار الموافقة» — التجميد شيء آخر.
        $this->assertSame(1, (int) $user->is_active);
    }

    public function test_agent_created_by_admin_awaits_review(): void
    {
        [$r, $user] = $this->create('agents');

        $r->assertSuccessful();
        $this->assertSame(0, (int) $user->is_kyc_verified);
    }

    public function test_merchant_profile_is_pending_not_verified(): void
    {
        [$r, $user] = $this->create('merchants', ['store_name' => 'متجر الاختبار']);

        $r->assertSuccessful();
        $this->assertSame(0, (int) $user->is_kyc_verified);

        $profile = MerchantProfile::where('user_id', $user->id)->first();
        $this->assertNotNull($profile);
        $this->assertSame('pending_review', $profile->verification_status);
        $this->assertNull($profile->verified_at);
    }

    public function test_new_account_appears_in_the_verification_queue(): void
    {
        // لا قيمة لجعل الحساب «بانتظار المراجعة» إن لم يظهر أمام المراجع.
        // ملاحظة: kyc.json يشترط وجود وثائق مرفوعة، وحساب اللوحة بلا وثائق —
        // فالمكان الصحيح هو لوحة التحقّق التي لا تشترطها.
        [, $user] = $this->create('customers');

        $ids = collect(
            $this->actingAs($this->admin(), 'user')
                ->getJson('/admin/amial/hub/verification/list.json?filter=pending')
                ->assertOk()
                ->json('data')
        )->pluck('id')->all();

        $this->assertContains($user->id, $ids);
    }

    public function test_verified_account_leaves_the_pending_queue(): void
    {
        [, $user] = $this->create('customers');

        // اعتمادٌ بلا وثيقة مرفوض بحقّ — يُبنى الدليلُ أوّلاً.
        $this->establishKycEvidence($user);
        $this->actingAs($this->admin(), 'user')
            ->postJson("/admin/amial/hub/users/{$user->id}/kyc", ['status' => 1]);

        $ids = collect(
            $this->actingAs($this->admin(), 'user')
                ->getJson('/admin/amial/hub/verification/list.json?filter=pending')
                ->json('data')
        )->pluck('id')->all();

        $this->assertNotContains($user->id, $ids);
    }

    public function test_admin_approval_verifies_the_account(): void
    {
        [, $user] = $this->create('merchants', ['store_name' => 'متجر']);

        // اعتمادٌ بلا وثيقة مرفوض بحقّ — يُبنى الدليلُ أوّلاً.
        $this->establishKycEvidence($user);
        $this->actingAs($this->admin(), 'user')
            ->postJson("/admin/amial/hub/users/{$user->id}/kyc", ['status' => 1])
            ->assertSuccessful();

        $this->assertSame(1, (int) $user->fresh()->is_kyc_verified);
        $this->assertSame('verified', MerchantProfile::where('user_id', $user->id)->value('verification_status'));
    }

    public function test_admin_rejection_marks_the_account_rejected(): void
    {
        [, $user] = $this->create('customers');

        $this->actingAs($this->admin(), 'user')
            ->postJson("/admin/amial/hub/users/{$user->id}/kyc", ['status' => 2, 'reason' => 'الوثائق غير واضحة ولا تُقرأ'])
            ->assertSuccessful();

        $this->assertSame(2, (int) $user->fresh()->is_kyc_verified);
    }
}
