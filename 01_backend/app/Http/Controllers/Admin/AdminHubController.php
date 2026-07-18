<?php

namespace App\Http\Controllers\Admin;

use App\CentralLogics\Helpers;
use App\Exceptions\TransactionFailedException;
use App\Http\Controllers\Controller;
use App\Models\EMoney;
use App\Models\Ledger\LedgerJournalEntry;
use App\Models\MerchantProfile;
use App\Models\Transfer;
use App\Models\User;
use App\Services\AgentNetworkService;
use App\Services\AuditService;
use App\Services\MoneyService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-ADMIN-HUB-001 — اللوحات المركزية الأربع للوحة الويب.
 *
 * الواجهات القديمة (customer/agent/merchant/transaction/transfer/emoney)
 * حُذفت في تنظيف 6cash بينما بقيت المسارات والمتحكمات — فبدت اللوحة ناقصة.
 * هذا المتحكم يقدّم أربع لوحات مركزية حديثة فوق المنطق الموجود:
 *   1. العملاء:  بحث/قائمة، إضافة، تجميد/فكّ، اعتماد وثائق KYC، إعادة مبلغ، سجل عمليات.
 *   2. الوكلاء:  نفس الأساس + تحويل رصيد للوكيل (adminCreditAgent) + إحصاءات الشبكة.
 *   3. التجّار:  نفس الأساس + ملف التاجر والفروع.
 *   4. المالية:  تعبئة محفظة الإدارة + أرصدة إجمالية + بثّ حيّ لحركة كل ريال
 *      (دفتر القيود المزدوج ledger_journal_entries: من أين أتى وإلى أين ذهب).
 */
class AdminHubController extends Controller
{
    // ==================== الصفحات ====================

    public function customers(): View
    {
        return view('admin-views.amial.hub.users', $this->hubViewData(CUSTOMER_TYPE));
    }

    public function agents(): View
    {
        return view('admin-views.amial.hub.users', $this->hubViewData(AGENT_TYPE));
    }

    public function merchants(): View
    {
        return view('admin-views.amial.hub.users', $this->hubViewData(MERCHANT_TYPE));
    }

    private function hubViewData(int $type): array
    {
        $meta = match ($type) {
            CUSTOMER_TYPE => ['slug' => 'customers', 'title' => 'مركز العملاء'],
            AGENT_TYPE => ['slug' => 'agents', 'title' => 'مركز الوكلاء'],
            MERCHANT_TYPE => ['slug' => 'merchants', 'title' => 'مركز التجّار'],
        };
        return [
            'hubType' => $type,
            'hubSlug' => $meta['slug'],
            'hubTitle' => $meta['title'],
            'stats' => [
                'total' => User::where('type', $type)->count(),
                'active' => User::where('type', $type)->where('is_active', 1)->count(),
                'frozen' => User::where('type', $type)->where('is_active', '!=', 1)->count(),
                'kyc_pending' => User::where('type', $type)
                    ->where('is_kyc_verified', '!=', 1)
                    ->whereNotNull('identification_image')->count(),
                'balance_sum' => (string) EMoney::whereHas('user', fn ($q) => $q->where('type', $type))
                    ->sum('current_balance'),
            ],
        ];
    }

    public function finance(): View
    {
        return view('admin-views.amial.hub.finance');
    }

    // ==================== JSON: قوائم المستخدمين ====================

    /** GET hub/{slug}/users.json?search=&page= */
    public function usersJson(Request $request, string $slug): JsonResponse
    {
        $type = $this->typeFromSlug($slug);
        if ($type === null) return response()->json(['message' => 'unknown hub'], 404);

        $q = User::where('type', $type);
        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $q->where(function ($w) use ($search) {
                $w->where('phone', 'like', "%{$search}%")
                    ->orWhere('f_name', 'like', "%{$search}%")
                    ->orWhere('l_name', 'like', "%{$search}%")
                    ->orWhere('id', $search);
            });
        }

