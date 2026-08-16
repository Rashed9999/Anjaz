<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-IDEMPOTENCY-002 — **مفتاحٌ يُولَّد في قائمة المُعامِلات ليس حمايةً.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمن — قِيس في تدقيق الجاهزيّة:**
 *
 *     safe_payment_repo.dart:41  ·  donations_repo.dart:39
 *     idempotencyKey: IdempotencyKeyGenerator.forFinancialAction('donate')
 *
 * المفتاحُ يُولَّد **داخل النداء** — أي جديدٌ في كلّ محاولة. فمن ضغط
 * «تبرَّع» وانقطع الاتّصالُ **بعد** وصول الطلب وقبل وصول الردّ يرى فشلاً
 * فيضغط ثانيةً، فيصل مفتاحٌ مختلفٌ ويقرأه الخادمُ عمليّةً جديدة.
 * **خصمان.** وانقطاعُ الشبكة في اليمن ليس حالةً نادرة.
 *
 * **والنمطُ الصحيح كان مكتوباً في المشروع** — في
 * `transaction_controller.dart` — ونُسخ خطأً في موضعين. ومن يكتب شاشةً
 * ماليّةً ثالثةً سينسخ الأقربَ إليه، لا الأصحّ.
 *
 * فيُنتزَع النمطُ من الذاكرة ويُوضع في `IdempotentIntent`، **ويُحرَس
 * هنا**: لا مولِّدَ داخل مستودع، والمستودعاتُ الماليّةُ تستقبل المفتاح.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولمَ حارسٌ في PHP على شيفرة Dart:** لأنّه المكانُ الوحيدُ الذي يجري
 * في هذا المشروع قبل كلّ التزام. و`flutter analyze` لا يرى نمطاً
 * صحيحاً نحويّاً وخاطئاً معنىً.
 */
class AppIdempotencyIntentGuardTest extends TestCase
{
    private function app(string $rel): string
    {
        $path = base_path('../02_flutter_app/' . $rel);

        $this->assertFileExists($path, "ملفٌّ مفقود: {$rel}");

        return (string) file_get_contents($path);
    }

    /** يُنزع التعليقُ أوّلاً — الحارسُ لا يسقط على شرحِ نفسِه. */
    private function code(string $rel): string
    {
        return (string) preg_replace('~///[^\n]*|//[^\n]*~', '', $this->app($rel));
    }

    /**
     * @test
     *
     * **لا مستودعٌ ماليٌّ يولّد مفتاحَه بنفسه.**
     *
     * المستودعُ `GetxService` مفردٌ يعيش طولَ التطبيق — لا يعرف متى تبدأ
     * نيّةُ المستخدم ومتى تنتهي. **والمتحكّمُ يعرف.**
     */
    public function no_money_repository_generates_its_own_idempotency_key(): void
    {
        // **المستودعاتُ الماليّةُ وحدَها** — والاستثناءُ مكتوبٌ لا مسكوتٌ عنه:
        //
        // `amial_repo.dart` يولّد خمسةَ مفاتيحَ في النداء (قبولُ الشروط،
        // واستعادةُ الحساب بأربع خطواتها) — **ولا واحدٌ منها يُحرّك مالاً**،
        // فلا خصمَ مزدوجَ يُخشى.
        //
        // بل تثبيتُ المفتاح في `recovery_verify_otp` **يضرّ**: المستخدمُ
        // يُعيد المحاولةَ برمزٍ مختلف، وهي نيّةٌ جديدةٌ لا إعادةُ الأولى.
        //
        // فالقاعدةُ «مفتاحٌ لكلّ نيّة» تُطبَّق حيث يتحرّك المال.
        $repos = [
            'lib/features/safe_payment/domain/repositories/safe_payment_repo.dart',
            'lib/features/donations/domain/repositories/donations_repo.dart',
            'lib/features/bill_pay/domain/repositories/bill_pay_repo.dart',
        ];

        foreach ($repos as $rel) {
            $path = base_path('../02_flutter_app/' . $rel);

            if (! is_file($path)) {
                continue;
            }

            $this->assertStringNotContainsString(
                'IdempotencyKeyGenerator.forFinancialAction', $this->code($rel),
                "«{$rel}» يولّد مفتاحَه في النداء — فكلُّ إعادةِ محاولةٍ "
                . 'عمليّةٌ جديدة، والانقطاعُ بعد وصول الطلب يُنتج خصمين');
        }
    }

    /**
     * @test
     *
     * **والمسارانِ المكسورانِ يستقبلان المفتاح الآن.**
     */
    public function the_two_broken_paths_now_receive_the_key(): void
    {
        foreach ([
            'lib/features/safe_payment/domain/repositories/safe_payment_repo.dart',
            'lib/features/donations/domain/repositories/donations_repo.dart',
        ] as $rel) {
            $this->assertStringContainsString('required String idempotencyKey', $this->code($rel),
                "«{$rel}» لا يستقبل مفتاحاً — فلا يستطيع المتحكّمُ تثبيتَه");
        }
    }

    /**
     * @test
     *
     * **والمتحكّمانِ يحفظان النيّةَ ويُتلفانها.**
     *
     * `keyFor` وحدَها لا تكفي: مفتاحٌ يُحفظ ولا يُتلَف يجعل تبرّعاً ثانياً
     * **مقصوداً** يُقرأ إعادةً للأوّل فيُرفض. والتلفُ عند جوابِ الخادم لا
     * عند نجاحه — وهو الفرقُ الذي كسر التطبيقَ مرّةً من قبل.
     */
    public function the_controllers_hold_the_intent_and_settle_it(): void
    {
        foreach ([
            'lib/features/safe_payment/controllers/safe_payment_controller.dart',
            'lib/features/donations/controllers/donations_controller.dart',
        ] as $rel) {
            $src = $this->code($rel);

            $this->assertStringContainsString('IdempotentIntent', $src,
                "«{$rel}» لا يستعمل المُعينَ المشترك");

            $this->assertStringContainsString('keyFor(', $src,
                "«{$rel}» لا يُثبّت مفتاحَ النيّة");

            $this->assertStringContainsString('settleKey(', $src,
                "«{$rel}» يحفظ المفتاحَ ولا يُتلفه — فعمليّةٌ ثانيةٌ مقصودةٌ تُرفض");
        }
    }

    /**
     * @test
     *
     * **والمُعينُ يُتلف عند الجواب لا عند النجاح.**
     *
     * صيغةٌ أولى في هذا المشروع أبقت المفتاحَ على كلّ فشل، **فكسرت
     * التطبيق**: من أخطأ رمزَه السرّيّ مرّةً لا يستطيع إعادةَ المحاولة —
     * يردّ الخادمُ `409 IDEMPOTENCY_FAILED_PREVIOUSLY`.
     *
     * فالتمييزُ بين **جوابٍ وصمت** لا بين نجاحٍ وفشل.
     */
    public function the_helper_settles_on_any_answer_not_only_success(): void
    {
        $src = $this->code('lib/data/api/idempotent_intent.dart');

        $this->assertMatchesRegularExpression('~code\s*>\s*1~', $src,
            'المُعينُ لا يميّز الجوابَ من الصمت — إمّا يكسر إعادةَ المحاولة '
            . 'بعد رفضٍ سليم، وإمّا يُنتج عمليّتين عند انقطاع');
    }
}
