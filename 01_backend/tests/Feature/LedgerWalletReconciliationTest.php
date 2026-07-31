<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\Ledger\LedgerAccount;
use App\Models\Ledger\LedgerEntryLine;
use App\Models\User;
use App\Traits\TransactionTrait;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-LEDGER-RECON-001 — الدفتر يجب أن يساوي المحافظ، وإلّا فأحدهما يكذب.
 *
 * **الفجوة التي يسدّها هذا الملفّ:**
 * في المشروع اليوم مصدرا حقيقة للمال: جدول `e_money` (الرصيد الذي يراه
 * المستخدم ويُخصم منه) و`ledger_entry_lines` (القيد المحاسبي). ولا شيء
 * يفرض تساويهما. والقيد يُكتب **بعد** الـ commit وخارجه، فإن فشل سُجّل في
 * اللوج ومضى المال.
 *
 * ومعنى ذلك أن الانحراف لا يُكتشف بخطأ ولا بانهيار، بل بفارقٍ يتراكم بصمت
 * حتى يسأل أحدٌ يوماً: «أين ذهب المال؟» — فلا يكون في السجلّ ما يجيب.
 *
 * فهذا الاختبار هو **المقياس** الذي يجعل الفارق مرئياً. وهو يسبق جعلَ
 * الدفتر إلزامياً عمداً: من يشدّ حزاماً في سيّارةٍ يجهل سرعتها لا يعرف
 * إن كان الحزام نفعه.
 *
 * ولا يقيس هذا الملفّ التوازن الداخلي (debit = credit) — ذاك مفروضٌ في
 * `LedgerService::assertBalanced` ومُختبَر في `LedgerServiceTest`. القياس
 * هنا **بين نظامين**: هل يقول الدفتر عن رصيد فلان ما تقوله محفظته؟
 */
class LedgerWalletReconciliationTest extends TestCase
{
    use RefreshDatabase;
    use TransactionTrait;

    /**
     * رصيد محفظة المستخدم كما يحسبه الدفتر من قيوده (لا من العمود المخزَّن).
     *
     * يُحسب من الأسطر لا من `current_balance` المخزَّن على الحساب: العمود
     * المخزَّن قد ينحرف هو الآخر، ومقارنةُ مخزَّنٍ بمخزَّن تُخفي عطلين.
     */
    private function ledgerBalanceOf(int $userId): string
    {
        $account = LedgerAccount::where('account_code', "USER_WALLET_{$userId}")->first();
        if (!$account) {
            return '0.0000';
        }

        $debits = (string) LedgerEntryLine::where('account_id', $account->id)
            ->where('direction', 'debit')->sum('amount');
        $credits = (string) LedgerEntryLine::where('account_id', $account->id)
            ->where('direction', 'credit')->sum('amount');

        // محفظة المستخدم التزامٌ على المنصّة: طبيعتها credit، فالزيادة credit.
        return bcsub($credits ?: '0', $debits ?: '0', 4);
    }

    private function walletBalanceOf(int $userId): string
    {
        $w = EMoney::where('user_id', $userId)->first();
        return $w ? bcadd((string) $w->current_balance, '0', 4) : '0.0000';
    }

    private function makeUser(string $balance = '10000'): User
    {
        $u = User::factory()->create();
        EMoney::updateOrCreate(
            ['user_id' => $u->id],
            ['current_balance' => $balance, 'zone_code' => 'SOUTH'],
        );
        return $u;
    }

    /**
     * يُدخل محفظة المستخدم في الدفتر برصيدها القائم.
     *
     * يُنادى `reconcileWalletBalance` صراحةً لأن الدفتر — كما هو اليوم —
     * ينشئ الحساب بصفر ولا يعرف شيئاً عن رصيدٍ مُوّل من خارجه. وهذا نفسه
     * سبب العطل الذي يوثّقه `test_the_ledger_is_silently_dead_...` أدناه.
     */
    private function openLedgerFor(User $u, string $balance): void
    {
        app(\App\Services\LedgerService::class)
            ->reconcileWalletBalance($u->id, 'رصيد قائم قبل دخول الدفتر');
    }

    // ── الفحص نفسه ────────────────────────────────────────────────────

    public function test_the_measure_detects_a_divergence_when_one_exists(): void
    {
        // فحصٌ ذاتيّ: مقياسٌ لا يكشف فرقاً مصنوعاً باليد لن يكشف فرقاً حقيقياً.
        // وقد مرّ عليّ في هذا المشروع فحصٌ نجح لأن نمطه لم يطابق سطراً واحداً.
        $u = $this->makeUser('10000');
        $this->openLedgerFor($u, '10000');

        $this->assertSame(
            0,
            bccomp($this->walletBalanceOf($u->id), $this->ledgerBalanceOf($u->id), 4),
            'الطرفان يجب أن يتساويا قبل أي عبث'
        );

        // عبثٌ بالمحفظة وحدها — كما يحدث تماماً حين يفشل ترحيل القيد.
        DB::table('e_money')->where('user_id', $u->id)->update(['current_balance' => '9500']);

        $this->assertNotSame(
            0,
            bccomp($this->walletBalanceOf($u->id), $this->ledgerBalanceOf($u->id), 4),
            'المقياس لم يلتقط فرقاً مصنوعاً — فهو أعمى ولا يُعتمد عليه'
        );
    }

