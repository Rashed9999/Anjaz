<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\FxConversionService;
use App\Services\FxRateService;
use App\Services\LedgerService;
use App\Support\Access\AccessConstants as A;
use App\Support\Money\Currencies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-MULTI-CURRENCY-002 — **المحافظُ الحقيقيّة.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * سأل صاحبُ المشروع عن «تعدّد العملات» فقِيس، فإذا هي **ليست محافظ**:
 * جدولُ أسعارٍ يكتبها التاجرُ بيده لتُطبَع سطرَ مكافئٍ في أسفل الإيصال.
 * و`transactions` بلا عمود عملةٍ إطلاقاً، و`ledger_entry_lines` كذلك،
 * و**١٥ حساباً في الدفتر كلُّها `YER`**.
 *
 * فبُني ما طلبه: محفظةٌ لكلّ عملةٍ من أربع، تقبض وتُصرَف.
 *
 * **وأخطرُ ما في البناء ليس ما أُضيف بل ما كان سينكسر صامتاً** — وهذه
 * الحالاتُ تحرسه:
 */
class MultiCurrencyWalletsGuardTest extends TestCase
{
    use RefreshDatabase;

    private function merchant(string $plan = A::PLAN_ENTERPRISE): User
    {
        $u = User::factory()->create([
            'type' => MERCHANT_TYPE, 'role' => A::ROLE_MERCHANT,
            'is_active' => 1, 'is_kyc_verified' => 1, 'zone_code' => 'SOUTH',
        ]);

        MerchantProfile::create([
            'user_id' => $u->id, 'tier' => 'small',
            'verification_status' => 'verified',
            'business_type' => 'retail',
            'subscription_plan' => $plan,
            'single_receive_limit' => '5000000',
            'daily_receive_limit' => '50000000',
        ]);

        return $u;
    }

    private function seedRate(string $cur = 'USD', string $rate = '530'): void
    {
        app(FxRateService::class)->setRate($cur, $rate, 'initial_seed', 'حارس');
    }

    /**
     * محفظةٌ بعملةٍ ورصيدٍ ابتدائيّ.
     *
     * **ولا يُكتب قيدُ الافتتاح هنا** — يكتبه `EMoneyObserver` لحظةَ
     * الإنشاء، وهو المسارُ الحقيقيّ في الإنتاج. وكتابتُه مرّةً أخرى هنا
     * تُنتج ضِعفَ الرصيد في الدفتر، **فيخضرّ الحارسُ أو يحمرّ على شيءٍ
     * صنعتُه أنا لا على الشيفرة**. (وقد وقع: قِيس الدفترُ ٢٠٠٠ والمحفظةُ
     * ١٠٠٠ في أوّل تشغيل.)
     */
    private function fund(User $u, string $currency, string $amount): void
    {
        EMoney::create([
            'user_id' => $u->id, 'currency' => $currency, 'current_balance' => $amount,
            'charge_earned' => '0', 'pending_balance' => '0', 'held_balance' => '0',
            'zone_code' => 'SOUTH', 'version' => 0,
        ]);
    }

    // ═════════════════════════════════════════════════════════════════

    /**
     * **① الصمتُ ما زال يعني الريال — و١٦٤ موضعاً تعتمد عليه.**
     *
     * ══════════════════════════════════════════════════════════════════
     * في المشروع **١٦٤ موضعاً** يستعلم `e_money`، كُتبت كلُّها حين كان
     * للمستخدم محفظةٌ واحدة: عهدةُ الوكيل، والتسوية، والمصالحة، وحدودُ
     * السحب، ولوحةُ الإدارة. فمجرّدُ وجود صفٍّ بالدولار يجعل
     * `EMoney::where('user_id', $x)->first()` تُرجع **أيَّهما اتّفق**.
     *
     * **ولا يمسكه مُصرِّفٌ ولا اختبارٌ قائم**: الصيغةُ سليمة، والرقمُ
     * يُقرأ — من المحفظة الخطأ. فالنطاقُ العامُّ على النموذج يُثبّت
     * المعنى، وهذا يقيسه.
     */
    /** @test */
    public function an_unqualified_wallet_query_still_means_the_base_currency(): void
    {
        $u = $this->merchant();
        $this->fund($u, 'YER', '1000');
        $this->fund($u, 'USD', '77');

        $w = EMoney::where('user_id', $u->id)->first();

        $this->assertNotNull($w, 'لا محفظةَ أساسٍ — النطاقُ العامُّ يحجب أكثرَ ممّا يجب');
        $this->assertSame(Currencies::BASE, (string) $w->currency,
            '**استعلامٌ بلا عملةٍ أرجع محفظةً غيرَ الأساس** — و١٦٤ موضعاً '
            .'في المشروع تقرأ بهذه الصيغة وتعني الريال. عهدةُ الوكيل '
            .'والتسويةُ والحدودُ ستقرأ أرصدةً بعملةٍ أخرى ولا يُخرج ذلك خطأً.');
        $this->assertSame('1000.0000', (string) $w->current_balance);

        // ومن أراد غيرَه يقوله صراحةً.
        $usd = EMoney::inCurrency('USD')->where('user_id', $u->id)->first();
        $this->assertSame('77.0000', (string) $usd->current_balance);
    }

