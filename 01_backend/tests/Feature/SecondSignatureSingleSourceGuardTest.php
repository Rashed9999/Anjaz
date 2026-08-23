<?php

namespace Tests\Feature;

use App\Models\Settlement;
use App\Models\User;
use App\Services\UniversalSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-SETTLE-SECOND-SIG-001 — **قاعدةٌ ماليّةٌ مكتوبةٌ أربعَ مرّات.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما قِيس:**
 *
 *   · `UniversalSettlementService::awaitingSecondApproval` مبنيّةٌ
 *     و**بصفرِ مُنادٍ** — أخرجها جردُ ساهر
 *   · وشاشةُ تسويات الشركاء تُعيد اشتقاقَها في جافاسكربت **ثلاثَ مرّات**:
 *     شارةُ العدّ العليا · لونُ الصفّ · خليّةُ حالة التوقيعين
 *
 * **والنسخُ الثلاثُ تخالف الأصلَ في شرطٍ واحد:**
 *
 *     PHP : approvals_required >= 2 && approved_by
 *           && !second_approved_by && **status = PENDING_APPROVAL**
 *     JS  : الثلاثةُ الأُوَل فقط
 *
 * و`reject()` **لا يمسح `approved_by`**. فتسويةٌ وُقّعت مرّةً ثمّ رُفضت
 * كانت تبقى في الشاشة «بانتظار التوقيع الثاني» **وتُعدّ في الشارة**.
 * فيفتح مسؤولُ المال طابوراً فيه ما ليس فيه — ويلاحق توقيعاً لا يُطلَب.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وهذا ما تمنعه `amial-admin-command` بنصّها:** «لوحةُ الإدارة يجب ألّا
 * تصير مصدرَ حقيقةٍ ماليّةٍ ثانياً». والمحرّكُ هو المصدر، واللوحةُ تقرأ.
 *
 * **وصنفُ العطل ليس «مبنيٌّ ولا يُوصَل إليه» وحدَه** — بل تعارضٌ: النسخةُ
 * الصحيحةُ موجودةٌ ومهجورة، والمهجورةُ هي الأصحّ. فمن أصلح إحداهما لم
 * يُصلح الأخرى، ولا شيءَ كان يقول إنّ ثمّةَ أخرى.
 */
class SecondSignatureSingleSourceGuardTest extends TestCase
{
    use RefreshDatabase;

    private function svc(): UniversalSettlementService
    {
        return app(UniversalSettlementService::class);
    }

    /**
     * تسويةٌ تحتاج توقيعين، وُقّعت مرّةً واحدة.
     *
     * **ولا مصنعَ لـ`Settlement`** — فتُبنى بأعمدتها الإلزاميّة كما
     * تقولها القاعدة، لا كما تُخمَّن.
     */
    private function settlement(array $attrs = []): Settlement
    {
        static $n = 0;
        $n++;

        return Settlement::create(array_merge([
            'reference_number' => 'STL-TEST-' . str_pad((string) $n, 6, '0', STR_PAD_LEFT),
            'ulid' => (string) \Illuminate\Support\Str::ulid(),
            'source_ledger_account_id' => $this->ledgerAccountId(),
            'destination_partner_id' => $this->partnerId(),
            'amount' => '250000.0000',
            'status' => Settlement::STATUS_PENDING_APPROVAL,
        ], $attrs));
    }

    private function halfSigned(string $status = Settlement::STATUS_PENDING_APPROVAL): Settlement
    {
        return $this->settlement([
            'approvals_required' => 2,
            'approved_by' => User::factory()->create()->id,
            'second_approved_by' => null,
            'status' => $status,
        ]);
    }

