<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\CustomerActionService;
use App\Services\CustomerCenterService;
use App\Services\PiiAccessAuditService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-CUSTOMER-CENTER-001 — مركز العملاء (الفصل ٠٢).
 *
 * تبويبٌ لكلّ نقطة نهاية — والوثيقة تشترط فتح الملفّ في ≤ ثانية. وتجميعُ
 * العشرة في ردٍّ واحد يجعل زمن الفتح مجموعَ أبطئها.
 */
class CustomerCenterController extends Controller
{
    /** كل تبويب يكشف بيانات مستقلة، فلا تكفي صلاحية فتح المركز وحدها. */
    private const TAB_PERMISSIONS = [
        'overview' => 'platform.customers.view',
        'wallets' => 'platform.customers.wallets.view',
        'transactions' => 'platform.transactions.view',
        'devices' => 'platform.customers.security.view',
        'authentication' => 'platform.customers.security.view',
        'kyc' => 'platform.customers.kyc.view',
        'risk' => 'platform.audit.view',
        'support' => 'platform.tickets.manage',
        'notifications' => 'platform.customers.notifications.view',
        'audit' => 'platform.audit.view',
    ];

    public function __construct(
        private readonly CustomerCenterService $center,
        private readonly CustomerActionService $actions,
    ) {
    }

    public function page(Request $request)
    {
        $actor = $request->user();
        $tabs = collect(self::TAB_PERMISSIONS)
            ->filter(fn (string $permission) => $actor->hasPlatformPermission($permission))
            ->keys()->values()->all();
        $actions = collect(CustomerActionService::ACTIONS)
            ->filter(fn (array $action) => $actor->hasPlatformPermission($action[1]))
            ->map(fn (array $action, string $code) => ['code' => $code, 'label' => $action[0]])
            ->values()->all();

        return view('admin-views.amial.customer.index', compact('tabs', 'actions'));
    }

    /**
     * سجل بحث مركز العملاء (customer-only) بمفاتيح موجودة ومثبتة فقط:
     * هاتف، رقم حساب، معرّف محفظة، اسم، بريد، هوية (لصاحب صلاحية PII)،
     * أو مرجع عملية. أرقام التاجر/الوكيل وQR ليست مفاتيح لهذا المركز؛
     * نسبتها إليه كانت ادعاءً لا يسنده مسار أو نموذج.
     */
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        if (mb_strlen($q) < 2) {
            return $this->ok(['items' => []]);
        }

        // البحث نفسه يُسجَّل — تشترطه الوثيقة: «يسجل النظام تلقائياً جميع
        // عمليات البحث». وبحثُ موظّفٍ عن أسماء لا علاقة لها بعمله أوّل ما
        // يظهر في مراجعة الأمن الداخليّ.
        app(PiiAccessAuditService::class)->logAccess(
            actorUserId: (int) $request->user()->id,
            subjectType: 'search',
            subjectId: 0,
            fieldName: 'customer_search',
            accessType: 'search',
            // لا يصبح سجل الحماية نسخةً أخرى من رقم الهاتف أو الهوية.
            accessReason: $this->safeSearchAuditReason($q),
        );

        $users = $this->searchCustomers($q, $request->user());

