<?php

namespace Tests\Feature;

use App\Models\KycDocument;
use App\Models\User;
use App\Models\WomenTier3SyncEvent;
use App\Services\Kyc\KycPrivacyService;
use App\Services\Muwathaq\WomenTierThreeService;
use App\Services\PlatformRoleService;
use App\Services\Verification\AccountVerificationDecisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\EstablishesKycEvidence;
use Tests\TestCase;

/**
 * AMIAL-WOMEN-TIER3-001 — **موثّق لا يملك بابًا للمستوى الثالث للمرأة
 * (لا فتحًا ولا ترقيةً — مؤكَّدٌ بكود موثّق نفسه). فأميال باي يقرّر، لا
 * يطلب، ويُبلَّغ موثّق بعد الاعتماد الفعليّ وحده.**
 *
 * **ومسار «حضوريّ بلا سيلفي» إلى المستوى الثالث لم يكن ينقص بناءً جديدًا**
 * — `KycPrivacyService::choose('in_person')` ثم `verifyInPerson()` (خلف
 * `platform.customers.kyc.restricted.decide`) يُسقطان شرط السيلفي فعليًا
 * ويفتحان `decide(target_tier: 3)` الموحَّد، لأيّ حساب. الناقصُ وحدَه:
 * إبلاغ موثّق بعد القرار — وهو ما يختبره هذا الملف.
 */
class WomenTierThreeTest extends TestCase
{
    use RefreshDatabase;
    use EstablishesKycEvidence;

    private function admin(): User
    {
        $u = User::factory()->create(['type' => ADMIN_TYPE, 'role' => 'super_admin', 'phone' => '967770001001']);
        app(PlatformRoleService::class)->assign($u, PlatformRoleService::ADMIN);

        return $u->refresh();
    }