    /**
     * **② وقيدٌ يخلط عملتين مرفوض.**
     *
     * «مدينٌ ١٠٠ / دائنٌ ١٠٠» عبر عملتين يجتاز فحصَ التوازن **عدداً**،
     * وهو **خلقُ مالٍ من فرق الصرف** بلا حسابٍ يستقبله. ولا يمسكه ميزانُ
     * مراجعةٍ لأنّه سيتوازن عددياً هو الآخر.
     */
    /** @test */
    public function a_journal_entry_may_not_mix_two_currencies(): void
    {
        $u = $this->merchant();
        $this->fund($u, 'YER', '1000');
        $this->fund($u, 'USD', '50');

        $l = app(LedgerService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('~Cross-currency~');

        $l->post('bad', null, 'خلط', [
            ['account' => "USER_WALLET_{$u->id}_USD", 'direction' => 'debit', 'amount' => '10'],
            ['account' => "USER_WALLET_{$u->id}", 'direction' => 'credit', 'amount' => '10'],
        ]);
    }

    /**
     * **③ والصرفُ يقع قيدين، كلٌّ متوازنٌ داخل عملته.**
     *
     * وهو **جوابُ حاجزِ الخزينة**: الوكلاءُ يحملون ريالاً نقداً، فتاجرٌ
     * رصيدُه دولارٌ لا يجد من يصرفه. فيحوّل في التطبيق ثمّ يُسوّى بالريال
     * كما اليوم — والوكيلُ لا يمسّ دولاراً قطّ.
     */
    /** @test */
    public function converting_moves_money_and_leaves_two_balanced_entries(): void
    {
        $this->seedRate('USD', '530');
        $u = $this->merchant();
        $this->fund($u, 'USD', '100');

        $r = app(FxConversionService::class)->convert($u->id, 'USD', 'YER', '40');

        $this->assertSame('21200.0000', $r['converted'], 'المكافئُ خاطئ');
        $this->assertSame('60.0000', (string) EMoney::inCurrency('USD')
            ->where('user_id', $u->id)->value('current_balance'));
        $this->assertSame('21200.0000', (string) EMoney::where('user_id', $u->id)
            ->value('current_balance'));

        // **وكلُّ قيدٍ متوازنٌ داخل عملته** — وهو الشرطُ الذي يجعل ميزانَ
        // المراجعة قابلاً للقراءة أصلاً بعد تعدّد العملات.
        $rows = DB::table('ledger_journal_entries as j')
            ->join('ledger_entry_lines as l', 'l.journal_entry_id', '=', 'j.id')
            ->whereIn('j.source_type', ['fx_convert_out', 'fx_convert_in'])
            ->groupBy('j.id', 'j.currency', 'j.source_type')
            ->selectRaw("j.source_type, j.currency,
                SUM(CASE WHEN l.direction='debit' THEN l.amount ELSE 0 END) d,
                SUM(CASE WHEN l.direction='credit' THEN l.amount ELSE 0 END) c")->get();

        $this->assertCount(2, $rows, 'الصرفُ لم يُنتج قيدين');

        $byType = collect($rows)->keyBy('source_type');
        foreach ($rows as $x) {
            $this->assertSame(0, bccomp((string) $x->d, (string) $x->c, 4),
                "**قيدُ {$x->source_type} غيرُ متوازن** — مدين={$x->d} دائن={$x->c}");
        }

        // **وعملةُ كلّ قيدٍ مسجّلةٌ صحيحةً** — وهذا ما سقط أوّلَ قياس:
        // `currency` لم يكن في `$fillable`، فـ`create()` أسقطه **صامتاً**
        // ووقع العمودُ على افتراضيّ القاعدة `YER`. فقيدُ صرفٍ بأربعين
        // دولاراً سُجّل «ريالاً» — يوازن، ويُقرأ، ويمرّ، ويكذب.
        $this->assertSame('USD', (string) $byType['fx_convert_out']->currency,
            '**قيدُ الخروج سُجّل بعملةٍ خاطئة** — راجع `$fillable` في '
            .'`LedgerJournalEntry`: الإسقاطُ صامتٌ ولا يُخرج خطأً.');
        $this->assertSame('YER', (string) $byType['fx_convert_in']->currency);
    }

    /**
     * **④ ولا صرفَ بلا سعرٍ مضبوط — ولا يُفترَض السعرُ واحداً.**
     *
     * سعرُ ١ للدولار مقابل الريال يجعل مئةَ دولارٍ مئةَ ريال: **محوُ
     * ٩٩٫٨٪ من المال في سطرٍ لا يُخرج خطأً**. (القاعدة السابعة: «غير
     * معروف» ليس واحداً كما ليس صفراً.)
     */
    /** @test */
    public function conversion_refuses_when_no_rate_has_been_set(): void
    {
        $u = $this->merchant();
        $this->fund($u, 'AED', '100');   // ولا سعرَ للدرهم

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('~لا سعرَ صرفٍ مضبوط~');

        app(FxConversionService::class)->convert($u->id, 'AED', 'YER', '10');
    }

    /**
     * **⑤ وحدُّ الاستلام لا يُلتَفُّ عليه بتغيير العملة.**
     *
     * ══════════════════════════════════════════════════════════════════
     * حدودُ التاجر بالريال منذ كُتبت. فمقارنةُ مبلغٍ بالدولار بحدٍّ بالريال
     * ليست مقارنة: تاجرٌ حدُّه خمسةُ ملايين ريال يقبض عشرةَ آلاف دولار —
     * **خمسةَ ملايينَ وثلاثمئة ألف** — ويمرّ لأنّ ١٠٬٠٠٠ < ٥٬٠٠٠٬٠٠٠.
     *
     * **وهو التفافٌ كاملٌ على حدود مكافحة الغسيل بقائمةٍ منسدلة**، ولا
     * يُخرج خطأً: الرقمان يُقارنان، والحارسُ يمرّ، وهو لم يقس شيئاً.
     */
    /** @test */
    public function the_receive_limit_is_measured_in_base_equivalent(): void
    {
        $this->seedRate('USD', '530');
        $u = $this->merchant();   // الحدُّ ٥٬٠٠٠٬٠٠٠ ر.ي

        $risk = app(\App\Services\MerchantRiskService::class);

        // ١٠٬٠٠٠ دولارٍ = ٥٬٣٠٠٬٠٠٠ ر.ي — **فوق الحدّ**.
        try {
            $risk->assertReceiveAllowed($u->id, '10000', 'USD');
            $this->fail(
                '**عشرةُ آلاف دولارٍ (٥٬٣٠٠٬٠٠٠ ر.ي) مرّت على حدٍّ قدرُه '
                .'٥٬٠٠٠٬٠٠٠ ر.ي** — الحدُّ يُقارَن بالرقم الخام لا بالمكافئ، '
                .'فيُلتَفُّ عليه بتغيير العملة.'
            );
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('حد الاستلام', $e->getMessage());
            // **والرسالةُ تقول العملتين** — «يتجاوز الحدّ (٥٠٠٠٠٠٠)» على
            // من أدخل ١٠٠٠٠ رسالةٌ لا تُفهَم.
            $this->assertStringContainsString('ر.ي', $e->getMessage(),
                'الرسالةُ لا تُظهر المكافئَ فلا يفهم التاجرُ سببَ الرفض');
        }

        // وتسعةُ آلافٍ (٤٬٧٧٠٬٠٠٠ ر.ي) دونه — فتمرّ.
        $risk->assertReceiveAllowed($u->id, '9000', 'USD');
        $this->assertTrue(true);
    }

    /**
     * **⑥ والمصالحةُ الليليّة تطابق لكلّ (مستخدم، عملة).**
     *
     * ══════════════════════════════════════════════════════════════════
     * كان الجمعُ بـ`owner_user_id` وحدَه ومرشِّحُه `USER_WALLET_%` —
     * **وهو يلتقط `USER_WALLET_5_USD` بالبادئة**. فمحفظةُ دولارٍ واحدةٌ
     * تُضاف إلى دلو الريال ثمّ تُقارَن بمحفظة الريال وحدَها: **فرقٌ كاذبٌ
     * كلَّ ليلةٍ على تاجرٍ لم يخطئ**، وقضيّةُ مصالحةٍ تُفتَح، وإنذارٌ
     * يُرسَل ٠٢:٠٠.
     *
     * وهو نمطُ «حارسٍ يكذب»: يُعوّد القارئَ أن يتجاهله يومَ يصدق.
     */
    /** @test */
    public function nightly_reconciliation_does_not_invent_a_gap_for_foreign_wallets(): void
    {
        $u = $this->merchant();
        $this->fund($u, 'YER', '1000');
        $this->fund($u, 'USD', '50');

        $report = app(\App\Services\LedgerReportService::class)->walletReconciliation(50);

        $mine = collect($report['rows'])->where('user_id', $u->id);

        $this->assertGreaterThanOrEqual(2, $mine->count(),
            '**محفظةٌ لم تُفحَص** — وغيابُها عن التقرير يُقرأ «صفرُ فرق» '
            .'وهو «لم يُنظَر». (القاعدة السابعة.)');

        $diverged = $mine->where('diverged', true);
        $this->assertSame(0, $diverged->count(), sprintf(
            "**فرقٌ كاذبٌ على محفظةٍ سليمة:**\n  %s\n\n"
            .'الدفترُ والمحفظةُ متطابقان في كلّ عملة — والفرقُ من خلط '
            .'`USER_WALLET_5_USD` بدلو الريال بالبادئة.',
            $diverged->map(fn ($r) => sprintf('%s: محفظة=%s دفتر=%s',
                $r['currency'] ?? '?', $r['wallet_balance'], $r['ledger_balance']))->implode("\n  ")
        ));
    }

    /**
     * **⑦ ولا يُقبَل القبضُ بعملةٍ بلا سعر.**
     *
     * فبيعةٌ بعملةٍ لا يُعرف مكافئُها لا يُحسَب عليها حدُّ استلامٍ ولا
     * تدخل تقريراً — تصير مالاً خارج كلّ رقابة.
     */
    /** @test */
    public function a_merchant_cannot_accept_a_currency_that_has_no_rate(): void
    {
        $u = $this->merchant();

        $r = $this->actingAs($u, 'api')->postJson(
            '/api/v1/amial/merchant/wallets/accept',
            ['currency' => 'AED', 'accepts' => true]
        );

        $this->assertSame(422, $r->status(),
            '**قُبلت عملةٌ بلا سعرٍ مضبوط** — فتُقيَّد بيعةٌ لا يُعرف مكافئُها.');
        $this->assertStringContainsString('لا سعرَ صرفٍ', (string) $r->json('message'));
    }

    /**
     * **⑧ والقدرةُ محروسةٌ في الخادم لا بإخفاء الشاشة.**
     *
     * (amial-rbac: «Frontend hiding is NOT security».)
     */
    /** @test */
    public function the_wallets_door_is_gated_by_plan_on_the_server(): void
    {
        $free = $this->merchant(A::PLAN_FREE);

        $r = $this->actingAs($free, 'api')->getJson('/api/v1/amial/merchant/wallets');

        $this->assertSame(402, $r->status(),
            '**تاجرٌ بباقةٍ مجّانيّة بلغ محافظَ العملات** — والقدرةُ مُسعَّرةٌ '
            .'لباقة المؤسّسة. وإخفاءُ الشاشة ليس حماية.');

        $ent = $this->merchant(A::PLAN_ENTERPRISE);
        $ok = $this->actingAs($ent, 'api')->getJson('/api/v1/amial/merchant/wallets');
        $this->assertSame(200, $ok->status(),
            'باقةُ المؤسّسة لا تبلغ بابَها — القفلُ أوسعُ من المطلوب');

        // **وكلُّ عملةٍ مدعومةٍ معروضةٌ ولو بصفر** — محفظةٌ لا تُعرَض لا
        // يعرف التاجرُ أنّها متاحةٌ أصلاً. (القاعدة الثانية عشرة.)
        $codes = collect($ok->json('meta.wallets'))->pluck('currency')->all();
        $this->assertSame(Currencies::codes(), $codes,
            '**عملةٌ مدعومةٌ غائبةٌ عن الشاشة** — مبنيٌّ ولا يُوصَل إليه.');
    }

    /**
     * **⑨ وسعرُ الصرف لا يُعدَّل ولا يُحذَف.**
     *
     * تغييرُ صفٍّ قائمٍ يُعيد كتابةَ مكافئ كلّ فاتورةٍ صدرت بذلك السعر.
     * وهو ما وقع في تسعيرة الباقات من قبل بصورةٍ أفدح.
     */
    /** @test */
    public function an_fx_rate_is_append_only(): void
    {
        $this->seedRate('USD', '530');
        $rate = \App\Models\FxRate::where('currency', 'USD')->firstOrFail();

        try {
            $rate->rate_to_base = '999';
            $rate->save();
            $this->fail('**سعرُ صرفٍ قائمٌ عُدِّل** — فمكافئُ كلّ فاتورةٍ '
                .'صدرت بذلك السعر تغيّر بأثرٍ رجعيّ.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('لا يُعدَّل', $e->getMessage());
        }

        // والسعرُ الجديد صفٌّ جديد، والقديمُ يبقى ليُقرأ به زمنُه.
        app(FxRateService::class)->setRate('USD', '545', 'manual_admin', 'تحديث');
        $this->assertSame(2, \App\Models\FxRate::where('currency', 'USD')->count());
        $this->assertSame('545.00000000', app(FxRateService::class)->rateToBase('USD'));
    }
}
