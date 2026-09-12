<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Services\FeatureAccessService;
use App\Services\FinancialGuardService;
use App\Services\FxConversionService;
use App\Services\FxRateService;
use App\Support\Access\AccessConstants as A;
use App\Support\Money\Currencies;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * AMIAL-MULTI-CURRENCY-002 — **محافظُ التاجر متعدّدةُ العملات.**
 *
 *   GET  /merchant/wallets            محافظي وأرصدتها والأسعارُ السارية
 *   POST /merchant/wallets/quote      كم أقبض لو صرفت — قبل التنفيذ
 *   POST /merchant/wallets/convert    الصرفُ الفعليّ
 *   POST /merchant/wallets/accept     أيَّ العملات أقبل في نقطة البيع
 *
 * **والصلاحيّةُ في الخادم لا في إخفاء الشاشة** (amial-rbac): كلُّ مسارٍ
 * يفحص الدورَ ثمّ القدرةَ `multi_currency` (باقةُ المؤسّسة).
 */
class MerchantWalletsController extends Controller
{
    public function __construct(
        private FeatureAccessService $access,
        private FinancialGuardService $guard,
        private FxRateService $rates,
        private FxConversionService $fx,
    ) {}

    private function gate(Request $request): mixed
    {
        $u = $request->user();
        if (!$u || $u->role !== A::ROLE_MERCHANT) {
            return $this->error('NOT_A_MERCHANT', 'متاح للتجّار فقط', 403);
        }
        if (!$this->access->hasFeature($u, A::F_MULTI_CURRENCY)) {
            return $this->error('FEATURE_LOCKED', 'تعدّد العملات متاح في باقة المؤسّسة', 402);
        }

        return $u;
    }

    /**
     * محافظي — **صفّاً لكلّ عملةٍ مدعومة، ولو كانت صفراً.**
     *
     * فمحفظةٌ لم تُنشأ بعدُ تُعرَض برصيد صفرٍ ومعها `exists=false`: إخفاؤها
     * يجعل التاجرَ لا يعرف أنّ في وسعه قبضَ الدولار أصلاً. **وميزةٌ لا
     * يُوصَل إليها ليست مبنيّة** (القاعدة الثانية عشرة).
     */
    public function index(Request $request): JsonResponse
    {
        $m = $this->gate($request);
        if ($m instanceof JsonResponse) {
            return $m;
        }

        $wallets = $this->guard->walletsOf($m->id);
        $rates = $this->rates->current();
        $accepted = \App\Models\MerchantCurrency::where('merchant_user_id', $m->id)
            ->where('accepts_payments', true)->pluck('code')->map('strtoupper')->all();

        $out = [];
        foreach (Currencies::codes() as $code) {
            $w = $wallets[$code] ?? null;
            $rate = $rates[$code];

            $out[] = [
                'currency' => $code,
                'name' => Currencies::nameAr($code),
                'symbol' => Currencies::symbol($code),
                'is_base' => Currencies::isBase($code),
                'balance' => (string) ($w?->current_balance ?? '0.0000'),
                'held' => (string) ($w?->held_balance ?? '0.0000'),
                'pending' => (string) ($w?->pending_balance ?? '0.0000'),
                'exists' => $w !== null,
                // **السعرُ ومصدرُه معاً** — رقمٌ بلا مصدرٍ لا يُدقَّق.
                'rate_to_base' => $rate['rate'],
                'rate_source' => $rate['source'],
                'rate_at' => $rate['at'],
                // **وغيابُ السعر يُقال ولا يُملأ بواحد.**
                'rate_missing' => $rate['missing'],
                'accepts_payments' => Currencies::isBase($code) || in_array($code, $accepted, true),
            ];
        }

        return $this->ok([
            'base' => Currencies::BASE,
            'base_symbol' => Currencies::symbol(Currencies::BASE),
            'wallets' => $out,
        ], 'OK', 'محافظ التاجر');
    }

    public function quote(Request $request): JsonResponse
    {
        $m = $this->gate($request);
        if ($m instanceof JsonResponse) {
            return $m;
        }

        $v = Validator::make($request->all(), [
            'from' => 'required|string|size:3',
            'to' => 'required|string|size:3',
            'amount' => 'required|numeric|gt:0',
        ]);
        if ($v->fails()) {
            return $this->error('VALIDATION', $v->errors()->first(), 422);
        }

        try {
            return $this->ok($this->fx->quote(
                (string) $request->input('from'),
                (string) $request->input('to'),
                (string) $request->input('amount'),
            ), 'OK', 'عرضُ صرف');
        } catch (\InvalidArgumentException|RuntimeException $e) {
            return $this->error('FX_UNAVAILABLE', $e->getMessage(), 422);
        }
    }

