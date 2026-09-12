<?php

namespace Tests\Feature;

use App\Models\KycDocument;
use App\Models\Merchant;
use App\Models\MerchantProfile;
use App\Models\MerchantVerificationRequest;
use App\Models\User;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AMIAL-MERCHANT-VERIFY-ADMIN-001 — **التاجرُ يقدّم، ولم يكن أحدٌ يعتمد.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما قِيس، ولم يُفترَض:**
 *
 *     MerchantVerificationController::adminApprove             ← مبنيّة
 *     MerchantVerificationController::adminReject              ← مبنيّة
 *     MerchantVerificationController::adminRequestResubmission ← مبنيّة
 *
 *     grep 'MerchantVerification' routes/ →  ثلاثةُ مساراتٍ للتاجر
 *                                            **وصفرٌ للإدارة**
 *
 * فالتاجرُ يرفع سجلَّه التجاريَّ وصورةَ متجره وحسابَه البنكيَّ، ويُقال
 * له «قيد المراجعة» — **ولا شاشةَ تعرض طلبَه ولا مسارَ يعتمده**. فيبقى
 * عالقاً بلا نهاية، ولا خطأ في أيّ سجلّ. (القاعدة الثانية عشرة: مبنيٌّ
 * ولا يُوصَل إليه — ووقع هنا على **ثلاث نقاطٍ دفعةً واحدة**.)
 *
 * وستُّ حالات، **وأخطرُها ⑤**: وثيقةٌ شخصيّةٌ تُخدَم بلا صلاحيّة.
 */
class MerchantVerificationAdminGuardTest extends TestCase
{
    use RefreshDatabase;

