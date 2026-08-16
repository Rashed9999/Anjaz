<?php

namespace App\Http\Middleware;

use App\Services\Access\EntitlementService;
use App\Support\Access\CapabilityRegistry;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AMIAL-ENTITLEMENTS-001 — **البوّابةُ الواحدة**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * كان الفحصُ منثوراً: ١٧ `if (!hasFeature(...))` في المتحكّمات، وكلُّ
 * واحدةٍ تكتب رسالتَها ورقمَ خروجها بيدها. **فمتحكّمٌ ينسى الفحصَ لا
 * يُكشف**، ومتحكّمٌ يفحص برسالةٍ مختلفة يُربك المستعمل.
 *
 * فصار المسارُ يُعلن قدرتَه مرّةً:
 *
 * ```php
 * Route::prefix('retail')->middleware('capability:retail.transfers')
 * ```
 *
 * **ولا متحكّمَ يعرف ما الباقات.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ورقمُ الخروج يفرّق بين البابين** — وهذا أهمّ ما فيه:
 *
 * | الحالة | الرقم | لأنّ |
 * |---|---|---|
 * | باقةٌ ناقصة | **402** | *Payment Required* — يذهب لصاحب المتجر ليرقّي |
 * | دورٌ ناقص | **403** | *Forbidden* — يذهب لمديره ليمنحه |
 * | حدٌّ مستنفَد | **402** | ومعه الرقمان: المستعمَل والحدّ |
 *
 * وردُّ ٤٠٣ على نقص الباقة يُرسل التاجرَ يبحث عن دورٍ يمنحه لنفسه ولن
 * يجد. **وهو عطلٌ لا يُنتج خطأً في أيّ سجلّ** — الردُّ سليمُ الشكل.
 */
class EnsureCapability
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    public function handle(Request $request, Closure $next, string $code): Response
    {
        $user = $request->user('api') ?? $request->user();

        if (! $user) {
            return $next($request);   // حارسُ المصادقة يتولّاها
        }

        // **الأدمن يمرّ** — يفحص ويُصلح، ولا يشتري باقةً ليرى شاشة.
        if ((int) ($user->type ?? -1) === ADMIN_TYPE) {
            return $next($request);
        }

        if (! CapabilityRegistry::exists($code)) {
            // إعلانٌ بقدرةٍ غيرِ مسجَّلة — **يسقط ولا يُتجاهل**.
            report(new \RuntimeException("قدرة غير مسجَّلة في البوّابة: {$code}"));

            return $this->deny('CAPABILITY_UNKNOWN',
                'هذه الخدمة غير معرّفة — أبلغ الدعم', 500);
        }

        // ══════════════════════════════════════════════════════════════
        // AMIAL-ENTITLEMENTS-002 — **القرارُ في `EntitlementService::gate`.**
        //
        // ولا يُنسَخ منطقُ الظلّ هنا: قدرتان في هذه الدفعة تُحرَسان من
        // **متحكّم** لا من وسيط (`advanced_reports` و`excel_export`
        // قيمتان داخل نقطةٍ واحدة). ولو تكرّر المنطقُ لصار تعريفان —
        // وقد افترق تعريفان في هذه الجولة ثلاثَ مرّات.
        //
        // `gate()` تُعيد `null` حين يمرّ الطلب (متاحةٌ أو في الظلّ وقد
        // كُتبت)، وحالةَ المنع فيما عدا ذلك.
        $denial = $this->entitlements->gate($user, $code);

        if ($denial === null) {
            return $next($request);
        }

        $r = $denial;

        return match ($r['state']) {
            EntitlementService::LOCKED_BY_PLAN => $this->deny(
                'PLAN_UPGRADE_REQUIRED',
                // **والعملةُ من مصدرها الواحد** لا محفورةً هنا — وقد كُتبت
                // «ر.ي» على سعرٍ سعوديٍّ في خمسة مواضعَ من قبل.
                sprintf('«%s» متاحة في باقة %s (%s %s شهرياً)',
                    $r['capability']['name'],
                    $r['unlock']['plan_name'] ?? '—',
                    $r['unlock']['price_monthly'] ?? '—',
                    \App\Support\Access\AccessConstants::PLAN_PRICE_CURRENCY),
                402, $r,
            ),

            EntitlementService::LIMIT_REACHED => $this->deny(
                'PLAN_LIMIT_REACHED',
                sprintf('بلغتَ حدّ باقتك في «%s»: %s من %s',
                    $r['capability']['name'],
                    $r['usage']['used'] ?? '—', $r['usage']['max'] ?? '—'),
                402, $r,
            ),

            EntitlementService::NOT_APPLICABLE => $this->deny(
                'NOT_FOR_BUSINESS_TYPE',
                'هذه الخدمة لا تخصّ نوع نشاطك', 404, $r,
            ),

            // ولا يبقى إلّا الدور.
            default => $this->deny(
                'PERMISSION_REQUIRED',
                sprintf('«%s» تحتاج صلاحية — اطلبها من %s',
                    $r['capability']['name'],
                    $r['unlock']['ask'] ?? 'مالك المنشأة'),
                403, $r,
            ),
        };
    }

    private function deny(string $code, string $message, int $status, ?array $r = null): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'code' => $code,
            'message' => $message,
            'errors' => (object) [],
            // **يُرسَل ما يُبنى منه زرُّ الحلّ** — رسالةٌ بلا طريقِ خروجٍ
            // تُخبر المستعمل أنّه ممنوع ولا تقول كيف يُسمح له.
            'meta' => $r ? [
                'capability' => $r['capability'],
                'state' => $r['state'],
                'unlock' => $r['unlock'],
                'usage' => $r['usage'],
            ] : (object) [],
        ], $status);
    }
}