    /** بعد إعلان الرصيد القائم للدفتر، يتطابق الطرفان عبر تحويلٍ حقيقيّ. */
    public function test_a_transfer_reconciles_once_the_opening_balance_is_declared(): void
    {
        $sender = $this->makeUser('10000');
        $receiver = $this->makeUser('0');
        $this->openLedgerFor($sender, '10000');
        $this->openLedgerFor($receiver, '0');

        $this->customer_send_money_transaction(
            from_user_id: $sender->id,
            to_user_id: $receiver->id,
            amount: '1000',
            charge: '0',
            note: 'مطابقة',
        );

        foreach ([$sender, $receiver] as $u) {
            $wallet = $this->walletBalanceOf($u->id);
            $ledger = $this->ledgerBalanceOf($u->id);

            $this->assertSame(0, bccomp($wallet, $ledger, 4),
                "انحراف على المستخدم {$u->id}: المحفظة $wallet والدفتر $ledger");
        }
    }


    public function test_without_an_opening_balance_the_posting_is_silently_lost(): void
    {
        // AMIAL-LEDGER-DEAD-001 — أخطر ما كُشف في هذا المشروع.
        //
        // محفظةٌ مموَّلة من خارج الدفتر (بذرة، شحن إداريّ، مسارٌ لا يُرحّل)
        // يبدأ حسابُها في الدفتر من صفر. فأوّل خصمٍ يرفضه الدفتر بـ«الرصيد
        // لا يكفي» — ويُبتلع الرفض في `PostsToLedger::safeLedgerPost`.
        //
        // النتيجة: المال يتحرّك و**لا يُكتب قيدٌ إطلاقاً**، بلا خطأ ولا
        // انهيار ولا صفٍّ في جدول. أي أن الدفتر ليس «غير مُلزِم» فحسب، بل
        // معطَّلٌ في الحالة الشائعة.
        //
        // ولا يُصلَح هذا بتوصيل الترحيل داخل المعاملة وحده: جُرّب فسقط 87
        // اختباراً بالسبب نفسه. العلاج الصحيح رصيدٌ افتتاحيّ لكل محفظة —
        // ومحاولةُ توليده تلقائياً عند أوّل لقاء تُضاعف القيد إن كانت
        // المحفظة قد مُوّلت في العملية نفسها (قيس: 100000 بدل 50000).
        // فالمسار السليم تسويةٌ **بعد** العملية لا افتتاحٌ قبلها.
        $sender = $this->makeUser('10000');
        $receiver = $this->makeUser('0');
        // بلا openLedgerFor عمداً — هذه هي الحالة الواقعة اليوم.

        $this->customer_send_money_transaction(
            from_user_id: $sender->id, to_user_id: $receiver->id,
            amount: '1000', charge: '0', note: 'عطل صامت',
        );

        $this->assertSame('9000.0000', $this->walletBalanceOf($sender->id),
            'المال لم يتحرّك — تغيّر شيء في المسار المالي');

        $entries = \App\Models\Ledger\LedgerJournalEntry::where('source_type', 'send_money')->count();
        $this->assertSame(0, $entries,
            'كُتب قيدٌ الآن — يبدو أن العطل أُصلح. اقلب هذا التوقّع إلى '
            . 'assertSame(1, ...) واحذف هذا الشرح.');
    }

    public function test_the_ledger_itself_stays_balanced_across_all_entries(): void
    {
        // شرطٌ ضروريّ لا كافٍ: دفترٌ غير متوازن داخلياً لا معنى لمقارنته
        // بأي شيء. يُفحص بعد حركةٍ حقيقية لا على قاعدة فارغة.
        $a = $this->makeUser('10000');
        $b = $this->makeUser('5000');
        $this->openLedgerFor($a, '10000');
        $this->openLedgerFor($b, '5000');

        $this->customer_send_money_transaction(
            from_user_id: $a->id, to_user_id: $b->id,
            amount: '750', charge: '10', note: 'توازن',
        );

        $debits = (string) LedgerEntryLine::where('direction', 'debit')->sum('amount');
        $credits = (string) LedgerEntryLine::where('direction', 'credit')->sum('amount');

        $this->assertGreaterThan(0, bccomp($debits, '0', 4),
            'لا قيود أصلاً — الفحص مرّ على العدم');
        $this->assertSame(0, bccomp($debits, $credits, 4),
            "الدفتر غير متوازن: مدين $debits دائن $credits");
    }
}
