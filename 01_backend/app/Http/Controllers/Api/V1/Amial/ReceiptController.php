<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\Receipt;
use App\Services\AuditService;
use App\Services\ReceiptDocumentService;
use App\Services\ReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * AMIAL-RECEIPTS-001 (v0.9-A)
 *
 * APIs:
 *   GET  /api/v1/amial/receipts                    — قائمة إيصالات المستخدم
 *   GET  /api/v1/amial/receipts/{id}               — تفاصيل إيصال
 *   GET  /api/v1/amial/receipts/{id}/download      — تحميل PDF
 *   GET  /v/{verification_code}                    — تحقق عام (لا مصادقة)
 */
class ReceiptController extends Controller
{
    public function __construct(
        private readonly ReceiptService $service,
        private readonly ReceiptDocumentService $documents,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Receipt::forUser($user->id)->orderByDesc('issued_at');

        if ($type = $request->query('type')) {
            $query->where('receipt_type', $type);
        }

        // ── بحثٌ وفلاتر ───────────────────────────────────────────────
        //
        // **الثمن الذي دُفع:** شاشةُ الإيصالات كانت قائمةً بلا بحثٍ ولا
        // فلتر. ومن له مئةُ عمليّةٍ في الشهر يبحث عن تحويلٍ لشخصٍ بعينه
        // **بالتمرير** — ولا يجده.
        //
        // والبحثُ الأوّل بالهاتف: هو ما يحفظه الناس، لا رقمُ الإيصال.

        // اتّجاه الحركة — «الصادر» و«الوارد».
        if (in_array($dir = $request->query('direction'), ['debit', 'credit'], true)) {
            $query->where('direction', $dir);
        }

        // مدّة — بالتاريخ لا بعدد الصفحات.
        if ($from = $request->query('from')) {
            $query->whereDate('issued_at', '>=', $from);
        }

        if ($to = $request->query('to')) {
            $query->whereDate('issued_at', '<=', $to);
        }

        // مدى المبلغ.
        if (is_numeric($min = $request->query('min_amount'))) {
            $query->where('amount', '>=', $min);
        }

        if (is_numeric($max = $request->query('max_amount'))) {
            $query->where('amount', '<=', $max);
        }

        $q = trim((string) $request->query('q', ''));

        if ($q !== '') {
            // **الهاتفُ يُطابَق بصيغه كلِّها.** فمن يكتب `777123456` يجب أن
            // يجد `+967777123456`، ومن ينسخ الرقم من جهات الاتصال يجده
            // بصيغةٍ ثالثة. ومطابقةٌ حرفيّةٌ واحدة تُخرج «لا نتائج» على
            // رقمٍ موجود — **وهو أسوأ من غياب البحث**: يُقنع الباحثَ أنّ
            // العمليّة لم تقع.
            $phoneDigits = preg_replace('/\D+/', '', $q);

            $query->where(function ($w) use ($q, $phoneDigits, $user) {
                $w->where('receipt_number', 'like', "%{$q}%")
                    ->orWhere('reference_transaction_id', 'like', "%{$q}%")
                    ->orWhere('verification_code', 'like', "%{$q}%");

                // الطرفُ الآخر: بالاسم أو بالهاتف بكلّ صيغه.
                $w->orWhereIn('counterparty_user_id',
                    $this->matchingCounterparties($q, $phoneDigits, (int) $user->id));
            });
        }

        $receipts = $query->paginate(20)->appends($request->query());

        return new JsonResponse([
            'success' => true,
            'code' => 'OK',
            'message' => 'Receipts list',
            'errors' => (object)[],
            'meta' => [
                'pagination' => [
                    'total' => $receipts->total(),
                    'per_page' => $receipts->perPage(),
                    'current_page' => $receipts->currentPage(),
                ],
                'items' => $receipts->items(),
            ],
        ]);
    }

    /**
     * معرّفاتُ الأطراف التي يطابقها نصُّ البحث — **بالهاتف بكلّ صيغه**.
     *
     * ولا يُبحث في `users` مباشرةً من الاستعلام الرئيسيّ: المستعملُ يبحث
     * عن **من عامله هو**، لا عن كلّ من في المنصّة. والحدُّ ٥٠ لأنّ من
     * يكتب حرفين لا يريد ألفَ مطابقة.
     *
     * @return array<int,int>
     */
    private function matchingCounterparties(string $q, string $digits, int $userId): array
    {
        $users = \App\Models\User::query()
            ->where('id', '!=', $userId)
            ->where(function ($w) use ($q, $digits) {
                $w->where('f_name', 'like', "%{$q}%")
                    ->orWhere('l_name', 'like', "%{$q}%");

                if ($digits !== '' && mb_strlen($digits) >= 3) {
                    // الصيغُ الخمس من `Support\Phone` — ومن كتب جزءاً من
                    // الرقم يُطابَق بـ`like` على الصيغة القانونيّة.
                    foreach (\App\Support\Phone::variants($digits) as $v) {
                        $w->orWhere('phone', $v);
                    }

                    $w->orWhere('phone', 'like', "%{$digits}%");
                }
            })
            ->limit(50)->pluck('id')->all();

        // **قائمةٌ فارغةٌ تعني «لا مطابق»** — ولا تُترك فتُلغي الشرط.
        return $users ?: [0];
    }

