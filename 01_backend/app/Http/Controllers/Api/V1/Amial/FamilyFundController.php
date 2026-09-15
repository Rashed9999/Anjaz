<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Models\FamilyFund;
use App\Models\FamilyFundMember;
use App\Models\FamilyFundTransaction;
use App\Services\FamilyFundService;
use App\Services\KycTierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-FUND-FAMILY-001 (v0.9-B)
 *
 * AMIAL-CUSTOMER-TIER-SURFACE-001:
 * صندوق العائلة ميزة Tier 2+ للعميل الفرد. المنع هنا Backend حقيقي،
 * وليس إخفاء بطاقة في Flutter فقط. كل قراءة/إدارة للصندوق تمر من
 * assertFeatureAllowed، وكل حركة مالية تمر كذلك من حدود KYC.
 */
class FamilyFundController extends AmialApiController
{
    public function __construct(
        private readonly FamilyFundService $service,
        private readonly KycTierService $kyc,
    ) {}

    /** GET /api/v1/amial/funds — قائمة صناديق المستخدم */
    public function index(Request $request): JsonResponse
    {
        if ($blocked = $this->requireFamilyFund($request)) return $blocked;

        $user = $request->user();
        $memberships = FamilyFundMember::with('fund')
            ->where('user_id', $user->id)
            ->whereIn('status', ['active', 'invited'])
            ->get();

        return $this->ok([
            'items' => $memberships->map(fn($m) => [
                'membership_id' => $m->id,
                'role' => $m->role,
                'status' => $m->status,
                'total_contributed' => $m->total_contributed,
                'total_disbursed' => $m->total_disbursed,
                'fund' => $m->fund ? [
                    'id' => $m->fund->id,
                    'fund_ulid' => $m->fund->fund_ulid,
                    'name' => $m->fund->name,
                    'description' => $m->fund->description,
                    'balance' => $m->fund->balance,
                    'status' => $m->fund->status,
                ] : null,
            ])->values()->toArray(),
        ]);
    }

    public function show(Request $request, string $ulid): JsonResponse
    {
        if ($blocked = $this->requireFamilyFund($request)) return $blocked;

        $fund = FamilyFund::where('fund_ulid', $ulid)->first();
        if (!$fund) return $this->error('NOT_FOUND', 'الصندوق غير موجود', 404);

        $user = $request->user();
        if (!$fund->isMember($user->id) && $fund->owner_user_id !== $user->id) {
            return $this->error('FORBIDDEN', 'لستَ عضواً في هذا الصندوق', 403);
        }

        $members = $fund->activeMembers()->with('user:id,f_name,l_name,phone')->get();
        $recentTx = FamilyFundTransaction::where('fund_id', $fund->id)
            ->with(['user:id,f_name,l_name', 'beneficiary:id,f_name,l_name'])
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return $this->ok([
            'fund' => $fund,
            'role' => $fund->memberRole($user->id),
            'members' => $members,
            'recent_transactions' => $recentTx,
        ]);
    }

