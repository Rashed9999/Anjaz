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

        $r = $this->entitlements->state($user, $code);

        // ══════════════════════════════════════════════════════════════
        // AMIAL-ENTITLEMENTS-002 — **وضعُ الظلّ: يُكتب المنعُ ولا يقع.**
        //
        // ستُّ قدراتٍ مدفوعةٍ رُبطت بمساراتها في هذه الدفعة، وقد عاشت بلا
        // حارسٍ حتّى اليوم — وتجّارُ التجربة يستعملونها الآن. فإشعالُ
        // ستّةِ جدرانِ دفعٍ في لحظةٍ واحدة **يسلبهم ما اعتادوه بلا إنذار**،
        // ولا سبيلَ لمعرفة من يتأثّر قبل وقوعه.
        //
        // فيمرّ الطلبُ ويُكتب من كان سيُمنَع. ويقرأ صاحبُ المنصّة القائمةَ
        // في مركز الأعطال، ثمّ يُشعل `AMIAL_ENTITLEMENTS_ENFORCE=true`.
        //
        // **والأساسيّةُ لا تدخل الظلَّ**: `NOT_APPLICABLE` ليست منعاً بل
        // إخفاءُ قدرةِ قطاعٍ آخر — وإمرارُها في الظلّ يفتح مضخّاتِ الوقود
        // لصيدليّة. فتُنفَّذ دائماً.
        if ($r['state'] !== EntitlementService::AVAILABLE
            && $r['state'] !== EntitlementService::NOT_APPLICABLE
            && ! config('amial.entitlements.enforce', false)) {
            $this->recordShadowDenial($user, $code, $r);

            return $next($request);
        }

        return match ($r['state']) {
            EntitlementService::AVAILABLE => $next($request),

            EntitlementService::LOCKED_BY_PLAN => $this->deny(
                'PLAN_UPGRADE_REQUIRED',
                sprintf('«%s» متاحة في باقة %s (%s ر.س شهرياً)',
                    $r['capability']['name'],
                    $r['unlock']['plan_name'] ?? '—',
                    $r['unlock']['price_monthly'] ?? '—'),
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

    /**
     * **يُكتب المنعُ الذي لم يقع** — في مركز الأعطال، لا في ملفٍّ لا يُقرأ.
     *
     * والبصمةُ بالقدرة والحالة والحساب: فتاجرٌ يفتح شاشةَ الموردين عشرين
     * مرّةً في اليوم يُنتج **سطراً واحداً** بعدّادٍ، لا عشرين سطراً تُغرق
     * القائمةَ فتُهجَر.
     *
     * ولا يُوقِف فشلُ التسجيل الطلبَ: غرضُ الظلّ ألّا يُلمَس السلوك.
     */
    private function recordShadowDenial(mixed $user, string $code, array $r): void
    {
        try {
            app(\App\Services\OpsAlertService::class)->note(
                'entitlement.shadow.' . $code . '.' . $r['state'],
                sprintf('استحقاق (ظلّ): «%s»', $r['capability']['name'] ?? $code),
                sprintf('الحالة %s · الحساب %s · لو كان الإنفاذُ مشتعلاً لرُدّ الطلب',
                    $r['state'], $user->id ?? '—'),
            );
        } catch (\Throwable) {
            // **صمتٌ مقصود.** وضعُ الظلّ وعدُه ألّا يُغيّر السلوك — فسقوطُ
            // التسجيل لا يجوز أن يُسقط طلبَ تاجر.
        }
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