        $users = $q->orderByDesc('id')->paginate(15);
        $balances = EMoney::whereIn('user_id', $users->pluck('id'))
            ->pluck('current_balance', 'user_id');

        return response()->json([
            'data' => $users->map(fn (User $u) => [
                'id' => $u->id,
                'name' => trim(($u->f_name ?? '') . ' ' . ($u->l_name ?? '')) ?: '—',
                'phone' => $u->phone,
                'balance' => (string) ($balances[$u->id] ?? '0'),
                'is_active' => (int) $u->is_active === 1,
                'kyc' => (int) ($u->is_kyc_verified ?? 0), // 0 معلّق / 1 موثّق / 2 مرفوض
                'has_docs' => !empty($u->identification_image),
                'created_at' => optional($u->created_at)->format('Y-m-d'),
            ])->values(),
            'current_page' => $users->currentPage(),
            'last_page' => $users->lastPage(),
            'total' => $users->total(),
        ]);
    }

    /** GET hub/{slug}/kyc.json — طلبات التوثيق المعلّقة مع الوثائق */
    public function kycJson(string $slug): JsonResponse
    {
        $type = $this->typeFromSlug($slug);
        if ($type === null) return response()->json(['message' => 'unknown hub'], 404);

        $users = User::where('type', $type)
            ->where('is_kyc_verified', '!=', 1)
            ->whereNotNull('identification_image')
            ->orderByDesc('updated_at')->limit(50)->get();

        return response()->json([
            'data' => $users->map(fn (User $u) => [
                'id' => $u->id,
                'name' => trim(($u->f_name ?? '') . ' ' . ($u->l_name ?? '')) ?: '—',
                'phone' => $u->phone,
                'id_type' => $u->identification_type,
                'id_number' => $u->identification_number,
                'documents' => $u->identification_image_fullpath ?? [],
                'kyc' => (int) ($u->is_kyc_verified ?? 0),
            ])->values(),
        ]);
    }

    /** GET hub/users/{id}/transactions.json — سجلّ عمليات مستخدم */
    public function userTransactionsJson(int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $rows = DB::table('transactions')
            ->where('user_id', $user->id)
            ->orderByDesc('id')->limit(30)
            ->get(['transaction_id', 'transaction_type', 'debit', 'credit', 'balance', 'created_at']);

        return response()->json([
            'user' => ['id' => $user->id, 'name' => trim(($user->f_name ?? '') . ' ' . ($user->l_name ?? ''))],
            'data' => $rows,
        ]);
    }

    // ==================== الإجراءات ====================

    /** POST hub/users/{id}/toggle-active — تجميد/فكّ تجميد */
    public function toggleActive(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        if ((int) $user->type === ADMIN_TYPE) {
            return response()->json(['message' => 'لا يمكن تجميد حساب أدمن من هنا'], 422);
        }

        $user->is_active = ((int) $user->is_active === 1) ? 0 : 1;
        $user->save();

        app(AuditService::class)->record([
            'actor_type' => 'admin', 'actor_user_id' => $request->user()?->id,
            'subject_type' => 'user', 'subject_id' => $user->id,
            'action' => $user->is_active ? 'ADMIN_UNFREEZE_ACCOUNT' : 'ADMIN_FREEZE_ACCOUNT',
            'decision_code' => $user->is_active ? 'UNFROZEN' : 'FROZEN',
            'reason' => trim((string) $request->input('reason', '')) ?: null,
            'severity' => 'warning',
        ]);

        return response()->json([
            'is_active' => (int) $user->is_active === 1,
            'message' => $user->is_active ? 'تم فكّ التجميد' : 'تم تجميد الحساب',
        ]);
    }

    /** POST hub/users/{id}/kyc — اعتماد/رفض الوثائق (status: 1 قبول / 2 رفض) */
    public function kycStatus(Request $request, int $id): JsonResponse
    {
        $status = (int) $request->input('status');
        if (!in_array($status, [1, 2], true)) {
            return response()->json(['message' => 'حالة غير صحيحة'], 422);
        }

        $user = User::findOrFail($id);
        $user->is_kyc_verified = $status;
        $user->save();

        app(AuditService::class)->record([
            'actor_type' => 'admin', 'actor_user_id' => $request->user()?->id,
            'subject_type' => 'user', 'subject_id' => $user->id,
            'action' => 'ADMIN_KYC_REVIEW',
            'decision_code' => $status === 1 ? 'KYC_APPROVED' : 'KYC_REJECTED',
            'severity' => 'info',
        ]);

        try {
            if ($user->fcm_token) {
                Helpers::send_push_notif_to_device($user->fcm_token, [
                    'title' => $status === 1 ? translate('verification_request_is_accepted') : translate('verification_request_is_denied'),
                    'description' => '', 'image' => '', 'type' => 'kyc_verification',
                ]);
            }
        } catch (\Throwable $e) { /* الإشعار تحسيني */ }

        return response()->json(['kyc' => $status, 'message' => $status === 1 ? 'تم اعتماد الوثائق' : 'تم رفض الوثائق']);
    }

    /** POST hub/{slug}/users — إضافة عميل/وكيل/تاجر */
    public function storeUser(Request $request, string $slug): JsonResponse
    {
        $type = $this->typeFromSlug($slug);
        if ($type === null) return response()->json(['message' => 'unknown hub'], 404);

        $v = Validator::make($request->all(), [
            'f_name' => 'required|string|max:100',
            'l_name' => 'nullable|string|max:100',
            'phone' => 'required|string|min:6|max:20',
            'password' => 'required|string|min:8',
        ]);
        if ($v->fails()) return response()->json(['message' => $v->errors()->first()], 422);

        $phone = Helpers::filter_phone($request->input('phone'));
        if (User::where('phone', $phone)->exists()) {
            return response()->json(['message' => 'رقم الهاتف مستخدم مسبقاً'], 422);
        }

        $user = DB::transaction(function () use ($request, $type, $phone) {
            $user = new User();
            $user->f_name = $request->input('f_name');
            $user->l_name = $request->input('l_name', '');
            $user->phone = $phone;
            $user->password = Hash::make($request->input('password'));
            $user->type = $type;
            $user->is_active = 1;
            $user->is_phone_verified = 1;
            if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'zone_code')) {
                $user->zone_code = 'SOUTH';
            }
            $user->save();

            EMoney::firstOrCreate(['user_id' => $user->id], [
                'current_balance' => '0.0000', 'held_balance' => '0.0000',
                'pending_balance' => '0.0000', 'charge_earned' => '0.0000',
                'zone_code' => 'SOUTH',
            ]);

            if ($type === MERCHANT_TYPE) {
                MerchantProfile::firstOrCreate(['user_id' => $user->id], [
                    'verification_status' => 'pending',
                ]);
            }
            return $user;
        });

        app(AuditService::class)->record([
            'actor_type' => 'admin', 'actor_user_id' => $request->user()?->id,
            'subject_type' => 'user', 'subject_id' => $user->id,
            'action' => 'ADMIN_CREATE_USER', 'decision_code' => 'USER_CREATED',
            'context' => ['type' => $type], 'severity' => 'info',
        ]);

        return response()->json(['id' => $user->id, 'message' => 'تم إنشاء الحساب'], 201);
    }

    /**
     * POST hub/transfer — تحويل من محفظة الإدارة لمستخدم (يخدم «إعادة مبلغ»
     * للعملاء و«تحويل للوكيل/التاجر»). نفس محاسبة TransferController القديم
     * (make_transaction المزدوجة + سجلّ Transfer) لكن بردّ JSON.
     */
    public function transfer(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'to_user_id' => 'required|integer|exists:users,id',
            'amount' => 'required|numeric|gt:0',
            'reason' => 'nullable|string|max:255',
        ]);
        if ($v->fails()) return response()->json(['message' => $v->errors()->first()], 422);

        $adminId = Helpers::get_admin_id();
        $amount = (string) $request->input('amount');

        $adminBalance = (string) (EMoney::where('user_id', $adminId)->value('current_balance') ?? '0');
        if (!MoneyService::gte($adminBalance, $amount)) {
            return response()->json(['message' => "رصيد محفظة الإدارة لا يكفي (المتاح: {$adminBalance})"], 422);
        }

        DB::beginTransaction();
        try {
            $data = [
                'from_user_id' => $adminId,
                'to_user_id' => (int) $request->input('to_user_id'),
                'user_id' => (int) $request->input('to_user_id'),
                'type' => 'credit',
                'transaction_type' => CASH_IN,
                'ref_trans_id' => null,
                'amount' => $amount,
            ];
            $customerTransaction = Helpers::make_transaction($data);
            if ($customerTransaction === null) throw new TransactionFailedException();

            $data['user_id'] = $adminId;
            $data['type'] = 'debit';
            $data['transaction_type'] = CASH_OUT;
            $data['ref_trans_id'] = $customerTransaction;
            $adminTransaction = Helpers::make_transaction($data);
            if ($adminTransaction === null) throw new TransactionFailedException();

            $transfer = new Transfer();
            $transfer->sender = $adminId;
            $transfer->receiver = (int) $request->input('to_user_id');
            $transfer->receiver_type = (string) (User::find($request->input('to_user_id'))->type ?? '');
            $transfer->amount = $amount;
            $transfer->save();
            $transfer->unique_id = $transfer->id . mt_rand(111111, 9999999999);
            $transfer->save();

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'فشل التحويل: ' . $e->getMessage()], 422);
        }

        app(AuditService::class)->record([
            'actor_type' => 'admin', 'actor_user_id' => $request->user()?->id,
            'subject_type' => 'user', 'subject_id' => (int) $request->input('to_user_id'),
            'action' => 'ADMIN_WALLET_TRANSFER', 'decision_code' => 'TRANSFERRED',
            'reason' => trim((string) $request->input('reason', '')) ?: null,
            'transaction_id' => $customerTransaction,
            'context' => ['amount' => $amount], 'severity' => 'notice',
        ]);

        return response()->json(['transaction_id' => $customerTransaction, 'message' => 'تم التحويل بنجاح']);
    }

    /** POST hub/agents/{id}/credit — تحويل للوكيل عبر مسار التسويات (الموصى به) */
    public function agentCredit(Request $request, int $id): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'amount' => 'required|numeric|gt:0',
            'reference' => 'nullable|string|max:120',
        ]);
        if ($v->fails()) return response()->json(['message' => $v->errors()->first()], 422);

        $agent = User::where('id', $id)->where('type', AGENT_TYPE)->first();
        if (!$agent) return response()->json(['message' => 'الوكيل غير موجود'], 404);

        try {
            $settlement = app(AgentNetworkService::class)->adminCreditAgent(
                $agent,
                (string) $request->input('amount'),
                $request->user(),
                $request->input('reference'),
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => 'فشل التحويل: ' . $e->getMessage()], 422);
        }

        return response()->json(['settlement_ulid' => $settlement->ulid ?? null, 'message' => 'تم تحويل الرصيد للوكيل']);
    }

    /** POST hub/finance/topup — تعبئة محفظة الإدارة (نفس EMoneyController القديم، JSON) */
    public function adminTopup(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'amount' => ['required', 'numeric', 'gt:0', 'regex:/^\d{1,12}(\.\d{1,4})?$/'],
        ]);
        if ($v->fails()) return response()->json(['message' => $v->errors()->first()], 422);

        $adminId = Helpers::get_admin_id();
        DB::beginTransaction();
        try {
            $tx = Helpers::make_transaction([
                'from_user_id' => $adminId, 'to_user_id' => $adminId,
                'user_id' => $adminId, 'type' => 'credit',
                'transaction_type' => CASH_IN, 'ref_trans_id' => null,
                'amount' => (string) $request->input('amount'),
            ]);
            if ($tx === null) throw new TransactionFailedException();
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'فشلت التعبئة: ' . $e->getMessage()], 422);
        }

        app(AuditService::class)->record([
            'actor_type' => 'admin', 'actor_user_id' => $request->user()?->id,
            'subject_type' => 'wallet', 'subject_id' => $adminId,
            'action' => 'ADMIN_WALLET_TOPUP', 'decision_code' => 'TOPPED_UP',
            'transaction_id' => $tx,
            'context' => ['amount' => (string) $request->input('amount')], 'severity' => 'notice',
        ]);

        $balance = (string) (EMoney::where('user_id', $adminId)->value('current_balance') ?? '0');
        return response()->json(['balance' => $balance, 'transaction_id' => $tx, 'message' => 'تمت تعبئة محفظة الإدارة']);
    }

    // ==================== المالية: إحصاءات + البثّ الحيّ ====================

    /** GET hub/finance/stats.json */
    public function financeStats(): JsonResponse
    {
        $adminId = Helpers::get_admin_id();
        $admin = EMoney::where('user_id', $adminId)->first();

        $byType = EMoney::join('users', 'users.id', '=', 'e_money.user_id')
            ->selectRaw('users.type, SUM(e_money.current_balance) as balance, SUM(e_money.held_balance) as held')
            ->groupBy('users.type')->get()->keyBy('type');

        $today = now()->startOfDay();
        return response()->json([
            'admin_balance' => (string) ($admin->current_balance ?? '0'),
            'admin_charge_earned' => (string) ($admin->charge_earned ?? '0'),
            'customers_balance' => (string) ($byType[CUSTOMER_TYPE]->balance ?? '0'),
            'agents_balance' => (string) ($byType[AGENT_TYPE]->balance ?? '0'),
            'merchants_balance' => (string) ($byType[MERCHANT_TYPE]->balance ?? '0'),
            'held_total' => (string) EMoney::sum('held_balance'),
            'today_entries' => LedgerJournalEntry::where('posted_at', '>=', $today)->count(),
            'today_volume' => (string) LedgerJournalEntry::where('posted_at', '>=', $today)->sum('total_amount'),
        ]);
    }

    /**
     * GET hub/finance/feed.json?since_id=0 — بثّ حركة المال المستمرّ.
     * كل قيد يومية مع سطوره: الحساب المدين (من أين خرج) والدائن (إلى أين ذهب)
     * والرصيد بعد الحركة — «كل ريال يتحرك: من أين أتى وأين ذهب».
     */
    public function financeFeed(Request $request): JsonResponse
    {
        $sinceId = (int) $request->query('since_id', 0);

        $entries = LedgerJournalEntry::with(['lines.account'])
            ->when($sinceId > 0, fn ($q) => $q->where('id', '>', $sinceId))
            ->orderByDesc('id')->limit(30)->get();

        return response()->json([
            'max_id' => (int) ($entries->max('id') ?? $sinceId),
            'data' => $entries->map(function (LedgerJournalEntry $e) {
                $from = $e->lines->where('direction', 'debit')->map(
                    fn ($l) => ['account' => $l->account->name_ar ?? $l->account->account_code ?? '—', 'amount' => (string) $l->amount]
                )->values();
                $to = $e->lines->where('direction', 'credit')->map(
                    fn ($l) => ['account' => $l->account->name_ar ?? $l->account->account_code ?? '—', 'amount' => (string) $l->amount]
                )->values();
                return [
                    'id' => $e->id,
                    'ulid' => $e->entry_ulid,
                    'source_type' => $e->source_type,
                    'description' => $e->description_ar,
                    'amount' => (string) $e->total_amount,
                    'status' => $e->status,
                    'from' => $from,
                    'to' => $to,
                    'posted_at' => optional($e->posted_at)->format('Y-m-d H:i:s'),
                ];
            })->values(),
        ]);
    }

    // ==================== مساعدات ====================

    private function typeFromSlug(string $slug): ?int
    {
        return match ($slug) {
            'customers' => CUSTOMER_TYPE,
            'agents' => AGENT_TYPE,
            'merchants' => MERCHANT_TYPE,
            default => null,
        };
    }
}
