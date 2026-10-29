<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\CustomerCreditAccount;
use App\Models\Ledger\LedgerAccount;
use App\Models\Merchant;
use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\CustomerCreditService;
use App\Services\Merchant\MerchantSectorDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/** Read-only wallet and receivables view for the isolated merchant workspace. */
final class MerchantWorkspaceFinancialController extends Controller
{
    public function __invoke(Request $request, CustomerCreditService $credit): JsonResponse
    {
        $context = $request->attributes->get('merchant.workspace.context', []);
        $owner = User::query()->find((int) ($context['actor_id'] ?? 0));
        $merchant = $owner ? Merchant::query()->where('user_id', $owner->id)->first() : null;
        $profile = $owner ? MerchantProfile::query()->where('user_id', $owner->id)->first() : null;

        if (! $owner || ! $merchant || ! $profile?->isVerified()
            || ! hash_equals('legacy_merchant_'.$merchant->getKey(), (string) ($context['tenant_id'] ?? ''))) {
            return response()->json([
                'success' => false, 'code' => 'MERCHANT_OWNER_SCOPE_INVALID',
                'message' => 'المالك أو المنشأة غير معتمدين لهذا الطلب',
                'errors' => (object) [], 'meta' => (object) [],
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'section' => ['required', 'in:wallet,debts,sector'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'filter' => ['sometimes', 'nullable', 'in:debtors,over_limit,paid_up'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false, 'code' => 'VALIDATION_ERROR', 'message' => 'البيانات غير صالحة',
                'errors' => $validator->errors(), 'meta' => (object) [],
            ], 422);
        }

        $section = $request->input('section');
        $scope = $section === 'sector' ? 'merchant.sector.read' : 'merchant.financial.read';
        if (($context['scopes'] ?? null) !== [$scope]) {
            return response()->json(['success' => false, 'code' => 'MERCHANT_SCOPE_INVALID'], 403);
        }
        if ($section === 'sector') {
            if (! in_array($profile->business_type, ['quick_sale', 'wholesale', 'fuel'], true)) {
                return response()->json(['success' => false, 'code' => 'MERCHANT_SECTOR_UNSUPPORTED'], 422);
            }
            $snapshot = app(MerchantSectorDashboardService::class)->build($owner);
            return response()->json(['success' => true, 'code' => 'OK', 'message' => '',
                'data' => ['vertical' => $profile->business_type, 'cards' => $snapshot['cards'] ?? [],
                    'configured' => $snapshot['meta']['configured'] ?? true],
                'errors' => (object) [], 'meta' => ['as_of' => now()->toIso8601String()]]);
        }

        if ($request->input('section') === 'wallet') {
            // Pure read: unlike the older ledger helper, this never creates a wallet account.
            $account = LedgerAccount::query()->where('owner_type', 'user')
                ->where('owner_user_id', $owner->id)->where('account_code', 'USER_WALLET_'.$owner->id)
                ->first(['account_code', 'current_balance', 'currency']);
            $entries = $account ? $account->lines()->with('journalEntry:id,entry_ulid,source_type,description_ar,posted_at')
                ->whereHas('journalEntry', fn ($q) => $q->where('status', 'posted'))
                ->orderByDesc('id')->limit(20)->get()
                ->map(fn ($line) => [
                    'direction' => $line->direction, 'amount' => (string) $line->amount,
                    'description' => $line->description_ar ?? $line->journalEntry?->description_ar,
                    'source_type' => $line->journalEntry?->source_type,
                    'reference' => $line->journalEntry?->entry_ulid,
                    'date' => $line->created_at?->toIso8601String(),
                ])->values() : collect();

            return response()->json(['success' => true, 'code' => 'OK', 'message' => '', 'data' => [
                'source' => 'amial_pay_ledger',
                'account' => $account ? ['balance' => (string) $account->current_balance, 'currency' => $account->currency] : null,
                'entries' => $entries,
            ], 'errors' => (object) [], 'meta' => ['as_of' => now()->toIso8601String()]]);
        }

        $summary = $credit->dashboardSummary((int) $owner->id);
        $customers = $credit->listCustomers(
            (int) $owner->id,
            $request->input('search'),
            $request->input('filter'),
            (int) $request->input('page', 1),
        );

        return response()->json(['success' => true, 'code' => 'OK', 'message' => '', 'data' => [
            'source' => 'amial_pay_customer_credit',
            'summary' => $summary,
            'customers' => $customers->getCollection()->map(fn (CustomerCreditAccount $account) => [
                'name' => $account->customer_name,
                'phone' => $account->customer_phone,
                'balance' => (string) $account->current_balance,
                'credit_limit' => (string) $account->credit_limit,
                'classification' => $account->classification,
                'last_payment_at' => $account->last_payment_at?->toIso8601String(),
            ])->values(),
            'pagination' => [
                'current_page' => $customers->currentPage(), 'last_page' => $customers->lastPage(),
                'per_page' => $customers->perPage(), 'total' => $customers->total(),
            ],
        ], 'errors' => (object) [], 'meta' => ['as_of' => now()->toIso8601String()]]);
    }
}
