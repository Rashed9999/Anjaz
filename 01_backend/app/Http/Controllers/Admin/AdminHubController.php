<?php

namespace App\Http\Controllers\Admin;

use App\CentralLogics\Helpers;
use App\Exceptions\InsufficientBalanceException;
use App\Http\Controllers\Controller;
use App\Models\EMoney;
use App\Models\Ledger\LedgerJournalEntry;
use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\AgentNetworkService;
use App\Services\AdminWalletTransferService;
use App\Services\AuditService;
use App\Services\MoneyService;
use App\Services\PlatformTreasuryService;
use App\Services\KycDocumentService;
use App\Services\RegistrationDossierService;
use App\Services\ZoneAssignmentService;
use App\Support\Kyc\KycProfileFields;
use App\Support\Phone;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
// **استُعيد مع `profileRules`** — حُذف معها، فكانت تسقط بـ
// `Class "App\Http\Controllers\Admin\Rule" not found`: خمسُ مئةٍ لا أربعُ مئة.
use Illuminate\Validation\Rule;
// **استُعيدت مع الأفعال التي تستعملها** — استعادةُ دالّةٍ بلا
// استيراداتها تُبدّل ٤٠٤ بـ٥٠٠، وهو أسوأ.
use App\Models\KycDocument;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

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
    /**
     * AMIAL-ADMIN-EDIT-001 — **حقولُ التعديل، مصدراً واحداً.**
     *
     * تقرؤها الشاشةُ لترسم الحقول، ويقرؤها الحفظُ ليقبلها. **وقائمتان
     * تفترقان بحقلٍ تُنتجان حقلاً يُرسَل ولا يصل**: النموذجُ يسأل عنه،
     * والموظّفُ يكتبه، ولا شيء يحفظه — ولا خطأَ في أيّ سجلّ.
     *
     * **ولا يدخلها الهاتفُ ولا رقمُ الهويّة** — الأوّلُ مفتاحُ الدخول
     * ومعرّفُ التحويل، والثاني ما يربط الحسابَ بشخصه. ولهما مساراهما
     * بموافقةٍ وسجلّ (استعادةُ الحسابات · طلباتُ تحديث البيانات).
     *
     * @var list<string>
     */
    public const EDITABLE_PROFILE_FIELDS = [
        'f_name', 'l_name', 'name_en', 'father_name', 'grandfather_name',
        'residence_governorate', 'residence_district', 'residence_area',
        'residence_landmark', 'occupation', 'gender',
        'income_source', 'account_purpose',
    ];


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
        return view('admin-views.amial.hub.finance', [
            'canPayout' => (bool) auth()->user()?->hasPlatformPermission('platform.treasury.payout'),
        ]);
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
            // ══════════════════════════════════════════════════════════
            // AMIAL-HUB-SEARCH-001 — **«أنشأتُ الحساب فلم أجده».**
            //
            // **الثمنُ الذي دُفع، بنصّ صاحب المشروع:** «حاولت إنشاء حساب
            // جديد، أخبرني تم الحفظ، بحثت عن الحساب فلم أجده».
            //
            // **والحسابُ موجودٌ فعلاً.** قِيس بالتشغيل على حسابٍ أُنشئ
            // للتوّ (٢٠١) باسمِ منشأةٍ «محطة الشرق» ومالكٍ «أحمد علي»:
            //
            //     «محطة الشرق»    → صفر     ← ما يكتبه المستعمل
            //     «الشرق»         → صفر
            //     «أحمد»          → واحد
            //     «712345678»     → واحد
            //     «+967712345678» → صفر     ← الهاتفُ كما يُنسَخ
            //     «0712345678»    → صفر     ← الهاتفُ كما يُملى
            //
            // فسببان اثنان، وكلاهما يُنتج «لم أجده» على حسابٍ قائم:
            //
            // ① **اسمُ المنشأة لم يكن يُبحَث فيه إطلاقاً.** والتاجرُ
            //    يُعرَف بمحطّته لا باسم مالكه — فمن أنشأ «محطة الشرق»
            //    يكتبها، والمطابقةُ على `f_name`/`l_name` وحدهما.
            //
            // ② **والهاتفُ يُخزَّن معياريّاً `967…` بلا `+` ولا صفر.**
            //    فمن نسخ الرقمَ من مكانٍ آخر بصيغة `+967…`، أو أملاه
            //    محلّيّاً `07…`، لا يطابق `LIKE` حرفاً بحرف.
            //
            // **والعلاج: تُطابَق أرقامُ الهاتف مجرّدةً من زخرفها**، ويُضمّ
            // اسمُ المنشأة من مصدريه (`merchants.store_name` وملفّ التاجر).
            // ══════════════════════════════════════════════════════════
            $digits = preg_replace('/\D+/', '', $search);

            // «07…» محلّيّاً و«00967…» دوليّاً و«+967…» كلُّها رقمٌ واحد.
            // ويُقتصر على الذيل فيطابق المخزَّنَ أيّاً كانت بادئتُه.
            $phoneTail = $digits !== '' ? substr($digits, -9) : '';

            $q->where(function ($w) use ($search, $phoneTail, $type) {
                $w->where('f_name', 'like', "%{$search}%")
                    ->orWhere('l_name', 'like', "%{$search}%")
                    ->orWhere('id', $search);

                if ($phoneTail !== '') {
                    $w->orWhere('phone', 'like', "%{$phoneTail}%");
                }

                // **واسمُ المنشأة يُبحَث فيه للتاجر وحدَه** — فالعميلُ لا
                // منشأةَ له، وضمُّ الجدول له استعلامٌ بلا فائدة.
                if ($type === MERCHANT_TYPE) {
                    $w->orWhereIn('id', \App\Models\Merchant::where('store_name', 'like', "%{$search}%")
                        ->select('user_id'));

                    if (Schema::hasColumn('merchant_profiles', 'business_name')) {
                        $w->orWhereIn('id', DB::table('merchant_profiles')
                            ->where('business_name', 'like', "%{$search}%")
                            ->select('user_id'));
                    }
                }
            });
        }

        $users = $q->orderByDesc('id')->paginate(15);
        $balances = EMoney::whereIn('user_id', $users->pluck('id'))
            ->pluck('current_balance', 'user_id');

        // الوكيل ليس عميلاً برصيد: هو شركة صرافة لها فروعٌ وخزائنُ نقدٍ ورقيّ،
        // ورصيدُه الإلكترونيّ وحده لا يقول إن كان يستطيع الخدمة اليوم.
        // تُحسب هذه الإضافات لمعرّفات الصفحة المعروضة فقط.
        $extra = $type === AGENT_TYPE
            ? app(\App\Services\AgentSupervisionService::class)
                ->summariesFor($users->pluck('id')->map(fn ($v) => (int) $v)->all())
            : [];

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
                'agent' => $extra[$u->id] ?? null,
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
    public function accountDetailJson(
        int $id,
        Request $request,
        \App\Services\Admin\KycEvidenceService $evidence,
    ): JsonResponse {
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
            // AMIAL-KYC-EVIDENCE-001 — الدليلُ من السجلّ الحديث، والقديمُ
            // معروضٌ داخلَه موسوماً «لا يُحتسَب». (وهي المسألةُ نفسُها في
            // `verificationJson`: كان يُعرَض عمودٌ ويُحكَم بجدول.)
            'evidence' => $evidence->for($user, 2, $request->user()),
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
            // AMIAL-GOVERNORATES-001: المطابقة الجغرافية أمام المراجع —
            // الأصل مقابل السكن مقابل جدول المناطق. أعلام لا حكم: القرار
            // بشريّ لأن النزوح والعمل والدراسة تفصل الأصل عن السكن بلا ريبة.
            'geo_check' => app(\App\Services\KycGeoConsistencyService::class)->evaluate($user),
            'zone_code' => $user->zone_code,
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
            $salesTotal = (string) \App\Models\MerchantSale::where('merchant_user_id', $user->id)->sum(\DB::raw('COALESCE(base_amount, total_amount)'));
            $salesCount = \App\Models\MerchantSale::where('merchant_user_id', $user->id)->count();

            // AMIAL-MERCHANT-CENTER-001 — **نُزع من هنا عرضُ أسطر المبيعة
            // بأسماء أصنافها.** أميال منصّةٌ ماليّة لا ERP للتاجر: تعرف أنّه
            // نفّذ كذا عمليّةً بكذا، ولا تحتاج اسمَ الصنف المباع.
            //
            // ومن احتاجه لتحقيقٍ يفتح إذناً مؤقّتاً بسببٍ مكتوب في
            // **مركز التاجر ← سجل التدقيق** — ويُسجَّل استعمالُه.

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

            // ══════════════════════════════════════════════════════════
            //  AMIAL-MERCHANT-360-001 — **الماليّ والتشغيليّ والإداريّ.**
            //
            //  كان الملفُّ يجيب عن «مَن هو» ولا يجيب عن «ماذا يُدرّ»،
            //  ولا «كيف يعمل»، ولا «ماذا أستطيع أن أفعل به». وحسابُ
            //  محطّةِ وقودٍ كان يُعرض بلا ذكرِ خزّانٍ ولا مضخّةٍ ولا
            //  ورديّة — والملفُّ لا يعلم أنّ له قطاعاً أصلاً.
            $payload['merchant360'] = app(\App\Services\Admin\MerchantThreeSixtyService::class)
                ->build($user);
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
        $targetTier = (int) $request->input('target_tier', 2);

        try {
            app(\App\Services\Verification\AccountVerificationDecisionService::class)->decide(
                account: $user,
                reviewer: $request->user(),
                approve: $status === 1,
                targetTier: $targetTier,
                requestedGovernorate: $request->input('governorate'),
                reason: $request->input('reason'),
            );
        } catch (\DomainException $e) {
            $code = $e->getMessage();
            $message = match ($code) {
                'KYC_TIER_TARGET_INVALID' => 'مستوى التوثيق المطلوب غير صالح.',
                'MISSING_RESIDENCE_GOVERNORATE' => 'اختر محافظة السكن قبل اعتماد الحساب.',
                default => $code,
            };
            return response()->json([
                'message' => $message,
                'code' => $code,
            ], str_contains($code, 'KYC_TIER_SEQUENCE_VIOLATION') ? 409 : 422);
        }

        return response()->json(['kyc' => $status, 'message' => $status === 1 ? 'تم اعتماد الوثائق' : 'تم رفض الوثائق']);
    }

    /**
     * POST hub/{slug}/users — ملف فتح واحد للعميل أو المنشأة.
     *
     * لا توجد هنا «طريقة تسجيل» ثانية: الموظف يجمع النموذج الكامل من نفس
     * نافذة الإضافة، ثم نحفظ لقطة أرشيفية مشفّرة ونربطها بالحساب. الحساب لا
     * يخرج معتمداً، والورقة الموقعة لا تُحوّل إلى تحقق هاتف مزعوم.
     */
    public function storeUser(Request $request, string $slug): JsonResponse{
        return response()->json([
            'success' => false,
            'code' => 'ACCOUNT_CREATION_OWNED_BY_MUWATHAQ',
            'message' => 'إنشاء الحساب يتم حصراً عبر موثّق؛ أميال باي لا ينشئ حسابات جديدة.',
            'muwathaq_url' => rtrim((string) config('muwathaq.base_url'), '/').'/open-account',
        ], 410);
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

        // ── البابُ الخلفيّ يُغلق هنا ────────────────────────────────────
        //
        // هذا التحويل عامٌّ يقبل أيّ `to_user_id`. فلو أُغلق مسارُ شحن
        // الوكلاء وحده لبقي هذا مفتوحاً، ونفسُ المال يصل بنفس الأثر —
        // بل أسوأ: بلا تسويةٍ أصلاً، فلا «متوقَّع» يرتفع ولا سطر يحمله.
        //
        // فرصيدُ الوكلاء وفروعهم يمرّ من مسار التسويات وحده.
        $receiver = User::find((int) $request->input('to_user_id'));
        if ($receiver && (int) $receiver->type === AGENT_TYPE) {
            try {
                app(AgentNetworkService::class)->assertNotBranchAccount($receiver, 'التمويل');
            } catch (\Throwable $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return response()->json([
                'message' => 'رصيد الوكلاء يُشحن من «تحويل رصيد» في صفّ الوكيل — '
                    . 'ليمرّ من مسار التسويات ويظهر في التوازن. والتحويل العامّ للعملاء.',
            ], 422);
        }

        $admin = User::find(Helpers::get_admin_id());
        if (! $admin) {
            report(new \RuntimeException('Admin wallet transfer attempted without an administration account'));
            return response()->json(['message' => 'تعذّر إتمام التحويل. تواصل مع الدعم.'], 503);
        }

        try {
            $result = app(AdminWalletTransferService::class)->transfer(
                sender: $admin,
                recipient: $receiver,
                amount: (string) $request->input('amount'),
                reason: (string) $request->input('reason', ''),
                requestIdempotencyKey: $request->header('Idempotency-Key')
                    ?: (is_string($request->input('idempotency_key')) ? $request->input('idempotency_key') : null),
                actor: $request->user(),
            );
        } catch (InsufficientBalanceException $e) {
            return response()->json(['message' => 'رصيد محفظة الإدارة لا يكفي لإتمام التحويل'], 422);
        } catch (\Throwable $e) {
            report($e);
            return response()->json([
                'message' => 'تعذّر إتمام التحويل. لم يتحرك أي مبلغ؛ استخدم مرجع الطلب عند التواصل مع الدعم.',
            ], 422);
        }

        app(AuditService::class)->record([
            'actor_type' => 'admin', 'actor_user_id' => $request->user()?->id,
            'subject_type' => 'user', 'subject_id' => (int) $request->input('to_user_id'),
            'action' => 'ADMIN_WALLET_TRANSFER', 'decision_code' => 'TRANSFERRED',
            'reason' => trim((string) $request->input('reason', '')) ?: null,
            'transaction_id' => $result['transaction_id'],
            'context' => [
                'amount' => (string) $request->input('amount'),
                'ledger_entry_ulid' => $result['ledger_entry_ulid'],
                'duplicate' => $result['duplicate'],
            ], 'severity' => 'notice',
        ]);

        return response()->json([
            'transaction_id' => $result['transaction_id'],
            'ledger_entry_ulid' => $result['ledger_entry_ulid'],
            'message' => 'تم التحويل بنجاح',
        ]);
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

    /** POST hub/finance/topup — إصدار خزينة موثق، لا زيادة رصيدٍ صامتة. */
    public function adminTopup(Request $request, PlatformTreasuryService $treasury): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'amount' => ['required', 'numeric', 'gt:0', 'regex:/^\d{1,12}(\.\d{1,4})?$/'],
            'reference' => ['required', 'string', 'max:120'],
            'reason' => ['required', 'string', 'max:500'],
            // AMIAL-TREASURY-SOURCE-001 — «أين وصل المالُ الحقيقيّ؟».
            // والافتراضُ `treasury_supply` يُبقي المستهلكَ القديمَ عاملاً
            // بالسلوك الذي كان له بالضبط، ولا يخترع مصدراً لم يُذكر.
            'funding_source' => ['nullable', 'string',
                \Illuminate\Validation\Rule::in(array_keys(PlatformTreasuryService::FUNDING_SOURCES))],
        ]);
        if ($v->fails()) return response()->json(['message' => $v->errors()->first()], 422);

        try {
            // AMIAL-TREASURY-MAKERCHECKER-001 — الطلبُ لا الإصدار؛ والمالُ
            // يُخلَق لحظةَ اعتماد مشرفٍ آخر.
            $issued = $treasury->requestIssuance(
                (string) $request->input('amount'), $request->user(),
                (string) $request->input('reference'), (string) $request->input('reason'),
                (string) $request->input('funding_source', 'treasury_supply'),
                $request->header('Idempotency-Key'),
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => 'فشلت التعبئة: ' . $e->getMessage()], 422);
        }

        if (($issued['mode'] ?? '') === 'pending_approval') {
            return response()->json([
                'approval_required' => true,
                'request_number' => $issued['request']->request_number,
                'request_id' => (int) $issued['request']->id,
                'preview' => $issued['request']->payload['preview'] ?? null,
                'message' => 'إصدارُ الرصيد يتطلّب اعتماد مشرفٍ آخر — أُنشئ طلب '
                    . $issued['request']->request_number . '. **لم يُصدَر رصيدٌ بعد.**',
            ], 202);
        }

        $adminId = Helpers::get_admin_id();
        $balance = (string) (EMoney::where('user_id', $adminId)->value('current_balance') ?? '0');
        return response()->json([
            'balance' => $balance,
            'transaction_id' => $issued['transaction_id'],
            'ledger_entry_ulid' => $issued['entry']->entry_ulid,
            'duplicate' => $issued['duplicate'],
            'message' => $issued['duplicate'] ? 'طلب مكرر — عُرض قيد الإصدار الأصلي' : 'تم ترحيل إصدار الخزينة',
        ]);
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
            'bizLabels' => \App\Domain\Verticals\VerticalRegistry::labels(),
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
    public function verificationJson(
        Request $request,
        \App\Services\Admin\KycEvidenceService $evidence,
    ): JsonResponse {
        $reviewer = $request->user();
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
            'data' => $users->map(function (User $u) use ($merchantRecords, $profiles, $evidence, $reviewer) {
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
                    // ══════════════════════════════════════════════════
                    // AMIAL-VERIFY-GOV-001 — **المحافظةُ تُرسَل، وكانت
                    // غائبةً عن البطاقة كلَّها.**
                    //
                    // `kycStatus` يرفض الاعتمادَ بلا محافظةِ سكن (٤٢٢
                    // `MISSING_RESIDENCE_GOVERNORATE`) — **وهي لم تكن
                    // تُعرَض ولا تُضبَط من هذه الشاشة إطلاقاً**. فيضغط
                    // المراجعُ «اعتماد» فيُردّ، ولا يجد باباً يُصلحه منه:
                    // الرسالةُ تحيله إلى «طابور مراجعة الهوية» بلا رابط.
                    //
                    // فصارت تُرسَل ليعرف **قبل** الضغط، ولتُختار في
                    // البطاقة إن غابت. (القاعدة السابعة: الغيابُ يُقال.)
                    // ══════════════════════════════════════════════════
                    'governorate' => \App\Support\YemenGovernorates::codeFromName(
                        (string) ($u->residence_governorate ?: $u->origin_governorate)),
                    'governorate_name' => \App\Support\YemenGovernorates::name(
                        \App\Support\YemenGovernorates::codeFromName(
                            (string) ($u->residence_governorate ?: $u->origin_governorate))),
                    'id_type' => $u->identification_type,
                    'id_number' => $u->identification_number,
                    // ══════════════════════════════════════════════════
                    // AMIAL-KYC-EVIDENCE-001 — **الدليلُ المعروضُ هو
                    // الدليلُ المحكومُ به.**
                    //
                    // كان هنا `identification_image_fullpath` — العمودُ
                    // القديم — **والقرارُ يُحسب من `kyc_documents`**.
                    // فالمراجعُ يرى شيئاً ويقرّر على شيءٍ آخر: يعتمد على
                    // صورةٍ بائدة، أو يقرأ «لا وثائق» ووثائقُ العميل
                    // مرفوعةٌ في السجلّ الحديث لم تُعرَض له.
                    //
                    // **وللأنواع الثلاثة سواء** — عميلاً ووكيلاً وتاجراً:
                    // الوثيقةُ الشخصيّةُ واحدةٌ لكلّ من يملك محفظة.
                    // ══════════════════════════════════════════════════
                    'evidence' => $evidence->for($u, 2, $reviewer),
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
    // ══════════════════════════════════════════════════════════════════
    // **استُعيدت الثلاثةُ بعد أن حُذفت وبقيت مساراتُها تشير إليها.**
    //
    // وهي أفعالُ نافذة «✏️ تعديل» كلُّها: تقرأ الجاهزيّة، وترفع مستنداً،
    // وتحفظ البيانات. فبحذفها كانت النافذةُ تُفتح ولا تمتلئ أبداً — وهو
    // ما وصل بنصّه: «تعديل لم يفتح».
    // ══════════════════════════════════════════════════════════════════

    /**
     * GET hub/users/{id}/readiness.json — **ما الذي ينقص هذا الحسابَ ليعمل.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **سؤالُ صاحب المشروع بنصّه:** «وهل إذا رفعتُ سوف يستقبل ويعمل بكلّ
     * الصلاحيات؟ **ما هذا الغموض**».
     *
     * والجوابُ المقيس: **لا، الرفعُ وحدَه لا يكفي — أربعُ خطواتٍ لا
     * واحدة.** وإن سقطت واحدةٌ بقي الحسابُ مقفلاً **وهو يبدو موثَّقاً**.
     *
     * فبدل أن يُجرَّب الأمرُ ويُنتظَر أثرُه، **تُقال الحالةُ كاملةً قبل
     * الضغط**: ما رُفع، وما اعتُمد، وما ينقص، ولماذا لا يستقبل الآن.
     *
     * ══════════════════════════════════════════════════════════════════
     * **ويُحسب من مصدره لا من عمودٍ مخزَّن** (القاعدة السادسة):
     *
     *   · الاكتمالُ من `completenessFor()` — وهي نفسُها التي يسألها
     *     قرارُ الاعتماد. فشاشةٌ تحسب الاكتمالَ بطريقتها تقول «مكتمل»
     *     ويردّ القرارُ ٤٢٢، أو العكس.
     *   · والمنطقةُ من `cityToZone()` عبر خدمة الإسناد — لا تُخمَّن من
     *     اسم المحافظة.
     *
     * **والمحافظاتُ تُعرَض ومعها منطقتُها** — فمن اختار «صنعاء» يرى قبل
     * الحفظ أنّ الحسابَ سيبقى غيرَ مستقبِل: خارجَ نطاق الخدمة لا ناقصَ
     * وثيقة. (القاعدة السابعة: الغيابُ يُقال ولا يُملأ بصفر.)
     */
    public function accountReadinessJson(int $id): JsonResponse
    {
        $user = User::find($id);
        if (!$user) {
            return response()->json(['message' => 'الحساب غير موجود'], 404);
        }

        $kycService = app(KycDocumentService::class);
        $completeness = $kycService->completenessFor($user, 2);

        // آخرُ مستندٍ من كلّ نوع — فالمرفوعُ ثانيةً يُلغي ما قبله.
        $docs = KycDocument::where('user_id', $user->id)
            ->orderByDesc('id')->get()->groupBy('doc_type');

        $documents = [];
        foreach ((array) $completeness['required'] as $type) {
            $doc = $docs->get($type)?->first();

            $documents[] = [
                'doc_type' => $type,
                'label' => KycDocument::TYPE_LABELS[$type] ?? $type,
                'id' => $doc?->id,
                'status' => $doc?->status,
                // **مرفوعٌ ومعتمَدٌ ومنتهٍ ثلاثُ حالاتٍ لا اثنتان**:
                // معتمَدٌ انتهت وثيقتُه لا يُحتسب، ويقولها `isUsable()`.
                'usable' => $doc !== null && $doc->isUsable(),
                'status_label' => match (true) {
                    $doc === null => 'لم يُرفع',
                    $doc->status === KycDocument::STATUS_APPROVED && $doc->isUsable() => 'معتمَد',
                    $doc->status === KycDocument::STATUS_APPROVED => 'معتمَدٌ ومنتهي الصلاحيّة',
                    $doc->status === KycDocument::STATUS_REJECTED => 'مرفوض',
                    $doc->status === KycDocument::STATUS_SUPERSEDED => 'استُبدل بأحدثَ منه',
                    default => 'مرفوعٌ بانتظار الاعتماد',
                },
            ];
        }

        $zoneService = app(ZoneAssignmentService::class);
        $governorate = trim((string) ($user->residence_governorate ?? ''));
        $zone = (string) ($user->zone_code ?? ZoneAssignmentService::ZONE_UNKNOWN);

        // المنطقةُ التي ستُسنَد لو اعتُمد الحسابُ الآن — تُقاس ولا تُوعَد بها.
        $wouldBeZone = $governorate === ''
            ? ZoneAssignmentService::ZONE_UNKNOWN
            : $zoneService->cityToZone($governorate);

        $governorates = array_map(fn (array $g) => $g + [
            'zone' => $zoneService->cityToZone($g['code']),
        ], \App\Support\YemenGovernorates::all());

        // ══════════════════════════════════════════════════════════════
        // ── ما يمنع الاستقبالَ الآن، بالترتيب الذي يُعالَج به ──
        //
        // **ولكلّ مانعٍ رمزُه إلى جانب نصّه.** والسببُ مقيس: أوّلُ صياغةٍ
        // للحارس فحصت وجودَ كلمة «محافظة» في النصّ، **فأُسكت مانعُ
        // «لا محافظةَ في الملفّ» ومرّ الحارسُ** — لأنّ مانعاً أخاه
        // («خارجَ نطاق الخدمة») يحمل الكلمةَ نفسَها.
        //
        // **وهما تشخيصان مختلفان يقودان إلى فعلين مختلفين**: الأوّلُ
        // يُملأ حقلٌ، والثاني لن يُخدَم الحسابُ أبداً. فخلطُهما يُرسل
        // الموظّفَ يملأ حقلاً لن يفيد، أو يترك حقلاً كان يكفي.
        // ══════════════════════════════════════════════════════════════
        $blockers = [];

        if (!$completeness['complete']) {
            $missing = array_map(
                fn ($t) => KycDocument::TYPE_LABELS[$t] ?? $t,
                (array) $completeness['missing'],
            );
            $blockers[] = ['code' => 'DOCUMENTS_INCOMPLETE', 'text' =>
                'وثائقُ الهويّة ناقصةٌ أو غيرُ معتمَدة: ' . implode('، ', $missing)];
        }

        if ($governorate === '') {
            $blockers[] = ['code' => 'GOVERNORATE_MISSING', 'text' =>
                '**لا محافظةَ سكنٍ في الملفّ** — ولو اعتُمد الحسابُ '
                . 'الآن لبقي خارجَ النطاق: موثَّقٌ ولا يستقبل.'];
        } elseif ($wouldBeZone !== ZoneAssignmentService::ZONE_SOUTH) {
            $blockers[] = ['code' => 'GOVERNORATE_OUT_OF_ZONE', 'text' =>
                'محافظةُ السكن خارجَ نطاق الخدمة الحاليّ — '
                . 'والحسابُ لن يستقبل مهما اكتملت وثائقُه.'];
        }

        if ((int) ($user->is_kyc_verified ?? 0) !== 1) {
            $blockers[] = ['code' => 'ACCOUNT_NOT_VERIFIED', 'text' =>
                'الحسابُ لم يُعتمد بعد — يُعتمد من تبويب «التوثيق» '
                . 'أو من هذه النافذة بعد اكتمال ما فوق.'];
        }

        if ($zone !== ZoneAssignmentService::ZONE_SOUTH) {
            $blockers[] = ['code' => 'ZONE_NOT_OPERATIONAL', 'text' =>
                'المنطقةُ الحاليّة «' . $zone . '» — والاستقبالُ '
                . 'مقصورٌ على نطاق التشغيل.'];
        }

        if ((int) ($user->is_active ?? 0) !== 1) {
            $blockers[] = ['code' => 'ACCOUNT_FROZEN', 'text' =>
                'الحسابُ مجمَّد — ويُفكّ من زرّ «فكّ التجميد».'];
        }

        return response()->json(['data' => [
            'id' => $user->id,
            'name' => trim(($user->f_name ?? '') . ' ' . ($user->l_name ?? '')) ?: '—',
            'phone' => $user->phone,
            'kyc' => (int) ($user->is_kyc_verified ?? 0),
            'is_active' => (int) ($user->is_active ?? 0) === 1,
            'zone_code' => $zone,
            'would_be_zone' => $wouldBeZone,
            'documents' => $documents,
            'doc_types' => array_map(fn ($t) => [
                'value' => $t, 'label' => KycDocument::TYPE_LABELS[$t] ?? $t,
            ], KycDocument::ALL_TYPES),
            'governorates' => $governorates,
            'profile' => collect(self::EDITABLE_PROFILE_FIELDS)
                ->filter(fn ($f) => Schema::hasColumn('users', $f))
                ->mapWithKeys(fn ($f) => [$f => $user->{$f}])
                ->all(),
            // قوائمُ الاختيار تُرسَل من مصدرها — قائمةٌ مكتوبةٌ في الشاشة
            // تشيخ: يُضاف مصدرُ دخلٍ في الجرد ولا يظهر في اللوحة أبداً.
            'income_sources' => KycProfileFields::INCOME_SOURCES,
            'account_purposes' => KycProfileFields::ACCOUNT_PURPOSES,
            // وما ينقص من الحقول الرقابيّة — يُعرَض ولا يمنع الفئةَ الثانية.
            'missing_fields' => (array) ($completeness['missing_fields'] ?? []),
            'blockers' => $blockers,
            'can_receive' => $blockers === [],
        ]]);
    }

    /**
     * POST hub/users/{id}/documents — **رفعُ وثائق الهويّة بالنيابة.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **الثمنُ الذي دُفع، بنصّ صاحب المشروع:** «هذا الحساب لا يستقبل
     * تحويلات بسبب لا يوجد مستندات. دخلتُ للحساب من لوحة الأدمن أردتُ رفعَ
     * مستنداتٍ من أجل يعمل — **لا طريقة للرفع**».
     *
     * وقِيس فكان محقّاً: الرفعُ كان **للعميل وحدَه** من تطبيقه
     * (`POST /kyc/documents`)، واللوحةُ تملك المراجعةَ والاعتمادَ والرفضَ
     * وقراءةَ OCR — **على وثائقَ موجودةٍ سلفاً**. فمن سجّل بلا وثائق
     * يبقى مقفلاً أبداً ما لم يرفع بنفسه.
     *
     * **وسلسلةُ القفل كاملةً، مقيسة:**
     *
     *     لا وثائق ⇒ `decideAccountVerification` ترمي KYC_DOCUMENTS_INCOMPLETE
     *             ⇒ لا اعتماد ⇒ `zone_code` يبقى UNKNOWN
     *             ⇒ `verifyRecipient` تردّ: «حسابُ المستلم لم يُوثَّق بعد»
     *
     * **والخدمةُ كانت مبنيّةً لهذا منذ البداية**: `upload()` تقبل
     * `?User $operator` — أي رفعاً بالنيابة موقَّعاً باسم الموظّف —
     * **ولم يكن يمرّرها أحد**. مبنيٌّ ولا يُوصَل إليه، مرّةً أخرى.
     *
     * ══════════════════════════════════════════════════════════════════
     * **ولا يُعتمد المستندُ هنا تلقائيّاً.** الرفعُ فعلٌ، والاعتمادُ قرارٌ
     * له شاشتُه وسجلُّه (`documents/{id}/approve`). ودمجُهما يجعل موظّفاً
     * يرفع صورةً فيصير الحسابُ موثَّقاً بضغطةٍ واحدةٍ بلا مراجعة — وهو
     * ما بُنيت الرقابةُ لمنعه.
     */
    public function uploadDocument(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'doc_type' => 'required|string',
            'file' => 'required|file|max:8192',
        ]);

        $user = User::findOrFail($id);

        try {
            $doc = app(KycDocumentService::class)->upload(
                user: $user,
                docType: (string) $request->input('doc_type'),
                file: $request->file('file'),
                operator: $request->user(),
            );
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        app(AuditService::class)->record([
            'actor_type' => 'admin',
            'actor_user_id' => $request->user()->id,
            'subject_type' => 'user',
            'subject_id' => (string) $user->id,
            'action' => 'ADMIN_KYC_DOCUMENT_UPLOADED',
            'decision_code' => 'KYC_DOC_UPLOADED_BY_STAFF',
            'reason' => 'رفعُ وثيقةِ هويّةٍ بالنيابة عن صاحب الحساب',
            'context' => ['doc_type' => $doc->doc_type, 'document_id' => $doc->id],
            'severity' => 'warning',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'رُفعت الوثيقة. تبقى مراجعتُها واعتمادُها من شاشة التحقّق.',
            'data' => ['id' => $doc->id, 'doc_type' => $doc->doc_type],
        ]);
    }

    /**
     * POST hub/users/{id}/profile — **تعديلُ بيانات الحساب.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **طلبٌ مكرَّرٌ شهراً:** «أنني أصرخُ من شهر يجب إضافة زرّ لتعديل حساب
     * المستخدم».
     *
     * وليس ترفاً: **محافظةُ السكن هي ما يُشتقّ منه النطاقُ التشغيليّ**
     * عند الاعتماد (`assignFromKyc`). فحسابٌ بلا محافظةٍ يُعتمد ويبقى
     * خارجَ النطاق — أي **موثَّقٌ ولا يعمل**، وهو أسوأُ من غير الموثَّق:
     * صاحبُه يظنّ الأمرَ تمّ.
     *
     * **ولا يُعدَّل ما يُغيّر الهويّة صامتاً:** الهاتفُ مفتاحُ الدخول
     * ومعرّفُ التحويل، وتغييرُه من هنا يُسلّم حساباً بتاريخه الماليّ
     * لشخصٍ آخر. فله مسارُه الخاصّ (استعادةُ الحسابات) بموافقةٍ وسجلّ.
     */
    public function updateProfile(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        $data = $request->validate(self::profileRules(), [
            'residence_governorate.in' => 'تُختار المحافظةُ من القائمة — '
                . 'ونصٌّ حرٌّ يُحفَظ ثمّ يُنتج حساباً موثَّقاً خارجَ النطاق.',
        ]);

        // عمودٌ غيرُ موجودٍ يُسقط الحفظَ كلَّه بخطأ SQL — فيُصفّى بالمخطَّط
        // لا بالثقة. (والصفةُ المفقودةُ تُقال، ولا تُبتلع صامتة.)
        $skipped = [];
        foreach (array_keys($data) as $field) {
            if (! Schema::hasColumn('users', $field)) {
                $skipped[] = $field;
                unset($data[$field]);
            }
        }

        if ($data === []) {
            return response()->json(['message' => $skipped === []
                ? 'لا حقلَ للتعديل'
                : 'لا حقلَ قابلاً للتعديل في هذا المخطَّط: ' . implode('، ', $skipped),
            ], 422);
        }

        $before = $user->only(array_keys($data));

        $user->forceFill($data)->save();

        app(AuditService::class)->record([
            'actor_type' => 'admin',
            'actor_user_id' => $request->user()->id,
            'subject_type' => 'user',
            'subject_id' => (string) $user->id,
            'action' => 'ADMIN_USER_PROFILE_UPDATED',
            'decision_code' => 'USER_PROFILE_UPDATED',
            'reason' => 'تعديلُ بيانات حسابٍ من اللوحة',
            // **قبلُ وبعدُ معاً** — تعديلٌ يُسجَّل بلا قيمته السابقة لا
            // يُراجَع: لا يُعرف ما تغيّر ولا يُستعاد.
            'context' => ['before' => $before, 'after' => $data],
            'severity' => 'warning',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'حُدّثت بيانات الحساب.',
            'data' => $user->only(array_keys($data)),
        ]);
    }
    // ══════════════════════════════════════════════════════════════════
    // **واستُعيد معهما ما تستند إليه**: قائمةُ الحقول القابلة للتعديل
    // وقواعدُ قبولها. فاستعادةُ دالّةٍ بلا ما تناديه تُبدّل ٤٠٤ بـ٥٠٠.
    // ══════════════════════════════════════════════════════════════════

    /**
     * قواعدُ قبول تلك الحقول — **ومفاتيحُها هي القائمةُ نفسُها.**
     *
     * وأُخرجت من جوف الدالّة لسببٍ مقيس: أوّلُ صياغةٍ للحارس قارنت
     * القائمةَ المُعلَنة بنفسِها، **فحذفُ حقلٍ منها مرّ بصمت** — تشيخ
     * القائمةُ وتبقى قاعدةُ القبول، أو العكس. فالمقارنةُ الآن بين
     * **مَن يقبل** ومَن **يُعرَض**، وهما شيئان مختلفان فعلاً.
     *
     * @return array<string, mixed>
     */
    public static function profileRules(): array
    {
        return [
            'f_name' => 'sometimes|nullable|string|max:100',
            'l_name' => 'sometimes|nullable|string|max:100',
            'name_en' => 'sometimes|nullable|string|max:120',
            'father_name' => 'sometimes|nullable|string|max:100',
            'grandfather_name' => 'sometimes|nullable|string|max:100',
            // **والمحافظةُ رمزٌ لا نصٌّ حرّ.** `cityToZone` تطابق الرمزَ
            // أو الاسمَ المعروف، وما لا يُطابَق يخرج `UNKNOWN` — فنصٌّ
            // حرٌّ يُحفَظ ويبدو صحيحاً ثمّ يُنتج حساباً موثَّقاً لا
            // يستقبل. فيُردّ عند بابه لا بعد الاعتماد.
            'residence_governorate' => [
                'sometimes', 'nullable',
                Rule::in(\App\Support\YemenGovernorates::codes()),
            ],
            'residence_district' => 'sometimes|nullable|string|max:120',
            'residence_area' => 'sometimes|nullable|string|max:120',
            'residence_landmark' => 'sometimes|nullable|string|max:160',
            'occupation' => 'sometimes|nullable|string|max:120',
            'gender' => 'sometimes|nullable|in:male,female',
            'income_source' => ['sometimes', 'nullable',
                Rule::in(KycProfileFields::INCOME_SOURCES)],
            'account_purpose' => ['sometimes', 'nullable',
                Rule::in(KycProfileFields::ACCOUNT_PURPOSES)],
        ];
    }

}