    public function create(Request $request): JsonResponse
    {
        if ($blocked = $this->requireFamilyFund($request)) return $blocked;

        $v = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'description' => 'sometimes|string|max:500',
            'require_owner_approval_for_disbursement' => 'sometimes|boolean',
            'target_amount' => 'sometimes|nullable|numeric|min:1',
        ]);
        if ($v->fails()) return $this->validationError($v);

        try {
            $fund = $this->service->create(
                owner: $request->user(),
                name: $request->input('name'),
                description: $request->input('description'),
                requireOwnerApproval: $request->boolean('require_owner_approval_for_disbursement', true),
                targetAmount: $request->filled('target_amount') ? (string) $request->input('target_amount') : null,
            );
        } catch (\RuntimeException $e) {
            return $this->error('FUND_CREATE_FAILED', $e->getMessage(), 422);
        }

        return $this->ok(['fund' => $fund], 'FUND_CREATED', 'Fund created', 201);
    }

    public function invite(Request $request, string $ulid): JsonResponse
    {
        if ($blocked = $this->requireFamilyFund($request)) return $blocked;

        $fund = FamilyFund::where('fund_ulid', $ulid)->first();
        if (!$fund) return $this->error('NOT_FOUND', 'الصندوق غير موجود', 404);

        $v = Validator::make($request->all(), [
            'phone' => 'required|string|min:6|max:20',
            'role' => 'sometimes|string|in:admin,member,viewer',
        ]);
        if ($v->fails()) return $this->validationError($v);

        // لا نرسل دعوة لصندوق لا يستطيع المستلم فتحه أصلاً.
        $invitee = \App\Models\User::whereIn(
            'phone',
            \App\Support\Phone::variants((string) $request->input('phone')),
        )->first();
        if ($invitee) {
            try {
                $this->kyc->assertFeatureAllowed($invitee, 'family_fund');
            } catch (\RuntimeException $e) {
                return $this->error(
                    'INVITEE_KYC_TIER_REQUIRED',
                    'المستخدم المدعو يحتاج إكمال توثيق المستوى الثاني قبل الانضمام إلى صندوق العائلة.',
                    422,
                );
            }
        }

        try {
            $member = $this->service->inviteMember(
                $fund,
                $request->user(),
                $request->input('phone'),
                $request->input('role', 'member'),
            );
        } catch (\RuntimeException $e) {
            return $this->error('INVITE_FAILED', $e->getMessage(), 422);
        }

        return $this->ok(['membership' => $member], 'INVITE_SENT', 'Invitation sent', 201);
    }

    public function acceptInvite(Request $request, int $membershipId): JsonResponse
    {
        if ($blocked = $this->requireFamilyFund($request)) return $blocked;

        $member = FamilyFundMember::find($membershipId);
        if (!$member) return $this->error('NOT_FOUND', 'الدعوة غير موجودة', 404);

        try {
            $ok = $this->service->acceptInvitation($member, $request->user());
        } catch (\RuntimeException $e) {
            return $this->error('ACCEPT_FAILED', $e->getMessage(), 403);
        }

        return $this->ok(['accepted' => $ok], $ok ? 'INVITE_ACCEPTED' : 'ALREADY_HANDLED', 'OK');
    }

    public function contribute(Request $request, string $ulid): JsonResponse
    {
        $fund = FamilyFund::where('fund_ulid', $ulid)->first();
        if (!$fund) return $this->error('NOT_FOUND', 'الصندوق غير موجود', 404);

        $v = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01',
            'note' => 'sometimes|string|max:500',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $amount = (string) $request->input('amount');
        try {
            // يشمل Tier 2 + الإقامة + حد العملية/اليوم/الشهر.
            $this->kyc->assertTransactionAllowed($request->user(), $amount, 'family_fund');

            $tx = $this->service->contribute(
                $fund,
                $request->user(),
                $amount,
                $request->input('note'),
                $request->header('Idempotency-Key'),
            );
        } catch (\App\Exceptions\InsufficientBalanceException $e) {
            return new JsonResponse($e->toApiArray(), 402);
        } catch (\RuntimeException $e) {
            return $this->error('CONTRIBUTE_FAILED', $e->getMessage(), 422);
        }

        return $this->ok(['transaction' => $tx], 'CONTRIBUTE_OK', 'Contribution recorded', 201);
    }

    public function proposeDisbursement(Request $request, string $ulid): JsonResponse
    {
        if ($blocked = $this->requireFamilyFund($request)) return $blocked;

        $fund = FamilyFund::where('fund_ulid', $ulid)->first();
        if (!$fund) return $this->error('NOT_FOUND', 'الصندوق غير موجود', 404);

        $v = Validator::make($request->all(), [
            'beneficiary_user_id' => 'required|integer|exists:users,id',
            'amount' => 'required|numeric|min:0.01',
            'note' => 'sometimes|string|max:500',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $beneficiary = \App\Models\User::find($request->input('beneficiary_user_id'));
        $amount = (string) $request->input('amount');

        try {
            // المستفيد نفسه يجب أن يكون مؤهلاً للصندوق، والاستلام يجب ألا
            // يتجاوز حد حركته أو رصيده في لحظة الاقتراح.
            $this->kyc->assertFeatureAllowed($beneficiary, 'family_fund');
            $this->kyc->assertCanReceive($beneficiary, $amount);

            $tx = $this->service->proposeDisbursement(
                $fund,
                $request->user(),
                $beneficiary,
                $amount,
                $request->input('note'),
            );
        } catch (\RuntimeException $e) {
            return $this->error('DISBURSE_FAILED', $e->getMessage(), 422);
        }

        return $this->ok(
            ['transaction' => $tx],
            $tx->status === 'completed' ? 'DISBURSEMENT_OK' : 'DISBURSEMENT_PENDING',
            $tx->status === 'completed' ? 'Disbursed' : 'Pending owner approval',
            201,
        );
    }

    public function approveDisbursement(Request $request, string $ulid): JsonResponse
    {
        if ($blocked = $this->requireFamilyFund($request)) return $blocked;

        $tx = FamilyFundTransaction::where('tx_ulid', $ulid)->first();
        if (!$tx) return $this->error('NOT_FOUND', 'العملية غير موجودة', 404);

        try {
            $beneficiary = $tx->beneficiary;
            if (!$beneficiary) {
                throw new \RuntimeException('Beneficiary no longer exists');
            }

            // إعادة الفحص عند التنفيذ الفعلي؛ فقد تتغير حدود المستفيد بين
            // الاقتراح والموافقة.
            $this->kyc->assertFeatureAllowed($beneficiary, 'family_fund');
            $this->kyc->assertCanReceive($beneficiary, (string) $tx->amount);

            $ok = $this->service->approveDisbursement($tx, $request->user());
        } catch (\RuntimeException $e) {
            return $this->error('APPROVE_FAILED', $e->getMessage(), 422);
        }

        return $this->ok(['approved' => $ok], $ok ? 'DISBURSEMENT_OK' : 'ALREADY_HANDLED', 'OK');
    }

    public function rejectDisbursement(Request $request, string $ulid): JsonResponse
    {
        if ($blocked = $this->requireFamilyFund($request)) return $blocked;

        $tx = FamilyFundTransaction::where('tx_ulid', $ulid)->first();
        if (!$tx) return $this->error('NOT_FOUND', 'العملية غير موجودة', 404);

        $v = Validator::make($request->all(), [
            'reason' => 'required|string|min:5|max:500',
        ]);
        if ($v->fails()) return $this->validationError($v);

        try {
            $ok = $this->service->rejectDisbursement($tx, $request->user(), $request->input('reason'));
        } catch (\RuntimeException $e) {
            return $this->error('REJECT_FAILED', $e->getMessage(), 422);
        }

        return $this->ok(['rejected' => $ok], 'DISBURSEMENT_REJECTED', 'Rejected');
    }

    public function transactions(Request $request, string $ulid): JsonResponse
    {
        if ($blocked = $this->requireFamilyFund($request)) return $blocked;

        $fund = FamilyFund::where('fund_ulid', $ulid)->first();
        if (!$fund) return $this->error('NOT_FOUND', 'الصندوق غير موجود', 404);
        if (!$fund->isMember($request->user()->id)) {
            return $this->error('FORBIDDEN', 'لستَ عضواً في هذا الصندوق', 403);
        }

        $txs = FamilyFundTransaction::where('fund_id', $fund->id)
            ->orderByDesc('id')
            ->paginate(50);

        return $this->ok([
            'pagination' => [
                'total' => $txs->total(),
                'per_page' => $txs->perPage(),
                'current_page' => $txs->currentPage(),
            ],
            'items' => $txs->items(),
        ]);
    }

    /**
     * لا يظهر الصندوق ولا يُفتح برابط مباشر لمن هو دون Tier 2.
     * KycTierService هو مصدر الحقيقة للمستوى والإقامة الفعلية.
     */
    private function requireFamilyFund(Request $request): ?JsonResponse
    {
        try {
            $this->kyc->assertFeatureAllowed($request->user(), 'family_fund');
            return null;
        } catch (\RuntimeException $e) {
            return $this->error('KYC_TIER_REQUIRED', $e->getMessage(), 403);
        }
    }
}
