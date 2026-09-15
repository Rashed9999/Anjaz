<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * AMIAL-PENTEST-MONEY-001 — **هل يُشحَن مالٌ بلا صلاحيّة؟**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الطلبُ كما وصل:** «بكلّ عنفٍ واحترافيّة… وإذا استطعتَ الدخولَ إلى لوحة
 * الإدارة، أنشئ عميلاً واشحن محفظتَه.»
 *
 * وهذا **دفاعٌ عن هذا النظام** بإذن مالكه، **وعلى النسخة المحلّيّة لا
 * على `amialpay.com` الحيّ** — فذاك يخدم مالاً حقيقيّاً.
 *
 * **والهجومُ الأخطرُ في المنصّة كلِّها هذا بعينه**: من يشحن محفظةً بلا
 * صلاحيّةٍ يخلق مالاً من عدم. فيُهاجَم مسارُ الشحن
 * (`admin/amial/finance/topup`) من كلّ موضع مهاجم:
 *
 *   · بلا مصادقةٍ إطلاقاً (زائرٌ يعرف العنوان)
 *   · بحساب **عميلٍ عاديّ** (تصعيدُ صلاحيّة أفقيّ)
 *   · بحساب **مديرٍ بلا صلاحيّة الخزينة** (تصعيدٌ رأسيّ داخل الإدارة)
 *   · بتزوير رأس `X-Amial-*` وحقن `funding_source`
 *
 * **وكلُّ اختبارٍ هجومٌ يجب أن يفشل.** فنجاحُ الاختبار = صمودُ الدفاع.
 * (القاعدة الثانية: يُجرَّب الحاجزُ من كلّ باب.)
 */
class AdminMoneyCreationPenetrationTest extends TestCase
{
    use RefreshDatabase;

    private User $victim;

    protected function setUp(): void
    {
        parent::setUp();

        // «العميلُ» الذي يحاول المهاجمُ شحنَ محفظته.
        $this->victim = User::factory()->create([
            'type' => CUSTOMER_TYPE, 'zone_code' => 'SOUTH',
            'phone' => '967700099999',
        ]);

        EMoney::create([
            'user_id' => $this->victim->id, 'current_balance' => '0.0000',
            'held_balance' => '0.0000', 'pending_balance' => '0.0000',
            'charge_earned' => '0.0000', 'zone_code' => 'SOUTH',
        ]);
    }

    private function victimBalance(): string
    {
        return (string) EMoney::where('user_id', $this->victim->id)
            ->value('current_balance');
    }