    /** امرأةٌ عند المستوى الثاني، اختارت التحقّق الحضوريّ — بلا سيلفي إطلاقًا. */
    private function tierTwoWomanInPersonMode(): User
    {
        $u = User::factory()->create([
            'type' => CUSTOMER_TYPE, 'gender' => 'female', 'phone' => '967781000'.random_int(100, 999),
            'kyc_tier' => 2, 'is_kyc_verified' => 1,
            // الحقول الرقابية التي يلزمها المستوى الثالث (AMIAL-KYC-INTL-001).
            'name_en' => 'TEST WOMAN', 'father_name' => 'أحمد', 'grandfather_name' => 'سالم',
            'residence_district' => 'المنصورة', 'income_source' => 'salary', 'account_purpose' => 'savings',
            'is_pep' => 0,
        ]);
        $this->establishKycEvidence($u, 2);
        // لا سيلفي على الإطلاق — الحالة الحقيقية لحساب النساء عند موثّق.
        KycDocument::where('user_id', $u->id)->where('doc_type', KycDocument::TYPE_SELFIE)->delete();
        KycDocument::query()->create([
            'user_id' => $u->id, 'doc_type' => KycDocument::TYPE_ADDRESS_PROOF,
            'status' => KycDocument::STATUS_APPROVED, 'encrypted_path' => 'kyc/test/addr-'.$u->id.'.enc',
            'original_mime' => 'image/jpeg', 'size_bytes' => 2048,
            'content_sha256' => hash('sha256', 'addr-'.$u->id), 'document_expires_at' => now()->addYears(5),
            'reviewed_at' => now(),
        ]);

        app(KycPrivacyService::class)->choose($u->fresh(), KycPrivacyService::MODE_IN_PERSON);

        return $u->fresh();
    }

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
            $this->assertNotNull($id, "صلاحيّةٌ غير موجودة في القاموس: {$code}");
            DB::table('admin_user_permissions')->insert([
                'user_id' => $staff->id, 'permission_id' => $id,
                'granted_by_user_id' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $staff->fresh();
    }

    /** @test */
    public function choosing_in_person_mode_drops_the_selfie_requirement_for_tier_three(): void
    {
        $woman = $this->tierTwoWomanInPersonMode();

        $completeness = app(\App\Services\KycDocumentService::class)->completenessFor($woman, 3);
        $this->assertNotContains(KycDocument::TYPE_SELFIE, $completeness['required'],
            'السيلفي لا يزال مطلوبًا رغم اختيار التحقّق الحضوريّ');
    }

    /** @test */
    public function the_full_decision_reaches_tier_three_without_a_selfie_and_queues_a_muwathaq_sync(): void
    {
        Queue::fake();

        $woman = $this->tierTwoWomanInPersonMode();
        $admin = $this->reviewer(['platform.customers.kyc.view', 'platform.customers.kyc.restricted.view', 'platform.customers.kyc.restricted.decide']);

        $this->actingAs($admin, 'user')
            ->postJson("/admin/amial/kyc/restricted/users/{$woman->id}/in-person-verify", [
                'reason' => 'حضرت العميلة فرع عدن بتاريخ 2026-11-14 وتأكّدنا من هويّتها.',
            ])->assertOk()->assertJsonPath('success', true);

        $approver = $this->admin();
        app(AccountVerificationDecisionService::class)->decide(
            account: $woman->fresh(), reviewer: $approver, approve: true, targetTier: 3,
        );

        $fresh = $woman->fresh();
        $this->assertSame(3, (int) $fresh->kyc_tier);
        $this->assertSame(1, (int) $fresh->is_kyc_verified);

        $this->assertDatabaseHas('women_tier3_sync_events', [
            'user_id' => $woman->id, 'verification_method' => 'in_person', 'sync_status' => 'pending',
        ]);
        Queue::assertPushed(\App\Jobs\DeliverWomenTier3SyncJob::class);
    }

    /** @test */
    public function a_rejected_decision_never_queues_a_muwathaq_sync(): void
    {
        Queue::fake();

        $woman = $this->tierTwoWomanInPersonMode();
        $verifier = $this->reviewer(['platform.customers.kyc.view', 'platform.customers.kyc.restricted.view', 'platform.customers.kyc.restricted.decide']);
        $this->actingAs($verifier, 'user')
            ->postJson("/admin/amial/kyc/restricted/users/{$woman->id}/in-person-verify", [
                'reason' => 'حضرت العميلة وتأكّدنا من هويّتها.',
            ])->assertOk();

        $approver = $this->admin();
        app(AccountVerificationDecisionService::class)->decide(
            account: $woman->fresh(), reviewer: $approver, approve: false, targetTier: 3, reason: 'مستند إضافي مطلوب',
        );

        $this->assertSame(2, (int) $woman->fresh()->kyc_tier);
        $this->assertDatabaseMissing('women_tier3_sync_events', ['user_id' => $woman->id]);
        Queue::assertNotPushed(\App\Jobs\DeliverWomenTier3SyncJob::class);
    }

    /** @test */
    public function choosing_in_person_alone_without_verification_does_not_notify_muwathaq(): void
    {
        // اختيار المسار وحده لا يثبت الملكية (KycOwnershipGuardService::assessInPerson)
        // فلا طريق للوصول لمستوى 3 بلا اعتماد verifyInPerson أولاً — ونفس المنطق
        // يحمي notifyMuwathaq من إبلاغ موثّق بقرارٍ لم يُطبَّق فعلاً.
        $woman = $this->tierTwoWomanInPersonMode();

        $event = app(WomenTierThreeService::class)->notifyMuwathaq($woman);

        $this->assertNull($event, 'أُبلغ موثّق بقرارٍ لم يُعتمد فعليًا بعد');
    }

    /** @test */
    public function sync_delivery_is_idempotent(): void
    {
        config(['muwathaq.base_url' => 'https://muwathaq.test']);
        \Illuminate\Support\Facades\Http::fake([
            'muwathaq.test/*' => \Illuminate\Support\Facades\Http::response(['accepted' => true], 202),
        ]);

        $woman = $this->tierTwoWomanInPersonMode();
        $verifier = $this->reviewer(['platform.customers.kyc.view', 'platform.customers.kyc.restricted.view', 'platform.customers.kyc.restricted.decide']);
        $this->actingAs($verifier, 'user')
            ->postJson("/admin/amial/kyc/restricted/users/{$woman->id}/in-person-verify", [
                'reason' => 'مرجعٌ ثابتٌ للاختبار 2026-11-14',
            ])->assertOk();

        $service = app(WomenTierThreeService::class);
        $first = $service->notifyMuwathaq($woman->fresh());
        $second = $service->notifyMuwathaq($woman->fresh());

        $this->assertNotNull($first);
        $this->assertSame($first->id, $second->id, 'استدعاءان متتاليان أنشآ حدثي تزامن لا حدثاً واحداً');
        $this->assertSame(1, WomenTier3SyncEvent::where('user_id', $woman->id)->count());
    }
}
