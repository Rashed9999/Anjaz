<?php

namespace App\Http\Middleware;

use App\Exceptions\UsageLimitExceededException;
use App\Models\MerchantProfile;
use App\Models\PosUser;
use App\Models\User;
use App\Services\UsageLimitService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CRITICAL-001-USAGE — Middleware يفحص حدّ الخطّة قبل عملية.
 *
 * الاستخدام:
 *   Route::post('/sale', ...)->middleware('amial.usage:sale_operation');
 *   Route::post('/products', ...)->middleware('amial.usage:add_product');
 *
 * الـ types المدعومة:
 *   - sale_operation: يفحص monthly_operations + يزيد العدّاد عند نجاح الطلب.
 *   - add_product: يفحص حدّ products live (لا يزيد عدّاداً).
 */
class EnforceUsageLimit
{
    public function __construct(
        private readonly UsageLimitService $svc,
    ) {}

    public function handle(Request $request, Closure $next, string $type = 'sale_operation'): Response
    {
        $merchant = $this->resolveMerchant($request);
        if (!$merchant) return $next($request); // لا تاجر؟ تجاهل (المحقّق سيرفض الطلب)

        try {
            match ($type) {
                'sale_operation' => $this->svc->assertCanPerformSale($merchant),
                'add_product' => $this->svc->assertCanAddProduct($merchant, 'auto'),
                default => null,
            };
        } catch (UsageLimitExceededException $e) {
            return $e->toJsonResponse();
        }

        $response = $next($request);

        // للعمليات الناجحة فقط، سجّل في العدّاد بعد المعالجة
        if ($type === 'sale_operation' && $response->getStatusCode() < 300) {
            try {
                $this->svc->recordSaleOperation($merchant);
            } catch (UsageLimitExceededException $e) {
                // race condition: شخص آخر استهلك الحدّ بين الفحص والتسجيل.
                // العملية نجحت لكن لن نسجّل counter — سنُسجّل تحذير فقط.
                \Log::warning('UsageLimit race detected', [
                    'merchant_id' => $merchant->id,
                    'limit' => $e->maxValue,
                ]);
            }
        }

        return $response;
    }

    /** ابحث عن التاجر سواء من user مباشرة أو من PosUser. */
    private function resolveMerchant(Request $request): ?User
    {
        $authUser = $request->user();
        if (!$authUser) return null;

        $pos = PosUser::where('user_id', $authUser->id)->where('is_active', true)->first();
        if ($pos) return User::find($pos->merchant_user_id);

        if (MerchantProfile::where('user_id', $authUser->id)->exists()) {
            return $authUser;
        }
        return null;
    }
}