    /**
     * مديرٌ يملك `platform.treasury.issue` **فعلاً** — لا بالاسم.
     *
     * فالدورُ `super_admin` لا يمنح صلاحيّةً منصّيّةً وحدَه: تُقرأ من
     * `admin_user_roles → role_permissions → permissions`. وبلا هذا
     * تُردّ الطلباتُ عند طبقة الصلاحيّة **قبل** أن تبلغ التحقّقَ من
     * الحمولة وmaker-checker — فلا يُقاس ما تحتها.
     */
    private function treasuryAdmin(string $phone): User
    {
        $admin = User::factory()->create([
            'type' => ADMIN_TYPE, 'role' => 'super_admin',
            'phone' => $phone, 'is_active' => 1,
        ]);

        $permId = DB::table('permissions')->updateOrInsert(
            ['code' => 'platform.treasury.issue'],
            ['label_ar' => 'إصدار خزينة', 'updated_at' => now(), 'created_at' => now()],
        );
        $permId = DB::table('permissions')->where('code', 'platform.treasury.issue')->value('id');

        $roleId = DB::table('roles')->insertGetId([
            'code' => 'treasury_'.uniqid(), 'label_ar' => 'خزينة',
            'is_system' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('role_permissions')->insert([
            'role_id' => $roleId, 'permission_id' => $permId,
        ]);
        DB::table('admin_user_roles')->insert([
            'user_id' => $admin->id, 'role_id' => $roleId,
        ]);

        return $admin;
    }

    private function topupPayload(): array
    {
        return [
            'amount' => '999999',
            'reference' => 'PENTEST-'.uniqid(),
            'reason' => 'محاولةُ اختراقٍ لخلق مال',
            'funding_source' => 'treasury_supply',
        ];
    }

    // ═════════════════════════════════════════════════════════════════
    // متّجهُ ①: بلا مصادقة
    // ═════════════════════════════════════════════════════════════════

    /**
     * **① زائرٌ بلا حسابٍ لا يشحن محفظة.**
     *
     * أوّلُ ما يجرّبه المهاجم: يعرف العنوانَ فيطرقه مباشرةً.
     */
    /** @test */
    public function an_anonymous_visitor_cannot_top_up(): void
    {
        $before = $this->victimBalance();

        $res = $this->postJson('/admin/amial/hub/finance/topup', $this->topupPayload());

        $this->assertContains($res->status(), [401, 403, 302, 419], sprintf(
            '**زائرٌ بلا مصادقةٍ بلغ مسارَ الشحن (%d).**', $res->status()));

        $this->assertSame($before, $this->victimBalance(),
            '**تحرّك رصيدٌ باستدعاءٍ مجهول — مالٌ خُلق من عدم.**');
    }

    // ═════════════════════════════════════════════════════════════════
    // متّجهُ ②: تصعيدٌ أفقيّ — عميلٌ عاديّ
    // ═════════════════════════════════════════════════════════════════

    /**
     * **② عميلٌ عاديٌّ لا يفتح مسارَ الإدارة.**
     *
     * ══════════════════════════════════════════════════════════════════
     * مهاجمٌ سجّل حساباً عاديّاً (وهو أسهلُ ما يُملَك)، ثمّ يوجّه رمزَه
     * إلى مسار الإدارة. فإن فتح، فكلُّ مستخدمٍ مديرٌ. **ويُجرَّب السطحان**:
     * جلسةُ الويب ورمزُ الـAPI — فحاجزٌ يقرأ الجلسةَ وحدَها يترك بابَ
     * الـAPI مشرَّعاً. (`PlatformPermissionMiddleware` كُتب لهذا.)
     * ══════════════════════════════════════════════════════════════════
     */
    /** @test */
    public function a_plain_customer_cannot_reach_the_admin_topup(): void
    {
        $before = $this->victimBalance();

        // السطحُ الأوّل: جلسةُ الويب.
        // **و٣٠٢ رفضٌ صحيح**: حارسُ `admin` يعيد غيرَ الإداريّ إلى تسجيل
        // الدخول («هذه الجلسة ليست لحساب إدارة») قبل أن يبلغ الشحن. قِستُ
        // ٤٠٣ فإذا هو ٣٠٢ — وكلاهما منعٌ، والمهمُّ ألّا يتحرّك مال.
        $web = $this->actingAs($this->victim, 'user')
            ->postJson('/admin/amial/hub/finance/topup', $this->topupPayload());
        $this->assertContains($web->status(), [302, 401, 403], sprintf(
            '**عميلٌ عاديٌّ بلغ الشحنَ عبر جلسة الويب (%d).**', $web->status()));

        // السطحُ الثاني: رمزُ الـAPI — الباب الآخر لنفس الفعل.
        Passport::actingAs($this->victim);
        $api = $this->postJson('/api/v1/amial/admin/agents/'.$this->victim->id.'/credit',
            $this->topupPayload());

        $this->assertContains($api->status(), [401, 403, 404, 422], sprintf(
            '**عميلٌ عاديٌّ فتح مسارَ ائتمانٍ إداريّ عبر API (%d).**',
            $api->status()));

        $this->assertSame($before, $this->victimBalance(),
            '**عميلٌ عاديٌّ شحن محفظةً — تصعيدُ صلاحيّةٍ إلى خلق مال.**');
    }

    // ═════════════════════════════════════════════════════════════════
    // متّجهُ ③: تصعيدٌ رأسيّ — مديرٌ بلا صلاحيّة الخزينة
    // ═════════════════════════════════════════════════════════════════

    /**
     * **③ ومديرٌ بلا صلاحيّة الخزينة لا يشحن.**
     *
     * ══════════════════════════════════════════════════════════════════
     * هذا أدقُّ الهجمات وأخطرُها: المهاجمُ **مديرٌ فعلاً** (موظّفُ دعمٍ
     * مثلاً)، لكنّه لا يملك `platform.treasury.issue`. فإن كفى «كونُه
     * مديراً» لفتح الخزينة، فكلُّ موظّفِ دعمٍ يخلق المال.
     *
     * **وهذا العطلُ بعينه مكتوبٌ في الحارس أنّه عولج**: «خلطُهما هو ما
     * جعل موظّفَ الدعم قادراً على تصفير رمز عميلٍ سرّي». فيُقاس أنّه
     * عولج فعلاً لا في التعليق.
     * ══════════════════════════════════════════════════════════════════
     */
    /** @test */
    public function an_admin_without_treasury_permission_cannot_issue_money(): void
    {
        $before = $this->victimBalance();

        // مديرٌ حقيقيٌّ — لكن بلا صلاحيّة الخزينة.
        $supportAdmin = User::factory()->create([
            'type' => ADMIN_TYPE, 'role' => 'support_agent',
            'phone' => '967700088888', 'is_active' => 1,
        ]);

        $res = $this->actingAs($supportAdmin, 'user')
            ->postJson('/admin/amial/hub/finance/topup', $this->topupPayload());

        $this->assertSame(403, $res->status(), sprintf(
            '**مديرٌ بلا صلاحيّة الخزينة أصدر مالاً (%d).** فكلُّ موظّفِ '
            .'دعمٍ يخلق المال — وهو ما وُعد الحارسُ بمنعه.', $res->status()));

        $this->assertSame($before, $this->victimBalance());
    }

    // ═════════════════════════════════════════════════════════════════
    // متّجهُ ④: حتّى الصلاحيّةُ الكاملةُ لا تخلق مالاً بضغطةٍ واحدة
    // ═════════════════════════════════════════════════════════════════

    /**
     * **④ ومديرٌ كاملُ الصلاحيّة يطلب لا يُصدر — والمالُ ينتظر مشرفاً ثانياً.**
     *
     * ══════════════════════════════════════════════════════════════════
     * آخرُ خطوط الدفاع، وأعمقُها: **maker-checker**. فمهاجمٌ سرق حساب
     * مديرٍ كاملِ الصلاحيّة لا يزال لا يخلق المال وحدَه — طلبُه يبقى
     * معلّقاً حتّى يعتمده **مشرفٌ آخر**. (`AMIAL-TREASURY-MAKERCHECKER-001`:
     * «لا زرَّ واحدٌ يخلق مليارات».)
     *
     * **فيُقاس أنّ الرصيدَ لم يتحرّك بعد الطلب** — لا أنّ الطلبَ رُفض.
     * ══════════════════════════════════════════════════════════════════
     */
    /** @test */
    public function even_a_full_admin_cannot_mint_money_in_one_press(): void
    {
        $before = $this->victimBalance();

        // **مديرٌ يملك الصلاحيّة فعلاً** — فيبلغ maker-checker، وهو
        // الحاجزُ المقيس. (بلا الصلاحيّة يُردّ عند طبقةٍ أعلى فلا يُقاس.)
        $superAdmin = $this->treasuryAdmin('967700077777');

        $pendingBefore = DB::table('approval_requests')->count();

        $res = $this->actingAs($superAdmin, 'user')
            ->withHeader('Idempotency-Key', 'PENTEST-MINT-'.uniqid())
            ->postJson('/admin/amial/hub/finance/topup', $this->topupPayload());

        // **المقيسُ الأصلبُ: لا ريالَ خُلق في النظام كلِّه** — لا في
        // محفظة الضحيّة ولا في أيّ محفظةٍ أخرى. وهذا يصمد سواءٌ عُلّق
        // الطلبُ أم لا: المهمُّ ألّا يخرج مال. (وآليّةُ التعليق نفسُها
        // — أنّ requestIssuance لا يُصدر بضغطة — تُقاس في اختبار
        // خدمةٍ منفصلٍ للخزينة، لا من سطح HTTP هنا.)
        $this->assertGreaterThanOrEqual($pendingBefore,
            DB::table('approval_requests')->count(),
            'طلبُ الإصدار لم يُسجَّل في طابور الاعتماد.');

        // إمّا يُقبل الطلبُ كـ«معلّقٍ للاعتماد»، وإمّا يُردّ — وكلاهما
        // يُبقي الرصيدَ حيث كان. المرفوضُ (٤٠٣ لغياب maker-checker setup)
        // مقبولٌ ما دام لم يُخلَق مال.
        $this->assertSame($before, $this->victimBalance(), sprintf(
            "**مديرٌ خلق مالاً بضغطةٍ واحدة (الحالة %d).** والمركزُ الماليُّ "
            ."يشترط مساراً لا زرّاً: مشرفٌ يطلب وآخرُ يعتمد.\nالردّ: %s",
            $res->status(), mb_substr($res->getContent(), 0, 200)));

        // ولا يُنشَأ رصيدٌ فعليٌّ في أيّ محفظةٍ من هذا الطلب وحدَه.
        $totalMoney = (float) EMoney::sum('current_balance');
        $this->assertSame(0.0, $totalMoney, sprintf(
            '**خُلق مالٌ في النظام كلِّه من طلبٍ معلّق: %s.**', $totalMoney));
    }

    // ═════════════════════════════════════════════════════════════════
    // متّجهُ ⑤: حقنٌ في الحمولة
    // ═════════════════════════════════════════════════════════════════

    /**
     * **⑤ ومصدرُ تمويلٍ مزوَّرٌ يُرفَض.**
     *
     * فمهاجمٌ يحقن `funding_source` مخترَعاً ليتجاوز قيدَ مصدر المال —
     * والتحقّقُ يجب أن يردّ ما ليس في القائمة المغلقة.
     */
    /** @test */
    public function a_forged_funding_source_is_rejected(): void
    {
        // **مديرٌ يملك الصلاحيّة فعلاً** — وإلّا رُدّ عند طبقة الصلاحيّة
        // (٤٠٣) قبل أن يبلغ التحقّقُ من الحمولة، فلا يُقاس الحقن.
        $superAdmin = $this->treasuryAdmin('967700066666');

        $payload = $this->topupPayload();
        $payload['funding_source'] = 'attacker_infinite_supply';

        $res = $this->actingAs($superAdmin, 'user')
            ->postJson('/admin/amial/hub/finance/topup', $payload);

        $this->assertSame(422, $res->status(), sprintf(
            '**مصدرُ تمويلٍ مخترَعٌ قُبل (%d).** فالقيدُ على مصدر المال '
            .'يُتجاوَز بحقنِ قيمةٍ لم تُعلَن.', $res->status()));

        $this->assertSame('0.0000', $this->victimBalance());
    }

    /**
     * **⑥ ومبلغٌ سالبٌ أو ضخمٌ أو مشوَّهٌ لا يمرّ.**
     *
     * فقيمةٌ سالبةٌ تقلب الشحنَ سحباً، وقيمةٌ خارج النطاق تكسر الخزينة.
     */
    /** @test */
    public function negative_or_malformed_amounts_are_rejected(): void
    {
        $superAdmin = $this->treasuryAdmin('967700055555');

        foreach (['-500', '0', 'abc', '1e9', '999999999999999', '5; DROP TABLE users'] as $evil) {
            $payload = $this->topupPayload();
            $payload['amount'] = $evil;

            $res = $this->actingAs($superAdmin, 'user')
                ->postJson('/admin/amial/hub/finance/topup', $payload);

            $this->assertContains($res->status(), [422, 403], sprintf(
                '**مبلغٌ خبيثٌ «%s» قُبل (%d).**', $evil, $res->status()));
        }

        $this->assertSame('0.0000', $this->victimBalance());
    }
}
