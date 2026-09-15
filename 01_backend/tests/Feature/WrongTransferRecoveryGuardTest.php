<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WrongTransferClaim;
use App\Services\MoneyService;
use App\Services\WrongTransferRecoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * AMIAL-WRONG-TRANSFER-001 — **السؤالُ الذي يقيس نضجَ المشروع.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الحالةُ كما وصلت حرفاً:** «أحمد عليّ حوّل ١٠٠ ألفٍ فأخطأ في إدخال
 * رقم الهاتف، فوصل المبلغُ إلى محفظة سالم نور. ولنزد التعقيد: سالمٌ
 * صرفه عبر مشترياتٍ عند التجّار».
 *
 * **وكان الجوابُ قبل هذا الحارس: لا شيء.** آليّةُ النزاعات تنقل المالَ
 * بقيدٍ مزدوج، **وتفترض أنّ الرصيدَ موجود**. فإن صُرِف سقطت باستثناء —
 * **والملفُّ كان قد حُفظ «disputed» قبلها بلا `try/catch`**.
 *
 * فيبقى للعميل ملفٌّ يقول «حُلّ» ولا ريالَ تحرّك.
 *
 * **وكلُّ حالةٍ هنا جُرّبت بالعكس** (القاعدة الثانية): كُسر ما تحرسه
 * فسقطت، ثمّ أُعيد.
 */
class WrongTransferRecoveryGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $ahmed;    // المُرسِل — أخطأ الرقم

    private User $salem;    // المستلِم — وصله ما ليس له

    private User $merchant; // التاجر — عنده أنفق سالم

    private WrongTransferRecoveryService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->svc = app(WrongTransferRecoveryService::class);

        $this->ahmed = User::factory()->create(['zone_code' => 'SOUTH', 'phone' => '+967700111222', 'type' => 2]);
        $this->salem = User::factory()->create(['zone_code' => 'SOUTH', 'phone' => '+967700111223', 'type' => 2]);
        $this->merchant = User::factory()->create(['zone_code' => 'SOUTH', 'phone' => '+967700999888', 'type' => 3]);

        foreach ([$this->ahmed, $this->salem, $this->merchant] as $u) {
            EMoney::create(['user_id' => $u->id, 'current_balance' => '0.0000',
                'held_balance' => '0.0000', 'pending_balance' => '0.0000',
                'charge_earned' => '0.0000', 'zone_code' => 'SOUTH', 'version' => 0]);
        }
    }

    // ═════════════════════════════════════════════════════════════════

    /**
     * **① الحالةُ كاملةً: حُوِّل ١٠٠ ألف، أُنفق ٦٠، فاسترُدّ ٤٠ وبقيت ٦٠ ذمّةً.**
     *
     * ══════════════════════════════════════════════════════════════════
     * وهذا هو الفرقُ بين «لا حلَّ لدينا» و«حلٌّ جزئيٌّ يكمُل لاحقاً».
     * فالحجزُ الصارم (`hold`) كان سيرمي على النقص فلا يُحجَز **شيء**،
     * والأربعون الباقيةُ تُنفَق في الدقائق التالية.
     * ══════════════════════════════════════════════════════════════════
     */
    /** @test */
    public function a_partly_spent_wrong_transfer_recovers_what_is_left_and_books_the_rest_as_a_debt(): void
    {
        $tx = $this->sendMoney('100000');
        $this->spendAtMerchant($this->salem, '60000');

        $claim = $this->svc->open($tx, '+967700111233');

        $this->assertSame(WrongTransferClaim::HOLDING, $claim->status,
            '**لم يُحجَز شيءٌ من الأربعين الباقية** — فالمالُ يُنفَق بينما الملفُّ يُدرَس.');

        $this->assertSame('40000.0000', (string) $claim->held_amount,
            'المحجوزُ ليس الموجودَ كلَّه.');

        $this->assertSame('0.0000', (string) $this->wallet($this->salem)->current_balance,
            '**بقي في متناول سالمٍ ما حُجز** — فالحجزُ لا يوقف نزيفاً.');

        $claim = $this->svc->resolve($claim, null, 'أُثبت خطأُ الرقم بفارق رقمٍ واحد');

        $this->assertSame('40000.0000', (string) $this->wallet($this->ahmed)->current_balance,
            'لم يصل المستردُّ إلى أحمد.');

        $this->assertSame('60000.0000', $claim->outstanding(),
            '**ما أُنفق ضاع بلا أثر** — فلا يُسجَّل في أيّ موضعٍ أنّ على سالمٍ ستّين ألفاً.');

        $this->assertTrue(
            MoneyService::compare((string) $this->wallet($this->salem)->current_balance, '0') >= 0,
            '**رصيدٌ سالب** — وهو ثابتٌ يفحصه ضغطُ البوّابة، وكسرُه هنا يفتح باباً في كلّ مسار.');
    }

    /**
     * **② والذمّةُ تُقتطَع من الوارد — تلقائيّاً وبلا تدخّل.**
     *
     * فهذا هو نصفُ الجواب الثاني عن «صرفها عند التجّار»: المالُ يعود على
     * دفعاتٍ كلّما دخل حسابَ سالمٍ ريال. **وما لا يجري تلقائيّاً لا يجري.**
     */
    /** @test */
    public function the_debt_is_collected_from_later_incoming_money(): void
    {
        $tx = $this->sendMoney('100000');
        $this->spendAtMerchant($this->salem, '60000');

        $claim = $this->svc->resolve($this->svc->open($tx, '+967700111233'), null, 'ثبت الخطأ');
        $this->assertSame('60000.0000', $claim->outstanding());

        // دخل سالماً ٢٥ ألفاً بعد أسبوع.
        $this->creditWallet($this->salem, '25000');

        $collected = $this->svc->collectReceivables();

        $this->assertSame('25000.0000', $collected,
            '**دخل المالُ ولم يُقتطَع منه ريال** — فالذمّةُ سطرٌ في جدولٍ لا أثرَ له.');

        $this->assertSame('0.0000', (string) $this->wallet($this->salem)->current_balance,
            'اقتُطع أقلُّ ممّا وجب أو أكثر.');

        $this->assertSame('65000.0000', (string) $this->wallet($this->ahmed)->current_balance,
            'المُقتطَعُ لم يصل أحمد.');

        $this->assertSame('35000.0000', $claim->fresh()->outstanding(),
            'الذمّةُ لم تنقص بما حُصِّل — فسيُحصَّل مرّتين.');
    }

    /**
     * **③ ولا يُقتطَع أكثرُ من الذمّة.**
     *
     * فمن دخله مليونٌ وعليه ستّون ألفاً لا يُؤخَذ منه إلّا ستّون. وهذا
     * الحارسُ **سقط أوّلَ ما جُرّب بالعكس** حين جُعل الاقتطاعُ يأخذ كلَّ
     * المتاح.
     */
    /** @test */
    public function collection_never_takes_more_than_what_is_owed(): void
    {
        $tx = $this->sendMoney('100000');
        $this->spendAtMerchant($this->salem, '60000');
        $claim = $this->svc->resolve($this->svc->open($tx, '+967700111233'), null, 'ثبت الخطأ');

        $this->creditWallet($this->salem, '1000000');

        $this->assertSame('60000.0000', $this->svc->collectReceivables(),
            '**اقتُطع أكثرُ من الذمّة** — فالاستردادُ صار عقوبة.');

        $this->assertSame('940000.0000', (string) $this->wallet($this->salem)->current_balance,
            'مالُ سالمٍ الذي لا علاقةَ له بالحادثة نُقص.');

        $this->assertSame('0.0000', $claim->fresh()->outstanding());
        $this->assertSame('0.0000', $this->svc->collectReceivables(),
            'الذمّةُ سُدّدت ثمّ حُصِّلت ثانيةً.');
    }

    /**
     * **④ والدفعُ لتاجرٍ يُرفَض — ولو بحرف.**
     *
     * ══════════════════════════════════════════════════════════════════
     * فمن اشترى ثمّ ندم يستردّ من التاجر لا من هذا الباب. ولولا هذا
     * الحدُّ لصار كلُّ زبونٍ نادمٍ يسحب مالَه من صندوق تاجرٍ سلّم بضاعتَه
     * — **وهو أخطرُ استعمالٍ ممكنٍ لهذه الميزة**.
     * ══════════════════════════════════════════════════════════════════
     */
    /** @test */
    public function a_payment_to_a_merchant_is_refused(): void
    {
        $ref = 'TXPAY'.uniqid();

        Transaction::create([
            'user_id' => $this->ahmed->id, 'transaction_id' => $ref,
            'transaction_type' => PAYMENT, 'debit' => '5000', 'credit' => '0',
            'amount' => '5000', 'balance' => '0',
            'from_user_id' => $this->ahmed->id, 'to_user_id' => $this->merchant->id,
            'zone_code' => 'SOUTH', 'transaction_no' => '20'.random_int(1000000000000, 9999999999999),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/تاجر/u');

        $this->svc->open($ref, '+967700111233');
    }

    /**
     * **⑤ والحجزُ يُفرَج عنه إن مضت المهلةُ بلا قرار.**
     *
     * فحجزٌ بلا نهايةٍ ليس إجراءً احترازيّاً — هو مصادرةٌ بيد الدعم على
     * من لم تثبت عليه دعوى.
     */
    /** @test */
    public function an_undecided_hold_is_released_when_its_clock_runs_out(): void
    {
        $tx = $this->sendMoney('100000');
        $claim = $this->svc->open($tx, '+967700111233');

        $this->assertSame('100000.0000', (string) $claim->held_amount);
        $this->assertSame('0.0000', (string) $this->wallet($this->salem)->current_balance);

        $claim->forceFill(['hold_expires_at' => now()->subMinute()])->save();

        $this->assertSame(1, $this->svc->expireStale());

        $this->assertSame(WrongTransferClaim::EXPIRED, $claim->fresh()->status);

        $this->assertSame('100000.0000', (string) $this->wallet($this->salem)->current_balance,
            '**مضت المهلةُ وبقي المالُ محجوزاً** — عقوبةٌ بلا حكم.');

        $this->assertSame('0.0000', (string) $this->wallet($this->salem)->held_balance,
            'الحجزُ لم يُرفَع من عمود المحجوز.');
    }

    /** **⑥ والرفضُ يُفرِج فوراً — لا بعد انتظار المهلة.** */
    /** @test */
    public function rejecting_a_claim_releases_the_hold_at_once(): void
    {
        $tx = $this->sendMoney('100000');
        $claim = $this->svc->open($tx, '+967700111233');

        $claim = $this->svc->reject($claim, null, 'الرقمُ محفوظٌ لديه ولا خطأ');

        $this->assertSame(WrongTransferClaim::REJECTED, $claim->status);
        $this->assertSame('100000.0000', (string) $this->wallet($this->salem)->current_balance,
            '**رُفضت الدعوى وبقي المالُ محجوزاً** — رفضٌ بلا أثر.');
    }

    /**
     * **⑦ ودعوى واحدةٌ حيّةٌ لكلّ عمليّة — وإلّا حُجز المبلغُ مرّتين.**
     *
     * فمن فتح دعويين على تحويلٍ واحدٍ يحجز ضعفَ ما استُلم، من مالٍ لا
     * علاقةَ له بالحادثة.
     */
    /** @test */
    public function one_transaction_cannot_carry_two_live_claims(): void
    {
        $tx = $this->sendMoney('100000');
        $this->svc->open($tx, '+967700111233');

        $this->expectException(\RuntimeException::class);
        $this->svc->open($tx, '+967700111233');
    }

    /**
     * **⑧ والمحتالُ يُحسَب حسابُه: لا حجزَ تلقائيَّ على دعوى عاليةِ الريبة.**
     *
     * ══════════════════════════════════════════════════════════════════
     * وهذه أهمُّ حالةٍ في الملفّ. بابُ استردادٍ بلا تقديرٍ يُستعمَل مرّتين
     * ثمّ يُغلَق على الجميع: يشتري المحتالُ من بائعٍ ثمّ يدّعي «أخطأتُ
     * الرقم» فيُنتزَع مالُ البائع بضغطة.
     *
     * **والدعوى تُفتَح ولا تُرفَض** — فالفرقُ بين «لا تُحجَز» و«تُرفَض»
     * جوهريّ: الأولى تُبقي الملفَّ أمام إنسانٍ يقرّر، والثانيةُ تُغلق
     * البابَ على شكوى قد تصدق. (القاعدة السابعة: الشكُّ ليس حكماً.)
     * ══════════════════════════════════════════════════════════════════
     */
    /** @test */
    public function a_high_risk_claim_opens_for_review_but_holds_nothing(): void
    {
        // سابقةُ تحويلٍ إلى سالمٍ نفسِه (يعرفه)، ورقمٌ مقصودٌ بعيدٌ جدّاً
        // عن رقمه، وبلاغٌ بعد أسبوعين.
        $this->sendMoney('5000', now()->subMonths(2));

        $tx = $this->sendMoney('100000', now()->subDays(14));

        $claim = $this->svc->open($tx, '+967733555444');

        $this->assertGreaterThanOrEqual(
            WrongTransferRecoveryService::SCORE_BLOCKS_AUTO_HOLD,
            $claim->risk_score,
            '**دعوى بكلّ إشارات الريبة قُدِّرت منخفضةً** — فالتقديرُ زينةٌ لا حارس.');

        $this->assertSame(WrongTransferClaim::OPEN, $claim->status,
            '**حُجز المالُ على دعوى عاليةِ الريبة بلا نظرِ إنسان.**');

        $this->assertSame('0.0000', (string) $claim->held_amount);

        // ١٠٥ لا ١٠٠: التحويلُ السابقُ (٥ آلاف) جزءٌ من التجهيز، وهو
        // الإشارةُ التي تجعل الدعوى مريبةً أصلاً.
        $this->assertSame('105000.0000', (string) $this->wallet($this->salem)->current_balance,
            'مالُ الطرف الآخر انتُزع بضغطة.');

        $this->assertNotEmpty($claim->risk_signals,
            '**درجةٌ بلا إشاراتٍ تشرحها** — فالموظّفُ يقرأ رقماً لا يعرف من أين جاء.');
    }

    /**
     * **⑨ ومن رُفضت دعواه مرّتين لا تُحجَز ثالثتُه تلقائيّاً.**
     *
     * وهو سقفٌ على المدّعي نفسِه لا على العمليّة — فالمحتالُ يوزّع دعاواه
     * على عمليّاتٍ مختلفةٍ ليتجاوز سقفَ العمليّة الواحدة.
     */
    /** @test */
    public function a_claimant_with_a_history_of_rejections_gets_no_automatic_hold(): void
    {
        for ($i = 0; $i < WrongTransferRecoveryService::REJECTED_BLOCKS_AUTO_HOLD; $i++) {
            $ref = $this->sendMoney('1000');
            $this->svc->reject($this->svc->open($ref, '+967700111233'), null, 'دعوى غيرُ صحيحة');
        }

        $tx = $this->sendMoney('100000');
        $claim = $this->svc->open($tx, '+967700111233');

        $this->assertSame(WrongTransferClaim::OPEN, $claim->status,
            '**حُجز تلقائيّاً لمن رُفضت دعواه مرّتين** — والسقفُ مكتوبٌ ولا يُطبَّق.');

        $this->assertSame(
            WrongTransferRecoveryService::REJECTED_BLOCKS_AUTO_HOLD,
            $claim->risk_signals['rejected_claims_90d'] ?? null,
            'سجلُّ المدّعي غيرُ محسوبٍ في الإشارات.');
    }

    /**
     * **⑩ والمالُ محفوظ: ما خرج من سالمٍ دخل أحمدَ بالضبط.**
     *
     * فحارسٌ يفحص الأرصدةَ ولا يفحص الحفظَ يقبل استرداداً يخلق مالاً من
     * العدم — وهو أخطرُ عطلٍ ممكنٍ في نظام دفع.
     */
    /** @test */
    public function money_is_conserved_across_the_whole_recovery(): void
    {
        $tx = $this->sendMoney('100000');
        $this->spendAtMerchant($this->salem, '60000');

        $before = $this->totalMoney();

        $claim = $this->svc->resolve($this->svc->open($tx, '+967700111233'), null, 'ثبت الخطأ');
        $this->creditWallet($this->salem, '25000');
        $afterCredit = $this->totalMoney();

        $this->svc->collectReceivables();

        $this->assertSame($afterCredit, $this->totalMoney(),
            '**تغيّر مجموعُ المال في المنظومة أثناء الاسترداد** — أي أنّ '
            .'ريالاتٍ خُلقت أو أُبيدت. وهو أخطرُ ما يقع في نظام دفع.');

        $this->assertSame(
            MoneyService::add($before, '25000.0000'), $afterCredit,
            'حسابُ الشحن نفسُه غيرُ متّزن — فالقياسُ الذي يليه لا معنى له.');

        // **وما لم يُحصَّل يبقى ذمّةً ولا يُمحى.** دخل سالماً ٢٥ من ٦٠،
        // فبقيت ٣٥ تنتظر وارداً تالياً — ولا تُقرأ صفراً لأنّ الجولةَ
        // انتهت. (القاعدة السابعة.)
        $this->assertSame('35000.0000', $claim->fresh()->outstanding());
    }

    // ═════════════════════════════════════════════════════════════════
    // الوصول — **مبنيٌّ ولا يُوصَل إليه هو نمطُ العطل الأشيع هنا**
    // ═════════════════════════════════════════════════════════════════

    /**
     * **⑪ ولها مدخلان — الويبُ والـAPI — لا واحدٌ منهما.**
     *
     * ══════════════════════════════════════════════════════════════════
     * شاشةُ الدعم تنادي `admin/support-center/...` وحدَها. فمسارٌ في
     * الـAPI بلا توأمٍ في `routes/admin.php` **زرٌّ مبنيٌّ لا يعمل** —
     * وقد كتبتُ مسارات الـAPI أوّلاً فوقعتُ فيه، ثمّ قِيس فأُضيف التوأم.
     * (القاعدة الرابعة: ميزةٌ لها مدخلان تُختبَر من مدخليها.)
     * ══════════════════════════════════════════════════════════════════
     */
    /** @test */
    public function the_claim_actions_exist_on_both_doors(): void
    {
        $missing = [];

        foreach ([
            'admin.support-center.wrong-transfer.open' => 'الفتحُ من شاشة الدعم',
            'admin.support-center.wrong-transfer.resolve' => 'الحسمُ من شاشة الدعم',
            'admin.support-center.wrong-transfer.reject' => 'الرفضُ من شاشة الدعم',
            'amial.admin.support.wrong-transfer.open' => 'الفتحُ من الـAPI',
            'amial.admin.support.wrong-transfer.resolve' => 'الحسمُ من الـAPI',
            'amial.admin.support.wrong-transfer.reject' => 'الرفضُ من الـAPI',
        ] as $name => $label) {
            if (! \Illuminate\Support\Facades\Route::has($name)) {
                $missing[] = $label." ({$name})";
            }
        }

        $this->assertSame([], $missing, sprintf(
            "**مداخلُ ناقصةٌ للدعوى:**\n  %s\n\n"
            .'فالشاشةُ تنادي مسارَ الويب، والتطبيقاتُ تنادي الـAPI — '
            .'وناقصُ أحدِهما زرٌّ يَعِد ولا يفعل.',
            implode("\n  ", $missing)));
    }

    /**
     * **⑫ والزرُّ في الشاشة، ومعالجُه بالتفويض لا بربطٍ وقتَ التحميل.**
     *
     * فجدولُ الدعاوى يُرسَم بعد كلّ فحص، و`onclick` يُربَط مرّةً عند
     * تحميل الصفحة على عنصرٍ لم يُخلَق بعد — **فيبدو الزرُّ حيّاً ولا
     * يفعل شيئاً**. (القاعدة التاسعة.)
     */
    /** @test */
    public function the_support_screen_can_actually_open_and_decide_a_claim(): void
    {
        $src = (string) file_get_contents(
            resource_path('views/admin-views/support/console.blade.php'));

        foreach ([
            'wrongTransferPanel(' => 'لوحةُ الدعاوى غيرُ مرسومةٍ في نتيجة الفحص',
            'data-testid="wtc-open"' => 'لا زرَّ لفتح الدعوى',
            'data-testid="wtc-resolve"' => 'لا زرَّ للاسترداد',
            'data-testid="wtc-reject"' => 'لا زرَّ للرفض',
            "closest('#wtc-open')" => '**الأزرارُ بلا معالجٍ مفوَّض** — تُرسَم بعد الفحص فلا يمسكها ربطٌ وقتَ التحميل',
            'wrong_transfer_claims' => 'الشاشةُ لا تقرأ الدعاوى من ردّ التتبّع',
        ] as $needle => $why) {
            $this->assertStringContainsString($needle, $src, $why);
        }
    }

    /**
     * **⑬ والكنسُ مجدولٌ — وإلّا صار الحجزُ مصادرة.**
     *
     * أمرٌ مبنيٌّ لا يُنادى ليس أمراً. وبلا جدولةٍ يبقى مالُ المستلِم
     * محجوزاً كلَّما نسي الدعمُ ملفّاً، وتبقى الذمّةُ سطراً لا يُحصَّل.
     */
    /** @test */
    public function the_sweep_is_scheduled_not_merely_written(): void
    {
        $this->assertContains('amial:recovery-sweep',
            array_keys(\Illuminate\Support\Facades\Artisan::all()),
            '**أمرُ الكنس غيرُ مسجَّل** — فلا يُنادى بحال.');

        // ══════════════════════════════════════════════════════════════
        // **يُقرأ سجلُّ الجدولة الحيُّ لا نصُّ الملفّ.**
        //
        // أوّلُ صياغةٍ بحثت عن `Schedule::command('amial:recovery-sweep')`
        // في `routes/console.php` — **ومرّت وهي معلَّقةٌ بشرطتين**. أي
        // أنّ السطرَ الذي يوقف الجدولةَ كان يُقرأ دليلاً عليها.
        //
        // وهو عينُ ما وقع في هذا المشروع من قبل: حارسٌ للنقاط الميّتة
        // مرّ لأنّ الكلمةَ وردت في **تعليقٍ عربيٍّ يشرح أنّ النقطة غير
        // موصولة**. (القاعدة الثانية: حارسٌ يمرّ والعطلُ قائمٌ أسوأ من
        // غيابه.)
        // ══════════════════════════════════════════════════════════════
        $scheduled = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->map(fn ($e) => (string) $e->command)
            ->filter(fn ($c) => str_contains($c, 'amial:recovery-sweep'));

        $this->assertTrue($scheduled->isNotEmpty(),
            "**الكنسُ مبنيٌّ ولا يُجدوَل.**\n"
            .'فالحجزُ بلا إفراجٍ تلقائيٍّ مصادرةٌ بيد الدعم، والذمّةُ بلا '
            .'اقتطاعٍ تلقائيٍّ سطرٌ في جدولٍ لا أثرَ له. '
            .'**وما لا يجري تلقائيّاً لا يجري.**');
    }

    /** **⑭ والكنسُ يعمل حين يُنادى — لا مجدولٌ فارغُ اليد.** */
    /** @test */
    public function running_the_sweep_command_actually_releases_and_collects(): void
    {
        $tx = $this->sendMoney('100000');
        $claim = $this->svc->open($tx, '+967700111233');
        $claim->forceFill(['hold_expires_at' => now()->subMinute()])->save();

        $this->artisan('amial:recovery-sweep')->assertSuccessful();

        $this->assertSame(WrongTransferClaim::EXPIRED, $claim->fresh()->status,
            '**الأمرُ يمرّ ولا يفعل** — مجدولٌ فارغُ اليد.');

        $this->assertSame('100000.0000', (string) $this->wallet($this->salem)->current_balance);
    }

    // ═════════════════════════════════════════════════════════════════
    // التجهيزات — **تُحرّك المالَ بالفعل ولا تُزوّر أرصدة**
    // ═════════════════════════════════════════════════════════════════

    /** تحويلٌ حقيقيٌّ من أحمد إلى سالم: يُشحَن أحمدُ ثمّ يُنقَل المال. */
    private function sendMoney(string $amount, $at = null): string
    {
        $ref = 'TX'.strtoupper(uniqid());

        $this->creditWallet($this->ahmed, $amount);
        $this->moveMoney($this->ahmed, $this->salem, $amount, 'test_send');

        $tx = Transaction::create([
            'user_id' => $this->ahmed->id, 'transaction_id' => $ref,
            'transaction_type' => SEND_MONEY, 'debit' => $amount, 'credit' => '0',
            'amount' => $amount, 'balance' => (string) $this->wallet($this->ahmed)->current_balance,
            'from_user_id' => $this->ahmed->id, 'to_user_id' => $this->salem->id,
            'zone_code' => 'SOUTH',
            'transaction_no' => '120'.random_int(100000000000, 999999999999),
        ]);

        // **الطابعُ الزمنيُّ يُكتب بعد الإنشاء عمداً.** `created_at` في
        // مصفوفة `create()` تتجاوزه Eloquent وتضع `now()` — وقد مرّت
        // حالةُ «الدعوى عاليةُ الريبة» أوّلَ مرّة بدرجة ٣٠ لا ٩٠ بسبب
        // ذلك: عمليّةٌ عمرُها أسبوعان صارت عمرَها ثانية، فسقطت إشارتا
        // «بُلِّغ متأخّراً» و«حوّل إليه من قبل» معاً. **وتجهيزٌ يكذب
        // يجعل الحارسَ يمرّ لسببٍ خطأ.**
        if ($at) {
            \Illuminate\Support\Facades\DB::table('transactions')
                ->where('id', $tx->id)->update(['created_at' => $at, 'updated_at' => $at]);
        }

        return $ref;
    }

    /** إنفاقٌ عند التاجر — **خصمٌ حقيقيٌّ لا تعديلُ عمود**. */
    private function spendAtMerchant(User $who, string $amount): void
    {
        $this->moveMoney($who, $this->merchant, $amount, 'test_purchase');
    }

    /**
     * نقلٌ يمرّ بالمحفظة **وبالدفتر معاً**.
     *
     * ══════════════════════════════════════════════════════════════════
     * **وهذا صوابٌ اشتُري بسقوطٍ في أوّل تشغيل.** كانت التجهيزاتُ تحرّك
     * `EMoney` وحدَها، فبقيت حساباتُ الدفتر بصفر — وسقط الاستردادُ بـ
     * `Insufficient balance in account USER_WALLET_8`.
     *
     * **والخللُ كان في التجهيز لا في الخدمة**: مسارُ التحويل الحقيقيُّ
     * يقيّد في الدفتر مع كلّ حركة. لكنّ الدرسَ باقٍ: **تجهيزٌ يبني نصفَ
     * الحقيقة يُسقط حارساً سليماً — أو يُمرّره، وهو أسوأ.**
     * ══════════════════════════════════════════════════════════════════
     */
    private function moveMoney(User $from, User $to, string $amount, string $reason): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($from, $to, $amount, $reason) {
            $guard = app(\App\Services\FinancialGuardService::class);
            $guard->debit($from->id, $amount, $reason);
            $guard->credit($to->id, $amount, $reason);
        });

        $ledger = app(\App\Services\LedgerService::class);

        $ledger->post(
            sourceType: $reason, sourceId: (string) uniqid(),
            description: 'نقلٌ في تجهيز الاختبار',
            lines: [
                ['account' => $ledger->getOrCreateUserWallet($from->id)->account_code,
                    'direction' => 'debit', 'amount' => $amount],
                ['account' => $ledger->getOrCreateUserWallet($to->id)->account_code,
                    'direction' => 'credit', 'amount' => $amount],
            ],
            idempotencyKey: 'test_move_'.uniqid(),
        );
    }

    private function creditWallet(User $who, string $amount): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($who, $amount) {
            app(\App\Services\FinancialGuardService::class)
                ->credit($who->id, $amount, 'test_topup');
        });

        $ledger = app(\App\Services\LedgerService::class);

        $source = $ledger->getOrCreateSystemAccount(
            'TEST_FUNDING_SOURCE', 'asset', 'مصدرُ تمويلٍ في الاختبار', 'debit');

        $ledger->post(
            sourceType: 'test_topup', sourceId: (string) $who->id,
            description: 'شحنٌ في تجهيز الاختبار',
            lines: [
                ['account' => $source->account_code, 'direction' => 'debit', 'amount' => $amount],
                ['account' => $ledger->getOrCreateUserWallet($who->id)->account_code,
                    'direction' => 'credit', 'amount' => $amount],
            ],
            idempotencyKey: 'test_topup_'.uniqid(),
        );
    }

    private function wallet(User $u): EMoney
    {
        return EMoney::where('user_id', $u->id)->first()->refresh();
    }

    /** مجموعُ المال في المنظومة — **المتاحُ والمحجوزُ معاً**. */
    private function totalMoney(): string
    {
        $sum = MoneyService::normalize('0');

        foreach (EMoney::all() as $w) {
            $sum = MoneyService::add($sum, (string) $w->current_balance);
            $sum = MoneyService::add($sum, (string) $w->held_balance);
        }

        return $sum;
    }
}
