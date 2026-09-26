<?php

namespace Tests\Feature;

use App\Models\KycDocument;
use App\Models\User;
use App\Services\CustomerCenterService;
use App\Services\Kyc\KycPrivacyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-CUSTOMER-KYC-HUB-001
 *
 * يحرس أن «اعرف عميلك» ليس خدمات خلفية متفرقة:
 * مركز العميل الواحد يجب أن يعرض الحقيقة الرقابية، الإقامة، الملكية،
 * الخصوصية، انتهاء الوثيقة، التكرار، وطلبات التحديث.
 *
 * ويحرس الباب المقيد: توحيد الشاشات لا يعني توحيد الصلاحيات.
 */
class CustomerKycUnifiedCenterGuardTest extends TestCase
{
    use RefreshDatabase;

    private function operator(array $permissions): User
    {
        $operator = User::factory()->create([
            'type' => ADMIN_TYPE,
            'role' => 'operator',
        ]);

        DB::table('platform_operator_tab_access')->insert([
            'user_id' => $operator->id,
            'tab_code' => 'customers',
            'access_level' => 'read',
            'granted_by_user_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ids = DB::table('permissions')
            ->whereIn('code', $permissions)
            ->pluck('id', 'code');

        foreach ($permissions as $code) {
            $this->assertArrayHasKey($code, $ids->all());
            DB::table('admin_user_permissions')->insert([
                'user_id' => $operator->id,
                'permission_id' => $ids[$code],
                'granted_by_user_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $operator->fresh();
    }

    private function customer(): User
    {
        $user = User::factory()->create([
            'type' => CUSTOMER_TYPE,
            'is_phone_verified' => 1,
            'is_email_verified' => 1,
            'email_verified_at' => now(),
            'kyc_tier' => 1,
            'is_kyc_verified' => 0,
            'residence_governorate' => 'YE-AD',
            'residence_district' => 'دار سعد',
            'residence_area' => 'المنصورة',
            'name_en' => 'Ahmed Saleh',
            'father_name' => 'محمد',
            'grandfather_name' => 'علي',
            'income_source' => 'salary',
            'account_purpose' => 'payments',
            'is_pep' => false,
        ]);

        DB::table('residence_verifications')->insert([
            'user_id' => $user->id,
            'kyc_document_id' => null,
            'declared_governorate' => 'YE-AD',
            'evidence_type' => 'government_residence_document',
            'evidence_strength' => 'strong',
            'status' => 'verified',
            'submitted_at' => now()->subDay(),
            'reviewed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user->fresh();
    }

    public function test_customer_kyc_tab_exposes_the_connected_know_your_customer_systems(): void
    {
        $actor = $this->operator([
            'platform.customers.kyc.view',
            'platform.customers.kyc.restricted.view',
            'platform.customers.kyc.biometric.view',
        ]);
        $customer = $this->customer();

        DB::table('profile_change_requests')->insert([
            'user_id' => $customer->id,
            'opened_by' => $actor->id,
            'opened_by_type' => 'admin',
            'field' => 'job_title',
            'old_value' => 'موظف',
            'new_value' => null,
            'reason' => 'تحديث المسمى الوظيفي',
            'status' => 'PENDING_CUSTOMER',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $kyc = app(CustomerCenterService::class)->kyc($customer, $actor->id);

        foreach ([
            'contact_verification',
            'regulatory_profile',
            'residence',
            'identity_expiry',
            'ownership',
            'privacy',
            'reuse_findings',
            'profile_change_requests',
            'tier_policies',
            'documents',
            'registration_dossiers',
        ] as $key) {
            $this->assertArrayHasKey($key, $kyc, "جزء KYC غير موصول بمركز العميل: {$key}");
        }

        $this->assertTrue($kyc['contact_verification']['phone_verified']);
        $this->assertTrue($kyc['contact_verification']['email_verified']);
        $this->assertSame('verified', $kyc['residence']['status']);
        $this->assertSame('دار سعد', $kyc['residence']['residence_district']);
        $this->assertCount(4, $kyc['tier_policies']);
        $this->assertCount(1, $kyc['profile_change_requests']);
    }

    public function test_restricted_kyc_case_stays_hidden_in_unified_customer_center(): void
    {
        $actor = $this->operator(['platform.customers.kyc.view']);
        $customer = $this->customer();

        app(KycPrivacyService::class)->choose(
            $customer,
            KycPrivacyService::MODE_RESTRICTED,
        );

        KycDocument::create([
            'user_id' => $customer->id,
            'doc_type' => KycDocument::TYPE_ID_FRONT,
            'encrypted_path' => 'tests/restricted-front.jpg',
            'status' => KycDocument::STATUS_PENDING,
        ]);

        $kyc = app(CustomerCenterService::class)->kyc($customer->fresh(), $actor->id);

        $this->assertTrue($kyc['documents_hidden']);
        $this->assertSame([], $kyc['documents']);
        $this->assertTrue($kyc['privacy']['hidden']);
        $this->assertTrue($kyc['ownership']['hidden']);
        $this->assertTrue($kyc['reuse_findings']['hidden']);
    }

    public function test_customer_center_view_renders_all_know_your_customer_sections(): void
    {
        $view = file_get_contents(
            resource_path('views/admin-views/amial/customer/index.blade.php')
        );

        foreach ([
            'ملف «اعرف عميلك» الرقابي',
            'إثبات الإقامة',
            'إثبات ملكية الهوية',
            'الخصوصية والتحقق الحيوي',
            'كشف تكرار الهوية والمستندات',
            'طلبات تحديث بيانات هذا العميل',
            'مستويات KYC وحدودها الحالية',
        ] as $label) {
            $this->assertStringContainsString($label, $view);
        }
    }
}