    public function convert(Request $request): JsonResponse
    {
        $m = $this->gate($request);
        if ($m instanceof JsonResponse) {
            return $m;
        }

        $v = Validator::make($request->all(), [
            'from' => 'required|string|size:3',
            'to' => 'required|string|size:3',
            'amount' => 'required|numeric|gt:0',
        ]);
        if ($v->fails()) {
            return $this->error('VALIDATION', $v->errors()->first(), 422);
        }

        try {
            $r = $this->fx->convert(
                $m->id,
                (string) $request->input('from'),
                (string) $request->input('to'),
                (string) $request->input('amount'),
                // **مفتاحُ منع التكرار من الطلب** — ضغطتان على «تأكيد» أو
                // إعادةُ إرسالٍ على شبكةٍ متقطّعة لا تصرفان مرّتين.
                $request->header('Idempotency-Key') ?: null,
            );
        } catch (\App\Exceptions\InsufficientBalanceException $e) {
            return $this->error('INSUFFICIENT', 'الرصيد لا يكفي لهذا الصرف', 422);
        } catch (\InvalidArgumentException|RuntimeException $e) {
            return $this->error('FX_FAILED', $e->getMessage(), 422);
        }

        return $this->ok([
            'rate' => $r['rate'],
            'rate_source' => $r['rate_source'],
            'rate_at' => $r['rate_at'],
            'converted' => $r['converted'],
            'reference' => $r['ref'],
            'from_balance' => (string) $r['from']->current_balance,
            'to_balance' => (string) $r['to']->current_balance,
        ], 'CONVERTED', 'تمّ الصرف');
    }

    /** أيَّ العملات يقبلها التاجرُ في نقطة البيع. */
    public function setAccepted(Request $request): JsonResponse
    {
        $m = $this->gate($request);
        if ($m instanceof JsonResponse) {
            return $m;
        }

        $v = Validator::make($request->all(), [
            'currency' => 'required|string|size:3',
            'accepts' => 'required|boolean',
        ]);
        if ($v->fails()) {
            return $this->error('VALIDATION', $v->errors()->first(), 422);
        }

        try {
            $code = Currencies::normalize((string) $request->input('currency'));
        } catch (\InvalidArgumentException $e) {
            return $this->error('BAD_CURRENCY', $e->getMessage(), 422);
        }

        if (Currencies::isBase($code)) {
            return $this->error('BASE_ALWAYS_ON', 'العملةُ الأساس مقبولةٌ دائماً ولا تُطفأ', 422);
        }

        $accepts = (bool) $request->boolean('accepts');

        // **ولا يُقبَل القبضُ بعملةٍ بلا سعرٍ مضبوط** — وإلّا قُيّدت بيعةٌ
        // لا يُعرف مكافئُها، ولا يُحسَب حدُّ الاستلام عليها.
        if ($accepts) {
            try {
                $this->rates->rateAt($code);
            } catch (RuntimeException $e) {
                return $this->error('NO_RATE', $e->getMessage(), 422);
            }
        }

        \App\Models\MerchantCurrency::updateOrCreate(
            ['merchant_user_id' => $m->id, 'code' => $code],
            [
                'name' => Currencies::nameAr($code),
                'symbol' => Currencies::symbol($code),
                'accepts_payments' => $accepts,
                'is_active' => $accepts,
            ],
        );

        return $this->ok(['currency' => $code, 'accepts_payments' => $accepts], 'SAVED', 'تمّ الحفظ');
    }

    // ── الردّ الموحّد ────────────────────────────────────────────────

    private function ok(array $meta, string $code = 'OK', string $message = 'OK', int $status = 200): JsonResponse
    {
        return new JsonResponse(['success' => true, 'code' => $code, 'message' => $message,
            'errors' => (object) [], 'meta' => $meta], $status);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['success' => false, 'code' => $code, 'message' => $message,
            'errors' => (object) [], 'meta' => (object) []], $status);
    }
}
