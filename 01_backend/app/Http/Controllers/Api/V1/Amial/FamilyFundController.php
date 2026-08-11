<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\FamilyFund;
use App\Models\FamilyFundMember;
use App\Models\FamilyFundTransaction;
use App\Services\FamilyFundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-FUND-FAMILY-001 (v0.9-B)
 */
class FamilyFundController extends AmialApiController // AMIAL-FIX-007
{
    public function __construct(
        private readonly FamilyFundService $service,
    ) {}

    /** GET /api/v1/amial/funds  — قائمة صناديق المستخدم */
    public function index(Request $request): JsonResponse
    {
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
        $fund = FamilyFund::where('fund_ulid', $ulid)->first();
        if (!$fund) return $this->error('NOT_FOUND', 'الصندوق غير موجود', 404);

        $user = $request->user();
        if (!$fund->isMember($user->id) && $fund->owner_user_id !== $user->id) {
            return $this->error('FORBIDDEN', 'لستَ عضواً في هذا الصندوق', 403);
        }

        $members = $fund->activeMembers()->with('user:id,f_name,l_name,phone')->get();
        // AMIAL-FUND-UI: أسماء منفّذ الحركة والمستفيد — حتى يعرض التطبيق
        // «بواسطة فلان» و«إلى فلان» في صرف/مساهمات الصندوق.
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
        $v = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'description' => 'sometimes|string|max:500',
            'require_owner_approval_for_disbursement' => 'sometimes|boolean',
            'target_amount' => 'sometimes|nullable|numeric|min:1', // AMIAL-FUND-002
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
        $fund = FamilyFund::where('fund_ulid', $ulid)->first();
        if (!$fund) return $this->error('NOT_FOUND', 'الصندوق غير موجود', 404);

        $v = Validator::make($request->all(), [
            'phone' => 'required|string|min:6|max:20',
            'role' => 'sometimes|string|in:admin,member,viewer',
        ]);
        if ($v->fails()) return $this->validationError($v);

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

        try {
            $tx = $this->service->contribute(
                $fund,
                $request->user(),
                (string)$request->input('amount'),
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
        $fund = FamilyFund::where('fund_ulid', $ulid)->first();
        if (!$fund) return $this->error('NOT_FOUND', 'الصندوق غير موجود', 404);

        $v = Validator::make($request->all(), [
            'beneficiary_user_id' => 'required|integer|exists:users,id',
            'amount' => 'required|numeric|min:0.01',
            'note' => 'sometimes|string|max:500',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $beneficiary = \App\Models\User::find($request->input('beneficiary_user_id'));

        try {
            $tx = $this->service->proposeDisbursement(
                $fund,
                $request->user(),
                $beneficiary,
                (string)$request->input('amount'),
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
        $tx = FamilyFundTransaction::where('tx_ulid', $ulid)->first();
        if (!$tx) return $this->error('NOT_FOUND', 'العملية غير موجودة', 404);

        try {
            $ok = $this->service->approveDisbursement($tx, $request->user());
        } catch (\RuntimeException $e) {
            return $this->error('APPROVE_FAILED', $e->getMessage(), 422);
        }

        return $this->ok(['approved' => $ok], $ok ? 'DISBURSEMENT_OK' : 'ALREADY_HANDLED', 'OK');
    }

    public function rejectDisbursement(Request $request, string $ulid): JsonResponse
    {
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

    // Helpers
}