    /**
     * AMIAL-RECIPIENTS-001 — **المستلمون يُشتقّون من السجلّ، لا يُخزَّنون في الهاتف.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **الثمن الذي دُفع:**
     *
     * كانت قائمة «تحويل سريع» تُقرأ من `SharedPreferences` وحدها. فمن
     * أعاد تثبيت التطبيق — أو بدّل هاتفه — **بدأ من الصفر**: «لا مستلمين
     * بعد» وهو يحوّل منذ شهور.
     *
     * ولا خطأ في أيّ سجلّ: القائمةُ فارغةٌ فتُعرض بطاقةُ الدعوة، وكأنّه
     * مستعملٌ جديد.
     *
     * **والبياناتُ موجودةٌ أصلاً**: كلُّ تحويلٍ له إيصالٌ بـ
     * `counterparty_user_id`. فاشتقاقُها من المصدر يُغني عن جدولٍ جديد
     * **ويصحّح نفسه** — من حوّل أمس يظهر، ومن لم يعد يُحوَّل له ينزل.
     * (القاعدة السادسة: الرقم يُحسب من مصدره لا من عمودٍ مخزَّن.)
     *
     * **ولا يُخلط بالمفضّلة**: تلك يختارها المستعمل بنفسه ولها جدولُها.
     * وهذه تاريخُه — تُقترح ولا تُدار.
     */
    public function recentRecipients(Request $request): JsonResponse
    {
        $me = $request->user();

        $rows = Receipt::query()
            ->where('user_id', $me->id)
            // **`debit` لا `out`.** العمودُ enum('debit','credit') — وكتبتُها
            // `out` أوّلَ مرّة فكانت النقطةُ تردّ قائمةً فارغةً **أبداً**،
            // بلا خطأٍ في أيّ سجلّ. أمسكها حارسٌ يقيس السلوك لا الوجود.
            ->where('direction', 'debit')
            ->whereNotNull('counterparty_user_id')
            ->where('counterparty_user_id', '!=', $me->id)
            // **`voided` لا `cancelled`.** والقيمةُ الخاطئة لا تُنتج خطأً:
            // الشرطُ يمرّ على كلّ صفّ، فيظهر في القائمة من أُلغي تحويلُه.
            ->where('status', '!=', 'voided')
            // آخرُ تحويلٍ لكلّ طرف، وعددُ مرّاته — فالترتيبُ بالقرب لا
            // بالكثرة وحدها: من حوّلتَ له أمس أقربُ ممّن حوّلتَ له عشراً
            // قبل سنة.
            ->selectRaw('counterparty_user_id, MAX(issued_at) as last_at, COUNT(*) as times')
            ->groupBy('counterparty_user_id')
            ->orderByDesc('last_at')
            ->limit(12)
            ->get();

        $users = \App\Models\User::whereIn('id', $rows->pluck('counterparty_user_id'))
            ->get(['id', 'f_name', 'l_name', 'phone', 'image'])
            ->keyBy('id');

        $items = $rows->map(function ($r) use ($users) {
            $u = $users->get($r->counterparty_user_id);

            if (! $u) {
                return null;   // حسابٌ حُذف — لا يُقترح طرفٌ لا وجود له
            }

            return [
                'user_id' => $u->id,
                'name' => trim(($u->f_name ?? '') . ' ' . ($u->l_name ?? '')),
                'phone' => $u->phone,
                'image' => $u->image,
                'last_at' => $r->last_at,
                'times' => (int) $r->times,
            ];
        })->filter()->values();

        return new JsonResponse([
            'success' => true,
            'code' => 'OK',
            'message' => '',
            'errors' => (object) [],
            'meta' => ['items' => $items, 'total' => $items->count()],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $receipt = Receipt::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$receipt) {
            return $this->error('RECEIPT_NOT_FOUND', 'الإيصال غير موجود', 404);
        }

        $payload = $receipt->toArray();
        $payload['document'] = $this->documents->descriptor($receipt);

        return new JsonResponse([
            'success' => true,
            'code' => 'OK',
            'message' => 'Receipt details',
            'errors' => (object)[],
            'meta' => $payload,
        ]);
    }

    /**
     * GET /api/v1/amial/receipts/{id}/download
     * يعيد PDF stream.
     */
    public function download(Request $request, int $id): \Symfony\Component\HttpFoundation\Response
    {
        // AMIAL-PDF-PIPELINE-001 — «ولّد مرّة، اخدم كثيراً».
        //
        // كان هذا المسار يُعيد التصيير في **كل** طلب، لأن الملف المخزَّن لم يكن
        // يُنتَج أصلاً: المهمّة تُرسَل إلى طابور `receipts` والعامل يستمع إلى
        // `default,notifications` فقط. فبقي كل تنزيل تصييراً كاملاً داخل الطلب —
        // بطيء على خادم صغير، فيُقطع الاتصال على شبكة جوّال
        // (Connection closed while receiving data).
        //
        // هذا ما تفعله الشركات: المستند يُولَّد مرّة عند إنشاء العملية ويُخزَّن،
        // ثم يُخدَم كقراءة ملف — أجزاء من الثانية. وإن غاب الملف (نشر جديد على
        // تخزين مؤقّت) نُولّده **ونحفظه** فتكون المرّة التالية فورية.
        $receipt = Receipt::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();
        if (!$receipt) {
            return $this->error('RECEIPT_NOT_FOUND', 'الإيصال غير موجود', 404);
        }

        $path = $receipt->pdf_storage_path;
        if ($path
            && $this->documents->isCurrent($receipt)
            && Storage::disk('local')->exists($path)) {
            $receipt->incrementDownloadCount();
            $this->auditDocumentAccess($request, $receipt, 'standard_pdf');

            return $this->pdfResponse($path, $receipt->receipt_number);
        }

        // لا ملف بعد — ولّده الآن **بشكل متزامن** واحفظه للمرّات القادمة.
        try {
            (new \App\Jobs\GeneratePdfReceiptJob($receipt->id))->handle();
            $receipt->refresh();
            $path = $receipt->pdf_storage_path;
            if ($path && Storage::disk('local')->exists($path)) {
                $receipt->incrementDownloadCount();
                $this->auditDocumentAccess($request, $receipt, 'standard_pdf');

                return $this->pdfResponse($path, $receipt->receipt_number);
            }
        } catch (\Throwable $e) {
            // AMIAL-PDF-VISIBILITY-001: كان تحذيراً بنصّ الرسالة وحدها.
            //
            // وذلك يعني أن أخطر فشل في هذا المسار — تعذّر تصيير الإيصال —
            // يُبتلع بلا نوع ولا ملفّ ولا سطر ولا أثر استدعاء. فيبقى السؤال
            // «لماذا لا يُحمَّل الإيصال؟» بلا جواب في السجلّ، وقد بقي كذلك
            // فعلاً حتى كشفه تسجيلُ شاشة من جهاز المستخدم.
            \Illuminate\Support\Facades\Log::error('Receipt PDF generation failed', [
                'receipt_id' => $receipt->id,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
                'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 12),
            ]);
        }

        // آخر ملاذ: مسار الفاتورة القديم (تصيير في الذاكرة بلا تخزين).
        return $this->invoice($request, $id);
    }

    /**
     * ردُّ ملفّ PDF من القرص — لا من ذاكرة PHP.
     *
     * ══════════════════════════════════════════════════════════════════
     * **كان يُقرأ الملفّ كاملاً إلى نصٍّ ثمّ يُردّ.** ولذلك ثلاثة عيوب،
     * ثالثُها هو الذي كان يقطع الاتّصال:
     *
     * ١. مئةُ كيلوبايت في ذاكرة PHP لكلّ تنزيلٍ متزامن.
     * ٢. `Content-Length` يُحسب بنداءٍ ثانٍ إلى القرص — فلو تغيّر الملفّ
     *    بين النداءين لاختلف المعلَن عن المُرسَل، والعميل ينتظر بايتاتٍ
     *    لا تأتي **فيُبلغ «انقطع الاتّصال أثناء الاستقبال»** بالضبط.
     * ٣. ولا يستفيد من `sendfile` في nginx.
     *
     * و`BinaryFileResponse` تحسب الطول من الملفّ الذي سترسله هي نفسها،
     * فلا يفترقان أبداً — وتُرسله على دفعاتٍ بلا تحميله كلّه.
     */
    private function pdfResponse(string $path, string $receiptNumber): \Symfony\Component\HttpFoundation\Response
    {
        $absolute = Storage::disk('local')->path($path);

        return response()->file($absolute, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$receiptNumber}.pdf\"",
            'Cache-Control' => 'private, max-age=86400',
            // **لا يُضغط PDF.** هو مضغوطٌ أصلاً، وضغطُه في طبقةٍ وسيطة
            // يجعل الطولَ المعلَن يفارق المُرسَل — وهو نفس العطل من بابٍ آخر.
            'Content-Encoding' => 'identity',
            // يمنع وسيطاً من محاولة تخمين النوع فيُفسد الترويسات.
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * AMIAL-THERMAL-001 — GET /api/v1/amial/receipts/{id}/thermal?size=58|80
     * يُصيّر إيصالاً حرارياً (58مم/80مم) عند الطلب لطابعات POS. متزامن (سريع).
     */
    public function thermal(Request $request, int $id): \Symfony\Component\HttpFoundation\Response
    {
        $receipt = Receipt::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();
        if (!$receipt) {
            return $this->error('RECEIPT_NOT_FOUND', 'الإيصال غير موجود', 404);
        }

        // العرض: 58مم≈164pt، 80مم≈226pt (72pt/بوصة، 25.4مم/بوصة)
        $sizeMm = (int) $request->query('size', 58);
        $widthMm = in_array($sizeMm, [58, 80], true) ? $sizeMm : 58;
        $width = (int) round($widthMm / 25.4 * 72);

        $document = $this->documents->build($receipt);
        $qrDataUri = $this->qrDataUri((string) $document['verification_url']);

        // AMIAL-FIX(PDF-RTL): mPDF بدل DomPDF — تشكيل الحروف العربية وترتيبها.
        $html = view('receipts.thermal', [
            'document' => $document,
            'qrDataUri' => $qrDataUri,
            'width' => $width,
            'widthMm' => $widthMm,
        ])->render();

        $content = \App\Support\ArabicPdf::render($html, [
            'format' => \App\Support\ArabicPdf::thermalFormat($widthMm),
            'margin' => 3,
        ]);

        $receipt->incrementDownloadCount();
        $this->auditDocumentAccess($request, $receipt, "thermal_{$widthMm}");

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Length' => (string) strlen($content),
            'Content-Disposition' => "inline; filename=\"{$receipt->receipt_number}_{$widthMm}mm.pdf\"",
        ]);
    }

