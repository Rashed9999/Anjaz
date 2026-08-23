<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-MERCHANT-RECEIVE-LIMIT-001 — **حدٌّ يُضبَط ويُعرَض ولا يُطبَّق.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما قِيس، ولم يُفترَض:**
 *
 *   · `MerchantRiskService::assertReceiveAllowed` مبنيّةٌ وتفرض ثلاثةً:
 *     إيقافَ التاجر تحت المراجعة · حدَّ العمليّة الواحدة · الحدَّ اليوميّ
 *   · **وصفرُ مُنادٍ لها في المشروع كلِّه** — مسحاً بالرموز لا بالنصّ
 *   · بينما `AdminHubController` يضبط الحدَّ،
 *     و`MerchantThreeSixtyService` يعرضه في مركز التاجر ٣٦٠
 *
 * **فالمدقِّقُ يرى رقماً في الشاشة ويقرؤه حاجزاً.** وحاجزٌ يُعرَض ولا
 * يعمل أسوأ من غيابه: من رآه ترك الاحتياط، ولا خطأَ في أيّ سجلٍّ يقول
 * إنّ الحدَّ لم يُسأل.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولمَ لم يمسكه مقياسٌ قائم:** الدالّةُ **مُختبَرةٌ** — لها اختبارُ
 * وحدةٍ أخضر. فهي تعمل كما كُتبت، ولا شيءَ يسأل: **من يناديها؟**
 *
 * وهذا صنفٌ أخطرُ من الشيفرة الميّتة: الميّتةُ لا تَعِد بشيء،
 * **والمُختبَرةُ غيرُ الموصولة تَعِد بحمايةٍ لا تقع**. وأخرجه جردُ ساهر
 * (`SERVICE_METHOD_UNREACHED`) لا مجموعةُ الاختبارات.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والبابان يُختبَران معاً — القاعدةُ الرابعة:**
 *
 *     QR / POS        →  `merchant_payment_transaction`
 *     رابطٌ / فاتورة  →  `PaymentRequestService::settle`
 *
 * فحدٌّ على أحدهما يُقرأ «مطبَّق» ويُلتفّ عليه من الآخر بفاتورةٍ واحدة.
 */
class MerchantReceiveLimitGuardTest extends TestCase
{
    use RefreshDatabase;

    /** تاجرٌ بحدٍّ للعمليّة الواحدة وحدٍّ يوميّ، كما تضبطهما اللوحة. */
    private function merchant(string $single, string $daily, string $status = 'verified'): User
    {
        $m = User::factory()->create(['type' => 3, 'is_active' => 1]);

        MerchantProfile::create([
            'user_id' => $m->id,
            'tier' => 'small',
            'verification_status' => $status,
            'single_receive_limit' => $single,
            'daily_receive_limit' => $daily,
        ]);

        return $m;
    }

    // ══════════════════════════════════════════════════════════════════
    // البابُ الأوّل — QR / POS
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_qr_door_calls_the_receive_limit_check(): void
    {
        // **ولا يُقاس هذا بفتح شاشةٍ ولا بقراءة تعليق.** يُبدَّل الخدمةُ
        // بمزيَّفٍ يسجّل النداء، فإن لم يُنادَ لم يُسجَّل شيء.
        $seen = [];

        $this->swap(\App\Services\MerchantRiskService::class,
            new class ($seen) extends \App\Services\MerchantRiskService {
                public function __construct(public array &$seen)
                {
                    parent::__construct(app(\App\Services\AuditService::class));
                }

                public function assertReceiveAllowed(int $merchantUserId, string $amount): void
                {
                    $this->seen[] = [$merchantUserId, $amount];
                }
            });

        $customer = User::factory()->create(['type' => 2, 'is_active' => 1]);
        $merchant = $this->merchant('50000', '200000');

        $this->fund($customer, '5000');

        $svc = new class { use \App\Traits\TransactionTrait; };
        $svc->merchant_payment_transaction($customer->id, $merchant->id, '1000', 'qr');

        $this->assertNotEmpty($seen,
            'دفعُ QR لا يسأل حدَّ الاستلام — فالحدُّ المضبوطُ في اللوحة زينة');

        $this->assertSame($merchant->id, $seen[0][0],
            'الحدُّ سُئل عن الطرف الخطأ — والدافعُ ليس من يُحَدُّ استلامُه');
    }

    /** @test */
    public function a_payment_over_the_single_limit_is_refused_at_the_qr_door(): void
    {
        // **والرفضُ يُقاس بالأثر لا بالرسالة**: لا مالَ تحرّك.
        $customer = User::factory()->create(['type' => 2, 'is_active' => 1]);
        $merchant = $this->merchant('500', '200000');

        $this->fund($customer, '5000');

        $before = $this->balance($merchant);

        try {
            $svc = new class { use \App\Traits\TransactionTrait; };
            $svc->merchant_payment_transaction($customer->id, $merchant->id, '1000', 'qr');
            $this->fail('مرّت دفعةٌ فوق حدّ العمليّة الواحدة');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('حد الاستلام', $e->getMessage());
        }

        $this->assertSame($before, $this->balance($merchant),
            'رُفضت الدفعةُ ومع ذلك تحرّك رصيدُ التاجر — فالرفضُ بعد الإضافة');
    }

