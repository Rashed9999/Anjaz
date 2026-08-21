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
        // AMIAL-MONEY-SHAPE-001 — الأرقامُ الثلاثةُ في بطاقةٍ واحدةٍ تُقرأ
        // بصيغةٍ واحدة. `ledger_balance` و`gap` يخرجان من `bcsub(...,4)`،
        // فتركُ التشغيليّ خاماً يُخرج «١٤٠٠٠٠» بجوار «١٥٠٠٠٠٫٠٠٠٠» —
        // ومن يقارن رقمين مختلفَي الشكل يشكّ في الحساب لا في الفرق.
        $operational = $wallet
            ? \App\Services\MoneyService::normalize((string) ($wallet->current_balance ?? '0'))
            : null;

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
    /**
     * AMIAL-CHART-IDENTITY-001 — **`USER_WALLET_417` ليس هويّةً.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **ما تطلبه الوثيقةُ في المحورين ٢٣ و٢٤:**
     *
     *     ٢٣) لا تجعل Chart of Accounts قائمةً مسطّحةً فقط — اعرض Hierarchy.
     *     ٢٤) لا تعرض `USER_WALLET_1` وحدَه: أظهر الاسمَ والمالكَ والنوعَ
     *         والاتّجاهَ الطبيعيَّ والعملةَ والحالة. **والرمزُ التقنيُّ ثانويّ.**
     *
     * وثلاثةُ أعطالٍ قِيست في الشكل القديم:
     *
     * | ما كان | الأثر |
     * |---|---|
     * | `owner_user_id: 417` رقماً | مراجعٌ يرى الرقمَ ولا يعرف صاحبَه، فيفتح ملفَّ كلّ رقمٍ ليعرف — **ويُسجّل اطّلاعاً على بياناتٍ شخصيّةٍ في كلّ مرّة** |
     * | قائمةٌ مسطّحةٌ بالرمز | أصولٌ وخصومٌ وإيراداتٌ متجاورةٌ بلا مجموعٍ لكلّ فئة — والميزانيّةُ لا تُقرأ منها |
     * | `limit(500)` **صامتاً** | إنتاجٌ فيه آلافُ المحافظ يُخرج ٥٠٠ **ولا يقول إنّه قطع**. ومن قرأ «هذه حساباتُنا» قرأ كذبةً. (القاعدة السابعة.) |
     *
     * **والاسمُ أقلُّ كشفاً من فتح الملفّ وأكثرُ إفادة** — وهو المنطقُ
     * نفسُه في طابور مستندات الهويّة.
     *
     * @return array{
     *   groups:array<int,array{type:string,label:string,total:string,count:int,
     *     subgroups:array<int,array{key:string,label:string,total:string,accounts:array<int,array<string,mixed>>}>}>,
     *   shown:int, total:int, truncated:bool, note:?string
     * }
     */
    public function chartOfAccounts(?string $type = null, int $limit = 500): array
    {
        $q = LedgerAccount::query();

        if ($type) {
            $q->where('account_type', $type);
        }

        $total = (clone $q)->count();
        $rows = $q->orderBy('account_type')->orderBy('account_code')->limit($limit)->get();

        // **أسماءُ المالكين دفعةً واحدة** — لا استعلامٌ لكلّ صفّ. وألفُ
        // حسابٍ بألف استعلامٍ يجعل الصفحةَ تنتظر دقيقةً فتُهجَر.
        $ownerIds = $rows->pluck('owner_user_id')->filter()->unique()->all();
        $owners = $ownerIds === [] ? [] : \App\Models\User::whereIn('id', $ownerIds)
            ->get(['id', 'f_name', 'l_name', 'type'])
            ->mapWithKeys(fn ($u) => [(int) $u->id => [
                'name' => trim(($u->f_name ?? '').' '.($u->l_name ?? '')) ?: ('حساب #'.$u->id),
                'type' => (int) $u->type,
            ]])->all();

        $groups = [];

        foreach ($rows as $a) {
            $ownerId = $a->owner_user_id ? (int) $a->owner_user_id : null;
            $owner = $ownerId !== null ? ($owners[$ownerId] ?? null) : null;
            $sub = $this->accountSubgroup($a);

            $groups[$a->account_type]['type'] = $a->account_type;
            $groups[$a->account_type]['label'] = self::TYPE_LABELS[$a->account_type] ?? $a->account_type;
            $groups[$a->account_type]['total'] = bcadd(
                $groups[$a->account_type]['total'] ?? '0', (string) $a->current_balance, 4);
            $groups[$a->account_type]['count'] = ($groups[$a->account_type]['count'] ?? 0) + 1;

            $groups[$a->account_type]['subgroups'][$sub['key']]['key'] = $sub['key'];
            $groups[$a->account_type]['subgroups'][$sub['key']]['label'] = $sub['label'];
            $groups[$a->account_type]['subgroups'][$sub['key']]['total'] = bcadd(
                $groups[$a->account_type]['subgroups'][$sub['key']]['total'] ?? '0',
                (string) $a->current_balance, 4);

            $groups[$a->account_type]['subgroups'][$sub['key']]['accounts'][] = [
                'id' => (int) $a->id,
                // **الاسمُ أوّلاً والرمزُ ثانياً** — كما تطلب الوثيقة.
                'label' => $owner !== null
                    ? $owner['name'].' — '.$this->ownerRole($owner['type'])
                    : ($a->name_ar ?: $a->account_code),
                'name' => $a->name_ar,
                'owner_name' => $owner['name'] ?? null,
                'owner_role' => $owner !== null ? $this->ownerRole($owner['type']) : null,
                'owner_user_id' => $ownerId,
                'owner_type' => $a->owner_type,
                'account_code' => $a->account_code,
                'account_type' => $a->account_type,
                'normal_balance' => $a->normal_balance,
                'current_balance' => (string) $a->current_balance,
                'currency' => $a->currency,
                'is_active' => (bool) $a->is_active,
            ];
        }

        // إعادةُ الفهرسة إلى قوائمَ مرتّبة — والمفاتيحُ النصّيّةُ تُخرج
        // كائناً في JSON فتنكسر الحلقةُ في الشاشة.
        $out = [];

        foreach ($groups as $g) {
            $g['subgroups'] = array_values($g['subgroups']);
            $out[] = $g;
        }

        $truncated = $total > count($rows);

        return [
            'groups' => $out,
            'shown' => count($rows),
            'total' => $total,
            'truncated' => $truncated,
            // **القطعُ يُقال ولا يُسكت عنه** — ومن قرأ «هذه حساباتُنا» على
            // قائمةٍ مقطوعةٍ قرأ كذبة.
            'note' => $truncated
                ? sprintf('عُرض %d من %d حساباً — القائمةُ مقطوعة. صفِّ بالنوع لترى الباقي.',
                    count($rows), $total)
                : null,
        ];
    }

    /** الفئاتُ الخمسُ كما في وثيقة مركز الدفتر. */
    private const TYPE_LABELS = [
        'asset' => 'الأصول',
        'liability' => 'الالتزامات',
        'equity' => 'حقوق الملكيّة',
        'revenue' => 'الإيرادات',
        'expense' => 'المصروفات',
    ];

    /**
     * الفئةُ الفرعيّةُ **تُستنتَج من رمز الحساب لا تُخترَع**.
     *
     * والوثيقةُ تطلب تحت الأصول: نقداً وبنكاً واحتياطيّاً وذمماً؛ وتحت
     * الالتزامات: محافظَ العملاء والتجّار والوكلاء والضمان. وهذه أقربُ
     * ما يُقاس من الرموز القائمة — **ومن أضاف رمزاً جديداً بلا قاعدةٍ
     * هنا يقع في «أخرى» ظاهراً، لا يختفي**.
     *
     * @return array{key:string,label:string}
     */
    private function accountSubgroup(LedgerAccount $a): array
    {
        $code = (string) $a->account_code;

        return match (true) {
            str_starts_with($code, 'USER_WALLET_') => match ((int) ($a->owner_type === 'user' ? 1 : 0)) {
                default => ['key' => 'wallets', 'label' => 'محافظ المستعملين'],
            },
            str_starts_with($code, 'FAMILY_FUND_') => ['key' => 'pooled', 'label' => 'أحواضٌ مشتركة'],
            str_contains($code, 'HOLD') || str_contains($code, 'PENDING') || str_contains($code, 'ESCROW')
                => ['key' => 'holds', 'label' => 'أموالٌ محجوزة'],
            str_contains($code, 'SUSPENSE') => ['key' => 'suspense', 'label' => 'حساباتٌ معلَّقةٌ قيد التحقيق'],
            str_contains($code, 'TREASURY') || str_contains($code, 'RESERVE')
                => ['key' => 'treasury', 'label' => 'الخزينة والاحتياطيّ'],
            str_contains($code, 'FEE') || str_contains($code, 'COMMISSION')
                => ['key' => 'fees', 'label' => 'رسومٌ وعمولات'],
            default => ['key' => 'other', 'label' => 'أخرى'],
        };
    }

    private function ownerRole(int $type): string
    {
        return match ($type) {
            CUSTOMER_TYPE => 'عميل',
            AGENT_TYPE => 'وكيل',
            MERCHANT_TYPE => 'تاجر',
            ADMIN_TYPE => 'إدارة',
            default => 'حساب',
        };
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

        // ══════════════════════════════════════════════════════════════
        // AMIAL-LEDGER-SEARCH-001 — **المحقّقُ لا يبدأ من رقم القيد.**
        //
        // يبدأ ممّا في يده: **رقمِ معاملةٍ** يشتكي منها عميل، أو **هاتفٍ**
        // على شاشة الدعم، أو **معرّفِ مستخدم**. وبحثٌ لا يقبل إلّا
        // `entry_ulid` يفترض أنّ السائلَ يعرف الجوابَ سلفاً.
        //
        // فتُفتَح ثلاثةُ مداخلَ لا واحد.

        // ① مرجعُ المصدر — رقمُ المعاملة أو رقمُ الطلب أو `tx_ulid`.
        if (!empty($filters['source_id'])) {
            $q->where('source_id', (string) $filters['source_id']);
        }

        // ② **الهاتفُ يصير معرّفَ مستخدمٍ ثمّ حسابَ محفظة.** ولا يُبحث
        //   بالهاتف في القيود مباشرةً: القيدُ لا يحمل هاتفاً، وبحثٌ نصّيٌّ
        //   في وصفه يُطابق أرقاماً في جملٍ أخرى فيُخرج نتائجَ كاذبة.
        $userId = null;

        if (!empty($filters['phone'])) {
            $userId = \App\Models\User::whereIn('phone',
                \App\Support\Phone::variants((string) $filters['phone']))->value('id');

            // **هاتفٌ لا حسابَ له يُخرج «لا نتائج» لا «كلَّ القيود».**
            // ومرشِّحٌ يُسقَط بصمتٍ عند غياب قيمته يعرض الدفترَ كلَّه
            // لمن سأل عن شخصٍ واحد — وهو تسريبٌ لا نقصُ دقّة.
            if (! $userId) {
                return [];
            }
        }

        if (!empty($filters['user_id'])) {
            $userId = (int) $filters['user_id'];
        }

        // ③ قيودُ حسابِ مستخدمٍ بعينه — من أيّ طرفٍ كان، مديناً أو دائناً.
        if ($userId) {
            $accountId = LedgerAccount::where('account_code', 'USER_WALLET_' . $userId)->value('id');

            if (! $accountId) {
                return [];
            }

            $q->whereExists(fn ($sub) => $sub->select(DB::raw(1))
                ->from('ledger_entry_lines as sl')
                ->whereColumn('sl.journal_entry_id', 'ledger_journal_entries.id')
                ->where('sl.account_id', $accountId));
        }

        if (!empty($filters['max_amount'])) {
            $q->where('total_amount', '<=', $filters['max_amount']);
        }

        if (!empty($filters['status'])) {
            $q->where('status', (string) $filters['status']);
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
                'idempotency_key' => $e->idempotency_key,
                'source_id' => $e->source_id,
                // AMIAL-LEDGER-SEARCH-002 — **مدينٌ ودائنٌ ليسا «من» و«إلى».**
                'economic_effect' => $this->economicEffect($e),
            ])->all();
    }

    /**
     * AMIAL-LEDGER-SEARCH-002 — **«مدين» و«دائن» ليسا «من» و«إلى».**
     *
     * ══════════════════════════════════════════════════════════════════
     * **ما تطلبه الوثيقةُ في المحور ٢٦:**
     *
     *     لا تسمِّ `Debit = من` و`Credit = إلى`. استخدم «مدين» و«دائن»،
     *     **ثمّ أضف تفسيراً اقتصاديّاً منفصلاً**:
     *     «انخفض رصيدُ الوكيل ٥٠٬٠٠٠» · «زادت أصولُ النقد ١٠٠٬٠٠٠».
     *
     * **ولماذا هذا ليس تجميلَ لغة:** «من/إلى» صحيحةٌ في التحويل وحدَه،
     * **وتنقلب في نصف القيود**. حسابُ العميل **التزامٌ** على المنصّة:
     * فإيداعُه يُقيَّد **دائناً** — أي «إلى» بمنطق التحويل — وهو في
     * الحقيقة **زيادةُ ما ندين به له**. ومن قرأ «إلى العميل» على قيد
     * إيداعٍ ظنّ أنّ مالاً خرج من المنصّة إليه، **والعكسُ هو الواقع**.
     *
     * فيُترجَم الاتّجاهُ إلى أثرٍ اقتصاديٍّ بحسب **صنف الحساب** لا بحسب
     * موضعه في القيد.
     *
     * @return array<int,string>
     */
    private function economicEffect(LedgerJournalEntry $entry): array
    {
        $out = [];

        foreach ($entry->lines as $line) {
            $account = $line->account;

            if (! $account) {
                continue;
            }

            $name = $account->name_ar ?: $account->account_code;
            $isDebit = $line->direction === 'debit';

            // المدينُ يزيد الأصولَ والمصروفات، ويُنقص الالتزاماتِ وحقوقَ
            // الملكيّة والإيرادات. والدائنُ عكسُه. **وهذه هي القاعدةُ
            // المحاسبيّةُ نفسُها، لا اصطلاحٌ للعرض.**
            $increases = match ($account->account_type) {
                'asset', 'expense' => $isDebit,
                default => ! $isDebit,
            };

            $out[] = sprintf('%s %s بمقدار %s',
                $increases ? 'زاد' : 'انخفض', $name, (string) $line->amount);
        }

        return $out;
    }

    /** أنواع المصادر الموجودة فعلاً — للفلتر. */
    public function sourceTypes(): array
    {
        return LedgerJournalEntry::select('source_type')
            ->distinct()->orderBy('source_type')->pluck('source_type')->all();
    }
}