    private function admin(array $permissions): User
    {
        $u = User::factory()->create([
            'type' => ADMIN_TYPE, 'role' => 'super_admin', 'is_active' => 1,
        ]);

        $roleId = DB::table('roles')->whereNull('merchant_user_id')
            ->where('code', 'platform_admin')->value('id');

        if ($roleId) {
            DB::table('admin_user_roles')->insert([
                'user_id' => $u->id, 'role_id' => $roleId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $u;
    }

    /** موظّفٌ بلا صلاحيّاتِ المنصّة إطلاقاً. */
    private function stranger(): User
    {
        return User::factory()->create([
            'type' => ADMIN_TYPE, 'role' => 'staff', 'is_active' => 1,
        ]);
    }

    private function merchant(): User
    {
        $u = User::factory()->create([
            'type' => MERCHANT_TYPE, 'role' => A::ROLE_MERCHANT,
            'is_active' => 1, 'is_kyc_verified' => 0,
            'zone_code' => 'SOUTH', 'residence_governorate' => 'عدن',
        ]);

        MerchantProfile::create([
            'user_id' => $u->id, 'tier' => 'small',
            'verification_status' => 'pending_review', 'business_type' => A::BIZ_RETAIL,
            'daily_receive_limit' => '5000000', 'single_receive_limit' => '1000000',
        ]);

        $m = new Merchant();
        $m->user_id = $u->id;
        $m->store_name = 'متجر الاختبار';
        $m->merchant_number = 'AM-'.Str::upper(Str::random(6));
        $m->save();

        return $u;
    }

    private function request(User $merchant): MerchantVerificationRequest
    {
        return MerchantVerificationRequest::create([
            'request_ulid' => (string) Str::ulid(),
            'merchant_user_id' => $merchant->id,
            'business_name' => 'متجر النور للتجزئة',
            'commercial_register_number' => 'CR-4455',
            'city' => 'عدن',
            'contact_phone' => '967771234567',
            'status' => 'pending_review',
            'zone_code' => 'SOUTH',
        ]);
    }

    /** @test */
    public function the_three_admin_actions_finally_have_a_door(): void
    {
        // ① قبلَ هذا التغيير: `route()` ترمي لأنّ المسارَ لا وجودَ له.
        foreach ([
            'admin.amial.merchants.verification.page',
            'admin.amial.merchants.verification.list',
            'admin.amial.merchants.verification.approve',
            'admin.amial.merchants.verification.reject',
            'admin.amial.merchants.verification.resubmit',
            'admin.amial.merchants.verification.document',
        ] as $name) {
            $this->assertTrue(\Illuminate\Support\Facades\Route::has($name),
                "المسار «{$name}» غير مسجَّل — والفعلُ مبنيٌّ في المتحكّم "
                .'بلا باب، فيبقى التاجرُ عالقاً بلا نهاية');
        }
    }

    /** @test */
    public function the_queue_shows_the_request_with_its_documents(): void
    {
        $merchant = $this->merchant();
        $req = $this->request($merchant);

        $json = $this->actingAs($this->admin([]), 'user')
            ->getJson(route('admin.amial.merchants.verification.list'))
            ->assertOk()->json();

        $row = collect($json['data'])->firstWhere('id', $req->id);

        $this->assertNotNull($row, 'الطلبُ لا يظهر في الطابور إطلاقاً');
        $this->assertSame('متجر النور للتجزئة', $row['business_name']);
        $this->assertSame('بانتظار المراجعة', $row['status_label']);

        // **والمرفوعُ وغيرُ المرفوعِ يُقالان** — قائمةٌ تعرض المرفوعَ
        // وحدَه تُقرأ «هذا كلُّ ما طُلب».
        $this->assertCount(7, $row['documents']);
        $this->assertContains(false, array_column($row['documents'], 'uploaded'),
            'لا يُقال أيُّ الوثائق لم تُرفَع');
    }

    /** @test */
    public function the_screen_says_the_owner_identity_is_still_unverified(): void
    {
        $merchant = $this->merchant();
        $req = $this->request($merchant);

        $row = collect($this->actingAs($this->admin([]), 'user')
            ->getJson(route('admin.amial.merchants.verification.list'))
            ->assertOk()->json('data'))->firstWhere('id', $req->id);

        $this->assertFalse($row['identity']['verified'],
            'يُقال إنّ هويّة صاحب المتجر موثّقةٌ وهي ليست كذلك');
        $this->assertNotEmpty($row['identity']['missing'],
            'لا يُقال ما ينقص هويّةَ صاحب المتجر — فيعتمد المراجعُ ظانّاً '
            .'أنّه فتح سقفَ المال، وهو لم يفتحه');
    }

    /** @test */
    public function approving_the_business_says_the_money_lock_did_not_lift(): void
    {
        $merchant = $this->merchant();
        $req = $this->request($merchant);

        $body = $this->actingAs($this->admin([]), 'user')
            ->postJson(route('admin.amial.merchants.verification.approve', $req->id))
            ->assertOk()->json();

        $this->assertSame('verified', $req->fresh()->status);

        // **وتوثيقُ النشاط لا يوثّق الشخص** — والردُّ يقولها.
        $this->assertFalse($body['identity']['verified'],
            'قيل إنّ الهويّةَ وُثِّقت باعتماد النشاط — وسجلُّ المتجر لا '
            .'يثبت هويّةَ صاحبه');
        $this->assertSame(0, (int) $merchant->fresh()->is_kyc_verified);
    }

    /** @test */
    public function a_complete_identity_does_lift_the_lock_through_the_one_door(): void
    {
        $merchant = $this->merchant();

        foreach ([KycDocument::TYPE_ID_FRONT, KycDocument::TYPE_ID_BACK,
            KycDocument::TYPE_SELFIE] as $type) {
            KycDocument::create([
                'user_id' => $merchant->id, 'doc_type' => $type,
                'status' => KycDocument::STATUS_APPROVED,
                'encrypted_path' => 'kyc/'.Str::random(8).'.enc',
                'size_bytes' => 1024, 'ocr_status' => 'not_run',
            ]);
        }

        $req = $this->request($merchant);

        $this->actingAs($this->admin([]), 'user')
            ->postJson(route('admin.amial.merchants.verification.approve', $req->id))
            ->assertOk();

        $this->assertSame(1, (int) $merchant->fresh()->is_kyc_verified,
            'ملفُّ الهويّة مكتملٌ ومع ذلك بقي القفل — فالاعتمادُ اسمٌ بلا أثر');
    }

    /** @test */
    public function a_rejection_without_a_reason_is_refused(): void
    {
        $req = $this->request($this->merchant());
        $admin = $this->admin([]);

        // ③ «مرفوض» بلا سبب تُنتج تذكرةَ دعمٍ لا إجراءً، والتاجرُ يعيد
        // الرفعَ نفسَه فيُرفض ثانية.
        $this->actingAs($admin, 'user')
            ->postJson(route('admin.amial.merchants.verification.reject', $req->id), ['reason' => 'لا'])
            ->assertStatus(422);

        $this->assertSame('pending_review', $req->fresh()->status,
            'رُفض الطلبُ رغم رفض السبب');

        $this->actingAs($admin, 'user')
            ->postJson(route('admin.amial.merchants.verification.reject', $req->id),
                ['reason' => 'السجلّ التجاريّ غير واضح في الصورة'])
            ->assertOk();

        $this->assertSame('rejected', $req->fresh()->status);
    }

    /** @test */
    public function a_stranger_can_neither_read_the_queue_nor_open_a_document(): void
    {
        // ⑤ **وثيقةٌ شخصيّةٌ تُخدَم بلا صلاحيّة** — أخطرُ ما في الشاشة.
        $req = $this->request($this->merchant());
        $stranger = $this->stranger();

        $this->actingAs($stranger, 'user')
            ->get(route('admin.amial.merchants.verification.list'))
            ->assertForbidden();

        $this->actingAs($stranger, 'user')
            ->get(route('admin.amial.merchants.verification.document',
                ['id' => $req->id, 'type' => 'commercial_register']))
            ->assertForbidden();

        // **والقرارُ خلف صلاحيّةٍ أضيقَ من القراءة** — الفصلُ بينهما هو
        // ما يمنع موظّفَ مراجعةٍ من أن يقرّر وحدَه.
        $this->actingAs($stranger, 'user')
            ->postJson(route('admin.amial.merchants.verification.approve', $req->id))
            ->assertForbidden();
    }
}