        return $this->ok([
            'items' => $users->map(fn (User $u) => [
                'id' => (int) $u->id,
                'name' => trim((string) ($u->f_name . ' ' . $u->l_name)) ?: '—',
                'phone' => $request->user()->hasPlatformPermission('platform.customers.pii.reveal')
                    ? (string) $u->phone : $this->maskPhone($u->phone),
                'type' => (int) $u->type,
                'is_frozen' => (int) ($u->is_temp_blocked ?? 0) === 1,
                // 2 = مرفوض و3 = لم يقدّم؛ كلاهما ليس توثيقاً.
                'is_kyc_verified' => (int) ($u->is_kyc_verified ?? 0) === 1,
            ])->all(),
        ]);
    }

    private function maskPhone(?string $phone): string
    {
        $value = trim((string) $phone);
        if ($value === '') return '—';
        return mb_substr($value, 0, 3) . '••••' . mb_substr($value, -2);
    }

    /** @return \Illuminate\Support\Collection<int,User> */
    private function searchCustomers(string $query, User $actor)
    {
        $columns = ['id', 'f_name', 'l_name', 'phone', 'type', 'is_temp_blocked', 'is_kyc_verified'];
        $digits = preg_replace('/\D+/', '', $query);
        $base = fn () => User::query()->where('type', CUSTOMER_TYPE);

        // المعرّفات الفريدة تُفحَص exact أولاً؛ لا نوسّعها إلى LIKE بطيء
        // أو قد يخلط حسابين متشابهين.
        if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'account_number')) {
            $found = $base()->where('account_number', $query)->get($columns);
            if ($found->isNotEmpty()) return $found;
        }

        if ($actor->hasPlatformPermission('platform.customers.pii.reveal')
            && \Illuminate\Support\Facades\Schema::hasColumn('users', 'national_id_blind_index')) {
            $found = $base()->whereNationalId($query)->get($columns);
            if ($found->isNotEmpty()) return $found;
        }

        if (\Illuminate\Support\Facades\Schema::hasTable('e_money') && ctype_digit((string) $digits)) {
            $ownerId = DB::table('e_money')->where('id', (int) $digits)->value('user_id');
            if ($ownerId) {
                $found = $base()->whereKey($ownerId)->get($columns);
                if ($found->isNotEmpty()) return $found;
            }
        }

        if (\Illuminate\Support\Facades\Schema::hasTable('transactions')) {
            $ownerId = DB::table('transactions')->where('transaction_id', $query)->value('user_id');
            if ($ownerId) {
                $found = $base()->whereKey($ownerId)->get($columns);
                if ($found->isNotEmpty()) return $found;
            }
        }

        return $base()->where(function ($w) use ($query, $digits) {
            $w->where('f_name', 'like', "%{$query}%")
                ->orWhere('l_name', 'like', "%{$query}%")
                ->orWhere('email', 'like', "%{$query}%");

            if ($digits !== '') {
                // يحفظ البحث بالصيَغ المحلية و+967 دون أن نفترض أن كل رقم
                // هو phone كامل أو أن name search يحتاج مسح جدول كامل.
                $w->orWhere('phone', 'like', "%{$digits}%")->orWhere('id', (int) $digits);
            }
        })->limit(25)->get($columns);
    }

    public function tab(Request $request, int $id, string $tab): JsonResponse
    {
        $permission = self::TAB_PERMISSIONS[$tab] ?? null;
        if ($permission === null) {
            return $this->error('تبويب غير معروف', 404);
        }
        if (!$request->user()->hasPlatformPermission($permission)) {
            return $this->error('لا تملك صلاحية هذا التبويب', 403);
        }

        $customer = User::where('type', CUSTOMER_TYPE)->find($id);
        if (!$customer) {
            return $this->error('العميل غير موجود', 404);
        }

        $actorId = (int) $request->user()->id;

        $data = match ($tab) {
            'overview' => $this->center->overview($customer, $actorId),
            'wallets' => $this->center->wallets($customer, $actorId),
            'transactions' => $this->center->transactions($customer, $actorId, $request->only(['type', 'from', 'to'])),
            'devices' => $this->center->devices($customer, $actorId),
            'authentication' => $this->center->authentication($customer, $actorId),
            'kyc' => $this->center->kyc($customer, $actorId),
            'risk' => $this->center->risk($customer, $actorId),
            'support' => $this->center->support($customer, $actorId),
            'notifications' => $this->center->notifications($customer, $actorId),
            'audit' => $this->center->audit($customer, $actorId),
            default => null,
        };

        if ($data === null) {
            return $this->error('تبويب غير معروف', 404);
        }

        return $this->ok($data);
    }

    public function act(Request $request, int $id): JsonResponse
    {
        $customer = User::where('type', CUSTOMER_TYPE)->find($id);
        if (!$customer) {
            return $this->error('العميل غير موجود', 404);
        }

        try {
            $out = $this->actions->run(
                $customer,
                $request->user(),
                (string) $request->input('action'),
                (string) $request->input('reason', ''),
                (array) $request->input('payload', []),
            );
        } catch (DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->ok($out, $out['message']);
    }

    private function ok(array $meta, string $message = 'OK'): JsonResponse
    {
        return new JsonResponse([
            'success' => true, 'code' => 'OK', 'message' => $message,
            'errors' => (object) [], 'meta' => $meta,
        ]);
    }

    private function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'success' => false, 'code' => 'ERROR', 'message' => $message,
            'errors' => (object) [], 'meta' => (object) [],
        ], $status);
    }

    private function safeSearchAuditReason(string $query): string
    {
        $normalized = mb_strtolower(trim($query));
        $kind = filter_var($normalized, FILTER_VALIDATE_EMAIL)
            ? 'email'
            : (preg_match('/^[+()\\s\\-\\d]{7,}$/u', $normalized) ? 'phone_or_identifier' : 'name_or_reference');

        return sprintf(
            'بحث في مركز العملاء: %s [fingerprint:%s]',
            $kind,
            substr(hash('sha256', $normalized), 0, 16),
        );
    }
}
