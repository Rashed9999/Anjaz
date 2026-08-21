<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\Ledger\LedgerAccount;
use App\Models\Ledger\LedgerEntryLine;
use App\Models\Ledger\LedgerJournalEntry;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-LEDGER-OPENING-002 — أمرُ الترحيل يُشغَّل مرّة على الإنتاج، فلا مجال لخطأ.
 *
 * هذا الأمر يكتب قيوداً افتتاحية لكل محفظة قائمة. وخطؤه لا يظهر كانهيار بل
 * كأرصدةٍ مضاعفة في دفتر أستاذٍ يُبنى عليه كل تقرير مالي لاحق. ولأنه يُنفَّذ
 * على الإنتاج مرّةً واحدة، لا توجد «جولة ثانية» يُصحَّح فيها.
 *
 * فالمفحوص هنا ليس «هل يعمل» بل ثلاثة أسئلةٍ تُسقِط أمراً يبدو عاملاً:
 *   1. هل يضاعف إن أُعيد؟ (أوامر النشر تُعاد عند الفشل الجزئي)
 *   2. هل يترك المطابقة تامّة، أم يقترب منها؟
 *   3. هل تمنعه محفظةٌ واحدة فاسدة من إدخال البقيّة؟
 */
class LedgerBackfillTest extends TestCase
{
    use RefreshDatabase;

    private function ledgerBalanceOf(int $userId): string
    {
        $account = LedgerAccount::where('account_code', "USER_WALLET_{$userId}")->first();
        if (!$account) {
            return '0.0000';
        }
        $d = (string) LedgerEntryLine::where('account_id', $account->id)
            ->where('direction', 'debit')->sum('amount');
        $c = (string) LedgerEntryLine::where('account_id', $account->id)
            ->where('direction', 'credit')->sum('amount');

        return bcsub($c ?: '0', $d ?: '0', 4);
    }

