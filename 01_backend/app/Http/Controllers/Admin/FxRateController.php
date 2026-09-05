<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FxRate;
use App\Services\AuditService;
use App\Services\FxRateService;
use App\Support\Money\Currencies;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * AMIAL-MULTI-CURRENCY-002 — **أسعارُ الصرف تُضبط من شاشة.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولمَ شاشةٌ لا متغيّرُ بيئةٍ ولا أمرُ سطر:** بلا هذه الصفحة تبقى كلُّ
 * محافظ العملات **مبنيّةً ولا يُوصَل إليها** — فلا سعرَ يُضبَط، فلا قبضَ
 * ولا صرف. وهو نمطُ العطل الأكثر تكراراً في هذا المشروع.
 *
 * وسعرُ الصرف في اليمن يتحرّك في اليوم الواحد. فرقمٌ لا يُغيَّر إلّا
 * بنشرةٍ ليس سعراً يُدار: هو رقمٌ يشيخ ويكذب.
 *
 * **والصلاحيّة `platform.money.move`:** رفعُ سعرِ الدولار عشرةَ أضعافٍ
 * يُضاعف رصيدَ كلّ من يصرف بعده. **فمن يملك تغييرَ السعر يملك المال** —
 * وهو أخطرُ من رفع سقفٍ، لأنّ أثرَه على كلّ محفظةٍ دفعةً واحدة.
 */
class FxRateController extends Controller
{
    public function __construct(
        private readonly FxRateService $rates,
        private readonly AuditService $audit,
    ) {}

    public function page(): View
    {
        return view('admin-views.amial.fx.rates');
    }

    /**
     * الأسعارُ السارية — **ومعها ما لم يُضبَط صراحةً.**
     *
     * فعملةٌ محذوفةٌ من القائمة تُقرأ «كلُّ شيءٍ مضبوط» وهي «لم يُنظَر».
     * (القاعدة السابعة.)
     */
    public function show(): JsonResponse
    {
        $current = $this->rates->current();

        $rows = [];
        foreach (Currencies::ALL as $code => $meta) {
            $c = $current[$code];
            $rows[] = [
                'code' => $code,
                'name' => $meta['name'],
                'symbol' => $meta['symbol'],
                'is_base' => Currencies::isBase($code),
                'rate' => $c['rate'],
                'source' => $c['source'],
                'effective_at' => $c['at'],
                'missing' => $c['missing'],
            ];
        }

        // تاريخُ آخر عشرين تغييراً — **فالسعرُ مُلحَقٌ لا مُعدَّل**، وهذا
        // ما يجعل مكافئَ فاتورةِ الأمس قابلاً لإعادة الحساب.
        $history = FxRate::with('createdBy:id,f_name,l_name')
            ->orderByDesc('effective_at')->orderByDesc('id')->limit(20)->get()
            ->map(fn (FxRate $r) => [
                'currency' => $r->currency,
                'rate' => (string) $r->rate_to_base,
                'source' => $r->source,
                'note' => $r->source_note,
                'effective_at' => $r->effective_at?->toDateTimeString(),
                'by' => trim(($r->createdBy?->f_name ?? '').' '.($r->createdBy?->l_name ?? '')) ?: '—',
            ]);

        return response()->json(['success' => true, 'meta' => [
            'base' => Currencies::BASE,
            'base_symbol' => Currencies::symbol(Currencies::BASE),
            'rates' => $rows,
            'history' => $history,
        ]]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'currency' => 'required|string|size:3',
            'rate_to_base' => 'required|numeric|gt:0',
            'note' => 'nullable|string|max:160',
        ]);

        try {
            $code = Currencies::normalize($data['currency']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        if (Currencies::isBase($code)) {
            return response()->json([
                'success' => false,
                'message' => 'العملةُ الأساس سعرُها ١ بالتعريف ولا يُضبَط',
            ], 422);
        }

        $before = null;
        try {
            $before = (string) $this->rates->rateAt($code)->rate_to_base;
        } catch (\RuntimeException) {
            // لا سعرَ سابق — أوّلُ ضبط.
        }

        $rate = $this->rates->setRate(
            $code,
            (string) $data['rate_to_base'],
            FxRate::SOURCE_ADMIN,
            $data['note'] ?? null,
            (int) auth()->id(),
        );

        $this->audit->record([
            'actor_type' => 'admin',
            'actor_user_id' => (int) auth()->id(),
            'subject_type' => 'fx_rate',
            'subject_id' => (string) $rate->id,
            'action' => 'FX_RATE_SET',
            'decision_code' => 'OK',
            'severity' => 'warning',   // يمسّ قيمةَ كلّ محفظةٍ أجنبيّة
            'reason' => $data['note'] ?? 'ضبط سعر صرف',
            'context' => [
                'currency' => $code,
                'before' => $before,
                'after' => (string) $rate->rate_to_base,
                'effective_at' => $rate->effective_at?->toDateTimeString(),
            ],
        ]);

        return response()->json(['success' => true, 'message' => 'تمّ ضبط السعر', 'meta' => [
            'currency' => $code,
            'rate' => (string) $rate->rate_to_base,
            'before' => $before,
        ]]);
    }
}