    /** حسابُ دفترٍ حقيقيّ — المفتاحُ الأجنبيُّ يرفض رقماً مخترَعاً. */
    private function ledgerAccountId(): int
    {
        $id = \Illuminate\Support\Facades\DB::table('ledger_accounts')->value('id');

        if ($id) {
            return (int) $id;
        }

        return (int) \Illuminate\Support\Facades\DB::table('ledger_accounts')->insertGetId([
            'account_code' => 'TEST_SRC', 'account_type' => 'asset',
            'name_ar' => 'حساب اختبار',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function partnerId(): int
    {
        $id = \Illuminate\Support\Facades\DB::table('settlement_partners')->value('id');

        if ($id) {
            return (int) $id;
        }

        return (int) \Illuminate\Support\Facades\DB::table('settlement_partners')->insertGetId([
            'code' => 'TESTP', 'name_ar' => 'شريك اختبار',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function screenSource(): string
    {
        return (string) file_get_contents(base_path(
            'resources/views/admin-views/amial/settlements_partners/index.blade.php'));
    }

    // ══════════════════════════════════════════════════════════════════
    // القاعدةُ نفسُها
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function a_half_signed_pending_settlement_is_awaiting(): void
    {
        $this->assertTrue(
            $this->svc()->awaitingSecondApproval($this->halfSigned()),
            'تسويةٌ وُقّعت مرّةً وما زالت معلَّقةً لا تُعدّ منتظِرة — فتُنسى');
    }

    /** @test */
    public function a_rejected_settlement_leaves_the_queue(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **هذا هو الفرقُ بين النسختين، مُصاغاً اختباراً.**
        //
        // `reject()` لا يمسح `approved_by` — فالشروطُ الثلاثةُ التي
        // تعرفها الشاشةُ تبقى صادقةً على تسويةٍ **مرفوضة**.
        // ══════════════════════════════════════════════════════════════
        $s = $this->halfSigned(Settlement::STATUS_REJECTED);

        $this->assertFalse($this->svc()->awaitingSecondApproval($s),
            'تسويةٌ مرفوضةٌ ما زالت تُحسب منتظِرةً توقيعاً — '
            . 'فيلاحق مسؤولُ المال توقيعاً لا يُطلَب');
    }

    /** @test */
    public function one_signature_settlements_are_never_awaiting(): void
    {
        // **وحاجزٌ يشلّ عملاً سليماً يُطفَأ عند أوّل شكوى**: تسويةٌ يكفيها
        // توقيعٌ واحد لا تنتظر ثانياً.
        $s = $this->settlement([
            'approvals_required' => 1,
            'approved_by' => User::factory()->create()->id,
            'second_approved_by' => null,
        ]);

        $this->assertFalse($this->svc()->awaitingSecondApproval($s));
    }

    // ══════════════════════════════════════════════════════════════════
    // مصدرٌ واحد
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_screen_reads_the_field_and_never_re_derives_it(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **والمنعُ على الاشتقاق لا على الكلمات.** ثلاثُ نسخٍ في الشاشة
        // كانت تكتب الشرطَ يدويّاً؛ ونسخةٌ رابعةٌ ستُكتب يوماً وتخالف
        // الثلاث. فيُمنع النمطُ نفسُه.
        // ══════════════════════════════════════════════════════════════
        $v = $this->screenSource();

        $this->assertStringContainsString('s.awaiting_second_approval', $v,
            'الشاشةُ لا تقرأ الحقلَ المحسوب — فهي مصدرُ حقيقةٍ ثانٍ');

        $this->assertDoesNotMatchRegularExpression(
            '~approvals_required[^\n]{0,80}>=\s*2[^\n]{0,80}approved_by~', $v,
            'الشاشةُ ما زالت تشتقّ قاعدةَ التوقيع الثاني بنفسها — '
            . 'ونسخةٌ تخالف الأصلَ تُظهر في الطابور ما ليس فيه');
    }

    /** @test */
    public function the_endpoint_actually_sends_the_field(): void
    {
        // **وحقلٌ تقرؤه الشاشةُ ولا يرسله الخادم يُقرأ `undefined`** —
        // أي «غيرُ منتظِرة» دائماً، فيختفي الطابورُ كلُّه بصمت.
        // وهو أسوأُ من العطل الأوّل: ذاك يُظهر زائداً، وهذا يُخفي.
        $src = (string) file_get_contents(base_path(
            'app/Http/Controllers/Api/V1/Amial/SettlementController.php'));

        $src = preg_replace('~/\*.*?\*/~s', '', $src) ?? '';
        $src = preg_replace('~^[ \t]*//[^\n]*$~m', '', $src) ?? '';

        $this->assertStringContainsString("'awaiting_second_approval'", $src,
            'نقطةُ النهاية لا ترسل الحقل — فالشاشةُ تقرأ `undefined` '
            . 'ويختفي طابورُ التوقيع الثاني كلُّه');

        $this->assertStringContainsString('awaitingSecondApproval($s)', $src,
            'الحقلُ يُحسب في المتحكّم لا في الخدمة — فنسختان من جديد');
    }

    /** @test */
    public function the_field_travels_end_to_end(): void
    {
        // **ولا يُكتفى بقراءة الشيفرة.** الحقلُ يُطلَب من النقطة الحيّة،
        // فسلسلةُ `toArray` والموارد والتصفية تُختبَر كما هي.
        // **والمديرُ `type = 0`؛ و`1` هو الوكيل** — والتعليقُ في
        // `isAdmin` يقوله صراحةً: «لا النوع ١ (وكيل)». وأوّلُ كتابةٍ
        // استعملت ١ فرُدّت ٤٠٣، وكان التخطّي سيُخفي ذلك.
        $admin = User::factory()->create(['type' => 0]);
        $s = $this->halfSigned();

        // **ويُطلَب بالاسم لا بمسارٍ مكتوب.** أوّلُ كتابةٍ خمّنت
        // `/api/v1/amial/settlements` فردّت ٤٠٤ — والمسارُ الحقيقيّ
        // `admin/settlements`. ومسارٌ مكتوبٌ يدويّاً يشيخ مع أوّل نقل.
        $res = $this->actingAs($admin, 'api')
            ->getJson(route('amial.admin.settlements.index', ['per_page' => 50]));

        // **ولا تخطٍّ هنا.** اختبارٌ يُخطّى على ٤٠٣ يُخفي أنّ الهويّةَ
        // خاطئة، ويُقرأ أخضرَ وهو لم يمسّ النقطةَ إطلاقاً.
        $res->assertOk();

        $row = collect($res->json('meta.settlements') ?? [])
            ->firstWhere('id', $s->id);

        $this->assertNotNull($row, 'التسويةُ لم تعد في القائمة');

        $this->assertArrayHasKey('awaiting_second_approval', $row,
            'الحقلُ لا يصل الشاشةَ فعلاً — والقراءةُ من الشيفرة لا تُثبت الوصول');

        $this->assertTrue($row['awaiting_second_approval']);
    }
}
