<?php

namespace App\Services;

use App\Models\EMoney;
use App\Models\Ledger\LedgerAccount;
use App\Models\Ledger\LedgerJournalEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-LEDGER-CENTER-001 — مركز الدفتر (الفصل ١٧).
 *
 * **المفارقة التي يُنهيها هذا الملفّ:** أُصلح الدفتر بالكامل — القيد
 * الافتتاحيّ، والترحيل الإلزاميّ، وتغطية كلّ مسارات المال، وحرّاسٌ تمنع
 * تراجعه — **ولا شاشة تقرؤه**. الموجود بثٌّ حيّ لآخر ثلاثين قيداً، وهو
 * عرضٌ لا دفتر: لا ميزان مراجعة، ولا دليل حسابات، ولا كشف حساب، ولا مطابقة
 * مع المحافظ.
 *
 * وميزان المراجعة هو **أوّل ما يطلبه المدقّق أو المنظّم**.
 *
 * ══════════════════════════════════════════════════════════════
 * **القاعدة الحاكمة في هذا الملفّ — وهي أهمّ ما فيه:**
 *
 * كلّ رقمٍ يُقصد به **التحقّق** يُحسب من **سطور القيود**، لا من
 * `ledger_accounts.current_balance`.
 *
 * والسبب أنّ `current_balance` رقمٌ **مُخزَّن ومُحدَّث** عند كلّ حركة. وهو
 * بالضبط ما نريد التحقّق منه. فميزانُ مراجعةٍ يُبنى منه يُثبت أنّ العمود
 * يساوي نفسه — ويمرّ **حتى لو انحرف عن مجموع القيود**، وهو الانحراف الوحيد
 * الذي يستحقّ الكشف.
 *
 * وهذا الخطأ سهلٌ جداً ومغرٍ: قراءة العمود أسرع بمئة مرّة من تجميع
 * الملايين من السطور. لكنّ السرعة هنا تشتري رقماً لا يعني شيئاً.
 * ══════════════════════════════════════════════════════════════
 */
class LedgerReportService
{
    /**
     * الحقيقة المالية لمحفظة عميل واحدة.
     *
     * مركز العملاء لا يحسب رصيداً موازياً: الرصيد التشغيلي يبقى من محرك
     * E-Money، بينما يُشتق الرصيد الدفتري من سطور القيود للمطابقة. إن غاب
     * أحد المصدرين أو اختلفا نُعيد حالة صريحة قابلة للتصعيد، لا بطاقة خضراء
     * برقم لا يمكن تفسيره.
     *
     * @return array{state:string,operational_balance:?string,ledger_balance:?string,gap:?string,account_id:?int,account_code:?string,first_entry_at:?string,last_entry_at:?string,last_entry_ulid:?string,reason:?string}
     */
    public function walletTruth(int $userId): array
    {
        $wallet = EMoney::where('user_id', $userId)->first();
        $operational = $wallet ? (string) ($wallet->current_balance ?? '0') : null;

        if (!Schema::hasTable('ledger_accounts')
            || !Schema::hasTable('ledger_entry_lines')
            || !Schema::hasTable('ledger_journal_entries')) {
            return $this->walletTruthUnavailable($operational, 'جداول الدفتر غير متاحة للتحقق');
        }

        $account = LedgerAccount::query()
            ->where('owner_user_id', $userId)
            ->where('account_code', 'USER_WALLET_' . $userId)
            ->first();

        if (!$account) {
            return $this->walletTruthUnavailable($operational, 'لا يوجد حساب دفتر للمحفظة');
        }

        $totals = DB::table('ledger_entry_lines as l')
            ->join('ledger_journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.account_id', $account->id)
            ->where('e.status', 'posted')
            ->selectRaw("COALESCE(SUM(CASE WHEN l.direction = 'debit' THEN l.amount ELSE 0 END), 0) AS debit_total")
            ->selectRaw("COALESCE(SUM(CASE WHEN l.direction = 'credit' THEN l.amount ELSE 0 END), 0) AS credit_total")
            ->selectRaw('MIN(e.posted_at) AS first_entry_at, MAX(e.posted_at) AS last_entry_at')
            ->first();

        $debit = (string) ($totals->debit_total ?? '0');
        $credit = (string) ($totals->credit_total ?? '0');
        $ledger = $account->normal_balance === 'debit'
            ? bcsub($debit, $credit, 4)
            : bcsub($credit, $debit, 4);
        $last = DB::table('ledger_entry_lines as l')
            ->join('ledger_journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.account_id', $account->id)->where('e.status', 'posted')
            ->orderByDesc('e.posted_at')->orderByDesc('l.id')
            ->first(['e.entry_ulid']);

        $gap = $operational === null ? null : bcsub($operational, $ledger, 4);
        $state = $operational === null
            ? 'unverifiable'
            : (bccomp($gap, '0', 4) === 0 ? 'reconciled' : 'mismatch');

        return [
            'state' => $state,
            'operational_balance' => $operational,
            'ledger_balance' => $ledger,
            'gap' => $gap,
            'account_id' => (int) $account->id,
            'account_code' => (string) $account->account_code,
            'first_entry_at' => $totals->first_entry_at ?? null,
            'last_entry_at' => $totals->last_entry_at ?? null,
            'last_entry_ulid' => $last?->entry_ulid,
            'reason' => $state === 'mismatch' ? 'الرصيد التشغيلي لا يطابق قيود الدفتر' : null,
        ];
    }