    /** @test */
    public function a_suspended_merchant_cannot_receive(): void
    {
        // **وتاجرٌ موقوفٌ للمراجعة يقبض** هو أسوأُ الثلاثة: الإيقافُ
        // إجراءُ امتثالٍ، وقبضُه أثناءه يُبطل الإجراءَ كلَّه.
        $customer = User::factory()->create(['type' => 2, 'is_active' => 1]);
        $merchant = $this->merchant('50000', '200000', 'verification_suspended');

        $this->fund($customer, '5000');

        $this->expectException(\RuntimeException::class);

        $svc = new class { use \App\Traits\TransactionTrait; };
        $svc->merchant_payment_transaction($customer->id, $merchant->id, '1000', 'qr');
    }

    // ══════════════════════════════════════════════════════════════════
    // البابُ الثاني — رابطُ الدفع والفاتورة
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_payment_link_door_is_guarded_too(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **وهذا هو البابُ الذي يُنسى.** حدٌّ على QR وحدَه يُقرأ
        // «مطبَّق»، ويلتفّ عليه التاجرُ بإصدار فاتورةٍ بالمبلغ نفسِه.
        // ══════════════════════════════════════════════════════════════
        $src = $this->codeOnly('app/Services/PaymentRequestService.php');

        $this->assertStringContainsString('assertReceiveAllowed', $src,
            'مسارُ رابط الدفع لا يسأل حدَّ الاستلام — والحدُّ يُلتفّ عليه بفاتورة');

        // **وموضعُه داخلَ القفل** — عدٌّ يوميٌّ يُقرأ قبل القفل تقرؤه
        // دفعتان متزامنتان فتمرّان معاً.
        $lockAt = strpos($src, 'lockWalletsOrdered([$payer->id, $requesterId])');
        $checkAt = strpos($src, 'assertReceiveAllowed');

        $this->assertNotFalse($lockAt);
        $this->assertGreaterThan($lockAt, $checkAt,
            'فحصُ الحدّ قبل القفل — ودفعتان متزامنتان تقرآن المجموعَ نفسَه فتمرّان');
    }

    /** @test */
    public function the_check_sits_inside_the_lock_at_the_qr_door_as_well(): void
    {
        // **الطبقةُ الثامنة بنصّها**: الفحصُ خارج القفل مرّ في ستٍّ من
        // ثمانية شبابيك، ولا يظهر إلّا بالتوازي.
        $src = $this->codeOnly('app/Traits/TransactionTrait.php');

        $fn = substr($src, (int) strpos($src, 'function merchant_payment_transaction'));
        $lockAt = strpos($fn, 'lockWalletsOrdered');
        $checkAt = strpos($fn, 'assertReceiveAllowed');

        $this->assertNotFalse($checkAt, 'بابُ QR بلا فحصِ حدٍّ إطلاقاً');
        $this->assertNotFalse($lockAt);
        $this->assertGreaterThan($lockAt, $checkAt,
            'فحصُ الحدّ قبل قفل المحافظ — فدفعتان متزامنتان تتجاوزانه معاً');
    }

    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function a_non_merchant_recipient_is_not_subjected_to_merchant_limits(): void
    {
        // **وحاجزٌ يشلّ عملاً سليماً يُطفَأ عند أوّل شكوى.** حدودُ
        // التاجر لا تُطبَّق على عميلٍ يستقبل تحويلاً عاديّاً.
        $src = $this->codeOnly('app/Services/PaymentRequestService.php');

        $this->assertMatchesRegularExpression(
            '~\(int\)\s*\(\$requester->type\s*\?\?\s*0\)\s*===\s*3~', $src,
            'حدُّ التاجر يُطبَّق على كلّ مستلم — فتحويلٌ بين عميلين يُرفض بحدٍّ لا يخصّه');
    }

    // ── أدوات ─────────────────────────────────────────────────────────

    /**
     * **الشيفرةُ بلا تعليقاتها.**
     *
     * أوّلُ قلبٍ لهذا الحارس نزع الفحصَ من باب QR **فمرّ اختباران** —
     * لأنّ التعليقَ العربيَّ فوقَه يذكر `assertReceiveAllowed` بالاسم.
     * وهو العطلُ المسجَّل في `CLAUDE.md` حرفاً: «التعليقُ الذي يصف العطلَ
     * كان يُخفيه».
     *
     * والنزعُ مرحلتان لا تعبيرٌ واحدٌ جشع: `s` مطلوبةٌ للكتليّ ومُهلِكةٌ
     * للسطريّ — فـ`.*$` بها تبتلع بقيّةَ الملفّ. (القاعدةُ الخامسة.)
     */
    private function codeOnly(string $relPath): string
    {
        $s = (string) file_get_contents(base_path($relPath));
        $s = preg_replace('~/\*.*?\*/~s', '', $s) ?? '';

        return preg_replace('~^[ \t]*//[^\n]*$~m', '', $s) ?? '';
    }

    private function fund(User $u, string $amount): void
    {
        \App\Models\EMoney::updateOrCreate(
            ['user_id' => $u->id],
            ['current_balance' => $amount],
        );
    }

    private function balance(User $u): string
    {
        return (string) (\App\Models\EMoney::where('user_id', $u->id)->value('current_balance') ?? '0');
    }
}
