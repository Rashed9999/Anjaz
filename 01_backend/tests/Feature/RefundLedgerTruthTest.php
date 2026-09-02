<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\Ledger\LedgerJournalEntry;
use App\Models\MerchantProfile;
use App\Models\MerchantRefund;
use App\Models\User;
use App\Services\CashierService;
use App\Services\MerchantSaleRefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-LEDGER-REFUND-001 — مرتجعُ المحفظة يدخل دفتر الأستاذ، والعمودُ لا يكذب.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **عمودٌ اسمُه «قيد الدفتر» يحمل ما ليس قيداً.**
 *
 * لجدول `merchant_refunds` عمودٌ `ledger_entry_ulid`. ويكتب فيه مساران:
 *
 *   MerchantService::approveRefund      → $journalEntry->entry_ulid   ✅ قيدٌ حقيقيّ
 *   MerchantSaleRefundService::refundToWallet → $refund->refund_ulid  ❌ معرّفُ المرتجع نفسِه
 *
 * والثاني **يحرّك مالاً حقيقيّاً**: يخصم من محفظة التاجر ويضيف إلى محفظة
 * العميل. ثمّ يكتب في خانة القيد معرّفَ نفسِه — فلا قيدَ في الدفتر، والعمود
 * ممتلئ.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولماذا هذا أخطرُ من عمودٍ فارغ؟**
 *
 * لأنّ من يسأل «أيُّ المرتجعات رُحِّلت؟» يكتب `WHERE ledger_entry_ulid IS
 * NOT NULL` فيحصل على **مئةٍ بالمئة**. الغيابُ يُرى، والكذبُ لا. وهو
 * `حارسٌ يكذب أسوأ من غيابه` في صورة بيانات.
 *
 * وثلاثةُ أنواعٍ أخرى تُكتب في العمود نفسه بلا مميّز:
 *   'cash:no_wallet_movement'   نصٌّ ثابت
 *   $movement->movement_ulid    معرّفٌ من نظام الديون — لا الدفتر
 *
 * فالعمود الواحد يحمل أربعةَ معانٍ، ولا شيء يقول أيُّها هذا.
 *
 * ══════════════════════════════════════════════════════════════════════
 * (المهارة ٩ — `amial-double-entry`: «RULE 1 — No transaction exists
 * without accounting.» والمهارة ٨: «Never skip ledger.»)
 */
class RefundLedgerTruthTest extends TestCase
{
    use RefreshDatabase;

    private MerchantSaleRefundService $svc;
    private CashierService $cashier;
    private User $merchant;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc     = app(MerchantSaleRefundService::class);
        $this->cashier = app(CashierService::class);

        $this->merchant = User::factory()->create(['type' => 3, 'zone_code' => 'SOUTH']);
        MerchantProfile::create([
            'user_id' => $this->merchant->id, 'verification_status' => 'verified',
        ]);
        $this->wallet($this->merchant->id, '500000.0000');

