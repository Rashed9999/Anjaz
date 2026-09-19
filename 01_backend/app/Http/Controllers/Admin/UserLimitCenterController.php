<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AgentProfile;
use App\Models\EMoney;
use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\AgentNetworkService;
use App\Services\AuditService;
use App\Services\CustomerActionService;
use App\Services\KycTierService;
use App\Services\Kyc\KycAccountStatusService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/** مركز حدود المستخدمين: قراءة من المصدر التشغيلي وتعديل موثّق للحدود فقط. */
class UserLimitCenterController extends Controller
{
    public function __construct(
        private readonly KycTierService $kyc,
        private readonly KycAccountStatusService $kycStatus,
        private readonly CustomerActionService $customers,
        private readonly AgentNetworkService $agents,
        private readonly AuditService $audit,
    ) {}

    public function index()
    {
        return view('admin-views.amial.hub.user-limits');
    }

    public function overview(Request $request): JsonResponse
    {
        $policies = [];
        foreach ([1, 2, 3] as $tier) {
            $policies[] = [
                'tier' => $tier,
                'limits' => $this->kyc->getLimits($tier),
                'ceiling' => $this->kyc->getPolicyCeiling($tier),
            ];
        }

        return response()->json([
            'customers' => [
                'total' => User::where('type', 2)->count(),
                'overrides' => User::where('type', 2)->whereNotNull('limit_override')->count(),
            ],
            'merchants' => [
                'total' => User::where('type', 3)->count(),
                'verified' => MerchantProfile::where('verification_status', 'verified')->count(),
            ],
            'agents' => [
                'total' => User::where('type', 1)->count(),
                'active' => AgentProfile::where('status', 'active')->count(),
            ],
            'settlement' => [
                'max_topup_per_request' => (string) config('amial.agent.max_topup', '100000000'),
            ],
            'tier_policies' => $policies,
            'can_manage_tier_policy' => (bool) $request->user()?->hasPlatformPermission('platform.settings.update'),
        ]);
    }

    public function users(Request $request): JsonResponse
    {
        $kind = (string) $request->query('kind', 'customer');
        $type = match ($kind) { 'customer' => 2, 'merchant' => 3, 'agent' => 1, default => null };
        if ($type === null) return response()->json(['message' => 'فئة غير صالحة'], 422);

        $search = trim((string) $request->query('search', ''));
        $rows = User::where('type', $type)
            ->when($search !== '', fn ($q) => $q->where(fn ($x) => $x->where('phone', 'like', "%{$search}%")
                ->orWhere('f_name', 'like', "%{$search}%")->orWhere('l_name', 'like', "%{$search}%")))
            ->orderByDesc('id')->limit(100)->get()
            ->map(function (User $user) use ($kind) {
                $base = [
                    'id' => $user->id,
                    'name' => trim($user->f_name . ' ' . $user->l_name),
                    'phone' => $user->phone,
                    'balance' => (string) (EMoney::where('user_id', $user->id)->value('current_balance') ?? '0'),
                ];

                if ($kind === 'customer') return $base + [
                    'kyc' => $this->kyc->getUserTierInfo($user),
                    'kyc_status' => $this->kycStatus->for($user),
                    'override' => $user->limit_override,
                ];

                if ($kind === 'merchant') {
                    $p = MerchantProfile::where('user_id', $user->id)->first();
                    return $base + [
                        'profile' => $p
                            ? $p->only(['tier', 'verification_status', 'single_receive_limit', 'daily_receive_limit', 'monthly_receive_limit'])
                            : null,
                    ];
                }

                $p = AgentProfile::where('user_id', $user->id)->first();
                return $base + [
                    'profile' => $p
                        ? $p->only(['status', 'single_transaction_limit', 'daily_cash_in_limit', 'daily_cash_out_limit'])
                        : null,
                ];
            });

        return response()->json(['users' => $rows]);
    }

