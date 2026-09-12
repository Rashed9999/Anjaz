<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\FeeScheme;
use App\Models\User;
use App\Models\WhatsappLinkedDevice;
use App\Services\FeeService;
use App\Services\Whatsapp\WhatsappApiClient;
use App\Services\Whatsapp\WhatsappBotService;
use App\Services\Whatsapp\WhatsappSessionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * AMIAL-TRUTH-003 — رسمُ التحويل عبر واتساب: **يُقاس، لا يُقرأ**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولمَ ملفٌّ ثانٍ لِما «حُرس» أمس:**
 *
 * كتبتُ بالأمس `FeeChannelParityTest` فحرس **نصَّ الملفّ**: أنّ الرمز
 * `'SEND_MONEY'` مكتوبٌ فيه، وأنّ `'fee_amount'` ليس فيه.
 *
 * **وذاك ليس حارساً على المال — هو حارسٌ على الإملاء.** يمرّ لو غُيّرت
 * الدالّةُ كلُّها ما دامت السلسلتان في مكانهما، ويسقط لو نُقل السطرُ إلى
 * دالّةٍ مساعدة. وهو عينُ ما أعيبه على غيري: **قياسُ الوصف لا السلوك.**
 *
 * فهذا الملفُّ يُشغّل البوتَ فعلاً، ويقرأ **الرسمَ المخزَّن في الجلسة** —
 * وهو الرقمُ نفسُه الذي يُمرَّر إلى
 * `PendingTransferService::initiate(fee: …)`، **وتلك تستقبل ولا تحسب**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولمَ لم يمسكه أحدُ الاختبارات الثمانية عشر القائمة:**
 *
 * `WhatsappBotTest` فيه ثمانيةَ عشرَ اختباراً و**صفرُ ذكرٍ للرسم**. كلُّها
 * تُجرّب مسارَ المحادثة — أيردّ البوت؟ أيفهم الأمر؟ — ولا واحدَ منها
 * يسأل: **بكم؟**
 *
 * فمرّ تحويلٌ مجّانيٌّ تماماً تحت ثمانيةَ عشرَ اختباراً خضراء.
 */
class WhatsappFeeParityTest extends TestCase
{
    use RefreshDatabase;

    private const WA = '967777123456';

    private WhatsappBotService $bot;
    private User $user;
    private User $recipient;

    protected function setUp(): void
    {
        parent::setUp();

        // لا رسائلَ حقيقيّة.
        $this->app->instance(
            WhatsappApiClient::class,
            Mockery::mock(WhatsappApiClient::class)->shouldIgnoreMissing(),
        );

        $this->bot = app(WhatsappBotService::class);

        $this->user = User::factory()->create(['phone' => '967777123456', 'type' => 3]);
        // **المستلِمُ موثَّقٌ ومحافظتُه محدَّدة** — وإلّا رفضته
        // `RecipientVerificationService` فلا تبلغ المحادثةُ خطوةَ
        // التسعير أصلاً، **فيُقرأ رفضُ أهليّةٍ عطلَ رسوم**.
        $this->recipient = User::factory()->create([
            'phone' => '967777999888', 'type' => 3,
            'is_kyc_verified' => 1, 'zone_code' => 'SOUTH']);

        foreach ([$this->user, $this->recipient] as $u) {
            EMoney::create([
                'user_id' => $u->id,
                'current_balance' => 500000,
                'pending_balance' => 0,
            ]);
        }

        WhatsappLinkedDevice::create([
            'user_id' => $this->user->id,
            'whatsapp_number' => self::WA,
            'status' => WhatsappLinkedDevice::STATUS_ACTIVE,
            'device_fingerprint' => hash('sha256', self::WA),
            'otp_verified_at' => now(),
            'risk_score' => 0,
        ]);
    }

