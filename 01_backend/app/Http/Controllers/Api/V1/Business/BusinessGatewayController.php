<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Controller;
use App\Models\BusinessGateway\BusinessLink;
use App\Services\BusinessGateway\BusinessGatewayException;
use App\Services\BusinessGateway\BusinessLinkService;
use App\Services\BusinessGateway\BusinessCustomerDebtService;
use App\Services\BusinessGateway\BusinessPaymentIntentService;
use App\Services\BusinessGateway\BusinessRefundService;
use App\Services\BusinessGateway\CreditHandoverService;
use App\Services\BusinessGateway\CustomerIdentity;
use App\Services\PaymentRequestService;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-BUSINESS-GATEWAY-001 — نقاط أميال أعمال (خادمٌ لخادم).
 *
 * المصادقة في VerifyBusinessGatewaySignature، والرابط يأتي منه لا من الطلب.
 * الردّ بالغلاف القائم: success · code · message · errors · meta.
 */
class BusinessGatewayController extends Controller
{
    public function __construct(
        private readonly BusinessLinkService $links,
        private readonly BusinessPaymentIntentService $intents,
    ) {}

    public function link(Request $request): JsonResponse
    {
        return $this->ok($this->links->summary($this->resolveLink($request)));
    }

    public function createIntent(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            // البادئة APP- محجوزة لنوايا يُنشئها العميل من تطبيقه.
            'business_reference' => ['required', 'string', 'regex:/^[A-Za-z0-9_.:-]{1,64}$/', 'not_regex:/^APP-/i'],
            // نصٌّ لا رقمٌ عشريّ: لا يمرّ مبلغٌ عبر float في أي مرحلة.
            'amount' => ['required', 'string', 'regex:/^\d{1,15}(\.\d{1,2})?$/'],
            'currency' => ['required', 'string', 'size:3'],
            'description' => ['nullable', 'string', 'max:255'],
            'branch_ref' => ['nullable', 'string', 'max:64'],
            'device_ref' => ['nullable', 'string', 'max:64'],
            'expires_in_seconds' => ['nullable', 'integer'],
            'purpose' => ['nullable', 'string', 'in:sale,debt_collection'],
            'contact_ref' => ['nullable', 'required_if:purpose,debt_collection', 'string', 'regex:/^[A-Za-z0-9_.:-]{1,64}$/'],
            'payer_phone' => ['nullable', 'string', 'regex:/^\+?[0-9]{7,15}$/'],
        ]);
        if ($v->fails()) {
            return $this->validationError($v);
        }

        return $this->guard(function () use ($request) {
            $intent = $this->intents->create(
                $this->resolveLink($request),
                (string) $request->input('business_reference'),
                (string) $request->input('amount'),
                (string) $request->input('currency'),
                $request->input('description'),
                $request->input('branch_ref'),
                $request->input('device_ref'),
                $request->filled('expires_in_seconds') ? (int) $request->input('expires_in_seconds') : null,
                purpose: (string) ($request->input('purpose') ?: 'sale'),
                contactRef: $request->input('contact_ref'),
                payerPhone: $request->input('payer_phone'),
            );

            return $this->ok($this->intents->present($intent), 'INTENT_READY', 'رمز الدفع جاهز', 201);
        });
    }

    public function showIntent(Request $request, string $intent): JsonResponse
    {
        return $this->guard(fn () => $this->ok(
            $this->intents->present($this->intents->find($this->resolveLink($request), $intent))
        ));
    }

    public function cancelIntent(Request $request, string $intent): JsonResponse
    {
        return $this->guard(fn () => $this->ok(
            $this->intents->present($this->intents->cancel($this->resolveLink($request), $intent)),
            'INTENT_CANCELLED',
            'أُلغيت نيّة الدفع',
        ));
    }

    /** AMIAL-BUSINESS-MIGRATION-001 — ملخص بيانات التاجر للترحيل (قراءة فقط). */
    public function migrationSummary(Request $request, \App\Services\BusinessGateway\BusinessMigrationExport $export): JsonResponse
    {
        return $this->ok($export->summary($this->resolveLink($request)));
    }

    public function migrationPage(Request $request, string $section, \App\Services\BusinessGateway\BusinessMigrationExport $export): JsonResponse
    {
        return $this->guard(fn () => $this->ok($export->page($this->resolveLink($request), $section, $request->query('cursor'))));
    }

    /**
     * AMIAL-BUSINESS-CREDIT-001 — أميال أعمال تتسلّم دفتر الآجل (قبل تثبيت استيرادها).
     * بعده يرفض أميال باي أي حركة آجل لهذا التاجر.
     */
    public function creditHandover(Request $request, CreditHandoverService $handover): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'reference' => ['required', 'string', 'regex:/^[A-Za-z0-9_.:-]{1,64}$/'],
            'credit_fingerprint' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],
        ]);
        if ($v->fails()) {
            return $this->validationError($v);
        }

        return $this->guard(function () use ($request, $handover) {
            $result = $handover->handover(
                $this->resolveLink($request),
                (string) $request->input('reference'),
                (string) $request->input('credit_fingerprint'),
            );

            return $this->ok($result, 'CREDIT_HANDED_OVER', 'صارت ديون التاجر تُدار في أميال أعمال');
        });
    }

    /** مرآة دين عميل: أحدث نسخة تفوز، والأقدم تُتجاهل بلا خطأ. */
    public function upsertCustomerDebt(Request $request, string $contactRef, BusinessCustomerDebtService $debts): JsonResponse
    {
        $money = ['required', 'string', 'regex:/^-?\d{1,15}(\.\d{1,4})?$/'];
        $v = Validator::make($request->all(), [
            'customer_name' => ['required', 'string', 'max:191'],
            'customer_phone' => ['nullable', 'string', 'regex:/^\+?[0-9]{7,15}$/'],
            'balance' => $money,
            'overdue_amount' => $money,
            'next_due_date' => ['nullable', 'date_format:Y-m-d'],
            'reminders_enabled' => ['required', 'boolean'],
            'version' => ['required', 'integer', 'min:1'],
            'as_of' => ['required', 'date'],
        ]);
        if ($v->fails()) {
            return $this->validationError($v);
        }
        if (!preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $contactRef)) {
            return $this->error('CONTACT_REF_INVALID', 'مرجع العميل غير صالح', 422);
        }

        $result = $debts->upsert($this->resolveLink($request), $contactRef, [
            'customer_name' => (string) $request->input('customer_name'),
            'customer_phone' => $request->input('customer_phone'),
            'balance' => (string) $request->input('balance'),
            'overdue_amount' => (string) $request->input('overdue_amount'),
            'next_due_date' => $request->input('next_due_date'),
            'reminders_enabled' => $request->boolean('reminders_enabled'),
            'version' => (int) $request->input('version'),
            'as_of' => (string) $request->input('as_of'),
        ]);

        return $this->ok([
            'applied' => $result['applied'],
            'version' => (int) $result['debt']->version,
            'customer_linked' => $result['debt']->customer_user_id !== null,
        ], $result['applied'] ? 'DEBT_MIRRORED' : 'DEBT_VERSION_STALE');
    }

    /**
     * يتأكد الكاشير من صاحب الرقم قبل ربط العميل أو إرسال طلب لتطبيقه.
     * اسمٌ مقنّع فقط، وبحدٍّ أضيق من بقية القناة (لا تعداد للمشتركين).
     */
    public function resolveCustomer(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'phone' => ['required', 'string', 'regex:/^\+?[0-9]{7,15}$/'],
        ]);
        if ($v->fails()) {
            return $this->validationError($v);
        }

        $link = $this->resolveLink($request);
        $key = 'business-resolve:'.$link->id;
        if (RateLimiter::tooManyAttempts($key, 30)) {
            return $this->error('RATE_LIMITED', 'محاولات كثيرة؛ انتظر دقيقة', 429);
        }
        RateLimiter::hit($key, 60);

        $user = app(PaymentRequestService::class)->resolveRecipient((string) $request->input('phone'));
        $found = $user && (bool) $user->is_active && (int) $user->id !== (int) $link->merchant_user_id;

        return $this->ok([
            'found' => $found,
            'masked_name' => $found ? CustomerIdentity::maskedName($user) : null,
        ]);
    }

    /**
     * AMIAL-BUSINESS-REFUND-001 — طلب استرداد لدفعٍ ناجح. لا يُخرج مالاً بنفسه:
     * يُنفَّذ ضمن تفويض التاجر، وإلا ينتظر موافقته برمز PIN من تطبيقه.
     */
    public function requestRefund(Request $request, BusinessRefundService $refunds): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'business_reference' => ['required', 'string', 'regex:/^[A-Za-z0-9_.:-]{1,64}$/'],
            'intent_id' => ['required', 'string', 'regex:/^[0-9A-HJKMNP-TV-Z]{26}$/'],
            'amount' => ['required', 'string', 'regex:/^\d{1,15}(\.\d{1,2})?$/'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        if ($v->fails()) {
            return $this->validationError($v);
        }

        return $this->guard(function () use ($request, $refunds) {
            $refund = $refunds->request($this->resolveLink($request), (string) $request->input('business_reference'),
                (string) $request->input('intent_id'), (string) $request->input('amount'), $request->input('reason'));

            return $this->ok($refunds->present($refund), $refund->status === 'completed' ? 'REFUND_COMPLETED' : 'REFUND_PENDING_APPROVAL',
                $refund->status === 'completed' ? 'تم الاسترداد إلى محفظة العميل' : 'بانتظار موافقة التاجر', 201);
        });
    }

    public function showRefund(Request $request, string $refund, BusinessRefundService $refunds): JsonResponse
    {
        $row = \App\Models\BusinessGateway\BusinessRefund::where('business_link_id', $this->resolveLink($request)->id)->where('refund_ulid', $refund)->first();

        return $row ? $this->ok($refunds->present($row)) : $this->error('REFUND_NOT_FOUND', 'طلب الاسترداد غير موجود', 404);
    }

    /**
     * AMIAL-MERCHANT-CASHOUT-001 — رصيد محفظة التاجر لأميال أعمال (قراءة فقط)،
     * لتطابق به دفترها. لا رقم عملية سحب يخرج من هنا: هو سرّ الصرف عند الوكيل.
     */
    public function wallet(Request $request): JsonResponse
    {
        $link = $this->resolveLink($request);
        $wallet = \App\Models\EMoney::where('user_id', $link->merchant_user_id)->first();
        $pending = \App\Models\WithdrawalRequest::where('customer_user_id', $link->merchant_user_id)->where('status', 'pending');

        return $this->ok([
            'currency' => (string) config('amial_business.wallet_currency', 'YER'),
            'available' => \App\Services\MoneyService::normalize((string) ($wallet?->current_balance ?? '0')),
            'held' => \App\Services\MoneyService::normalize((string) ($wallet?->held_balance ?? '0')),
            'pending_cash_outs' => (int) (clone $pending)->count(),
            'pending_cash_out_total' => \App\Services\MoneyService::normalize((string) ((clone $pending)->sum('total_debit') ?: '0')),
            'as_of' => now()->toIso8601String(),
        ]);
    }

    /**
     * AMIAL-BUSINESS-AUDIT-001 — حدث تدقيق حساس من أميال أعمال. يُحفظ مرة لكل مرجع،
     * ويُكتب في سجل تدقيق أميال باي ليظهر في ملف التاجر.
     */
    public function auditEvent(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'event_ref' => ['required', 'string', 'max:40'], 'action' => ['required', 'string', 'regex:/^[a-z_.]{3,64}$/'],
            'occurred_at' => ['required', 'date'], 'actor' => ['nullable', 'array'], 'actor.name' => ['nullable', 'string', 'max:191'],
            'actor.role' => ['nullable', 'string', 'max:32'], 'context' => ['nullable', 'array', 'max:20'], 'hash' => ['nullable', 'string', 'size:64'],
        ]);
        if ($v->fails()) {
            return $this->validationError($v);
        }
        $link = $this->resolveLink($request);
        $row = \App\Models\BusinessGateway\BusinessAuditEvent::firstOrCreate(
            ['business_link_id' => $link->id, 'event_ref' => (string) $request->input('event_ref')],
            ['action' => (string) $request->input('action'), 'actor' => $request->input('actor'), 'context' => $request->input('context'),
                'source_hash' => $request->input('hash'), 'occurred_at' => \Carbon\Carbon::parse((string) $request->input('occurred_at'))],
        );
        if ($row->wasRecentlyCreated) {
            app(\App\Services\AuditService::class)->record([
                'actor_type' => 'business_staff', 'actor_user_id' => $link->merchant_user_id,
                'subject_type' => 'business_link', 'subject_id' => $link->link_ulid,
                'action' => 'AB_'.strtoupper(str_replace('.', '_', $row->action)), 'decision_code' => 'BUSINESS_AUDIT',
                'reason' => 'Sensitive action in Amial Business', 'severity' => 'info',
                'context' => ['ref' => $row->event_ref, 'actor' => $row->actor, 'details' => $row->context],
            ]);
        }

        return $this->ok(['recorded' => true, 'replayed' => !$row->wasRecentlyCreated]);
    }

    /**
     * AMIAL-BUSINESS-FEES-001 — عرض الرسم قبل الفعل. الحساب هنا وحده (محرّك الرسوم)،
     * وأميال أعمال تعرضه ولا تخزّن جداول رسوم.
     *
     *   merchant_cash_out  رسم سحب التاجر عبر الوكيل
     *   wallet_payment     ما يتحمّله التاجر من دفعة عميل بالمحفظة (صفرٌ اليوم)
     */
    public function feeQuote(Request $request): JsonResponse
    {
        $v = Validator::make($request->query(), [
            'operation' => ['required', 'in:merchant_cash_out,wallet_payment'],
            'amount' => ['required', 'string', 'regex:/^\d{1,15}(\.\d{1,2})?$/'],
        ]);
        if ($v->fails()) {
            return $this->validationError($v);
        }
        $amount = \App\Services\MoneyService::normalize((string) $request->query('amount'));
        $merchant = \App\Models\User::findOrFail($this->resolveLink($request)->merchant_user_id);
        $fee = $request->query('operation') === 'merchant_cash_out'
            ? \App\Services\MoneyService::normalize((string) app(\App\Services\CustomerWithdrawService::class)->merchantCashOutFee($merchant, $amount)['fee'])
            : '0.0000';

        return $this->ok([
            'operation' => $request->query('operation'), 'amount' => $amount, 'fee' => $fee,
            'net_amount' => $request->query('operation') === 'merchant_cash_out' ? $amount : \App\Services\MoneyService::sub($amount, $fee),
            'total_debit' => $request->query('operation') === 'merchant_cash_out' ? \App\Services\MoneyService::add($amount, $fee) : $amount,
        ]);
    }

    /** AMIAL-BUSINESS-PLANS-001 — باقة التاجر وميزات أميال أعمال وحدودها؛ الاشتراك يُدار هنا وحده. */
    public function entitlements(Request $request): JsonResponse
    {
        return $this->ok(app(\App\Services\BusinessGateway\BusinessEntitlementService::class)->for($this->resolveLink($request)));
    }

    private function resolveLink(Request $request): BusinessLink
    {
        return $request->attributes->get('business_link');
    }

    private function guard(callable $fn): JsonResponse
    {
        try {
            return $fn();
        } catch (BusinessGatewayException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->httpStatus);
        }
    }

    private function ok(array $meta, string $code = 'OK', string $message = 'OK', int $status = 200): JsonResponse
    {
        return new JsonResponse([
            'success' => true, 'code' => $code, 'message' => $message,
            'errors' => (object) [], 'meta' => $meta,
        ], $status);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'success' => false, 'code' => $code, 'message' => $message,
            'errors' => (object) [], 'meta' => (object) [],
        ], $status);
    }

    private function validationError(\Illuminate\Contracts\Validation\Validator $v): JsonResponse
    {
        return new JsonResponse([
            'success' => false, 'code' => 'VALIDATION', 'errors' => $v->errors(),
            'message' => (string) $v->errors()->first(), 'meta' => (object) [],
        ], 422);
    }
}
