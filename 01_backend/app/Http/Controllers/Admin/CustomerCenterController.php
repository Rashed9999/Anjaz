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
        'wallets' => 'platform.customers.view',
        'transactions' => 'platform.transactions.view',
        'devices' => 'platform.customers.sessions',
        'authentication' => 'platform.customers.sessions',
        'kyc' => 'platform.approvals.decide',
        'risk' => 'platform.audit.view',
        'support' => 'platform.tickets.manage',
        'notifications' => 'platform.customers.view',
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
     * بحثٌ بعشرة مفاتيح — والوثيقة تعدّها كلّها: الهاتف، ورقم الحساب،
     * والمحفظة، والاسم، والبريد، ورقم الهوية، وQR، ورقم العملية، والتاجر،
     * والوكيل.
     *
     * والشرط ≤ ٥٠٠ms: فيُحدّ الناتج، ويُبحث في الأعمدة المفهرسة أوّلاً.
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

        $digits = preg_replace('/\D+/', '', $q);

        $users = User::query()->where('type', CUSTOMER_TYPE)
            ->where(function ($w) use ($q, $digits) {
                $w->where('phone', 'like', "%{$q}%")
                    ->orWhere('f_name', 'like', "%{$q}%")
                    ->orWhere('l_name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");

                if ($digits !== '') {
                    $w->orWhere('id', (int) $digits)->orWhere('phone', 'like', "%{$digits}%");
                }
            })
            ->limit(25)
            ->get(['id', 'f_name', 'l_name', 'phone', 'type', 'is_temp_blocked', 'is_kyc_verified']);

        // رقم عملية: يُترجَم إلى صاحبها بدل أن يُردّ «لا نتائج».
        if ($users->isEmpty() && \Illuminate\Support\Facades\Schema::hasTable('transactions')) {
            $ownerId = DB::table('transactions')->where('transaction_id', $q)->value('user_id');
            if ($ownerId) {
                $users = User::where('id', $ownerId)->where('type', CUSTOMER_TYPE)
                    ->get(['id', 'f_name', 'l_name', 'phone', 'type', 'is_temp_blocked', 'is_kyc_verified']);
            }
        }

        return $this->ok([
            'items' => $users->map(fn (User $u) => [
                'id' => (int) $u->id,
                'name' => trim((string) ($u->f_name . ' ' . $u->l_name)) ?: '—',
                'phone' => (string) $u->phone,
                'type' => (int) $u->type,
                'is_frozen' => (int) ($u->is_temp_blocked ?? 0) === 1,
                'is_kyc_verified' => (bool) ($u->is_kyc_verified ?? false),
            ])->all(),
        ]);
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
        $customer = User::find($id);
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
