<?php

namespace App\Services;

use App\Models\Agent\AgentBranch;
use App\Models\EMoney;
use App\Models\User;
use App\Services\LedgerService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * AMIAL-AGENT-PORTAL-001 — فروع الوكيل.
 *
 * **الفرع حسابُ وكيلٍ ابن.** يفعل ما يفعله الوكيل — يودع ويسحب — ويحتاج ما
 * يحتاجه: محفظةً وحدوداً وتسويات وعمولة. فجدولُ فروعٍ مستقلّ يعني تكرار ذلك
 * كلّه ثمّ انحرافه.
 */
class AgentBranchService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly AgentTillService $till,
    ) {
    }

    /**
     * إنشاء فرع: حسابٌ فرعيّ ومحفظةٌ وخزنة، في معاملةٍ واحدة.
     *
     * ولو أُنشئ الحساب ثمّ فشلت المحفظة لبقي فرعٌ بلا محفظة يُقبل في الشاشة
     * ويسقط عند أوّل عملية.
     */
    public function create(User $agent, array $data): AgentBranch
    {
        if ((int) $agent->type !== AGENT_TYPE) {
            throw new DomainException('صاحب الحساب ليس وكيلاً');
        }

        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        if ($code === '') {
            throw new DomainException('رمز الفرع إلزاميّ');
        }

        if (AgentBranch::where('agent_user_id', $agent->id)->where('code', $code)->exists()) {
            throw new DomainException("الرمز «{$code}» مستعمل في فرعٍ آخر");
        }

        $phone = preg_replace('/\D+/', '', (string) ($data['phone'] ?? ''));
        if (mb_strlen($phone) < 9) {
            // هاتف الفرع هو اسم دخوله — فلا يكون ناقصاً.
            throw new DomainException('هاتف الفرع إلزاميّ (٩ أرقام على الأقل)');
        }

        if (User::where('phone', $phone)->exists()) {
            throw new DomainException('الهاتف مستعمل في حسابٍ آخر');
        }

        return DB::transaction(function () use ($agent, $data, $code, $phone) {
            // `forceFill` لا `create`: نموذج المستخدم يحصر `$fillable` في حقولٍ
            // قليلة، و`create` **تُسقط بصمتٍ** ما ليس فيها — فيُنشأ الحساب بلا
            // نوعٍ ولا كلمة مرور، ويُقبل في الشاشة ويسقط عند أوّل دخول.
            //
            // (وهذا الإسقاط الصامت هو ما جعل أوّل تشغيلٍ لهذه الخدمة يفشل
            // على عمود `type` وحده، والباقي كان سيمرّ بلا إشعار.)
            $branchUser = new User();
            $branchUser->forceFill([
                'f_name' => (string) $data['name'],
                'l_name' => 'فرع ' . ($agent->f_name ?? ''),
                'phone' => $phone,
                'type' => AGENT_TYPE,
                'password' => Hash::make($data['password'] ?? Str::random(12)),
                'zone_code' => $agent->zone_code,
                // الفرع يرث توثيق الوكيل الأمّ: الشركة موثَّقة والفرع امتدادها،
                // ومطالبتُه بهويّةٍ مستقلّة تعطّله بلا فائدة رقابية.
                'is_kyc_verified' => (int) $agent->is_kyc_verified === 1 ? 1 : 0,
            ])->save();

            EMoney::create([
                'user_id' => $branchUser->id,
                'current_balance' => '0',
                'zone_code' => $agent->zone_code,
            ]);

            // الأبوّة تُسجَّل في ملفّ الوكيل: هي ما يجعل التسويات والحدود
            // تعرف إلى من ينتمي الفرع.
            if (Schema::hasTable('agent_profiles')) {
                DB::table('agent_profiles')->insert([
                    'user_id' => $branchUser->id,
                    'parent_agent_id' => $agent->id,
                    'agent_level' => 2,
                    'business_name' => (string) $data['name'],
                    'location_city' => $data['city'] ?? null,
                    'location_address' => $data['address'] ?? null,
                    'status' => 'active',
                    'zone_code' => $agent->zone_code,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            $branch = AgentBranch::create([
                'agent_user_id' => $agent->id,
                'branch_user_id' => $branchUser->id,
                'name' => (string) $data['name'],
                'code' => $code,
                'city' => $data['city'] ?? null,
                'address' => $data['address'] ?? null,
                'phone' => $phone,
                'lat' => $data['lat'] ?? null,
                'lng' => $data['lng'] ?? null,
                'is_active' => true,
            ]);

            $this->till->tillFor($branch);

            $this->audit->record([
                'actor_type' => 'agent',
                'actor_user_id' => $agent->id,
                'subject_type' => 'agent_branch',
                'subject_id' => (string) $branch->id,
                'action' => 'AGENT_BRANCH_CREATED',
                'decision_code' => 'BRANCH_CREATE',
                'severity' => 'warning',
                'context' => ['code' => $code, 'branch_user_id' => $branchUser->id],
            ]);

            return $branch->fresh();
        });
    }

    /** فروع الوكيل مع أرصدتها — النقديّ والإلكترونيّ معاً. */
    public function listFor(User $agent): array
    {
        return AgentBranch::with(['account:id,phone', 'till'])
            ->where('agent_user_id', $agent->id)
            ->orderBy('name')->get()
            ->map(function (AgentBranch $b) {
                $emoney = (string) (EMoney::where('user_id', $b->branch_user_id)
                    ->value('current_balance') ?? '0');
                $cash = (string) ($b->till->cash_on_hand ?? '0');

                return [
                    'id' => (int) $b->id,
                    'name' => $b->name,
                    'code' => $b->code,
                    'city' => $b->city,
                    'phone' => $b->phone,
                    'is_active' => (bool) $b->is_active,
                    // الرصيدان جنباً إلى جنب دائماً: أحدهما يحدّ الإيداع
                    // والآخر يحدّ السحب، وعرضُ واحدٍ منهما نصفُ الحقيقة.
                    'emoney_balance' => $emoney,
                    'cash_on_hand' => $cash,
                    'cash_is_low' => (bool) ($b->till?->isLow() ?? false),
                    'cash_is_overloaded' => (bool) ($b->till?->isOverloaded() ?? false),
                ];
            })->all();
    }

    /**
     * شحن رصيد الفرع الإلكترونيّ من الوكيل الأمّ.
     *
     * **عمليةٌ كانت ناقصة، وكشفها اختبارٌ سقط:** رسالةُ رفض الإيداع تقول
     * «اطلب شحن رصيد من الوكيل الأمّ» — ولم يكن في النظام مسارٌ يفعل ذلك.
     * فالفرع يُنشأ برصيدٍ صفر ولا سبيل إلى تمويله إلّا من قاعدة البيانات.
     *
     * وهي **غير** توريد النقد: هذه تنقل رصيداً إلكترونياً يحدّ الإيداع، وتلك
     * تنقل أوراقاً تحدّ السحب. وخلطُهما يجعل مديراً يورّد نقداً ويظنّ أنّه
     * مكّن فرعه من الإيداع.
     */
    public function fundBranch(AgentBranch $branch, User $actor, string $amount, string $note): array
    {
        if (bccomp($amount, '0', 4) <= 0) {
            throw new DomainException('المبلغ يجب أن يكون موجباً');
        }

        if ((int) $actor->id !== (int) $branch->agent_user_id) {
            throw new DomainException('الشحن من حساب الشركة الأمّ وحده');
        }

        $parentBalance = (string) (EMoney::where('user_id', $actor->id)
            ->value('current_balance') ?? '0');

        if (bccomp($parentBalance, $amount, 4) < 0) {
            throw new DomainException(
                "رصيد الشركة {$parentBalance} ولا يكفي لشحن {$amount}",
            );
        }

        $reference = 'FND-' . strtoupper(Str::random(10));

        return DB::transaction(function () use ($branch, $actor, $amount, $note, $reference) {
            $ids = [$actor->id, $branch->branch_user_id];
            sort($ids);
            EMoney::whereIn('user_id', $ids)->lockForUpdate()->get();

            EMoney::where('user_id', $actor->id)->decrement('current_balance', $amount);
            EMoney::where('user_id', $branch->branch_user_id)->increment('current_balance', $amount);

            app(LedgerService::class)->post(
                sourceType: 'agent_branch_funding',
                sourceId: $reference,
                description: "شحن رصيد فرع {$branch->name}",
                lines: [
                    ['account' => app(LedgerService::class)->getOrCreateUserWallet($actor->id)->account_code,
                        'direction' => 'debit', 'amount' => $amount],
                    ['account' => app(LedgerService::class)->getOrCreateUserWallet((int) $branch->branch_user_id)->account_code,
                        'direction' => 'credit', 'amount' => $amount],
                ],
                idempotencyKey: 'branch_funding_' . $reference,
            );

            $this->audit->record([
                'actor_type' => 'agent',
                'actor_user_id' => $actor->id,
                'subject_type' => 'agent_branch',
                'subject_id' => (string) $branch->id,
                'action' => 'AGENT_BRANCH_FUNDED',
                'decision_code' => 'BRANCH_FUND',
                'reason' => mb_substr($note, 0, 500),
                'severity' => 'critical',
                'context' => ['amount' => $amount, 'reference' => $reference],
            ]);

            return [
                'reference' => $reference,
                'amount' => $amount,
                'branch_balance' => (string) EMoney::where('user_id', $branch->branch_user_id)
                    ->value('current_balance'),
            ];
        });
    }

    /**
     * سحبُ رصيدٍ إلكترونيٍّ من الفرع إلى الشركة الأمّ — عكسُ `fundBranch`.
     *
     * **الاتّجاه المعاكس، وكان غائباً — وهو نفسُ النقص الذي وقع في الطبقة
     * الأولى حين كان `payout` غائباً عن تسويات المنصّة.**
     *
     * فالفرع الذي يخدم سحوبات العملاء طول اليوم يستقبل رصيداً إلكترونيّاً
     * ويدفع نقداً ورقيّاً. فيمتلئ رصيدُه ويفرغ درجُه. وبلا هذا المسار يبقى
     * الرصيد حبيساً في محفظة الفرع: الشركة لا تستطيع سحبه لتوزّعه على فرعٍ
     * آخر يحتاجه، ولا أن تعيده إلى المنصّة في تسويةِ صرف — لأنّ تسوية
     * الصرف تُطلب من محفظة الأمّ وحدها.
     *
     * وتسويةُ طبقةٍ باتّجاهٍ واحد ليست تسوية: هي صرفٌ بلا استرداد.
     */
    public function collectFromBranch(AgentBranch $branch, User $actor, string $amount, string $note): array
    {
        if (bccomp($amount, '0', 4) <= 0) {
            throw new DomainException('المبلغ يجب أن يكون موجباً');
        }

        if ((int) $actor->id !== (int) $branch->agent_user_id) {
            throw new DomainException('السحب إلى حساب الشركة الأمّ وحده');
        }

        $branchBalance = (string) (EMoney::where('user_id', $branch->branch_user_id)
            ->value('current_balance') ?? '0');

        if (bccomp($branchBalance, $amount, 4) < 0) {
            throw new DomainException(
                "رصيد الفرع {$branchBalance} ولا يكفي لسحب {$amount}",
            );
        }

        $reference = 'CLT-' . strtoupper(Str::random(10));

        return DB::transaction(function () use ($branch, $actor, $amount, $note, $reference) {
            $ids = [$actor->id, $branch->branch_user_id];
            sort($ids);
            EMoney::whereIn('user_id', $ids)->lockForUpdate()->get();

            // يُعاد الفحص **داخل القفل**: الرصيد الذي قُرئ قبله كان لقطةً،
            // وفرعٌ نشِطٌ يودع بين القراءة والقفل فيصير الرصيد سالباً.
            $locked = (string) (EMoney::where('user_id', $branch->branch_user_id)
                ->value('current_balance') ?? '0');
            if (bccomp($locked, $amount, 4) < 0) {
                throw new DomainException("رصيد الفرع {$locked} ولا يكفي لسحب {$amount}");
            }

            EMoney::where('user_id', $branch->branch_user_id)->decrement('current_balance', $amount);
            EMoney::where('user_id', $actor->id)->increment('current_balance', $amount);

            app(LedgerService::class)->post(
                sourceType: 'agent_branch_sweep',
                sourceId: $reference,
                description: "سحب رصيد من فرع {$branch->name}",
                lines: [
                    ['account' => app(LedgerService::class)->getOrCreateUserWallet((int) $branch->branch_user_id)->account_code,
                        'direction' => 'debit', 'amount' => $amount],
                    ['account' => app(LedgerService::class)->getOrCreateUserWallet($actor->id)->account_code,
                        'direction' => 'credit', 'amount' => $amount],
                ],
                idempotencyKey: 'branch_sweep_' . $reference,
            );

            $this->audit->record([
                'actor_type' => 'agent',
                'actor_user_id' => $actor->id,
                'subject_type' => 'agent_branch',
                'subject_id' => (string) $branch->id,
                'action' => 'AGENT_BRANCH_SWEPT',
                'decision_code' => 'BRANCH_SWEEP',
                'reason' => mb_substr($note, 0, 500),
                'severity' => 'critical',
                'context' => ['amount' => $amount, 'reference' => $reference],
            ]);

            return [
                'reference' => $reference,
                'amount' => $amount,
                'branch_balance' => (string) EMoney::where('user_id', $branch->branch_user_id)
                    ->value('current_balance'),
                'company_balance' => (string) EMoney::where('user_id', $actor->id)
                    ->value('current_balance'),
            ];
        });
    }

    /**
     * كشفُ الطبقة الثانية — ما بين الوكيل وكلّ فرعٍ من فروعه.
     *
     * الطبقة الأولى (المنصّة ← الوكيل) لها مركزُ تسويات. وهذه الطبقة كانت
     * بلا كشف: المدير يشحن فرعاً ثمّ لا يعرف بعد أسبوعٍ كم أعطاه ولا كم
     * استردّ. والأرقام تُقرأ من **سطور الدفتر** لا من عمودٍ مخزَّن — فالرصيد
     * الحاليّ لا يقول شيئاً عن الحركة التي أوصلته إليه.
     *
     * @return array<int, array>
     */
    public function settlementWithBranches(User $agent): array
    {
        $branches = AgentBranch::with('till')->where('agent_user_id', $agent->id)
            ->orderBy('name')->get();

        if ($branches->isEmpty()) {
            return [];
        }

        $walletIds = $branches->pluck('branch_user_id')->map(fn ($v) => (int) $v)->all();

        $accounts = DB::table('ledger_accounts')
            ->whereIn('owner_user_id', $walletIds)
            ->pluck('owner_user_id', 'id');

        $flow = DB::table('ledger_entry_lines as l')
            ->join('ledger_journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->whereIn('l.account_id', $accounts->keys())
            ->whereIn('e.source_type', ['agent_branch_funding', 'agent_branch_sweep'])
            ->selectRaw('l.account_id, e.source_type, sum(l.amount) as total')
            ->groupBy('l.account_id', 'e.source_type')
            ->get();

        $given = [];
        $returned = [];
        foreach ($flow as $r) {
            $uid = (int) ($accounts[$r->account_id] ?? 0);
            $bucket = $r->source_type === 'agent_branch_funding' ? 'given' : 'returned';
            ${$bucket}[$uid] = bcadd(${$bucket}[$uid] ?? '0', (string) $r->total, 4);
        }

        return $branches->map(function (AgentBranch $b) use ($given, $returned) {
            $uid = (int) $b->branch_user_id;
            $g = $given[$uid] ?? '0.0000';
            $r = $returned[$uid] ?? '0.0000';

            return [
                'id' => (int) $b->id,
                'name' => $b->name,
                'code' => $b->code,
                'is_active' => (bool) $b->is_active,
                // ما أعطته الشركة، وما استردّته، والصافي المستحقّ عليها.
                'float_given' => $g,
                'float_returned' => $r,
                'float_net' => bcsub($g, $r, 4),
                // والرصيدان الحاليّان: الإلكترونيّ يحدّ الإيداع، والنقد يحدّ السحب.
                'emoney_balance' => bcadd((string) (EMoney::where('user_id', $uid)
                    ->value('current_balance') ?? '0'), '0', 4),
                'cash_on_hand' => bcadd((string) ($b->till->cash_on_hand ?? '0'), '0', 4),
            ];
        })->values()->all();
    }

    /**
     * توريد نقدٍ بين الوكيل الأمّ والفرع.
     *
     * حركةٌ نقديّة محضة لا تمسّ المحافظ الإلكترونية: نقلُ أوراقٍ من خزنة
     * الشركة إلى درج الفرع أو العكس. ولو خُلطت بالرصيد الإلكترونيّ لبدا
     * توريدُ النقد كأنّه شحن رصيد — وهما شيئان مختلفان تماماً.
     */
    public function moveTreasuryCash(
        AgentBranch $branch, User $actor, string $direction, string $amount, string $note
    ): array {
        if (!in_array($direction, ['in', 'out'], true)) {
            throw new DomainException('اتّجاه غير معروف');
        }

        if (mb_strlen(trim($note)) < 5) {
            throw new DomainException('سبب التوريد إلزاميّ');
        }

        $movement = $this->till->record(
            $branch, $direction,
            $direction === 'in' ? 'treasury_in' : 'treasury_out',
            $amount, $actor, note: trim($note),
        );

        return [
            'amount' => $amount,
            'balance_after' => (string) $movement->balance_after,
        ];
    }

    public function setTillLimits(AgentBranch $branch, string $max, string $min): void
    {
        if (bccomp($max, '0', 4) > 0 && bccomp($min, $max, 4) > 0) {
            throw new DomainException('حدّ التنبيه الأدنى أكبر من الحدّ الأعلى — راجع القيم');
        }

        $till = $this->till->tillFor($branch);
        $till->max_cash_on_hand = $max;
        $till->min_cash_alert = $min;
        $till->save();
    }
}
