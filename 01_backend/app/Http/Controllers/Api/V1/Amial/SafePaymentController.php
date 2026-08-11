<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\SafePayment;
use App\Models\User;
use App\Services\SafePaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-SAFE-PAYMENT-001 (v1.1) — customer endpoints
 */
class SafePaymentController extends AmialApiController // AMIAL-FIX-007
{
    public function __construct(
        private readonly SafePaymentService $service,
    ) {}

    /** GET /api/v1/amial/safe-payments — قائمة (as buyer or seller) */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $role = $request->query('role', 'all'); // all|buyer|seller
        $status = $request->query('status'); // optional filter

        $query = SafePayment::query()->orderByDesc('id');
        if ($role === 'buyer') {
            $query->asBuyer($user->id);
        } elseif ($role === 'seller') {
            $query->asSeller($user->id);
        } else {
            $query->forUser($user->id);
        }
        if ($status) {
            $query->where('status', $status);
        }

        $items = $query->with(['buyer:id,f_name,l_name,phone', 'seller:id,f_name,l_name,phone'])
            ->paginate(20);

        return $this->ok([
            'pagination' => [
                'total' => $items->total(),
                'per_page' => $items->perPage(),
                'current_page' => $items->currentPage(),
            ],
            'items' => $items->items(),
        ]);
    }

    /** GET /api/v1/amial/safe-payments/{ulid} — تفاصيل */
    public function show(Request $request, string $ulid): JsonResponse
    {
        $payment = SafePayment::where('payment_ulid', $ulid)->first();
        if (!$payment) return $this->error('NOT_FOUND', 'الدفعة الآمنة غير موجودة', 404);

        $user = $request->user();
        if ($payment->buyer_user_id !== $user->id && $payment->seller_user_id !== $user->id) {
            return $this->error('FORBIDDEN', 'لستَ طرفاً في هذه الدفعة', 403);
        }

        $payment->load([
            'buyer:id,f_name,l_name,phone',
            'seller:id,f_name,l_name,phone',
            'events' => fn($q) => $q->orderBy('created_at'),
        ]);

        $isBuyer = $payment->buyer_user_id === $user->id;

        return $this->ok([
            'payment' => $payment,
            'your_role' => $isBuyer ? 'buyer' : 'seller',
            'can_actions' => $this->resolveAvailableActions($payment, $user),
            // AMIAL-SAFEPAY-CODE-001: الرمز للمشتري وحده — هو من يسلّمه
            // للبائع لحظة الاستلام. إظهاره للبائع يُبطل الغرض منه كلّه.
            'delivery_code' => $isBuyer && $payment->delivery_code_verified_at === null
                ? $this->service->plainDeliveryCode($payment)
                : null,
            'delivery_code_verified' => $payment->delivery_code_verified_at !== null,
            // AMIAL-SAFEPAY-TRUST-001: سجلّ الطرف المقابل
            // سجلّ الطرف المقابل في الدور الذي يلعبه هنا: المشتري يرى سجلّ
            // خصمه بائعاً، والبائع يرى سجلّ خصمه مشترياً. خلطُ الدورين يُظهر
            // «صفر صفقات» لبائع مخضرم لم يشترِ قطّ.
            'counterparty_trust' => $isBuyer
                ? $this->trustSummary((int) $payment->seller_user_id, 'seller')
                : $this->trustSummary((int) $payment->buyer_user_id, 'buyer'),
            'evidence' => app(\App\Services\SafePaymentEvidenceService::class)->timeline($payment),
        ]);
    }

    /** POST /api/v1/amial/safe-payments — إنشاء + دفع */
    public function create(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'seller_phone' => 'required|string|min:6|max:20',
            'title' => 'required|string|min:3|max:200',
            'description' => 'required|string|min:10|max:5000',
            'amount' => 'required|numeric|min:1',
            'delivery_terms' => 'sometimes|nullable|string|max:5000',
            'attachments' => 'sometimes|nullable|array|max:5',
        ]);
        if ($v->fails()) return $this->validationError($v);

        // AMIAL-FIX: مطابقة صيغ الهاتف المكافئة (777… ↔ 967777…) كي يُقبل أي صيغة
        $seller = User::whereIn('phone', \App\Support\Phone::variants($request->input('seller_phone')))->first();
        if (!$seller) return $this->error('SELLER_NOT_FOUND', 'البائع غير مسجل في النظام', 422);

        try {
            $payment = $this->service->createAndFund(
                buyer: $request->user(),
                seller: $seller,
                title: $request->input('title'),
                description: $request->input('description'),
                amount: (string)$request->input('amount'),
                deliveryTerms: $request->input('delivery_terms'),
                attachments: $request->input('attachments'),
            );
        } catch (\App\Exceptions\InsufficientBalanceException $e) {
            return new JsonResponse($e->toApiArray(), 402);
        } catch (\RuntimeException $e) {
            return $this->error('CREATE_FAILED', $e->getMessage(), 422);
        }

        return $this->ok(['payment' => $payment], 'SAFE_PAYMENT_CREATED', 'تم إنشاء الطلب وحجز المبلغ', 201);
    }

    /** POST /api/v1/amial/safe-payments/{ulid}/seller-accept */
    public function sellerAccept(Request $request, string $ulid): JsonResponse
    {
        return $this->execAction($ulid, $request->user(), 'seller',
            fn($p, $u) => $this->service->sellerAccept($p, $u, $request->input('note')),
            'SAFE_PAYMENT_ACCEPTED', 'تم قبول الطلب'
        );
    }

    public function sellerReject(Request $request, string $ulid): JsonResponse
    {
        $v = Validator::make($request->all(), ['reason' => 'required|string|min:5|max:500']);
        if ($v->fails()) return $this->validationError($v);

        return $this->execAction($ulid, $request->user(), 'seller',
            fn($p, $u) => $this->service->sellerReject($p, $u, $request->input('reason')),
            'SAFE_PAYMENT_REJECTED', 'تم رفض الطلب، استرداد المشتري'
        );
    }

    public function sellerMarkInDelivery(Request $request, string $ulid): JsonResponse
    {
        return $this->execAction($ulid, $request->user(), 'seller',
            fn($p, $u) => $this->service->sellerMarkInDelivery($p, $u, $request->input('note')),
            'SAFE_PAYMENT_IN_DELIVERY', 'تم تأكيد بدء التسليم'
        );
    }

    public function sellerMarkDelivered(Request $request, string $ulid): JsonResponse
    {
        return $this->execAction($ulid, $request->user(), 'seller',
            fn($p, $u) => $this->service->sellerMarkDelivered($p, $u, $request->input('note')),
            'SAFE_PAYMENT_DELIVERED', 'تم تأكيد التسليم — في انتظار تأكيد المشتري'
        );
    }

    public function buyerConfirm(Request $request, string $ulid): JsonResponse
    {
        return $this->execAction($ulid, $request->user(), 'buyer',
            fn($p, $u) => $this->service->buyerConfirm($p, $u),
            'SAFE_PAYMENT_RELEASED', 'تم تأكيد الاستلام وإفراج المبلغ للبائع'
        );
    }

    public function buyerCancel(Request $request, string $ulid): JsonResponse
    {
        $v = Validator::make($request->all(), ['reason' => 'required|string|min:5|max:500']);
        if ($v->fails()) return $this->validationError($v);

        return $this->execAction($ulid, $request->user(), 'buyer',
            fn($p, $u) => $this->service->buyerCancel($p, $u, $request->input('reason')),
            'SAFE_PAYMENT_CANCELLED', 'تم إلغاء الطلب واسترداد المبلغ'
        );
    }

    public function buyerDispute(Request $request, string $ulid): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'reason' => 'required|string|min:10|max:5000',
            'attachments' => 'sometimes|nullable|array|max:5',
            // AMIAL-SAFEPAY-DISPUTE-001: تصنيف يسرّع القرار ويسمح بالإحصاء
            'reason_code' => 'sometimes|nullable|string|in:'
                . implode(',', array_keys(\App\Services\SafePaymentService::DISPUTE_REASONS)),
        ]);
        if ($v->fails()) return $this->validationError($v);

        return $this->execAction($ulid, $request->user(), 'buyer',
            fn($p, $u) => $this->service->buyerDispute(
                $p, $u, $request->input('reason'),
                $request->input('attachments'), $request->input('reason_code')
            ),
            'SAFE_PAYMENT_DISPUTED', 'تم فتح نزاع — ستراجع الإدارة الطلب'
        );
    }

    /**
     * GET /safe-payments/dispute-reasons — قائمة الأسباب المنظّمة.
     * تُقرأ من الخادم لا تُطبع في التطبيق: إضافة سبب لا تستحقّ إصداراً.
     */
    public function disputeReasons(): JsonResponse
    {
        $reasons = [];
        foreach (\App\Services\SafePaymentService::DISPUTE_REASONS as $code => $label) {
            $reasons[] = ['code' => $code, 'label' => $label];
        }

        return new JsonResponse(['success' => true, 'data' => $reasons]);
    }

    /**
     * POST /safe-payments/{ulid}/evidence — رفع أدلّة (ملفات حقيقية).
     *
     * الطرفان معاً: البائع يُثبت الشحن والتسليم، والمشتري يُثبت دعواه.
     * كان المشتري وحده يستطيع الإرفاق — والبائع بلا وسيلة إثبات إطلاقاً.
     */
    public function uploadEvidence(Request $request, string $ulid): JsonResponse
    {
        $payment = SafePayment::where('payment_ulid', $ulid)->first();
        if (!$payment) return $this->error('NOT_FOUND', 'الدفعة الآمنة غير موجودة', 404);

        $user = $request->user();
        $role = match ((int) $user->id) {
            (int) $payment->buyer_user_id => 'buyer',
            (int) $payment->seller_user_id => 'seller',
            default => null,
        };
        if ($role === null) {
            return $this->error('FORBIDDEN', 'ليست عمليتك', 403);
        }

        $v = Validator::make($request->all(), [
            'stage' => 'required|string|in:' . implode(',', \App\Services\SafePaymentEvidenceService::STAGES),
            'files' => 'required|array|min:1|max:5',
            'files.*' => 'required|file|max:8192|mimes:jpg,jpeg,png,webp,pdf',
            'note' => 'sometimes|nullable|string|max:1000',
        ]);
        if ($v->fails()) return $this->validationError($v);

        try {
            $result = app(\App\Services\SafePaymentEvidenceService::class)->store(
                $payment, $user, $role,
                $request->input('stage'),
                $request->file('files'),
                $request->input('note'),
            );
        } catch (\RuntimeException $e) {
            return $this->error('EVIDENCE_REJECTED', $e->getMessage(), 422);
        }

        return new JsonResponse([
            'success' => true,
            'code' => 'EVIDENCE_STORED',
            'message' => 'رُفعت ' . $result['stored'] . ' من الأدلّة',
            'errors' => (object) [],
            'meta' => $result['evidence'],
        ]);
    }

    /** GET /safe-payments/{ulid}/evidence — أدلّة الطرفين مجموعةً بالمرحلة. */
    public function listEvidence(Request $request, string $ulid): JsonResponse
    {
        $payment = SafePayment::where('payment_ulid', $ulid)->first();
        if (!$payment) return $this->error('NOT_FOUND', 'الدفعة الآمنة غير موجودة', 404);

        $uid = (int) $request->user()->id;
        if ($uid !== (int) $payment->buyer_user_id && $uid !== (int) $payment->seller_user_id) {
            return $this->error('FORBIDDEN', 'ليست عمليتك', 403);
        }

        // الطرفان يريان أدلّة بعضهما: الشفافية تُنهي نزاعات كثيرة قبل
        // وصولها للإدارة، ومن يرى دليل خصمه يعرف موقفه فيتراجع أو يوضّح.
        return new JsonResponse([
            'success' => true,
            'data' => app(\App\Services\SafePaymentEvidenceService::class)->timeline($payment),
        ]);
    }

    /** GET /safe-payments/evidence/{id}/file — الملفّ نفسه لمن له حقّ. */
    public function evidenceFile(Request $request, int $id)
    {
        $evidence = \App\Models\SafePaymentEvidence::find($id);
        if (!$evidence) return $this->error('NOT_FOUND', 'الدليل غير موجود', 404);

        $payment = SafePayment::find($evidence->safe_payment_id);
        $uid = (int) $request->user()->id;
        if (!$payment
            || ($uid !== (int) $payment->buyer_user_id && $uid !== (int) $payment->seller_user_id)) {
            return $this->error('FORBIDDEN', 'ليست عمليتك', 403);
        }

        $svc = app(\App\Services\SafePaymentEvidenceService::class);
        $contents = $svc->read($evidence);
        if ($contents === null) {
            return $this->error('FILE_MISSING', 'تعذّر قراءة الملفّ', 404);
        }

        return response($contents, 200, [
            'Content-Type' => $evidence->mime,
            'Content-Length' => (string) strlen($contents),
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /**
     * POST /safe-payments/{ulid}/verify-delivery — البائع يؤكّد بالرمز.
     *
     * يُنهي نزاع «سلّمتُ / لم يصلني» من أصله: الرمز يملكه المشتري وحده
     * ولا يعطيه إلا وقد استلم.
     */
    public function verifyDelivery(Request $request, string $ulid): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'code' => 'required|string|min:4|max:12',
        ]);
        if ($v->fails()) return $this->validationError($v);

        return $this->execAction($ulid, $request->user(), 'seller',
            fn($p, $u) => $this->service->verifyDeliveryCode($p, $u, $request->input('code')),
            'DELIVERY_CONFIRMED', 'تم تأكيد التسليم برمز المشتري'
        );
    }

    /**
     * AMIAL-SAFEPAY-TRUST-001 — سجلّ الطرف المقابل في الدفع الآمن.
     *
     * أرخص وسيلة لخفض النزاعات ليست حسمها بعد وقوعها بل منعها قبلها:
     * المشتري الذي يرى «أتمّ 14 صفقة، نزاع واحد، عضو منذ 8 أشهر» يقرّر
     * على بيّنة. والبائع الذي يعرف أن سجلّه معروض يحرص عليه.
     *
     * أرقام مجرّدة بلا أسماء ولا مبالغ — تكفي للحكم ولا تكشف تعاملات أحد.
     */
    private function trustSummary(int $userId, string $role = 'seller'): array
    {
        $column = $role === 'buyer' ? 'buyer_user_id' : 'seller_user_id';
        $base = SafePayment::where($column, $userId);

        // الحالة النهائية للصفقة الناجحة اسمها released_to_seller لا released.
        // كان الشرط يطابق قيمة غير موجودة أصلاً، فكان «أتمّ» صفراً دائماً
        // ووسام «موثوق» غير قابل للبلوغ مهما بلغ سجلّ البائع.
        $completed = (clone $base)->where('status', 'released_to_seller')->count();
        $disputed = (clone $base)->where('is_disputed', true)->count();
        $total = (clone $base)->count();
        $user = User::find($userId);

        return [
            'role' => $role,
            'completed_deals' => $completed,
            'disputed_deals' => $disputed,
            'total_deals' => $total,
            'dispute_rate' => $total > 0 ? round($disputed / $total * 100, 1) : 0.0,
            'member_since' => optional($user?->created_at)->format('Y-m'),
            // تصنيف مفهوم بدل أرقام يفسّرها كل أحد بطريقته.
            'badge' => match (true) {
                $total === 0 => 'جديد',
                $disputed === 0 && $completed >= 10 => 'موثوق',
                $total > 0 && ($disputed / $total) > 0.25 => 'نزاعات متكرّرة',
                default => 'عادي',
            },
        ];
    }

    // ============================================================
    // Helpers
    // ============================================================

    private function execAction(
        string $ulid, User $user, string $role,
        \Closure $action, string $okCode, string $okMessage,
    ): JsonResponse {
        $payment = SafePayment::where('payment_ulid', $ulid)->first();
        if (!$payment) return $this->error('NOT_FOUND', 'الدفعة الآمنة غير موجودة', 404);

        $partyId = $role === 'buyer' ? $payment->buyer_user_id : $payment->seller_user_id;
        if ($partyId !== $user->id) {
            return $this->error('FORBIDDEN', "Action allowed only for the {$role}", 403);
        }

        try {
            $updated = $action($payment, $user);
        } catch (\App\Exceptions\InsufficientBalanceException $e) {
            return new JsonResponse($e->toApiArray(), 402);
        } catch (\RuntimeException $e) {
            return $this->error('ACTION_FAILED', $e->getMessage(), 422);
        }

        return $this->ok(['payment' => $updated], $okCode, $okMessage);
    }

    private function resolveAvailableActions(SafePayment $payment, User $user): array
    {
        $isBuyer = $payment->buyer_user_id === $user->id;
        $isSeller = $payment->seller_user_id === $user->id;

        return [
            'seller_accept' => $isSeller && $payment->canSellerRespond(),
            'seller_reject' => $isSeller && $payment->canSellerRespond(),
            'seller_mark_in_delivery' => $isSeller && $payment->canSellerMarkInDelivery(),
            'seller_mark_delivered' => $isSeller && $payment->canSellerMarkDelivered(),
            'buyer_confirm' => $isBuyer && $payment->canBuyerConfirm(),
            'buyer_cancel' => $isBuyer && $payment->canBuyerCancel(),
            'buyer_dispute' => $isBuyer && $payment->canBuyerDispute(),
        ];
    }
}
