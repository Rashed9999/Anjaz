<?php

namespace Tests\Feature;

use App\Models\Aml\AmlRule;
use App\Models\EMoney;
use App\Models\KycDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-ADMIN-REACH-001 — اللوحات تعمل، لا تُعرَض وحسب.
 *
 * `AdminPanelReachabilityGuardTest` يثبت أنّ الصفحة تُفتح وأنّ رابطاً يصل
 * إليها. وهذا الملفّ يثبت ما هو أبعد: أنّ ما تستدعيه الصفحة **يفعل شيئاً**.
 *
 * والفرق ليس شكليّاً. صفحةٌ تُفتح وتردّ 200 ثم يفشل كلّ استدعاءٍ فيها بصمت
 * تبدو للمشغّل كأنّها تعمل: الجدول فارغ فيظنّ الطابور خالياً، والزرّ لا يفعل
 * فيظنّ النقر لم يُسجَّل. وهذا ما يجعل «اللوحة موجودة» جواباً ناقصاً.
 */
class AdminCompliancePanelsTest extends TestCase
{
    use RefreshDatabase;

    private User $reviewer;
    private User $secondReviewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reviewer = $this->platformAdmin('967770009801');
        $this->secondReviewer = $this->platformAdmin('967770009802');
    }

    private function platformAdmin(string $phone): User
    {
        $u = User::factory()->create([
            'type' => ADMIN_TYPE, 'role' => 'super_admin', 'phone' => $phone,
        ]);

        $roleId = DB::table('roles')->whereNull('merchant_user_id')
            ->where('code', 'platform_admin')->value('id');

        DB::table('admin_user_roles')->updateOrInsert(
            ['user_id' => $u->id, 'role_id' => $roleId],
            ['created_at' => now(), 'updated_at' => now()],
        );

        return $u;
    }

    private function customer(string $phone): User
    {
        $u = User::factory()->create(['phone' => $phone, 'zone_code' => 'SOUTH']);
        EMoney::updateOrCreate(['user_id' => $u->id],
            ['current_balance' => '0', 'zone_code' => 'SOUTH']);

        return $u;
    }

    // ── لوحة مراجعة الهوية ──────────────────────────────────────────────

    /** @test */
    public function the_kyc_queue_endpoint_the_panel_calls_returns_the_waiting_documents(): void
    {
        $customer = $this->customer('770000101');

        KycDocument::create([
            'user_id' => $customer->id,
            'doc_type' => KycDocument::TYPE_ID_FRONT,
            'encrypted_path' => 'kyc/fake.enc',
            'original_mime' => 'image/jpeg',
            'size_bytes' => 1024,
            'content_sha256' => str_repeat('a', 64),
            'status' => KycDocument::STATUS_PENDING,
        ]);

        $this->actingAs($this->reviewer, 'user')
            ->getJson('/admin/amial/kyc/queue')
            ->assertOk()
            ->assertJsonPath('data.queue.0.user_id', $customer->id)
            // الاسم والهاتف أُضيفا للطابور عمداً: مراجعٌ يرى رقماً مجرّداً
            // يضطرّ لفتح ملفّ كلّ عميل — فيُسجَّل اطّلاعٌ على بيانات شخصية
            // في كلّ مرّة بلا حاجة.
            ->assertJsonPath('data.queue.0.customer_phone', $customer->phone);
    }

    /** @test */
    public function a_reviewer_cannot_approve_their_own_document_through_the_panel(): void
    {
        // موظّفو المنصّة عملاء أيضاً، ولهم محافظ وحدود. ومن يعتمد هويّته بيده
        // يرفع حدوده بيده.
        $doc = KycDocument::create([
            'user_id' => $this->reviewer->id,
            'doc_type' => KycDocument::TYPE_ID_FRONT,
            'encrypted_path' => 'kyc/fake.enc',
            'original_mime' => 'image/jpeg',
            'size_bytes' => 1024,
            'content_sha256' => str_repeat('b', 64),
            'status' => KycDocument::STATUS_PENDING,
        ]);

        $this->actingAs($this->reviewer, 'user')
            ->postJson("/admin/amial/kyc/documents/{$doc->id}/approve")
            ->assertStatus(422);

        $this->assertSame(KycDocument::STATUS_PENDING, $doc->fresh()->status);
    }

    /** @test */
    public function rejecting_from_the_panel_requires_a_reason_the_customer_can_act_on(): void
    {
        $customer = $this->customer('770000102');

        $doc = KycDocument::create([
            'user_id' => $customer->id,
            'doc_type' => KycDocument::TYPE_ID_BACK,
            'encrypted_path' => 'kyc/fake.enc',
            'original_mime' => 'image/jpeg',
            'size_bytes' => 1024,
            'content_sha256' => str_repeat('c', 64),
            'status' => KycDocument::STATUS_PENDING,
        ]);

        // رفضٌ بلا سبب يجعل العميل يرفع الصورة نفسها مرّةً بعد مرّة.
        $this->actingAs($this->reviewer, 'user')
            ->postJson("/admin/amial/kyc/documents/{$doc->id}/reject", [])
            ->assertStatus(422);

        $this->actingAs($this->reviewer, 'user')
            ->postJson("/admin/amial/kyc/documents/{$doc->id}/reject",
                ['reason' => 'الصورة غير واضحة — أعد التصوير في ضوء أفضل'])
            ->assertOk();

        $fresh = $doc->fresh();
        $this->assertSame(KycDocument::STATUS_REJECTED, $fresh->status);
        $this->assertNotEmpty($fresh->rejection_reason,
            'رُفض المستند بلا سبب مسجَّل — فلا يعرف العميل ما يُصلحه');
    }

    // ── لوحة مكافحة غسل الأموال ─────────────────────────────────────────

    /** @test */
    public function the_aml_rules_endpoint_the_panel_calls_exposes_shadow_mode(): void
    {
        // العمود الذي كانت اللوحة تحتاجه ولا سبيل إليه: قاعدةٌ «مفعَّلة» في
        // الظلّ تُحصي ولا تمنع، ومن يرى «مفعَّلة» وحدها يبني قراره على حمايةٍ
        // غير موجودة.
        $rule = AmlRule::create([
            'code' => 'TEST_SHADOW_RULE',
            'name_ar' => 'قاعدة اختبار',
            'rule_type' => 'threshold',
            'applies_to' => '*',
            'parameters' => ['max' => 1000],
            'action_on_match' => 'block',
            'risk_score_contribution' => 50,
            'priority' => 10,
            'is_active' => true,
            'shadow_mode' => true,
        ]);

        $this->actingAs($this->reviewer, 'user')
            ->getJson('/admin/amial/aml/rules')
            ->assertOk()
            ->assertJsonPath('meta.items.0.shadow_mode', true);

        // وإخراجُها من الظلّ يصير قراراً يُتّخذ من اللوحة لا من سطر أوامر
        // على الخادم.
        $this->actingAs($this->reviewer, 'user')
            ->patchJson("/admin/amial/aml/rules/{$rule->id}", ['shadow_mode' => false])
            ->assertOk();

        $this->assertFalse((bool) $rule->fresh()->shadow_mode,
            'لم تخرج القاعدة من وضع الظلّ — فاللوحة تعرض زرّاً لا يفعل شيئاً');
    }

    /** @test */
    public function the_aml_panel_page_and_its_three_lists_all_answer(): void
    {
        // صفحةٌ تُفتح ثم يفشل كلّ استدعاء فيها بصمت تبدو كأنّها تعمل:
        // الجداول فارغة فيظنّ المشغّل أن لا شيء ينتظره.
        $this->actingAs($this->reviewer, 'user')->get('/admin/amial/aml')->assertOk();

        foreach (['/admin/amial/aml/flagged', '/admin/amial/aml/alerts', '/admin/amial/aml/rules'] as $url) {
            $this->actingAs($this->reviewer, 'user')
                ->getJson($url)
                ->assertOk()
                ->assertJsonPath('success', true);
        }
    }

    // ── لوحة تسويات الشركاء ─────────────────────────────────────────────

    /** @test */
    public function the_partner_settlements_list_exposes_who_signed_and_who_is_still_needed(): void
    {
        // هذا هو العمود الذي لم يكن له سطحٌ إطلاقاً: الموافقة المزدوجة بُنيت
        // على `Settlement` وسطحها الوحيد كان الـAPI، فبقي ضابطاً لا يراه أحد.
        //
        // ولا يكفي فحصُ شكل الردّ: تسويةٌ **حقيقية** توقَّع مرّةً واحدة، ثمّ
        // يُقرأ ما تراه اللوحة. فلو نقص حقلٌ من الثلاثة لعجزت اللوحة عن
        // التمييز بين «لم يوقّع أحد» و«وقّع واحدٌ وينتظر ثانياً» — وهو الخلط
        // الذي جعل الضابط يبدو عطلاً.
        $svc = app(\App\Services\UniversalSettlementService::class);

        $wallet = \App\Models\Ledger\LedgerAccount::create([
            'account_code' => \App\Services\UniversalSettlementService::REVENUE_WALLET_CODE,
            'account_type' => 'liability',
            'name_ar' => 'محفظة إيرادات أميال',
            'owner_type' => 'platform',
            'normal_balance' => 'credit',
            'current_balance' => '10000000',
        ]);

        $partner = \App\Models\SettlementPartner::create([
            'code' => 'PANEL_EXCHANGE', 'name_ar' => 'صرافة اللوحة',
            'type' => \App\Models\SettlementPartner::TYPE_EXCHANGE,
            'currency' => 'YER', 'is_active' => true,
        ]);

        // مبلغٌ فوق حدّ الموافقة المزدوجة — العتبة لا تُخفَّض للاختبار، وإلّا
        // اختُبر مسارٌ لا يسلكه أحد في الإنتاج.
        $creator = $this->platformAdmin('967770009803');
        $s = $svc->create($wallet->id, $partner->id, '2000000', $creator);
        $s = $svc->submit($s, $creator);

        $s = $svc->approve($s, $this->reviewer);

        $this->assertTrue($svc->awaitingSecondApproval($s),
            'التسوية لا تنتظر توقيعاً ثانياً — تغيّرت العتبة أو المنطق');

        $body = $this->actingAs($this->secondReviewer, 'user')
            ->getJson('/admin/amial/partner-settlements/list?status=pending_approval')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json('meta.settlements');

        $row = collect($body)->firstWhere('id', $s->id);
        $this->assertNotNull($row, 'التسوية المنتظِرة لا تظهر في قائمة اللوحة');

        $this->assertSame(2, (int) $row['approvals_required'],
            'اللوحة لا تعرف أنّ التسوية تحتاج توقيعين');
        $this->assertNotNull($row['approved_by'],
            'اللوحة لا ترى التوقيع الأوّل — فتبدو التسوية كأنّ أحداً لم يوقّعها');
        $this->assertNull($row['second_approved_by'],
            'اللوحة تظنّ التوقيع الثاني تمّ وهو لم يتمّ');

        // واسمُ الموقّع الأوّل يصل مع الصفّ — فالغرض من التوقيعين أن يُعرف
        // **من** وقّع، ورقمٌ مجرَّد يُعيد الضابط إلى عدّاد.
        $this->assertNotEmpty($row['approver'] ?? null,
            'اسم الموقّع الأوّل لا يصل مع الصفّ');
    }

    /** @test */
    public function the_partner_settlements_page_opens_for_a_platform_admin(): void
    {
        $this->actingAs($this->reviewer, 'user')
            ->get('/admin/amial/partner-settlements')
            ->assertOk()
            // النصّ الذي يمنع سوء الفهم الذي أنتجه غيابُ اللوحة: من وافق
            // مرّةً على تسويةٍ فوق الحدّ كان يراها «بانتظار الاعتماد» فيظنّ
            // ضغطته لم تُسجَّل، فيعيدها — والنظام يرفضه لأنّه هو نفسه.
            ->assertSee('بانتظار التوقيع الثاني', false);
    }

    // ── أجهزة العميل في مركز الدعم ──────────────────────────────────────

    /** @test */
    public function the_devices_endpoints_the_console_calls_list_and_block_and_refuse_self_unblock(): void
    {
        $customer = $this->customer('770000103');

        $deviceId = DB::table('user_log_histories')->insertGetId([
            'user_id' => $customer->id,
            'device_id' => 'DEV-TEST-0001',
            'device_model' => 'Test Phone',
            'os' => 'Android 14',
            'ip_address' => '10.0.0.1',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->reviewer, 'user')
            ->getJson("/admin/support-center/customers/{$customer->id}/devices?reason=مراجعة أجهزة العميل")
            ->assertOk()
            ->assertJsonPath('meta.devices.0.device_id', 'DEV-TEST-0001');

        $this->actingAs($this->reviewer, 'user')
            ->postJson("/admin/support-center/devices/{$deviceId}/block",
                ['reason' => 'بلاغ سرقة الهاتف من صاحب الحساب'])
            ->assertOk();

        $this->assertTrue(
            (bool) DB::table('user_log_histories')->where('id', $deviceId)->value('is_blocked'),
            'لم يُحظر الجهاز — فزرّ اللوحة يعرض نجاحاً بلا أثر',
        );

        // من حظر لا يرفع الحظر: الحظر قرارُ حمايةٍ يُتّخذ على عجل تحت ضغط
        // مكالمة، ورفعُه بيد صاحبه يجعل الضابط شكليّاً.
        $this->actingAs($this->reviewer, 'user')
            ->postJson("/admin/support-center/devices/{$deviceId}/unblock",
                ['reason' => 'تبيّن أن البلاغ خاطئ'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'FOUR_EYES_VIOLATION');

        // وموظّفٌ آخر يستطيع.
        $this->actingAs($this->secondReviewer, 'user')
            ->postJson("/admin/support-center/devices/{$deviceId}/unblock",
                ['reason' => 'راجعتُ البلاغ وتبيّن أنه خاطئ'])
            ->assertOk();

        $this->assertFalse(
            (bool) DB::table('user_log_histories')->where('id', $deviceId)->value('is_blocked'),
        );
    }
}
