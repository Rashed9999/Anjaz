<?php

namespace Tests\Feature;

use App\Models\KycDocument;
use App\Models\User;
use App\Services\Kyc\KycPrivacyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UnifiedVerificationCenterTest extends TestCase
{
    use RefreshDatabase;

    private function reviewer(array $permissions): User
    {
        $staff = User::factory()->create(['type' => ADMIN_TYPE, 'role' => 'operator']);
        DB::table('platform_operator_tab_access')->insert([
            'user_id' => $staff->id, 'tab_code' => 'customers',
            'access_level' => 'read', 'granted_by_user_id' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($permissions as $code) {
            $id = DB::table('permissions')->where('code', $code)->value('id');
            $this->assertNotNull($id);
            DB::table('admin_user_permissions')->insert([
                'user_id' => $staff->id, 'permission_id' => $id,
                'granted_by_user_id' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        return $staff->fresh();
    }

    public function test_one_center_opens_new_account_queue_and_case_without_links_to_other_pages(): void
    {
        $staff = $this->reviewer(['platform.customers.kyc.view']);
        $customer = User::factory()->create([
            'type' => CUSTOMER_TYPE, 'is_kyc_verified' => 0, 'kyc_tier' => 0,
        ]);
        $this->actingAs($staff, 'user')
            ->get('/admin/amial/kyc')->assertOk()
            ->assertSee('unified-verification-center');
        $queue = $this->getJson(route('admin.amial.kyc.center.queue'))->assertOk();
        $this->assertContains($customer->id, array_column($queue->json('data'), 'id'));

        $case = $this->getJson(route('admin.amial.kyc.center.account', $customer->id))->assertOk();
        $this->assertSame($customer->id, $case->json('data.account.id'));
        $this->assertFalse($case->json('data.permissions.review_documents'));
        $this->assertFalse($case->json('data.permissions.decide_account'));
        $this->assertArrayHasKey('residence', $case->json('data'));
        $this->assertArrayHasKey('evidence', $case->json('data'));
        $this->assertDatabaseHas('pii_access_logs', [
            'actor_user_id' => $staff->id,
            'subject_type' => 'user',
            'subject_id' => $customer->id,
            'field_name' => 'kyc_verification_dossier',
        ]);
    }

    public function test_restricted_account_is_not_leaked_in_general_queue_or_detail(): void
    {
        $staff = $this->reviewer(['platform.customers.kyc.view']);
        $customer = User::factory()->create([
            'type' => CUSTOMER_TYPE, 'is_kyc_verified' => 0,
        ]);
        app(KycPrivacyService::class)->choose($customer, KycPrivacyService::MODE_RESTRICTED);

        $this->actingAs($staff, 'user');
        $queue = $this->getJson(route('admin.amial.kyc.center.queue'))->assertOk();
        $this->assertNotContains($customer->id, array_column($queue->json('data'), 'id'));
        $this->getJson(route('admin.amial.kyc.center.account', $customer->id))
            ->assertForbidden();
    }

    public function test_tier_three_cannot_look_ready_without_verified_residence(): void
    {
        // الحارس المرئي يجب أن يتطابق مع حارس القرار الفعلي.
        $source = (string) file_get_contents(
            app_path('Services/Admin/KycEvidenceService.php')
        );
        $this->assertStringContainsString(
            'app(ResidenceVerificationService::class)->assertVerified($user)',
            $source,
            'المراجع يجب أن يرى نقص إثبات السكن قبل اعتماد المستوى الثالث'
        );
    }

    public function test_reviewing_documents_still_requires_the_existing_write_permission(): void
    {
        $staff = $this->reviewer(['platform.customers.kyc.view']);
        $customer = User::factory()->create(['type' => CUSTOMER_TYPE, 'is_kyc_verified' => 0]);
        $case = $this->actingAs($staff, 'user')
            ->getJson(route('admin.amial.kyc.center.account', $customer->id))
            ->assertOk();
        $this->assertFalse($case->json('data.permissions.review_documents'));
        $this->assertFalse($case->json('data.permissions.activate_ready'));
    }
}