    public function update(Request $request, int $userId): JsonResponse
    {
        $user = User::find($userId);
        if (!$user) return response()->json(['message' => 'الحساب غير موجود'], 404);

        $reason = trim((string) $request->input('reason'));
        if (mb_strlen($reason) < 10) {
            return response()->json(['message' => 'السبب إلزاميّ (10 أحرف على الأقل)'], 422);
        }

        try {
            return match ((int) $user->type) {
                2 => $this->updateCustomer($request, $user, $reason),
                3 => $this->updateMerchant($request, $user, $reason),
                1 => $this->updateAgent($request, $user, $reason),
                default => response()->json(['message' => 'نوع حساب غير مدعوم'], 422),
            };
        } catch (DomainException|RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** تعديل سياسة مستوى KYC كاملاً — مختلف عن استثناء عميل واحد. */
    public function updateTierPolicy(Request $request, int $tier): JsonResponse
    {
        $reason = trim((string) $request->input('reason'));
        if (mb_strlen($reason) < 10) {
            return response()->json(['message' => 'سبب تعديل سياسة المستوى إلزامي (10 أحرف على الأقل)'], 422);
        }

        try {
            $result = $this->kyc->updateTierPolicy(
                tier: $tier,
                payload: $request->only([
                    'max_balance',
                    'max_single_transaction',
                    'max_daily_total',
                    'max_monthly_total',
                    'max_annual_total',
                ]),
                actor: $request->user(),
                reason: $reason,
            );

            return response()->json($result);
        } catch (DomainException|RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    private function updateCustomer(Request $request, User $user, string $reason): JsonResponse
    {
        $payload = $request->only([
            'max_balance',
            'max_single_transaction',
            'max_daily_total',
            'max_monthly_total',
            'max_annual_total',
        ]);

        // الاستثناء الفردي يستطيع تخفيض حد العميل فقط، لا فتح سقف أعلى
        // من سياسة مستوى التوثيق الحالية.
        $base = $this->kyc->getLimits($this->kyc->effectiveTier($user));
        $effective = $this->kyc->getLimitsForUser($user);
        foreach ($payload as $field => $value) {
            if ($value === null || $value === '') continue;
            $value = trim((string) $value);
            if (!preg_match('/^\d+(?:\.\d{1,4})?$/', $value)) {
                throw new DomainException('قيمة الحد غير صالحة: ' . $field);
            }

            $baseValue = (string) ($base[$field] ?? '0');
            if ($field !== 'max_annual_total'
                && bccomp($baseValue, '0', 4) > 0
                && bccomp($value, $baseValue, 4) > 0) {
                throw new DomainException('لا يمكن رفع استثناء العميل فوق سقف مستوى التوثيق');
            }
            if ($field === 'max_annual_total'
                && bccomp($baseValue, '0', 4) > 0
                && bccomp($value, $baseValue, 4) > 0) {
                throw new DomainException('لا يمكن رفع الحد السنوي فوق سقف مستوى التوثيق');
            }

            $effective[$field] = $value;
        }

        if (bccomp((string) $effective['max_single_transaction'], (string) $effective['max_daily_total'], 4) > 0
            || bccomp((string) $effective['max_daily_total'], (string) $effective['max_monthly_total'], 4) > 0) {
            throw new DomainException('يجب أن يكون حد العملية ≤ اليومي ≤ الشهري');
        }

        $annual = (string) ($effective['max_annual_total'] ?? '0');
        if (bccomp($annual, '0', 4) > 0
            && bccomp((string) $effective['max_monthly_total'], $annual, 4) > 0) {
            throw new DomainException('يجب أن يكون الحد الشهري ≤ السنوي');
        }

        $result = $this->customers->run(
            $user,
            $request->user(),
            'update_limits',
            $reason,
            $payload,
        );

        return response()->json($result);
    }

    private function updateMerchant(Request $request, User $user, string $reason): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'single_receive_limit' => 'required|numeric|min:0',
            'daily_receive_limit' => 'required|numeric|min:0',
            'monthly_receive_limit' => 'required|numeric|min:0',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        return DB::transaction(function () use ($v, $user, $request, $reason) {
            $profile = MerchantProfile::where('user_id', $user->id)->lockForUpdate()->first();
            if (!$profile) throw new RuntimeException('ملف التاجر غير موجود');

            $before = $profile->only(['single_receive_limit', 'daily_receive_limit', 'monthly_receive_limit']);
            $next = $v->validated();

            if (bccomp((string) $next['single_receive_limit'], (string) $next['daily_receive_limit'], 4) > 0
                || bccomp((string) $next['daily_receive_limit'], (string) $next['monthly_receive_limit'], 4) > 0) {
                throw new RuntimeException('يجب أن يكون حد العملية ≤ اليومي ≤ الشهري');
            }

            $profile->update($next);

            $id = $this->audit->record([
                'actor_type' => 'admin',
                'actor_user_id' => $request->user()->id,
                'subject_type' => 'merchant_profile',
                'subject_id' => (string) $profile->id,
                'action' => 'MERCHANT_RECEIVE_LIMITS_UPDATED',
                'decision_code' => 'OK',
                'reason' => $reason,
                'severity' => 'warning',
                'context' => ['before' => $before, 'after' => $next],
            ]);
            if ($id === null) throw new RuntimeException('تعذر حفظ سجل التدقيق');

            return response()->json([
                'message' => 'تم تحديث حدود استلام التاجر',
                'profile' => $profile->fresh(),
            ]);
        });
    }

    private function updateAgent(Request $request, User $user, string $reason): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'single_transaction_limit' => 'required|numeric|min:0',
            'daily_cash_in_limit' => 'required|numeric|min:0',
            'daily_cash_out_limit' => 'required|numeric|min:0',
        ]);
        if ($v->fails()) return response()->json(['errors' => $v->errors()], 422);

        return DB::transaction(function () use ($v, $user, $request, $reason) {
            $profile = $this->agents->updateOperationalLimits(
                $user,
                $request->user(),
                $v->validated(),
                $reason,
            );
            return response()->json([
                'message' => 'تم تحديث حدود تشغيل الوكيل',
                'profile' => $profile,
            ]);
        });
    }
}
