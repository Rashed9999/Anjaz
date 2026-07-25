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

    // ==================== صفحة تفاصيل الحساب ====================

    public function account(int $id): View|\Illuminate\Http\RedirectResponse
    {
        $user = User::find($id);
        if (!$user) return redirect()->route('admin.amial.hub.customers');
        return view('admin-views.amial.hub.account', [
            'accountId' => $id,
            'roleLabel' => match ((int) $user->type) {
                AGENT_TYPE => 'وكيل', MERCHANT_TYPE => 'تاجر', ADMIN_TYPE => 'أدمن', default => 'عميل',
            },
        ]);
    }

    /**
     * GET hub/users/{id}/detail.json — الملفّ الكامل للحساب:
     * بيانات شخصية + وثائق + محفظة + حالة المخاطر (AML) + سجل عمليات،
     * وإضافات حسب الدور: التاجر (موظفون/مبيعات/اشتراك) والوكيل (تسويات/فروع).
     */
    public function accountDetailJson(int $id): JsonResponse
    {
        $user = User::find($id);
        if (!$user) return response()->json(['message' => 'الحساب غير موجود'], 404);

        $type = (int) $user->type;
        $wallet = EMoney::where('user_id', $user->id)->first();

        // ===== حالة المخاطر (AML) =====
        $riskProfile = \App\Models\Aml\AmlUserRiskProfile::where('user_id', $user->id)->first();
        $merchantRisk = $type === MERCHANT_TYPE
            ? \App\Models\MerchantRiskProfile::where('merchant_user_id', $user->id)->first() : null;
        $level = $merchantRisk->risk_level ?? $riskProfile->risk_level ?? 'low';
        $score = (string) ($merchantRisk->current_risk_score ?? $riskProfile->current_risk_score ?? '0');
        $riskLabel = match ($level) {
            'critical' => 'خطر جداً',
            'high' => 'خطر',
            'medium' => 'مشبوه',
            default => 'سليم',
        };

        // ===== سجل العمليات =====
        $transactions = DB::table('transactions')->where('user_id', $user->id)
            ->orderByDesc('id')->limit(25)
            ->get(['transaction_id', 'transaction_type', 'debit', 'credit', 'balance', 'created_at'])
            ->map(fn ($t) => (array) $t)->all();

        $payload = [
            'id' => $user->id,
            'role' => $type,
            'role_label' => match ($type) {
                AGENT_TYPE => 'وكيل', MERCHANT_TYPE => 'تاجر', ADMIN_TYPE => 'أدمن', default => 'عميل',
            },
            'name' => trim(($user->f_name ?? '') . ' ' . ($user->l_name ?? '')) ?: '—',
            'phone' => $user->phone,
            'email' => $user->email,
            'gender' => $user->gender,
            'occupation' => $user->occupation,
            'address' => $user->address ?? null,
            'national_id' => $user->identification_number,
            'id_type' => $user->identification_type,
            'agent_number' => $user->agent_number ?? null,
            'is_active' => (int) $user->is_active === 1,
            'kyc' => (int) ($user->is_kyc_verified ?? 0),
            'documents' => $user->identification_image_fullpath ?? [],
            'kin' => trim(implode(' — ', array_filter([
                $user->kin_name ?? null, $user->kin_phone ?? null, $user->kin_relation ?? null,
            ]))) ?: null,
            'created_at' => optional($user->created_at)->format('Y-m-d H:i'),
            'wallet' => [
                'current' => (string) ($wallet->current_balance ?? '0'),
                'held' => (string) ($wallet->held_balance ?? '0'),
                'pending' => (string) ($wallet->pending_balance ?? '0'),
                'charge_earned' => (string) ($wallet->charge_earned ?? '0'),
            ],
            'risk' => [
                'level' => $level,
                'label' => $riskLabel,
                'score' => $score,
                'is_dangerous' => in_array($level, ['high', 'critical'], true),
            ],
            'transactions' => $transactions,
        ];

        // ===== إضافات التاجر =====
        if ($type === MERCHANT_TYPE) {
            $profile = MerchantProfile::where('user_id', $user->id)->first();
            $store = \App\Models\Merchant::where('user_id', $user->id)->first();
            $staff = \App\Models\PosUser::where('merchant_user_id', $user->id)
                ->with('branch:id,name')->get()
                ->map(fn ($p) => [
                    'display_name' => $p->display_name,
                    'pos_number' => $p->pos_number,
                    'branch' => $p->branch->name ?? null,
                    'is_active' => (bool) $p->is_active,
                ])->all();
            $branches = \App\Models\Branch::where('merchant_user_id', $user->id)
                ->get(['name', 'code', 'city', 'is_active'])->map(fn ($b) => (array) $b->toArray())->all();
            $salesTotal = (string) \App\Models\MerchantSale::where('merchant_user_id', $user->id)->sum('total_amount');
            $salesCount = \App\Models\MerchantSale::where('merchant_user_id', $user->id)->count();

            $payload['merchant'] = [
                'store_name' => $store->store_name ?? null,
                'merchant_number' => $store->merchant_number ?? null,
                'business_type' => $profile->business_type ?? null,
                'plan' => $profile->subscription_plan ?? null,
                'verification' => $profile->verification_status ?? null,
                'subscription_expires' => optional($profile->subscription_expires_at ?? null)->format('Y-m-d'),
                'staff' => $staff,
                'branches' => $branches,
                'sales_total' => $salesTotal,
                'sales_count' => $salesCount,
            ];
        }

        // ===== إضافات الوكيل =====
        if ($type === AGENT_TYPE) {
            $ap = \App\Models\AgentProfile::where('user_id', $user->id)->first();
            $settlements = \App\Models\AgentSettlement::where('agent_user_id', $user->id)
                ->orderByDesc('id')->limit(10)->get()
                ->map(fn ($s) => [
                    'ulid' => $s->settlement_ulid,
                    'type' => $s->settlement_type,
                    'amount' => (string) $s->amount,
                    'status' => $s->status,
                    'created_at' => optional($s->created_at)->format('Y-m-d H:i'),
                ])->all();
            $subCount = $ap ? \App\Models\AgentProfile::where('parent_agent_id', $ap->id)->count() : 0;

            $payload['agent'] = [
                'agent_level' => $ap->agent_level ?? null,
                'status' => $ap->status ?? null,
                'commission_rate' => (string) ($ap->commission_rate ?? '0'),
                'daily_cash_in_limit' => (string) ($ap->daily_cash_in_limit ?? '0'),
                'daily_cash_out_limit' => (string) ($ap->daily_cash_out_limit ?? '0'),
                'sub_agents_count' => $subCount,
                'settlements' => $settlements,
            ];
        }

        return response()->json($payload);
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

        // AMIAL-VERIFY-HUB: اعتماد تاجر يوثّق ملفه أيضاً (يفتح ميزات التطبيق فعلياً)
        if ((int) $user->type === MERCHANT_TYPE) {
            MerchantProfile::where('user_id', $user->id)->update(
                $status === 1
                    ? ['verification_status' => 'verified', 'verified_at' => now()]
                    : ['verification_status' => 'rejected'],
            );
        }

        app(AuditService::class)->record([
            'actor_type' => 'admin', 'actor_user_id' => $request->user()?->id,
            'subject_type' => 'user', 'subject_id' => $user->id,
            'action' => 'ADMIN_KYC_REVIEW',
            'decision_code' => $status === 1 ? 'KYC_APPROVED' : 'KYC_REJECTED',
            'severity' => 'info',
        ]);

        // AMIAL-VERIFY-GATE: إشعار داخل التطبيق (مركز الإشعارات) — يعرف صاحب
        // الحساب فور القرار دون انتظار محاولة دخول. آمن: لا يكسر القرار أبداً.
        try {
            $title = $status === 1 ? 'تم اعتماد حسابك ✅' : 'تعذّر اعتماد حسابك';
            $body = $status === 1
                ? 'وثّقنا حسابك بنجاح. يمكنك الآن استخدام كل الخدمات.'
                : 'راجعنا وثائقك ولم نتمكّن من اعتمادها. يرجى إعادة رفع وثائق واضحة أو مراجعة الدعم.';
            app(\App\Services\NotificationService::class)->dispatch(
                $user, 'kyc_verification', $title, $body,
                data: ['is_kyc_verified' => $status],
            );
        } catch (\Throwable $e) { /* الإشعار تحسيني */ }

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

    /**
     * POST hub/{slug}/users — إضافة عميل/وكيل/تاجر «حقيقي».
     *
     * الحساب المُنشأ هنا جاهز للدخول من التطبيق فوراً بنفس وصفة الحسابات
     * التجريبية العاملة: هاتف موثّق + PIN معاملات + KYC معتمد + محفظة، وللوكيل
     * رقم وكيل، وللتاجر سجلّ Merchant + ملف MerchantProfile موثّق بنشاطه
     * وباقته وحدوده — فيفتح التطبيق لوحة القطاع الصحيحة مباشرة.
     */
    public function storeUser(Request $request, string $slug): JsonResponse
    {
        $type = $this->typeFromSlug($slug);
        if ($type === null) return response()->json(['message' => 'unknown hub'], 404);

        $v = Validator::make($request->all(), [
            'f_name' => 'required|string|max:100',
            'l_name' => 'nullable|string|max:100',
            'phone' => 'required|string|min:6|max:20',
            'password' => 'required|string|min:8',
            'pin' => 'nullable|digits:4',
            // حقول التاجر
            'store_name' => 'nullable|string|max:120',
            'business_type' => 'nullable|string|in:' . implode(',', \App\Support\Access\AccessConstants::ALL_BUSINESS_TYPES),
            'plan' => 'nullable|string|in:' . implode(',', \App\Support\Access\AccessConstants::ALL_PLANS),
        ]);
        if ($v->fails()) return response()->json(['message' => $v->errors()->first()], 422);

        if ($type === MERCHANT_TYPE && trim((string) $request->input('store_name', '')) === '') {
            return response()->json(['message' => 'اسم المتجر مطلوب للتاجر'], 422);
        }

        $phone = Helpers::filter_phone($request->input('phone'));
        if (User::where('phone', $phone)->exists()) {
            return response()->json(['message' => 'رقم الهاتف مستخدم مسبقاً'], 422);
        }

        $schema = \Illuminate\Support\Facades\Schema::class;
        $user = DB::transaction(function () use ($request, $type, $phone, $schema) {
            $user = new User();
            $user->f_name = $request->input('f_name');
            $user->l_name = $request->input('l_name', '');
            $user->phone = $phone;
            $user->password = Hash::make($request->input('password'));
            $user->type = $type;
            $user->is_active = 1;
            // الهاتف يُعدّ متحقّقاً منه: الأدمن أدخله بنفسه، ولا OTP في هذا المسار.
            $user->is_phone_verified = 1;

            // AMIAL-ADMIN-KYC-001: كان هنا is_kyc_verified = 1 بتعليق يقول
            // «حساب أنشأه الأدمن = موثّق (نفس وصفة الحسابات التجريبية)».
            // هذا اختصار كُتب للحسابات التجريبية ثم صار سلوك الإنتاج: كل حساب
            // يُنشأ من اللوحة يخرج موثّقاً بلا وثيقة واحدة ولا مراجعة — في تطبيق
            // مالي هذا يُفرغ لوحة التحقّق من معناها ويكسر الامتثال.
            // الحساب الآن يخرج «بانتظار المراجعة» تماماً كالمسجَّل من التطبيق،
            // ويمرّ بلوحة التحقّق (قبول/رفض) عبر kycStatus.
            if ($schema::hasColumn('users', 'is_kyc_verified')) $user->is_kyc_verified = 0;
            // المستوى الأدنى حتى الاعتماد — يرفعه قرار المراجعة لا الإنشاء.
            if ($schema::hasColumn('users', 'kyc_tier')) $user->kyc_tier = 0;
            if ($schema::hasColumn('users', 'zone_code')) $user->zone_code = 'SOUTH';
            if ($schema::hasColumn('users', 'transaction_pin')) {
                $user->transaction_pin = Hash::make($request->input('pin') ?: '1234');
            }
            if ($type === AGENT_TYPE && $schema::hasColumn('users', 'agent_number')) {
                $seq = User::where('type', AGENT_TYPE)->count() + 1;
                $user->agent_number = sprintf('AG-%03d', $seq);
            }
            $user->save();

            EMoney::firstOrCreate(['user_id' => $user->id], [
                'current_balance' => '0.0000', 'held_balance' => '0.0000',
                'pending_balance' => '0.0000', 'charge_earned' => '0.0000',
                'zone_code' => 'SOUTH', 'version' => 1,
            ]);

            if ($type === MERCHANT_TYPE) {
                // سجلّ Merchant القديم (تعتمد عليه شاشات 6cash المتبقية)
                $mr = \App\Models\Merchant::where('user_id', $user->id)->first() ?? new \App\Models\Merchant();
                $mr->user_id = $user->id;
                $mr->store_name = trim((string) $request->input('store_name'));
                $mr->merchant_number = sprintf('M-%05d', $user->id);
                $mr->address = trim((string) $request->input('address', '')) ?: '—';
                $mr->save();

                // ملف أميال: النشاط يفتح لوحة القطاع، والباقة تفتح الميزات
                MerchantProfile::firstOrCreate(['user_id' => $user->id], [
                    'business_type' => $request->input('business_type') ?: \App\Support\Access\AccessConstants::BIZ_RETAIL,
                    // AMIAL-ADMIN-KYC-001: ملف التاجر يتبع حالة الحساب — بانتظار
                    // المراجعة لا موثّقاً. يرفعه kycStatus عند الاعتماد.
                    'verification_status' => 'pending_review',
                    'verified_at' => null,
                    'zone_code' => 'SOUTH',
                    'subscription_plan' => $request->input('plan') ?: \App\Support\Access\AccessConstants::PLAN_FREE,
                    'subscription_expires_at' => now()->addDays(30),
                    'subscription_notes' => 'أُنشئ من لوحة الإدارة',
                    'daily_receive_limit' => '5000000',
                    'single_receive_limit' => '1000000',
                    'monthly_receive_limit' => '50000000',
                    'can_transfer_out' => true,
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

        // أرقام الدخول التي يسلّمها الأدمن لصاحب الحساب — التطبيق يطلبها:
        // التاجر يدخل بـ merchant_number + الهاتف، والوكيل بـ agent_number + الهاتف.
        $merchantNumber = $type === MERCHANT_TYPE
            ? \App\Models\Merchant::where('user_id', $user->id)->value('merchant_number') : null;

        $loginHint = match ($type) {
            AGENT_TYPE => "دخول التطبيق: رقم الوكيل {$user->agent_number} + الهاتف + كلمة السر (ثم OTP)",
            MERCHANT_TYPE => "دخول التطبيق: رقم التاجر {$merchantNumber} + الهاتف + كلمة السر",
            default => 'دخول التطبيق: الهاتف + كلمة السر',
        };

        return response()->json([
            'id' => $user->id,
            'agent_number' => $user->agent_number ?? null,
            'merchant_number' => $merchantNumber,
            'message' => "تم إنشاء الحساب — {$loginHint}",
        ], 201);
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

    // ==================== لوحة الاشتراكات ====================

    public function subscriptions(): View
    {
        $A = \App\Support\Access\AccessConstants::class;
        return view('admin-views.amial.hub.subscriptions', [
            'plans' => array_map(fn ($p) => ['code' => $p, 'label' => $A::PLAN_LABELS[$p] ?? $p], $A::ALL_PLANS),
            'bizLabels' => $A::BUSINESS_TYPE_LABELS,
        ]);
    }

    /** GET hub/subscriptions/list.json?plan=&search= — التجّار مع باقاتهم */
    public function subsList(Request $request): JsonResponse
    {
        $A = \App\Support\Access\AccessConstants::class;
        $q = MerchantProfile::with('user:id,f_name,l_name,phone');
        if ($request->filled('plan')) $q->where('subscription_plan', $request->query('plan'));
        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $q->whereHas('user', fn ($w) => $w->where('phone', 'like', "%{$search}%")
                ->orWhere('f_name', 'like', "%{$search}%")->orWhere('l_name', 'like', "%{$search}%"));
        }
        $items = $q->orderByDesc('id')->paginate(15);

        return response()->json([
            'summary' => app(\App\Services\SubscriptionService::class)->summary(),
            'data' => collect($items->items())->map(fn (MerchantProfile $p) => [
                'merchant_user_id' => $p->user_id,
                'name' => trim(($p->user->f_name ?? '') . ' ' . ($p->user->l_name ?? '')) ?: '—',
                'phone' => $p->user->phone ?? '',
                'business_type' => $p->business_type,
                'plan' => $p->subscription_plan,
                'plan_label' => $A::PLAN_LABELS[$p->subscription_plan] ?? ($p->subscription_plan ?: 'مجاني'),
                'expires_at' => optional($p->subscription_expires_at)->format('Y-m-d'),
                'expired' => $p->subscription_expires_at !== null && $p->subscription_expires_at->isPast(),
                'verification' => $p->verification_status,
            ])->values(),
            'current_page' => $items->currentPage(),
            'last_page' => $items->lastPage(),
            'total' => $items->total(),
        ]);
    }

    /** POST hub/subscriptions/{merchantId}/plan — تغيير الباقة (حقيقي عبر الخدمة) */
    public function subsChangePlan(Request $request, int $merchantId): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'plan' => 'required|string|in:' . implode(',', \App\Support\Access\AccessConstants::ALL_PLANS),
            'notes' => 'nullable|string|max:500',
        ]);
        if ($v->fails()) return response()->json(['message' => $v->errors()->first()], 422);

        $merchant = User::where('id', $merchantId)->where('type', MERCHANT_TYPE)->first();
        if (!$merchant) return response()->json(['message' => 'التاجر غير موجود'], 404);

        try {
            $change = app(\App\Services\SubscriptionService::class)->changePlan(
                $merchant, $request->input('plan'), $request->user(),
                ['notes' => $request->input('notes')],
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => 'فشل تغيير الباقة: ' . $e->getMessage()], 422);
        }

        return response()->json([
            'new_expires_at' => optional($change->new_expires_at)->format('Y-m-d'),
            'message' => 'تم تغيير الباقة',
        ]);
    }

    /** POST hub/subscriptions/{merchantId}/extend — تمديد بالأيام */
    public function subsExtend(Request $request, int $merchantId): JsonResponse
    {
        $v = Validator::make($request->all(), ['days' => 'required|integer|min:1|max:365']);
        if ($v->fails()) return response()->json(['message' => $v->errors()->first()], 422);

        $merchant = User::where('id', $merchantId)->where('type', MERCHANT_TYPE)->first();
        if (!$merchant) return response()->json(['message' => 'التاجر غير موجود'], 404);

        try {
            $change = app(\App\Services\SubscriptionService::class)->extend(
                $merchant, (int) $request->input('days'), $request->user(), [],
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => 'فشل التمديد: ' . $e->getMessage()], 422);
        }

        return response()->json([
            'new_expires_at' => optional($change->new_expires_at)->format('Y-m-d'),
            'message' => 'تم التمديد',
        ]);
    }

    // ==================== لوحة النزاعات (الدفع الآمن) ====================

    public function disputes(): View
    {
        return view('admin-views.amial.hub.disputes');
    }

    // ==================== لوحة التحقق (اعتماد الحسابات الجديدة) ====================

    public function verification(): View
    {
        return view('admin-views.amial.hub.verification', [
            'pendingCount' => User::whereIn('type', [CUSTOMER_TYPE, AGENT_TYPE, MERCHANT_TYPE])
                ->where(fn ($q) => $q->where('is_kyc_verified', 0)->orWhereNull('is_kyc_verified'))
                ->count(),
        ]);
    }

    /**
     * GET hub/verification/list.json?filter=pending|rejected|all
     * كل الحسابات قيد التحقق (التسجيل الذاتي يصل هنا) عبر الأدوار الثلاثة،
     * مع بياناتها ووثائقها ومتجر التاجر — للاعتماد/الرفض/الحظر.
     */
    public function verificationJson(Request $request): JsonResponse
    {
        $filter = $request->query('filter', 'pending');
        $q = User::whereIn('type', [CUSTOMER_TYPE, AGENT_TYPE, MERCHANT_TYPE]);
        if ($filter === 'pending') {
            $q->where(fn ($w) => $w->where('is_kyc_verified', 0)->orWhereNull('is_kyc_verified'));
        } elseif ($filter === 'rejected') {
            $q->where('is_kyc_verified', 2);
        }

        $users = $q->orderByDesc('id')->paginate(12);
        $merchantRecords = \App\Models\Merchant::whereIn('user_id', $users->pluck('id'))
            ->get()->keyBy('user_id');
        $profiles = MerchantProfile::whereIn('user_id', $users->pluck('id'))
            ->get()->keyBy('user_id');

        return response()->json([
            'data' => $users->map(function (User $u) use ($merchantRecords, $profiles) {
                $roleLabel = match ((int) $u->type) {
                    AGENT_TYPE => 'وكيل', MERCHANT_TYPE => 'تاجر', default => 'عميل',
                };
                return [
                    'id' => $u->id,
                    'role' => $roleLabel,
                    'type' => (int) $u->type,
                    'name' => trim(($u->f_name ?? '') . ' ' . ($u->l_name ?? '')) ?: '—',
                    'phone' => $u->phone,
                    'kyc' => (int) ($u->is_kyc_verified ?? 0),
                    'is_active' => (int) $u->is_active === 1,
                    'id_type' => $u->identification_type,
                    'id_number' => $u->identification_number,
                    'documents' => $u->identification_image_fullpath ?? [],
                    'address' => $u->address ?? null,
                    'kin' => trim(implode(' — ', array_filter([
                        $u->kin_name ?? null, $u->kin_phone ?? null, $u->kin_relation ?? null,
                    ]))) ?: null,
                    'store_name' => $merchantRecords[$u->id]->store_name ?? null,
                    'merchant_number' => $merchantRecords[$u->id]->merchant_number ?? null,
                    'business_type' => $profiles[$u->id]->business_type ?? null,
                    'agent_number' => $u->agent_number ?? null,
                    'registered_at' => optional($u->created_at)->format('Y-m-d H:i'),
                ];
            })->values(),
            'current_page' => $users->currentPage(),
            'last_page' => $users->lastPage(),
            'total' => $users->total(),
        ]);
    }

    // ==================== لوحة التسويات (تسويات الوكلاء) ====================

    public function settlements(): View
    {
        return view('admin-views.amial.hub.settlements');
    }

    /** GET hub/settlements/list.json?status=pending|completed|rejected */
    public function settlementsJson(Request $request): JsonResponse
    {
        $status = $request->query('status', 'pending');
        $q = \App\Models\AgentSettlement::with('agent:id,f_name,l_name,phone');
        if (in_array($status, ['pending', 'completed', 'rejected'], true)) {
            $q->where('status', $status);
        }
        $items = $q->orderByDesc('id')->paginate(15);

        $sums = \App\Models\AgentSettlement::selectRaw('status, COUNT(*) c, SUM(amount) s')
            ->groupBy('status')->get()->keyBy('status');

        return response()->json([
            'summary' => [
                'pending' => (int) ($sums['pending']->c ?? 0),
                'pending_amount' => (string) ($sums['pending']->s ?? '0'),
                'completed' => (int) ($sums['completed']->c ?? 0),
                'completed_amount' => (string) ($sums['completed']->s ?? '0'),
            ],
            'data' => collect($items->items())->map(fn ($s) => [
                'ulid' => $s->settlement_ulid,
                'agent' => trim(($s->agent->f_name ?? '') . ' ' . ($s->agent->l_name ?? '')) ?: '—',
                'phone' => $s->agent->phone ?? '',
                'type' => $s->settlement_type,
                'amount' => (string) $s->amount,
                'commission' => (string) $s->commission_amount,
                'method' => $s->payment_method,
                'reference' => $s->payment_reference,
                'status' => $s->status,
                'note' => $s->note,
                'created_at' => optional($s->created_at)->format('Y-m-d H:i'),
            ])->values(),
            'current_page' => $items->currentPage(),
            'last_page' => $items->lastPage(),
            'total' => $items->total(),
        ]);
    }

    /** POST hub/settlements/{ulid}/approve — يعتمد التسوية ويضيف الرصيد (دفتر قيود) */
    public function settlementApprove(Request $request, string $ulid): JsonResponse
    {
        $s = \App\Models\AgentSettlement::where('settlement_ulid', $ulid)->first();
        if (!$s) return response()->json(['message' => 'التسوية غير موجودة'], 404);
        try {
            $r = app(AgentNetworkService::class)->approveSettlement($s, $request->user());
        } catch (\Throwable $e) {
            return response()->json(['message' => 'فشل الاعتماد: ' . $e->getMessage()], 422);
        }
        return response()->json(['status' => $r->status, 'message' => 'تم اعتماد التسوية وإضافة الرصيد']);
    }

    /** POST hub/settlements/{ulid}/reject */
    public function settlementReject(Request $request, string $ulid): JsonResponse
    {
        $s = \App\Models\AgentSettlement::where('settlement_ulid', $ulid)->first();
        if (!$s) return response()->json(['message' => 'التسوية غير موجودة'], 404);
        try {
            $r = app(AgentNetworkService::class)->rejectSettlement($s, $request->user(), $request->input('reason'));
        } catch (\Throwable $e) {
            return response()->json(['message' => 'فشل الرفض: ' . $e->getMessage()], 422);
        }
        return response()->json(['status' => $r->status, 'message' => 'تم رفض التسوية']);
    }

    // ==================== لوحة الموظفين (طاقم نقاط بيع التجّار) ====================

    public function staff(): View
    {
        return view('admin-views.amial.hub.staff', [
            'roles' => \App\Models\Role::orderBy('id')->get(['id', 'code', 'label_ar'])->map(fn ($r) => [
                'id' => $r->id, 'name' => $r->code,
                'label' => $r->label_ar ?? $r->code,
            ]),
        ]);
    }

    /** GET hub/staff/list.json?search=&merchant_id= */
    public function staffJson(Request $request): JsonResponse
    {
        $q = \App\Models\PosUser::with(['user:id,f_name,l_name,phone', 'merchant:id,f_name,l_name', 'branch:id,name']);
        if ($request->filled('merchant_id')) {
            $q->where('merchant_user_id', (int) $request->query('merchant_id'));
        }
        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $q->where(fn ($w) => $w->where('display_name', 'like', "%{$search}%")
                ->orWhere('pos_number', 'like', "%{$search}%")
                ->orWhereHas('user', fn ($u) => $u->where('phone', 'like', "%{$search}%")));
        }
        $items = $q->orderByDesc('id')->paginate(15);

        return response()->json([
            'summary' => [
                'total' => \App\Models\PosUser::count(),
                'active' => \App\Models\PosUser::where('is_active', true)->count(),
            ],
            'data' => collect($items->items())->map(fn ($p) => [
                'id' => $p->id,
                'display_name' => $p->display_name,
                'pos_number' => $p->pos_number,
                'phone' => $p->user->phone ?? '',
                'merchant' => trim(($p->merchant->f_name ?? '') . ' ' . ($p->merchant->l_name ?? '')) ?: '—',
                'branch' => $p->branch->name ?? null,
                'is_active' => (bool) $p->is_active,
                'roles' => $p->roles()->pluck('label_ar')->filter()->values(),
                'last_login' => optional($p->last_login_at)->format('Y-m-d H:i'),
            ])->values(),
            'current_page' => $items->currentPage(),
            'last_page' => $items->lastPage(),
            'total' => $items->total(),
        ]);
    }

    /** POST hub/staff/{id}/toggle-active */
    public function staffToggle(Request $request, int $id): JsonResponse
    {
        $p = \App\Models\PosUser::findOrFail($id);
        $p->is_active = !$p->is_active;
        $p->save();

        app(AuditService::class)->record([
            'actor_type' => 'admin', 'actor_user_id' => $request->user()?->id,
            'subject_type' => 'user', 'subject_id' => $p->user_id,
            'action' => 'ADMIN_POS_STAFF_TOGGLE',
            'decision_code' => $p->is_active ? 'STAFF_ENABLED' : 'STAFF_DISABLED',
            'severity' => 'notice',
        ]);

        return response()->json([
            'is_active' => (bool) $p->is_active,
            'message' => $p->is_active ? 'تم تفعيل الموظف' : 'تم تعطيل الموظف',
        ]);
    }

    // ==================== لوحة الإعدادات (تحكّم بضغطة زر) ====================

    public function settings(): View
    {
        $keys = ['phone_verification', 'referral_earning_status', 'maintenance_mode'];
        $values = [];
        foreach ($keys as $k) {
            $values[$k] = (string) (DB::table('business_settings')->where('key', $k)->value('value') ?? '0');
        }
        return view('admin-views.amial.hub.settings', ['flags' => $values]);
    }

    /** POST hub/settings/flag — تبديل قيمة إعداد منطقي (0/1) بضغطة زر */
    public function settingsToggle(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'key' => 'required|string|in:phone_verification,referral_earning_status,maintenance_mode',
            'value' => 'required|in:0,1',
        ]);
        if ($v->fails()) return response()->json(['message' => $v->errors()->first()], 422);

        DB::table('business_settings')->updateOrInsert(
            ['key' => $request->input('key')],
            ['value' => $request->input('value'), 'updated_at' => now()],
        );

        app(AuditService::class)->record([
            'actor_type' => 'admin', 'actor_user_id' => $request->user()?->id,
            'subject_type' => 'system', 'subject_id' => $request->input('key'),
            'action' => 'ADMIN_SETTING_TOGGLE', 'decision_code' => 'SETTING_UPDATED',
            'context' => ['key' => $request->input('key'), 'value' => $request->input('value')],
            'severity' => 'notice',
        ]);

        return response()->json(['message' => 'تم حفظ الإعداد']);
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