    /** @return array{state:string,operational_balance:?string,ledger_balance:null,gap:null,account_id:null,account_code:null,first_entry_at:null,last_entry_at:null,last_entry_ulid:null,reason:string} */
    private function walletTruthUnavailable(?string $operational, string $reason): array
    {
        return [
            'state' => 'unverifiable',
            'operational_balance' => $operational,
            'ledger_balance' => null,
            'gap' => null,
            'account_id' => null,
            'account_code' => null,
            'first_entry_at' => null,
            'last_entry_at' => null,
            'last_entry_ulid' => null,
            'reason' => $reason,
        ];
    }

    /**
     * ميزان المراجعة — مجموع المدين ومجموع الدائن لكلّ حساب.
     *
     * والمساواة بينهما في الإجمالي هي المعادلة الأساسية في القيد المزدوج:
     * كلّ ريالٍ خرج من حسابٍ دخل آخر. واختلافُهما يعني قيداً غير متوازن —
     * أي أنّ مالاً ظهر أو اختفى من العدم.
     */
    public function trialBalance(?string $from = null, ?string $to = null): array
    {
        // من السطور لا من `current_balance` — انظر شرح أعلى الملفّ.
        $q = DB::table('ledger_entry_lines as l')
            ->join('ledger_accounts as a', 'a.id', '=', 'l.account_id')
            ->join('ledger_journal_entries as e', 'e.id', '=', 'l.journal_entry_id');

        if ($from) {
            $q->where('e.posted_at', '>=', $from);
        }
        if ($to) {
            $q->where('e.posted_at', '<=', $to . ' 23:59:59');
        }

        $rows = $q->groupBy('a.id', 'a.account_code', 'a.name_ar', 'a.account_type',
                'a.normal_balance', 'a.current_balance')
            ->selectRaw("
                a.id, a.account_code, a.name_ar, a.account_type,
                a.normal_balance, a.current_balance,
                SUM(CASE WHEN l.direction = 'debit'  THEN l.amount ELSE 0 END) as debit_total,
                SUM(CASE WHEN l.direction = 'credit' THEN l.amount ELSE 0 END) as credit_total
            ")
            ->orderBy('a.account_code')
            ->get();

        $totalDebit = '0';
        $totalCredit = '0';
        $accounts = [];

        foreach ($rows as $r) {
            $d = (string) $r->debit_total;
            $c = (string) $r->credit_total;

            $totalDebit = bcadd($totalDebit, $d, 4);
            $totalCredit = bcadd($totalCredit, $c, 4);

            // الرصيد المحسوب من الحركة، باتّجاه الحساب الطبيعيّ.
            $computed = $r->normal_balance === 'debit'
                ? bcsub($d, $c, 4)
                : bcsub($c, $d, 4);

            $stored = (string) $r->current_balance;

            $accounts[] = [
                'id' => (int) $r->id,
                'account_code' => $r->account_code,
                'name' => $r->name_ar,
                'account_type' => $r->account_type,
                'normal_balance' => $r->normal_balance,
                'debit_total' => $d,
                'credit_total' => $c,
                'computed_balance' => $computed,
                'stored_balance' => $stored,
                // الفرق بين المحسوب والمخزَّن: هذا هو العمود الذي يبرّر
                // الحساب من السطور. لو قُرئ العمود المخزَّن لكان صفراً دائماً
                // بحكم التعريف.
                'drift' => bcsub($computed, $stored, 4),
                'has_drift' => bccomp($computed, $stored, 4) !== 0,
            ];
        }

        $difference = bcsub($totalDebit, $totalCredit, 4);

        return [
            'from' => $from,
            'to' => $to,
            'accounts' => $accounts,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'difference' => $difference,
            // المعادلة الأساسية. اختلالُها ليس تحذيراً بل إنذار.
            'balanced' => bccomp($difference, '0', 4) === 0,
            'drifted_accounts' => count(array_filter($accounts, fn ($a) => $a['has_drift'])),
            'unbalanced_entries' => $this->unbalancedEntries(),
        ];
    }

    /**
     * قيودٌ لا يتساوى مدينها بدائنها.
     *
     * **ولا يكفي فحصُ الإجمالي وحده:** قيدان مختلّان بمقدارين متعاكسين
     * يُصلحان الإجمالي ويبقيان خاطئين. فيُفحص كلّ قيدٍ على حدة.
     *
     * @return list<array{id: int, ulid: string, debit: string, credit: string}>
     */
    public function unbalancedEntries(int $limit = 50): array
    {
        return DB::table('ledger_entry_lines as l')
            ->join('ledger_journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->groupBy('e.id', 'e.entry_ulid', 'e.source_type', 'e.posted_at')
            ->havingRaw("
                SUM(CASE WHEN l.direction = 'debit'  THEN l.amount ELSE 0 END)
             <> SUM(CASE WHEN l.direction = 'credit' THEN l.amount ELSE 0 END)
            ")
            ->selectRaw("
                e.id, e.entry_ulid, e.source_type, e.posted_at,
                SUM(CASE WHEN l.direction = 'debit'  THEN l.amount ELSE 0 END) as d,
                SUM(CASE WHEN l.direction = 'credit' THEN l.amount ELSE 0 END) as c
            ")
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'ulid' => (string) $r->entry_ulid,
                'source_type' => (string) $r->source_type,
                'debit' => (string) $r->d,
                'credit' => (string) $r->c,
                'difference' => bcsub((string) $r->d, (string) $r->c, 4),
                'posted_at' => (string) $r->posted_at,
            ])->all();
    }

    /** دليل الحسابات. */
    public function chartOfAccounts(?string $type = null): array
    {
        $q = LedgerAccount::query();
        if ($type) {
            $q->where('account_type', $type);
        }

        return $q->orderBy('account_code')->limit(500)->get()
            ->map(fn (LedgerAccount $a) => [
                'id' => (int) $a->id,
                'account_code' => $a->account_code,
                'name' => $a->name_ar,
                'account_type' => $a->account_type,
                'normal_balance' => $a->normal_balance,
                'owner_type' => $a->owner_type,
                'owner_user_id' => $a->owner_user_id,
                'current_balance' => (string) $a->current_balance,
                'currency' => $a->currency,
                'is_active' => (bool) $a->is_active,
            ])->all();
    }

    /**
     * كشف حساب — حركات حسابٍ واحد برصيدٍ متدرّج.
     *
     * والرصيد المتدرّج يُحسب هنا من الصفر تصاعديّاً، ولا يُقرأ من
     * `balance_after` المخزَّن في السطر. فذاك ما نتحقّق منه — وقراءتُه تجعل
     * الكشف يُعيد سرد ما قيل بدل أن يُراجعه.
     */
    public function accountStatement(int $accountId, ?string $from = null, ?string $to = null, int $limit = 500): array
    {
        $account = LedgerAccount::find($accountId);
        if (!$account) {
            return ['account' => null, 'lines' => []];
        }

        $q = DB::table('ledger_entry_lines as l')
            ->join('ledger_journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.account_id', $accountId);

        if ($from) {
            $q->where('e.posted_at', '>=', $from);
        }
        if ($to) {
            $q->where('e.posted_at', '<=', $to . ' 23:59:59');
        }

        $rows = $q->orderBy('e.posted_at')->orderBy('l.id')->limit($limit)
            ->selectRaw('l.id, l.direction, l.amount, l.balance_after, l.description_ar,
                e.entry_ulid, e.source_type, e.description_ar as entry_desc, e.posted_at')
            ->get();

        $running = '0';
        $lines = [];

        foreach ($rows as $r) {
            $amount = (string) $r->amount;

            $running = ($r->direction === 'debit') === ($account->normal_balance === 'debit')
                ? bcadd($running, $amount, 4)
                : bcsub($running, $amount, 4);

            $stored = (string) $r->balance_after;

            $lines[] = [
                'id' => (int) $r->id,
                'entry_ulid' => (string) $r->entry_ulid,
                'source_type' => (string) $r->source_type,
                'description' => (string) ($r->description_ar ?: $r->entry_desc),
                'direction' => (string) $r->direction,
                'amount' => $amount,
                'running_balance' => $running,
                'stored_after' => $stored,
                'mismatch' => bccomp($running, $stored, 4) !== 0,
                'posted_at' => (string) $r->posted_at,
            ];
        }

        return [
            'account' => [
                'id' => (int) $account->id,
                'account_code' => $account->account_code,
                'name' => $account->name_ar,
                'normal_balance' => $account->normal_balance,
                'stored_balance' => (string) $account->current_balance,
            ],
            'lines' => $lines,
            'computed_balance' => $running,
            'mismatched_lines' => count(array_filter($lines, fn ($l) => $l['mismatch'])),
        ];
    }

    /**
     * مطابقة الدفتر بالمحافظ.
     *
     * **هذا هو الفحص الذي بُني في الجلسة كاختبارٍ وبقي بلا شاشة.** والدفتر
     * قد يكون متوازناً تماماً — مدينُه يساوي دائنه — ويختلف مع ذلك عن
     * المحافظ: التوازن الداخليّ لا يعني المطابقة الخارجية.
     *
     * ومصدر الحقيقة للرصيد هو `e_money` (هي المحمية بالأقفال والمُختبَرة)،
     * فالانحراف يعني **قيداً ناقصاً في الدفتر** لا خطأً في رصيد العميل.
     */
    public function walletReconciliation(int $limit = 200): array
    {
        // الرصيد الدفتريّ يُشتقّ من السطور لا من `current_balance`: المطابقةُ
        // بين رقمين مخزَّنين تُثبت أنّ أحدهما نُسخ عن الآخر، لا أنّ الحركة
        // صحيحة.
        $ledger = DB::table('ledger_accounts as a')
            ->leftJoin('ledger_entry_lines as l', 'l.account_id', '=', 'a.id')
            ->whereNotNull('a.owner_user_id')
            ->where('a.account_code', 'like', 'USER_WALLET_%')
            ->groupBy('a.owner_user_id')
            ->selectRaw("a.owner_user_id,
                SUM(CASE WHEN l.direction = 'credit' THEN l.amount ELSE 0 END)
              - SUM(CASE WHEN l.direction = 'debit'  THEN l.amount ELSE 0 END) as bal")
            ->pluck('bal', 'owner_user_id');

        $rows = [];
        $divergent = 0;
        $totalGap = '0';
        $walletUserIds = [];

        EMoney::with('user:id,f_name,l_name,phone')
            ->orderBy('user_id')->limit($limit)->get()
            ->each(function (EMoney $w) use (&$rows, &$divergent, &$totalGap, &$walletUserIds, $ledger) {
                $walletUserIds[(int) $w->user_id] = true;
                $wallet = (string) ($w->current_balance ?? '0');
                $book = (string) ($ledger[$w->user_id] ?? '0');
                $gap = bcsub($wallet, $book, 4);
                $off = bccomp($gap, '0', 4) !== 0;

                if ($off) {
                    $divergent++;
                    $totalGap = bcadd($totalGap, $gap, 4);
                }

                $rows[] = [
                    'user_id' => (int) $w->user_id,
                    'name' => trim((string) ($w->user?->f_name . ' ' . $w->user?->l_name)) ?: '—',
                    'phone' => (string) ($w->user?->phone ?? '—'),
                    'wallet_balance' => $wallet,
                    'ledger_balance' => $book,
                    'gap' => $gap,
                    'diverged' => $off,
                ];
            });

        // And the other direction matters just as much: a ledger wallet
        // without an E-Money row used to vanish from this report entirely.
        // That made a deleted/corrupt operational wallet look reconciled even
        // while the ledger still carried a liability.  It is a real mismatch:
        // the wallet side is zero because it does not exist, not because it
        // has been checked and found equal.
        foreach ($ledger as $userId => $book) {
            $userId = (int) $userId;
            if (isset($walletUserIds[$userId])) {
                continue;
            }

            $book = (string) $book;
            $gap = bcsub('0', $book, 4);
            $divergent++;
            $totalGap = bcadd($totalGap, $gap, 4);
            $rows[] = [
                'user_id' => $userId,
                'name' => 'محفظة دفترية بلا صف E-Money',
                'phone' => '—',
                'wallet_balance' => '0',
                'ledger_balance' => $book,
                'gap' => $gap,
                'diverged' => true,
                'missing_wallet' => true,
            ];
        }

        // المنحرفة أوّلاً — هي ما يُنظر فيه، والباقي حشو.
        usort($rows, fn ($a, $b) => ($b['diverged'] <=> $a['diverged']));

        return [
            'rows' => $rows,
            'checked' => count($rows),
            'divergent' => $divergent,
            'total_gap' => $totalGap,
        ];
    }

    /** بحث القيود. */
    public function searchEntries(array $filters, int $limit = 100): array
    {
        $q = LedgerJournalEntry::with('lines.account');

        if (!empty($filters['ulid'])) {
            $q->where('entry_ulid', 'like', '%' . $filters['ulid'] . '%');
        }
        if (!empty($filters['source_type'])) {
            $q->where('source_type', $filters['source_type']);
        }
        if (!empty($filters['from'])) {
            $q->where('posted_at', '>=', $filters['from']);
        }
        if (!empty($filters['to'])) {
            $q->where('posted_at', '<=', $filters['to'] . ' 23:59:59');
        }
        if (!empty($filters['min_amount'])) {
            $q->where('total_amount', '>=', $filters['min_amount']);
        }

        // ══════════════════════════════════════════════════════════════
        // AMIAL-FEE-TRUTH-019 — **الجسرُ من الحركة إلى قيدها.**
        //
        // كان البحثُ بـ`entry_ulid` وحدَه — وهو رقمُ القيد، **ولا يعرفه من
        // يقف أمام حركةٍ ماليّة**. فمن أراد أن يتتبّع رسماً من تقرير
        // الأرباح إلى قيده لم يجد سبيلاً إلّا المطابقةَ بالعين.
        //
        // و`idempotency_key` هو المفتاحُ المشترك: تكتبه `recordTransaction`
        // على الحركة، ويكتبه محرّكُ الترحيل على القيد. **فهو الوصلةُ
        // الوحيدةُ القائمةُ فعلاً** — لا تُخترع وصلةٌ ثانية.
        if (!empty($filters['idempotency_key'])) {
            $q->where('idempotency_key', $filters['idempotency_key']);
        }

        return $q->orderByDesc('id')->limit($limit)->get()
            ->map(fn (LedgerJournalEntry $e) => [
                'id' => (int) $e->id,
                'ulid' => $e->entry_ulid,
                'source_type' => $e->source_type,
                'description' => $e->description_ar,
                'amount' => (string) $e->total_amount,
                'status' => $e->status,
                'posted_at' => $e->posted_at?->format('Y-m-d H:i:s'),
                'debits' => $e->lines->where('direction', 'debit')->map(fn ($l) => [
                    'account' => $l->account->name_ar ?? $l->account->account_code ?? '—',
                    'amount' => (string) $l->amount,
                ])->values()->all(),
                'credits' => $e->lines->where('direction', 'credit')->map(fn ($l) => [
                    'account' => $l->account->name_ar ?? $l->account->account_code ?? '—',
                    'amount' => (string) $l->amount,
                ])->values()->all(),
            ])->all();
    }

    /** أنواع المصادر الموجودة فعلاً — للفلتر. */
    public function sourceTypes(): array
    {
        return LedgerJournalEntry::select('source_type')
            ->distinct()->orderBy('source_type')->pluck('source_type')->all();
    }
}