    /**
     * AMIAL-INVOICE-A4-001 — GET /api/v1/amial/receipts/{id}/invoice
     * يُصيّر فاتورة رسمية بمقاس A4 (وثيقة رسمية: ترويسة، بنود، إجماليات،
     * مبلغ كتابةً، ختم/توقيع، QR). متزامن.
     */
    public function invoice(Request $request, int $id): \Symfony\Component\HttpFoundation\Response
    {
        $receipt = Receipt::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();
        if (!$receipt) {
            return $this->error('RECEIPT_NOT_FOUND', 'الإيصال غير موجود', 404);
        }

        $document = $this->documents->build($receipt);
        $qrDataUri = $this->qrDataUri((string) $document['verification_url']);

        // AMIAL-FIX(PDF-FINAL): نخزّن ناتج التصيير مؤقّتاً (15 دقيقة) بمفتاح مرتبط
        // بآخر تعديل للإيصال. الإيصال ثابت المحتوى، فالتصيير مرّة واحدة يكفي —
        // وإن أسقط الخادمُ الاتصالَ أثناء الإرسال (شبكة بطيئة)، تصل محاولة
        // التطبيق التالية فوراً من الكاش بلا إعادة تصيير ثقيل.
        $cacheKey = "receipt_pdf_a4:v" . ReceiptDocumentService::DOCUMENT_VERSION
            . ":{$receipt->id}:{$receipt->reference_type}:{$receipt->reference_id}";
        $content = Cache::get($cacheKey);

        if ($content === null) {
            $html = view($this->documents->a4View($document), [
                'document' => $document,
                'qrDataUri' => $qrDataUri,
            ])->render();

            // القوالب الجديدة لا تضبط @page؛ ArabicPdf يفرض هامشاً واحداً.
            $content = \App\Support\ArabicPdf::render($html, ["format" => "A4", "margin" => 14]);

            try {
                Cache::put($cacheKey, $content, now()->addMinutes(15));
            } catch (\Throwable $e) { /* الكاش تحسين لا شرط */ }
        }

        $receipt->incrementDownloadCount();
        $this->auditDocumentAccess($request, $receipt, 'a4');

        $prefix = $document['kind'] === 'merchant_invoice' ? 'INV' : 'VOUCHER';

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Length' => (string) strlen($content),
            'Content-Disposition' => "inline; filename=\"{$prefix}-{$receipt->receipt_number}.pdf\"",
            'Cache-Control' => 'private, max-age=900',
            'Content-Encoding' => 'identity',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** يسجّل نجاح الطباعة الفعلية من طابعة التطبيق، لا مجرد تنزيل ملف. */
    public function recordPrint(Request $request, int $id): JsonResponse
    {
        $receipt = Receipt::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();
        if (!$receipt) {
            return $this->error('RECEIPT_NOT_FOUND', 'الإيصال غير موجود', 404);
        }

        $format = (string) $request->input('format', '');
        if (!in_array($format, ['thermal_58', 'thermal_80'], true)) {
            return $this->error('INVALID_PRINT_FORMAT', 'مقاس الطباعة غير مدعوم', 422);
        }

        $this->audit->record([
            'actor_type' => 'user',
            'actor_user_id' => $request->user()->id,
            'subject_type' => 'receipt',
            'subject_id' => $receipt->id,
            'action' => 'FINANCIAL_DOCUMENT_PRINTED',
            'decision_code' => 'THERMAL_PRINT_CONFIRMED',
            'transaction_id' => $receipt->reference_transaction_id,
            'zone_code' => $receipt->zone_code,
            'severity' => 'info',
            'context' => [
                'receipt_number' => $receipt->receipt_number,
                'format' => $format,
                'copies' => 1,
                'printer_name' => mb_substr((string) $request->input('printer_name', ''), 0, 80),
                'ip' => $request->ip(),
                'device' => mb_substr((string) $request->userAgent(), 0, 180),
            ],
        ]);

        return new JsonResponse([
            'success' => true,
            'code' => 'PRINT_RECORDED',
            'message' => 'تم تسجيل الطباعة',
            'errors' => (object) [],
            'meta' => (object) [],
        ]);
    }

    private function qrDataUri(string $url): ?string
    {
        try {
            $svg = \SimpleSoftwareIO\QrCode\Facades\QrCode::format('svg')
                ->size(150)->margin(0)->generate($url);
            return 'data:image/svg+xml;base64,' . base64_encode($svg);
        } catch (\Throwable) {
            return null;
        }
    }

    /** يسجّل إخراج المستند دون أن يلمس حقيقة المعاملة المالية. */
    private function auditDocumentAccess(Request $request, Receipt $receipt, string $format): void
    {
        $this->audit->record([
            'actor_type' => 'user',
            'actor_user_id' => $request->user()?->id,
            'subject_type' => 'receipt',
            'subject_id' => $receipt->id,
            'action' => 'FINANCIAL_DOCUMENT_RENDERED',
            'decision_code' => 'DOCUMENT_DELIVERED',
            'transaction_id' => $receipt->reference_transaction_id,
            'zone_code' => $receipt->zone_code,
            'severity' => 'info',
            'context' => [
                'receipt_number' => $receipt->receipt_number,
                'format' => $format,
                'copies' => 1,
                'ip' => $request->ip(),
                'device' => mb_substr((string) $request->userAgent(), 0, 180),
            ],
        ]);
    }

    /**
     * GET /v/{code}  (public, no auth)
     * تحقق من صحة إيصال عبر QR scan.
     */
    public function verifyPublic(string $code): JsonResponse
    {
        // AMIAL-RECEIPT-NUMBERS-001: يُقبل الشكلان — رقميّ (الجديد) وBase32
        // (القديم). ونسمح بالمسافات والشَرطات لأن العميل يكتب الكود كما
        // يراه مجموعاً على الورقة: «1234 5678 9012 3456».
        $code = preg_replace('/[\s\-]+/', '', strtoupper($code));

        if (!preg_match('/^([0-9]{16}|[A-Z2-9]{16})$/', $code)) {
            return $this->error('INVALID_CODE', 'صيغة رمز التحقّق غير صحيحة', 400);
        }

        $result = $this->service->verifyByCode($code);
        if (!$result) {
            return $this->error('RECEIPT_NOT_FOUND', 'لا إيصال صالح لهذا الرمز', 404);
        }

        return new JsonResponse([
            'success' => true,
            'code' => 'VERIFICATION_OK',
            'message' => 'Receipt is authentic',
            'errors' => (object)[],
            'meta' => $result,
        ]);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'code' => $code,
            'message' => $message,
            'errors' => (object)[],
            'meta' => (object)[],
        ], $status);
    }
}
