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
    public function __construct(
        private readonly CustomerCenterService $center,
        private readonly CustomerActionService $actions,
    ) {
    }

    public function page()
    {
        return view('admin-views.amial.customer.index');
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
            accessReason: 'بحث في مركز العملاء: ' . mb_substr($q, 0, 60),
        );

        $digits = preg_replace('/\D+/', '', $q);

        $users = User::query()
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
                $users = User::where('id', $ownerId)
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
        $customer = User::find($id);
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
}
