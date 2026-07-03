<?php

namespace Tests\Feature;

use App\Models\Ledger\LedgerAccount;
use App\Models\Ledger\LedgerEntryLine;
use App\Models\Ledger\LedgerJournalEntry;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-INTEGRITY-001 — اختبار السلامة المالية (Financial Integrity Test).
 *
 * السؤال الوحيد المهمّ لأيّ نظام مالي: بعد آلاف العمليات، هل
 *   إجمالي أرصدة المحافظ = إجمالي القيود المحاسبية = المال الحقيقي المحقون؟
 * ولو وُجد فرق 0.0001 ريال → يفشل الاختبار.
 *
 * يُشغِّل عمليات عشوائية حقيقية عبر LedgerService (تحويلات ناجحة وفاشلة)
 * ثم يتحقّق من أربعة ثوابت لا تقبل التسامح:
 *   A) لكلّ حساب: الرصيد المخزّن (cached) = الرصيد المُعاد حسابه من القيود.
 *   B) القيد المزدوج عالمياً: مجموع كلّ المدين = مجموع كلّ الدائن.
 *   C) حفظ المال: مجموع أرصدة كلّ المحافظ = المبلغ المحقون ابتداءً (التحويلات صفرية المجموع).
 *   D) العمليات الفاشلة لم تُحرّك أيّ فلس ولم تُنشئ أيّ سطر يتيم.
 *
 * الحجم قابل للضبط: INTEGRITY_USERS / INTEGRITY_OPS (افتراضياً خفيف ليعمل في CI).
 */
class FinancialIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private LedgerService $ledger;
    private const CAPITAL = 'PLATFORM_CAPITAL_TEST';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = app(LedgerService::class);
    }

    /** @test */
    public function ledger_stays_balanced_and_money_is_conserved_after_many_operations(): void
    {
        mt_srand(20260703); // تكرارية

        $userCount = (int) env('INTEGRITY_USERS', 60);
        $opCount   = (int) env('INTEGRITY_OPS', 1500);
        $mintEach  = '1000000'; // مليون ريال لكلّ محفظة

        // حساب رأس مال المنصّة (equity/debit) — مصدر الحقن، لا يخضع لفحص السالب
        $capital = $this->ledger->getOrCreateSystemAccount(
            self::CAPITAL, 'equity', 'رأس مال اختباري', 'debit'
        );

        // 1) إنشاء المحافظ + حقن مبلغ معلوم في كلّ محفظة
        $wallets = [];
        for ($i = 1; $i <= $userCount; $i++) {
            $w = $this->ledger->getOrCreateUserWallet(1000 + $i);
            $wallets[] = $w->account_code;
            $this->ledger->post(
                sourceType: 'integrity_mint',
                sourceId: (string) $i,
                description: "حقن رأس مال للمحفظة {$w->account_code}",
                lines: [
                    ['account' => self::CAPITAL,      'direction' => 'debit',  'amount' => $mintEach],
                    ['account' => $w->account_code,   'direction' => 'credit', 'amount' => $mintEach],
                ],
            );
        }

        $totalMinted = bcmul($mintEach, (string) $userCount, 4);

        // 2) تشغيل عمليات عشوائية: تحويلات بين محافظ (بعضها سيفشل لعدم الكفاية)
        $success = 0;
        $failed  = 0;

        for ($op = 0; $op < $opCount; $op++) {
            $from = $wallets[mt_rand(0, $userCount - 1)];
            $to   = $wallets[mt_rand(0, $userCount - 1)];
            if ($from === $to) {
                continue;
            }
            // مبلغ عشوائي — أحياناً ضخم عمداً ليتجاوز الرصيد ويُجبَر الفشل
            $amount = (string) mt_rand(1, 1_500_000);

            try {
                $this->ledger->post(
                    sourceType: 'integrity_transfer',
                    sourceId: (string) $op,
                    description: "تحويل {$from} → {$to}",
                    lines: [
                        ['account' => $from, 'direction' => 'debit',  'amount' => $amount],
                        ['account' => $to,   'direction' => 'credit', 'amount' => $amount],
                    ],
                );
                $success++;
            } catch (\Throwable $e) {
                // فشل متوقّع (رصيد غير كافٍ) — يجب ألّا يترك أثراً
                $failed++;
            }
        }

        // ══════════════════ التحقّق من الثوابت ══════════════════

        // ثابت A: لكلّ حساب، الرصيد المخزّن = المُعاد حسابه من القيود (لا انحراف كاش)
        $accounts = LedgerAccount::all();
        foreach ($accounts as $acc) {
            $computed = $this->ledger->computeBalanceFromLines($acc->id);
            $this->assertSame(
                0,
                bccomp((string) $acc->current_balance, $computed, 4),
                "انحراف كاش في {$acc->account_code}: مخزّن={$acc->current_balance} محسوب={$computed}"
            );
        }

        // ثابت B: القيد المزدوج عالمياً — مجموع المدين = مجموع الدائن
        $totalDebit  = (string) LedgerEntryLine::where('direction', 'debit')->sum('amount');
        $totalCredit = (string) LedgerEntryLine::where('direction', 'credit')->sum('amount');
        $this->assertSame(
            0,
            bccomp($totalDebit, $totalCredit, 4),
            "الدفتر غير متوازن: مدين={$totalDebit} دائن={$totalCredit}"
        );

        // ثابت C: حفظ المال — مجموع أرصدة المحافظ = المبلغ المحقون (التحويلات صفرية المجموع)
        $walletSum = '0';
        foreach ($wallets as $code) {
            $bal = (string) LedgerAccount::where('account_code', $code)->value('current_balance');
            $walletSum = bcadd($walletSum, $bal, 4);
        }
        $this->assertSame(
            0,
            bccomp($walletSum, $totalMinted, 4),
            "المال لم يُحفَظ: مجموع المحافظ={$walletSum} المحقون={$totalMinted}"
        );

        // ثابت C-2: رأس المال المُنفَق (سالب على جانب الدائن) = المحقون بإشارة معاكسة
        $capitalBal = (string) LedgerAccount::where('account_code', self::CAPITAL)->value('current_balance');
        $this->assertSame(
            0,
            bccomp($capitalBal, $totalMinted, 4),
            "رأس المال لا يطابق المحقون: {$capitalBal} != {$totalMinted}"
        );

        // ثابت D: كلّ قيد مزدوج له سطران بالضبط (لا سطر يتيم من عملية فاشلة)
        $entryCount = LedgerJournalEntry::count();
        $lineCount  = LedgerEntryLine::count();
        $this->assertSame(
            $entryCount * 2,
            $lineCount,
            "أسطر يتيمة: قيود={$entryCount} أسطر={$lineCount} (المتوقّع ×2)"
        );
        // القيود = الحقن + التحويلات الناجحة فقط
        $this->assertSame(
            $userCount + $success,
            $entryCount,
            "عدد القيود لا يطابق (حقن {$userCount} + ناجح {$success})"
        );

        fwrite(STDERR, sprintf(
            "\n[INTEGRITY] محافظ=%d عمليات=%d ناجح=%d فاشل=%d | محقون=%s محفوظ=%s | مدين=%s دائن=%s ✓\n",
            $userCount, $opCount, $success, $failed, $totalMinted, $walletSum, $totalDebit, $totalCredit
        ));

        $this->assertGreaterThan(0, $failed, 'يُتوقّع بعض الفشل (رصيد غير كافٍ) لإثبات أنّ الفشل لا يُفسد الدفتر');
    }
}