    /** مخطّطُ رسمٍ فعّالٌ — بلا هذا يردّ المحرّكُ صفراً ويمرّ الفحصُ كاذباً. */
    private function scheme(): void
    {
        FeeScheme::create([
            'code' => 'SEND_MONEY',
            'zone_code' => 'SOUTH',
            'applies_to' => 'customer',
            'fee_type' => 'percent_plus_fixed',
            'percent_rate' => '1.00',
            'fixed_amount' => '25.0000',
            'min_fee' => '0',
            'max_fee' => '100000',
            'bearer' => 'sender',
            'platform_share_percent' => '100.00',
            'agent_share_percent' => '0.00',
            'version' => 1,
            'is_active' => true,
        ]);
    }

    /**
     * @test
     *
     * **الرسمُ المخزَّن في الجلسة هو رسمُ المحرّك — لا صفر.**
     *
     * ══════════════════════════════════════════════════════════════════
     * وهذا هو الرقمُ الذي يُمرَّر إلى `initiate(fee: …)` حرفيّاً. فإن كان
     * صفراً، **خرج التحويلُ مجّانيّاً** وإن كان في اللوحة مخطّطٌ فعّال.
     */
    public function the_bot_stores_the_engine_fee_not_zero(): void
    {
        $this->scheme();

        $amount = '10000';

        $expected = app(FeeService::class)->calculate('SEND_MONEY', $amount, [
            'zone_code' => $this->user->zone_code ?? 'SOUTH',
            'applies_to' => 'customer',
        ])['fee'];

        $this->assertNotSame('0.0000', $expected,
            'المخطّطُ لم يُلتقط — الفحصُ يقارن صفراً بصفرٍ ولا يحرس شيئاً');

        // ── تُقاد المحادثةُ إلى تسعير التحويل ──
        $this->bot->handle(self::WA, 'تحويل');
        $this->bot->handle(self::WA, $this->recipient->phone);
        $this->bot->handle(self::WA, $amount);

        $session = app(WhatsappSessionManager::class);

        $storedFee = (string) $session->getData(self::WA, 'fee', 'MISSING');

        $this->assertNotSame('MISSING', $storedFee,
            'لم تبلغ المحادثةُ خطوةَ التسعير — راجع مسارَ الخطوات في الاختبار');

        $this->assertSame($expected, $storedFee, sprintf(
            "رسمُ واتساب يخالف رسمَ المحرّك:\n"
            . "  المحرّك = %s\n  واتساب  = %s\n"
            . "والرقمُ يُمرَّر كما هو إلى initiate(fee: …) — وتلك تستقبل ولا تحسب.\n"
            . 'فالفرقُ مالٌ لا عرض: قناتان ورسمان لعمليّةٍ واحدة.',
            $expected, $storedFee,
        ));
    }

    /**
     * @test
     *
     * **والمجموعُ المطلوب من الرصيد يشمل الرسم.**
     *
     * ══════════════════════════════════════════════════════════════════
     * فبرسمٍ صفريٍّ كان البوتُ يفحص كفايةَ الرصيد على المبلغ وحدَه.
     * **فيمرّ من لا يملك الرسم**، ثمّ يُخصم منه أو يفشل التحويلُ بعد أن
     * أدخل رمزَه — وكلاهما سيّئ.
     */
    public function the_quoted_total_includes_the_fee(): void
    {
        $this->scheme();

        $amount = '10000';

        $this->bot->handle(self::WA, 'تحويل');
        $this->bot->handle(self::WA, $this->recipient->phone);
        $this->bot->handle(self::WA, $amount);

        $session = app(WhatsappSessionManager::class);

        $fee = (string) $session->getData(self::WA, 'fee', '0');
        $total = (string) $session->getData(self::WA, 'total', '0');

        $this->assertSame(0, bccomp($total, bcadd($amount, $fee, 4), 4), sprintf(
            'المجموعُ %s لا يساوي المبلغَ %s زائدَ الرسم %s — '
            . 'ففحصُ كفاية الرصيد يقع على رقمٍ ناقص.',
            $total, $amount, $fee,
        ));

        $this->assertSame(1, bccomp($total, $amount, 4),
            'المجموعُ يساوي المبلغَ — أي أنّ الرسمَ صفرٌ وقد عاد العطل');
    }
}
