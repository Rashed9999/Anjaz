<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\PaymentRequest;
use App\Services\PaymentRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-PAYMENT-REQUESTS-001 — Controller طلبات الدفع.
 *
 *   POST /api/v1/amial/payment-requests              (إنشاء)
 *   GET  /api/v1/amial/payment-requests              (?direction=outgoing|incoming&status=...)
 *   GET  /api/v1/amial/payment-requests/code/{code}  (تفاصيل بالرمز — للدافع)
 *   POST /api/v1/amial/payment-requests/code/{code}/pay  (دفع الطلب)
 *   POST /api/v1/amial/payment-requests/{id}/cancel  (إلغاء)
 */
class PaymentRequestController extends AmialApiController // AMIAL-FIX-007
{
    public function __construct(
        private readonly PaymentRequestService $service,
    ) {}

    public function create(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01',
            'recipient_phone' => 'sometimes|nullable|string|max:32',
            'recipient_name' => 'sometimes|nullable|string|max:120',
            'note' => 'sometimes|nullable|string|max:255',
            'share_method' => 'sometimes|nullable|in:direct,link,qr',
            'is_recurring' => 'sometimes|nullable|boolean',
            'recurring_period' => 'sometimes|nullable|in:daily,weekly,monthly',
        ]);
        if ($v->fails()) return $this->validationError($v);

        try {
            $req = $this->service->create(
                requester: $request->user(),
                amount: (string)$request->input('amount'),
                recipientPhone: $request->input('recipient_phone'),
                recipientName: $request->input('recipient_name'),
                note: $request->input('note'),
                shareMethod: $request->input('share_method', 'link'),
                isRecurring: $request->boolean('is_recurring', false),
                recurringPeriod: $request->input('recurring_period'),
            );
        } catch (\InvalidArgumentException $e) {
            return $this->error('INVALID_REQUEST', $e->getMessage(), 422);
        }

        return $this->ok([
            'request' => $req,
            'short_code' => $req->short_code,
            'public_url' => $req->publicUrl(),
            // **الشاشةُ تُخبر بما وقع فعلاً**: أوصِل إلى حسابه، أم بقي
            // رابطاً ينتظر مشاركةً بيده؟ ورسالةُ نجاحٍ واحدةٌ للحالتين
            // تجعل الطالبَ يظنّ أنّ طلبه وصل وهو لم يصل.
            'delivered' => $req->isDirect(),
            'delivery_label' => $req->shareMethodAr(),
            // **الاسمُ يُرجَع مع النتيجة** — وإلّا قالت شاشةُ النجاح «وصل
            // الطلب» ولم تقل إلى مَن. والطالبُ كتب رقماً لا اسماً، فلا
            // شيء في يده يؤكّد أنّه أصاب الرقمَ الصحيح.
            'recipient_label' => $this->recipientLabel($req),
        ], 'REQUEST_CREATED',
            $req->isDirect() ? 'وصل الطلب إلى صاحبه' : 'أُنشئ الطلب — شاركه بالرابط',
            201);
    }

    /**
     * اسمُ المطلوب منه كما يُعرض: حسابُه أوّلاً، ثمّ ما كتبه الطالب،
     * ثمّ رقمُه. ولا يُترك فارغاً — «وصل الطلب» بلا مستلَمٍ لا تُطمئن.
     */
    private function recipientLabel(\App\Models\PaymentRequest $req): string
    {
        if ($req->recipient_user_id) {
            $u = \App\Models\User::find($req->recipient_user_id, ['f_name', 'l_name']);

            $name = trim(($u->f_name ?? '') . ' ' . ($u->l_name ?? ''));

            if ($name !== '') {
                return $name;
            }
        }

        return trim((string) $req->recipient_name) !== ''
            ? (string) $req->recipient_name
            : (string) $req->recipient_phone;
    }

    /**
     * **هل هذا الرقم مشترك؟** — AMIAL-REQUEST-DIRECT-002.
     *
     * ══════════════════════════════════════════════════════════════════
     * تُنادى **أثناء الكتابة** في خانة «من تطلب منه»، فتقول الشاشةُ قبل
     * الإرسال: «مشترك — سيصله الطلب فوراً» أو «غير مشترك — يُشارَك
     * برابط». ومن غيرها يضغط الطالبُ «إرسال» ثمّ يكتشف أنّ ما أُنشئ
     * رابطٌ عليه أن يُوصله بيده.
     *
     * **ولا تُفصح عن شيء**: تُرجع نعم/لا والاسمَ الأوّل وحدَه. فمن يمسح
     * أرقاماً بحثاً عمّن هو على أميال لا يحصل على أكثر ممّا يحصل عليه من
     * محاولة الطلب نفسها — وقيّدها الحدُّ على أيّ حال.
     */
    public function checkRecipient(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), ['phone' => 'required|string|max:32']);

        if ($v->fails()) {
            return $this->validationError($v);
        }

        $phone = (string) $request->input('phone');

        // **البابُ نفسُه الذي يستعمله الإنشاء** — وإلّا قالت الشاشةُ
        // «غير مشترك» وربط الخادمُ الطلبَ بصاحبه. (وقع هذا فعلاً: قيّدتُ
        // الفحصَ بـ`CUSTOMER_TYPE` والإنشاءُ لا يُقيّد.)
        $user = $this->service->resolveRecipient($phone);

        // **وطلبٌ من النفس يُقال قبل الإرسال لا بعده.**
        if ($user && (int) $user->id === (int) $request->user()->id) {
            return $this->ok([
                'found' => false, 'is_self' => true,
                'hint' => 'هذا رقمك أنت',
            ]);
        }

        if (! $user) {
            return $this->ok([
                'found' => false, 'is_self' => false,
                'hint' => 'غير مشترك في أميال — سيُنشأ رابطٌ تُشاركه معه',
            ]);
        }

        // حسابٌ موقوف: **يُقال، ولا يُترك الطالب ينتظر جواباً لن يأتي.**
        if (! $user->is_active) {
            return $this->ok([
                'found' => false, 'is_self' => false,
                'hint' => 'الحساب موقوف حالياً — استعمل الرابط',
            ]);
        }

        return $this->ok([
            'found' => true,
            'is_self' => false,
            'name' => trim(($user->f_name ?? '') . ' ' . ($user->l_name ?? '')),
            'hint' => 'مشترك في أميال — سيصله الطلب فوراً',
        ]);
    }

    public function list(Request $request): JsonResponse
    {
        $direction = $request->query('direction', 'outgoing');
        $status = $request->query('status');
        $page = (int)$request->query('page', 1);

        $paginated = $this->service->listForUser(
            $request->user(), $direction, $status, $page
        );

        return $this->ok([
            'requests' => $paginated->items(),
            'pagination' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    /** يعيد تفاصيل الطلب للدافع. authenticated فقط لمنع تسريب البيانات. */
    public function showByCode(Request $request, string $code): JsonResponse
    {
        $req = $this->service->findByCode($code);
        if (!$req) return $this->error('NOT_FOUND', 'الطلب غير موجود', 404);

        // إن كان مستلم محدّد، تأكّد المستخدم هو المستلم
        if ($req->recipient_user_id && $req->recipient_user_id !== $request->user()->id) {
            return $this->error('FORBIDDEN', 'هذا الطلب موجّه لشخص آخر', 403);
        }

        // معلومات الطالب (لعرضها للدافع)
        $requester = $req->requester;

        return $this->ok([
            'request' => $req,
            'requester' => [
                'name' => trim(($requester->f_name ?? '') . ' ' . ($requester->l_name ?? '')),
                'phone' => $requester->phone,
            ],
            'is_active' => $req->isActive(),
            'is_self' => $request->user()->id === $req->requester_user_id,
        ]);
    }

    /**
     * AMIAL-MERCHANT-PAY-002 — البحثُ عن فاتورةٍ برقم حساب التاجر ورقمها.
     *
     * POST /amial/merchant/invoice/lookup {merchant_phone|merchant_user_id, invoice_no}
     *
     * ══════════════════════════════════════════════════════════════════
     * **ولمَ يُطلب الاثنان ورقمُ الفاتورة وحده فريد؟**
     *
     * لأنّ الرقم يُكتب بيدٍ عند صندوق، وحرفٌ يُخطأ يقع على فاتورةِ تاجرٍ
     * آخر — فيدفع العميلُ لمن لا يعرفه ويرى اسماً غريباً بعد أن يُخصم
     * ماله. فمطابقةُ التاجر تجعل الخطأ **رسالةً** لا **دفعةً**.
     *
     * وهذا الطريقُ للحالة التي لا تعمل فيها الكاميرا: إضاءةٌ ضعيفة، أو
     * شاشةٌ مكسورة، أو رمزٌ مطبوعٌ بهت. ومن لا طريقَ له إلّا الكاميرا
     * يقف عاجزاً عند الصندوق.
     */
    public function lookupInvoice(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'merchant_user_id' => 'required_without:merchant_phone|integer',
            'merchant_phone' => 'required_without:merchant_user_id|string|max:32',
            'invoice_no' => 'required|string|max:16',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $merchant = $request->filled('merchant_user_id')
            ? \App\Models\User::find((int) $request->input('merchant_user_id'))
            : \App\Models\User::whereIn('phone', \App\Support\Phone::variants((string) $request->input('merchant_phone')))->first();

        if (!$merchant) {
            return $this->error('MERCHANT_NOT_FOUND', 'التاجر غير موجود — تحقّق من رقم الحساب', 404);
        }

        $req = $this->service->findByCode(trim((string) $request->input('invoice_no')));

        if (!$req) {
            return $this->error('INVOICE_NOT_FOUND', 'لا توجد فاتورة بهذا الرقم', 404);
        }

        // **والمطابقةُ هي الحارس:** رقمٌ صحيحٌ لتاجرٍ آخر يُردّ برسالةٍ
        // تقول ذلك، لا بفاتورةٍ تُدفع.
        if ((int) $req->requester_user_id !== (int) $merchant->id) {
            return $this->error('INVOICE_MERCHANT_MISMATCH',
                'رقم الفاتورة لا يخصّ هذا التاجر — تأكّد من الرقمين', 422);
        }

        return $this->ok([
            'request' => $req,
            'invoice_no' => $req->short_code,
            'requester' => [
                'name' => trim(($merchant->f_name ?? '') . ' ' . ($merchant->l_name ?? '')),
                'phone' => $merchant->phone,
            ],
            'is_active' => $req->isActive(),
            'is_self' => $request->user()->id === (int) $req->requester_user_id,
        ]);
    }

    /**
     * AMIAL-MERCHANT-PAY-002 — **ورمزُ المعاملات على هذا الباب أيضاً.**
     *
     * وموضعُه هنا في المتحكّم لا في `PaymentRequestService::pay`، لأنّ
     * تلك الدالّة لها **أربعةُ منادين**: هذا المتحكّم، وبوتُ واتساب
     * (`WhatsappBotService:748`)، ومسارُ الوقود، والاختبارات. واشتراطُ
     * PIN داخلها يكسر البوتَ والوقود معاً.
     *
     * **وبوتُ واتساب يبقى بلا PIN عمداً وأقولُها صراحةً:** طلبُ رمزٍ سرّيّ
     * في محادثةِ واتساب يعني كتابتَه نصّاً في سجلٍّ يبقى على الهاتفين وعند
     * المزوّد — وذاك أسوأ ممّا يُصلحه. حمايتُه جلسةُ رقمٍ موثَّق، ويلزمه
     * قرارٌ منفصل.
     */
    public function pay(Request $request, string $code): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'pin' => 'required|string|min:4|max:4',
        ]);
        if ($v->fails()) return $this->validationError($v);

        if (!\App\CentralLogics\Helpers::pin_check($request->user()->id, (string) $request->input('pin'))) {
            return $this->error('PIN_INVALID', 'رمز الحماية غير صحيح', 403);
        }

        $req = $this->service->findByCode($code);
        if (!$req) return $this->error('NOT_FOUND', 'الطلب غير موجود', 404);

        try {
            $result = $this->service->pay($request->user(), $req);
        } catch (\App\Exceptions\InsufficientBalanceException $e) {
            return new JsonResponse($e->toApiArray(), 402);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return $this->error('PAYMENT_FAILED', $e->getMessage(), 422);
        }

        return $this->ok($result, 'PAYMENT_OK', 'تم الدفع بنجاح');
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $req = PaymentRequest::find($id);
        if (!$req) return $this->error('NOT_FOUND', 'الطلب غير موجود', 404);

        try {
            $cancelled = $this->service->cancel($request->user(), $req);
        } catch (\InvalidArgumentException $e) {
            return $this->error('FORBIDDEN', $e->getMessage(), 403);
        } catch (\RuntimeException $e) {
            return $this->error('CANCEL_FAILED', $e->getMessage(), 422);
        }

        return $this->ok(['request' => $cancelled], 'CANCELLED', 'تم الإلغاء');
    }

    /**
     * POST /payment-requests/{id}/decline  {reason?}
     *
     * AMIAL-REQUEST-DIRECT-001 — الطرف الناقص في «يوافق أو يرفض».
     *
     * كان في المسار «ادفع» و«ألغِ» (للطالب) ولا «ارفض» (للمستلم). فمن وصله
     * طلبٌ لا يريده لم يكن أمامه إلّا تجاهله حتى تنتهي صلاحيته — والطالب
     * ينتظر أسبوعاً ثمّ يتّصل.
     */
    public function decline(Request $request, int $id): JsonResponse
    {
        $req = PaymentRequest::find($id);
        if (!$req) return $this->error('NOT_FOUND', 'الطلب غير موجود', 404);

        try {
            $declined = $this->service->decline(
                $request->user(), $req, $request->input('reason'),
            );
        } catch (\InvalidArgumentException $e) {
            return $this->error('FORBIDDEN', $e->getMessage(), 403);
        } catch (\RuntimeException $e) {
            return $this->error('DECLINE_FAILED', $e->getMessage(), 422);
        }

        return $this->ok(['request' => $declined], 'DECLINED', 'تم رفض الطلب');
    }

    /**
     * POST /payment-requests/{id}/pay — الدفع من قائمة الواردة.
     *
     * كان الدفع لا يُمكن إلّا بالرمز القصير (`code/{code}/pay`) — وهو مسار
     * من وصله رابط. أمّا من وصله الطلبُ في قائمته فلا يملك رمزاً يكتبه،
     * فيبقى يراه ولا يستطيع دفعه.
     */
    /**
     * **وهذا بابٌ ثانٍ إلى المال نفسِه — فيُقفل معه.**
     *
     * (القاعدة الرابعة.) وحاجزٌ على بابٍ واحدٍ من بابين ليس حاجزاً: من
     * يعرف العنوانَ الآخر يمرّ منه، **وأوّلُ ما يُجرَّب هو العنوانُ الذي
     * لا يُذكر في التوثيق.**
     */
    public function payById(Request $request, int $id): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'pin' => 'required|string|min:4|max:4',
        ]);
        if ($v->fails()) return $this->validationError($v);

        if (!\App\CentralLogics\Helpers::pin_check($request->user()->id, (string) $request->input('pin'))) {
            return $this->error('PIN_INVALID', 'رمز الحماية غير صحيح', 403);
        }

        $req = PaymentRequest::find($id);
        if (!$req) return $this->error('NOT_FOUND', 'الطلب غير موجود', 404);

        try {
            $result = $this->service->pay($request->user(), $req);
        } catch (\InvalidArgumentException $e) {
            return $this->error('FORBIDDEN', $e->getMessage(), 403);
        } catch (\RuntimeException $e) {
            return $this->error('PAY_FAILED', $e->getMessage(), 422);
        }

        return $this->ok($result, 'PAID', 'تم الدفع');
    }
}