        // **العميل يُربط بالهاتف لا بالمعرّف.** `resolveCustomerUserId`
        // تبحث عن `customer_phone` في البيع — فرقمٌ لا يطابق يجعل المرتجع
        // يسقط، لا يمرّ صامتاً.
        $this->customer = User::factory()->create([
            'type' => 2, 'zone_code' => 'SOUTH', 'phone' => self::CUSTOMER_PHONE,
        ]);
        $this->wallet($this->customer->id, '1000.0000');
    }

    private const CUSTOMER_PHONE = '+967700111222';

    private function wallet(int $userId, string $balance): void
    {
        EMoney::create([
            'user_id' => $userId, 'current_balance' => $balance,
            'held_balance' => '0.0000', 'pending_balance' => '0.0000',
            'charge_earned' => '0.0000', 'zone_code' => 'SOUTH',
        ]);
    }

    /** بيعٌ ثمّ مرتجعٌ إلى المحفظة. */
    private function walletRefund(string $amount = '2000'): MerchantRefund
    {
        // مرجعُ الدفع طلبُ QR مدفوعٌ حقيقيّ، لا نصٌّ يدّعي الدفع.
        $this->paidQrRequest($this->merchant, 'TX-LEDGER-TRUTH', '5000');
        $sale = $this->cashier->recordSale(
            merchant: $this->merchant,
            total: '5000',
            // `wallet` طريقةُ **استرداد** لا طريقةُ بيع. وطرق البيع:
            // cash · credit · amial_pay · corporate · mixed
            paymentMethod: 'amial_pay',
            items: [['name' => 'منتج', 'qty' => 1, 'price' => '5000']],
            customer: ['name' => 'محمد', 'phone' => self::CUSTOMER_PHONE],
            paidTransactionId: 'TX-LEDGER-TRUTH',
        );

        return $this->svc->refund(
            merchant: $this->merchant,
            originalSaleUlid: $sale->sale_ulid,
            refundAmount: $amount,
            refundMethod: 'wallet',
            reason: 'منتج تالف',
        );
    }

    /**
     * @test
     *
     * **المالُ تحرّك فعلاً — فالقياس التالي ليس على فراغ.**
     *
     * ولولا هذا لمرّ ما بعده على خدمةٍ لا تفعل شيئاً أصلاً.
     */
    public function the_wallet_refund_really_moves_money(): void
    {
        $before = (string) EMoney::where('user_id', $this->customer->id)->value('current_balance');

        $this->walletRefund('2000');

        $after = (string) EMoney::where('user_id', $this->customer->id)->value('current_balance');

        $this->assertNotSame($before, $after,
            'محفظةُ العميل لم تتغيّر — فلا مالَ تحرّك، والاختبار يقيس فراغاً');
    }

    /**
     * @test
     *
     * **ولكلّ حركةِ مالٍ قيدٌ في الدفتر.**
     *
     * (RULE 1: No transaction exists without accounting.)
     */
    public function a_wallet_refund_posts_a_journal_entry(): void
    {
        $refund = $this->walletRefund('2000');

        $entry = LedgerJournalEntry::where('source_id', $refund->refund_ulid)->first();

        $this->assertNotNull($entry,
            'مرتجعُ محفظةٍ حرّك مالاً ولا قيدَ له في دفتر الأستاذ');
    }

    /**
     * @test
     *
     * **والقيدُ متوازن: المدين يساوي الدائن.**
     *
     * (RULE 2: Debit must equal Credit. Always.)
     */
    public function the_posted_entry_is_balanced(): void
    {
        $refund = $this->walletRefund('2000');

        $entry = LedgerJournalEntry::where('source_id', $refund->refund_ulid)->first();
        $this->assertNotNull($entry, 'لا قيدَ يُوزَن');

        $sums = DB::table('ledger_entry_lines')
            ->where('journal_entry_id', $entry->id)
            ->selectRaw("SUM(CASE WHEN direction='debit' THEN amount ELSE 0 END) d")
            ->selectRaw("SUM(CASE WHEN direction='credit' THEN amount ELSE 0 END) c")
            ->first();

        $this->assertSame((float) $sums->d, (float) $sums->c,
            "قيدٌ غير متوازن: مدين {$sums->d} مقابل دائن {$sums->c}");

        $this->assertGreaterThan(0, (float) $sums->d, 'قيدٌ بصفرين — يتوازن ولا يعني شيئاً');
    }

    /**
     * @test
     *
     * **والعمودُ يُشير إلى قيدٍ موجود — لا إلى نفسه.**
     *
     * وهذا هو العطل بعينه: `ledger_entry_ulid = $refund->refund_ulid`.
     * فالعمود ممتلئ، ولا قيدَ خلفه.
     */
    public function the_ledger_column_points_at_a_real_entry(): void
    {
        $refund = $this->walletRefund('2000')->fresh();

        $this->assertNotNull($refund->ledger_entry_ulid, 'العمود فارغ');

        $this->assertNotSame($refund->refund_ulid, $refund->ledger_entry_ulid,
            'العمود يحمل معرّفَ المرتجع نفسِه — خانةُ القيد تُشير إلى نفسها');

        $this->assertTrue(
            LedgerJournalEntry::where('entry_ulid', $refund->ledger_entry_ulid)->exists(),
            'العمود يحمل قيمةً لا يقابلها قيدٌ في ledger_journal_entries'
        );
    }

    /**
     * @test
     *
     * **ولا يُرحَّل المرتجع مرّتين.**
     *
     * فالخدمة داخل `DB::transaction`، وإعادةُ محاولةٍ بعد انقطاعٍ شبكيّ
     * تُنتج قيداً ثانياً بلا مفتاح تفرّد — ومالٌ مضاعفٌ في الدفتر لا
     * يُكتشف إلّا في ميزان المراجعة بعد شهر.
     *
     * (المهارة ٨: «Never allow duplicated money.»)
     */
    public function the_refund_entry_is_idempotent(): void
    {
        $refund = $this->walletRefund('2000');

        $count = LedgerJournalEntry::where('source_id', $refund->refund_ulid)->count();

        $this->assertSame(1, $count, "رُحّل المرتجع {$count} مرّة");

        $entry = LedgerJournalEntry::where('source_id', $refund->refund_ulid)->first();
        $this->assertNotNull($entry->idempotency_key,
            'القيد بلا مفتاح تفرّد — فإعادةُ المحاولة تُنتج قيداً ثانياً');
    }

    /**
     * @test
     *
     * **والمرتجعُ النقديّ يقول إنّه ليس في الدفتر — لا يدّعي أنّه فيه.**
     *
     * (القاعدة السابعة: «غير معروف» ليس صفراً. والنقدُ الورقيّ خارج
     * الدفتر بحقّ — لكنّ خانةَ القيد لا تُملأ بنصٍّ يشبه المعرّف.)
     */
    public function a_cash_refund_does_not_pretend_to_have_a_ledger_entry(): void
    {
        $sale = $this->cashier->recordSale(
            merchant: $this->merchant, total: '5000', paymentMethod: 'cash',
            items: [['name' => 'منتج', 'qty' => 1, 'price' => '5000']],
        );

        $refund = $this->svc->refund(
            merchant: $this->merchant, originalSaleUlid: $sale->sale_ulid,
            refundAmount: '2000', refundMethod: 'cash', reason: 'نقدي',
        )->fresh();

        if ($refund->ledger_entry_ulid !== null) {
            $this->assertTrue(
                LedgerJournalEntry::where('entry_ulid', $refund->ledger_entry_ulid)->exists(),
                'خانةُ القيد ممتلئةٌ بقيمةٍ لا قيدَ خلفها: ' . $refund->ledger_entry_ulid
            );
        }

        $this->assertTrue(true);
    }

    /**
     * @test
     *
     * **وإن سقط القيد، لا يتحرّك ريال.**
     *
     * وقد كشفت إماتةُ التوازن أنّ `LedgerService` **يرفض** القيد غير
     * المتوازن ويرمي — فبقي أن يُثبَت أنّ الرفض يُرجع كلّ شيء.
     *
     * **وتصحيحٌ لما ظننتُه:** كتبتُ في الخدمة أنّ ترتيب «القيد ثمّ
     * المحفظتين» هو ما يمنع بقاءَ مالٍ منقولٍ بلا قيد. فأمتُّ الترتيب —
     * وضعتُ المحفظتين أوّلاً — **فمرّ هذا الحارس كما هو**. لأنّ `refund()`
     * كلَّها داخل `DB::transaction`: يسقط القيد فتُرجَع المحفظتان معه أيّاً
     * كان الترتيب.
     *
     * فالذي يحرسه هذا الاختبار **المعاملةُ لا الترتيب** — ولو أُخرجت حركةُ
     * المحافظ يوماً إلى مسارٍ خارج المعاملة لسقط هنا.
     *
     * (المهارة ٨: «If one step fails → Rollback Everything.»
     *  والمهارة ٩: «Unbalanced Journal → Reject Immediately.»)
     */
    public function when_the_ledger_refuses_no_money_moves(): void
    {
        $merchantBefore = (string) EMoney::where('user_id', $this->merchant->id)->value('current_balance');
        $customerBefore = (string) EMoney::where('user_id', $this->customer->id)->value('current_balance');

        // دفترٌ يرفض — كما يرفض القيدَ غير المتوازن أو الحسابَ المفقود.
        $this->instance(\App\Services\LedgerService::class, new class extends \App\Services\LedgerService {
            public function __construct() {}

            public function getOrCreateUserWallet(int $userId, string $zoneCode = 'SOUTH'): \App\Models\Ledger\LedgerAccount
            {
                return new \App\Models\Ledger\LedgerAccount(['account_code' => "WALLET:{$userId}"]);
            }

            public function post(
                string $sourceType, ?string $sourceId, string $description, array $lines,
                ?string $idempotencyKey = null, ?int $createdByUserId = null, array $metadata = [],
                string $zoneCode = 'SOUTH', bool $allowNegative = false, bool $isReversal = false,
                ?int $reversesEntryId = null,
            ): \App\Models\Ledger\LedgerJournalEntry {
                throw new \RuntimeException('الدفتر رفض القيد');
            }
        });

        // **تُعاد الخدمةُ من الحاوية بعد الربط.** فنسخةُ `setUp` تحمل
        // الدفترَ الحقيقيّ، وربطُ البديل بعدها لا يمسّها — فيمرّ الاختبار
        // وهو لا يفحص شيئاً.
        $this->svc = app(MerchantSaleRefundService::class);

        try {
            $this->walletRefund('2000');
            $this->fail('مرّ المرتجع والدفترُ رافض — فالقيد ليس شرطاً');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('الدفتر رفض', $e->getMessage());
        }

        $this->assertSame($merchantBefore,
            (string) EMoney::where('user_id', $this->merchant->id)->value('current_balance'),
            'خُصم من التاجر رغم سقوط القيد');

        $this->assertSame($customerBefore,
            (string) EMoney::where('user_id', $this->customer->id)->value('current_balance'),
            'أُضيف للعميل رغم سقوط القيد — مالٌ تحرّك بلا أثرٍ محاسبيّ');

        $this->assertSame(0, MerchantRefund::where('status', 'completed')->count(),
            'بقي سجلُّ مرتجعٍ مكتملٍ بعد سقوط القيد');
    }
}