    /**
     * ينشئ محفظةً **بلا** أثرٍ في الدفتر — أي كما هي محافظ الإنتاج القائمة.
     *
     * `EMoneyObserver` يفتح كل محفظة تولد مموَّلة، فلو استُعمل الإنشاء العاديّ
     * لما بقي شيءٌ ليرحّله الأمر، ونجح الاختبار على العدم. فتُكتب الصفوف
     * بـ`DB::table` تجاوزاً للمراقب — وهذه هي الحالة التي وُضع الأمر لها.
     */
    private function legacyWallet(string $balance): User
    {
        $u = User::factory()->create();
        DB::table('e_money')->insert([
            'user_id' => $u->id,
            'current_balance' => $balance,
            'charge_earned' => '0.0000',
            'pending_balance' => '0.0000',
            'held_balance' => '0.0000',
            'zone_code' => 'SOUTH',
            'version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $u;
    }

    public function test_the_fixture_really_leaves_the_ledger_empty(): void
    {
        // فحصٌ ذاتيّ: لو سرّب المراقبُ قيداً إلى هذه المحافظ لصار الأمرُ
        // يُرحّل ما رُحِّل، ونجح كل ما بعده لسببٍ خاطئ.
        $u = $this->legacyWallet('5000');

        $this->assertSame('0.0000', $this->ledgerBalanceOf($u->id),
            'المحفظة دخلت الدفتر قبل الترحيل — التهيئة لا تحاكي الإنتاج');
    }

    public function test_backfill_makes_ledger_match_wallets(): void
    {
        $a = $this->legacyWallet('10000');
        $b = $this->legacyWallet('250.5000');
        $empty = $this->legacyWallet('0');

        $this->artisan('amial:ledger-backfill')->assertSuccessful();

        $this->assertSame(0, bccomp($this->ledgerBalanceOf($a->id), '10000', 4));
        $this->assertSame(0, bccomp($this->ledgerBalanceOf($b->id), '250.5000', 4));

        // محفظةٌ فارغة لا تحتاج قيداً — وقيدٌ بمجموع صفر مرفوضٌ أصلاً.
        $this->assertSame('0.0000', $this->ledgerBalanceOf($empty->id));
    }

    public function test_running_it_twice_does_not_double_the_balances(): void
    {
        // أوامر النشر تُعاد عند الفشل الجزئيّ أو عند إعادة تشغيل الحاوية.
        // وأمرٌ يضاعف عند الإعادة يُفسد الدفتر بصمت — ولا يُكتشف إلّا في
        // تقريرٍ ماليّ بعد أسابيع.
        $u = $this->legacyWallet('7500');

        $this->artisan('amial:ledger-backfill')->assertSuccessful();
        $this->artisan('amial:ledger-backfill')->assertSuccessful();

        $this->assertSame(0, bccomp($this->ledgerBalanceOf($u->id), '7500', 4),
            'تضاعف الرصيد عند الإعادة — الأمر غير آمن للتكرار');

        $this->assertSame(1,
            LedgerJournalEntry::where('source_type', 'opening_balance')
                ->where('source_id', (string) $u->id)->count(),
            'كُتب قيدٌ ثانٍ للمحفظة نفسها');

        // AMIAL-LEDGER-OPENING-003 — **والافتتاحُ لا يُكتب تصحيحاً.**
        //
        // كان هذا القيدُ يُرحَّل بـ`external_adjustment`، فيختلط في كلّ
        // تقريرٍ بعده رصيدٌ افتتاحيٌّ مشروعٌ مع **تسويةٍ خارجيّةٍ تستوجب
        // تحقيقاً**. ومن قرأ التقريرَ رأى تسوياتٍ بعدد محافظ الإنتاج كلِّها.
        $this->assertSame(0,
            LedgerJournalEntry::where('source_type', 'external_adjustment')
                ->where('source_id', (string) $u->id)->count(),
            'الافتتاحُ رُحّل تسويةً خارجيّة — فيُقرأ في التقارير شبهةً لا ترحيلاً');
    }

    public function test_dry_run_writes_nothing(): void
    {
        // تجربةٌ جافّة تكتب شيئاً أسوأ من عدمها: يعتمد عليها المشغّل ليقرّر.
        $u = $this->legacyWallet('3000');

        $this->artisan('amial:ledger-backfill', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame('0.0000', $this->ledgerBalanceOf($u->id),
            'التجربة الجافّة كتبت قيداً');
    }

    public function test_the_ledger_stays_balanced_after_backfill(): void
    {
        $this->legacyWallet('10000');
        $this->legacyWallet('333.3300');
        $this->legacyWallet('1');

        $this->artisan('amial:ledger-backfill')->assertSuccessful();

        $debits = (string) LedgerEntryLine::where('direction', 'debit')->sum('amount');
        $credits = (string) LedgerEntryLine::where('direction', 'credit')->sum('amount');

        $this->assertGreaterThan(0, bccomp($debits, '0', 4), 'لم يُكتب شيء — الفحص على العدم');
        $this->assertSame(0, bccomp($debits, $credits, 4),
            "الدفتر غير متوازن بعد الترحيل: مدين $debits دائن $credits");
    }

    public function test_a_transfer_works_immediately_after_backfill(): void
    {
        // الغاية النهائية من الأمر كلّه: أن يقبل الدفترُ أوّل خصمٍ بعده.
        // وبلا هذا الفحص يمرّ أمرٌ يكتب قيوداً صحيحة ولا يحلّ المشكلة.
        $sender = $this->legacyWallet('10000');
        $receiver = $this->legacyWallet('0');

        $this->artisan('amial:ledger-backfill')->assertSuccessful();

        $svc = new class { use \App\Traits\TransactionTrait; };
        $svc->customer_send_money_transaction(
            from_user_id: $sender->id, to_user_id: $receiver->id,
            amount: '1000', charge: '0', note: 'بعد الترحيل',
        );

        $this->assertSame(1,
            LedgerJournalEntry::where('source_type', 'send_money')->count(),
            'رُفض قيد التحويل بعد الترحيل — لم يُحلّ شيء');

        foreach ([$sender->id, $receiver->id] as $id) {
            $wallet = (string) EMoney::where('user_id', $id)->value('current_balance');
            $this->assertSame(0, bccomp($wallet, $this->ledgerBalanceOf($id), 4),
                "انحراف على المستخدم $id بعد الترحيل");
        }
    }

    public function test_one_bad_wallet_does_not_stop_the_rest(): void
    {
        // محفظةٌ بلا مستخدم (بقايا حذف) لا يجوز أن تمنع إدخال آلاف غيرها.
        // فالأمر يُشغَّل في نافذة نشرٍ ضيّقة، وتوقّفه في منتصفه يترك الدفتر
        // نصفَ مرحَّل — وهي أسوأ حالة من عدم التشغيل.
        $good = $this->legacyWallet('4000');

        DB::table('e_money')->insert([
            'user_id' => 999999, // لا وجود له
            'current_balance' => '500.0000',
            'charge_earned' => '0.0000', 'pending_balance' => '0.0000',
            'held_balance' => '0.0000', 'zone_code' => 'SOUTH', 'version' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('amial:ledger-backfill');

        $this->assertSame(0, bccomp($this->ledgerBalanceOf($good->id), '4000', 4),
            'المحفظة السليمة لم تُدخَل — أوقفت الفاسدةُ الترحيلَ كلّه');
    }
}
