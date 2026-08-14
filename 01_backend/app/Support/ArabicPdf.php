<?php

namespace App\Support;

use Mpdf\Mpdf;

/**
 * AMIAL-FIX(PDF-RTL): مولّد PDF عربي سليم.
 *
 * **المشكلة:** DomPDF لا يدعم تشكيل الحروف العربية (letter shaping/joining)
 * ولا إعادة ترتيب النصّ ثنائي الاتجاه (bidi). النتيجة: أحرف منفصلة ومقلوبة —
 * وهو ما ظهر في إيصالات المستخدمين. هذا قصور في المحرّك لا في القالب.
 *
 * **الحلّ:** mPDF — المعيار العملي للعربية في PHP: يشكّل الحروف ويصلها،
 * ويعيد ترتيب الأسطر ثنائية الاتجاه، ويختار الخطّ تلقائياً حسب النصّ.
 *
 * الاستخدام:
 *   $bytes = ArabicPdf::render(view('receipts.a4-invoice', $data)->render());
 */
class ArabicPdf
{
    /**
     * يُصيّر HTML عربياً إلى بايتات PDF.
     *
     * @param string $html محتوى HTML كامل (يفضَّل أن يحمل dir="rtl").
     * @param array{format?:string|array,margin?:int} $opts
     */
    public static function render(string $html, array $opts = []): string
    {
        // ══════════════════════════════════════════════════════════════
        //  AMIAL-GATE-BLINDSPOT-001 — **يُرفع الحدُّ ولا يُخفض أبداً.**
        //
        //  كان السطرُ `ini_set('memory_limit', '512M')` بلا شرط، وهو
        //  **يخفض** الحدَّ متى كان أعلى — ويبقى منخفضاً لبقيّة العمليّة
        //  كلِّها لا لهذه الدالّة وحدها.
        //
        //  **والثمن:** أوّلُ اختبارٍ يُصيّر إيصالاً يقصّ سقفَ العمليّة إلى
        //  ٥١٢ ميغابايت، فتنهار مجموعةُ الاختبارات بعده بمئات الاختبارات:
        //  «Allowed memory size of 536870912 bytes exhausted». ولأنّ
        //  الانهيارَ يمنع طباعةَ سطر النتيجة، كانت البوّابةُ تقرأ الغيابَ
        //  نجاحاً وتطبع `✓` فارغاً في الطبقة التاسعة.
        //
        //  وفي الإنتاج أخطر: طلبٌ يطبع إيصالاً يقصّ سقفَ بقيّة عمله.
        // ══════════════════════════════════════════════════════════════
        $current = trim((string) ini_get('memory_limit'));

        if (self::toBytes($current) < 512 * 1024 * 1024) {
            @ini_set('memory_limit', '512M');
        }

        @set_time_limit(120);

        $format = $opts['format'] ?? 'A4';
        $margin = (int) ($opts['margin'] ?? 10);
        $tempDir = self::tempDir();

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => $format,
            'tempDir' => $tempDir,
            // تشكيل الحروف + الاتجاه: جوهر الإصلاح
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'directionality' => 'rtl',
            'margin_left' => $margin,
            'margin_right' => $margin,
            'margin_top' => $margin,
            'margin_bottom' => $margin,
        ]);

        $mpdf->SetDirectionality('rtl');
        $mpdf->SetTitle('Amial Pay');
        // لا رأس/تذييل تلقائي — القالب يتحكّم بالتصميم كاملاً.
        $mpdf->WriteHTML($html);

        return (string) $mpdf->Output('', 'S');
    }

    /**
     * AMIAL-PDF-DURABLE-001 — مجلّد mPDF المؤقّت، يُنشأ عند الحاجة.
     *
     * mPDF يكتب في هذا المجلّد بيانات الخطوط والصور أثناء التصيير. غيابه أو
     * منع الكتابة فيه يرمي استثناءً وسط توليد الإيصال.
     *
     * ولماذا يغيب أصلاً؟ `storage/app` مثبَّت عليه volume دائم، و volume
     * يحجب ما أنشأته الصورة تحته ولا يستقبل مجلدات جديدة تُضاف إليها لاحقاً.
     * فكل نشرة تبدأ بمجلّد غير موجود.
     *
     * الإصلاح الأساسي في entrypoint. وهذا خطّ ثانٍ: لا ينبغي لإيصال عميل أن
     * يتوقّف على ترتيب خطوات النشر. والاحتياط الأخير مجلّد النظام المؤقّت —
     * إيصال يُولَّد من مجلّد مؤقّت خير من إيصال لا يُولَّد.
     */
    public static function tempDir(): string
    {
        $dir = storage_path('app/mpdf');

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        if (is_dir($dir) && is_writable($dir)) {
            return $dir;
        }

        $fallback = sys_get_temp_dir() . '/amial-mpdf';
        if (!is_dir($fallback)) {
            @mkdir($fallback, 0775, true);
        }

        // يُسجَّل كتحذير لا كخطأ: الإيصال خرج، لكن الإعداد يحتاج إصلاحاً
        // وإلا بقي التوليد بطيئاً (ذاكرة الخطوط تُعاد بناؤها كل مرّة).
        \Illuminate\Support\Facades\Log::warning(
            'AMIAL-PDF: تعذّرت الكتابة في storage/app/mpdf — استُعمل المجلّد المؤقّت.',
            ['expected' => $dir, 'used' => $fallback]
        );

        if (!is_dir($fallback) || !is_writable($fallback)) {
            throw new \RuntimeException(
                'تعذّر توليد ملفّ PDF: لا يوجد مجلّد مؤقّت قابل للكتابة. '
                . 'شغّل `php artisan amial:pdf-doctor` على الخادم لمعرفة السبب.'
            );
        }

        return $fallback;
    }

    /** مقاس الطابعة الحرارية بالمليمتر (عرض × ارتفاع سخيّ يُقصّ). */
    public static function thermalFormat(int $widthMm): array
    {
        return [$widthMm, 300];
    }

    /**
     * يحوّل صيغةَ `memory_limit` إلى بايتات.
     *
     * **و`-1` بلا حدّ** — تُعامَل أكبرَ من كلّ شيءٍ لا صفراً. ولو قُرئت
     * صفراً لخُفض الحدُّ في كلّ بيئةٍ غيرِ محدودة، وهو عينُ العطل.
     */
    private static function toBytes(string $v): int
    {
        if ($v === '' || $v === '-1') {
            return PHP_INT_MAX;
        }

        $unit = strtolower(substr($v, -1));
        $n = (int) $v;

        return match ($unit) {
            'g' => $n * 1024 * 1024 * 1024,
            'm' => $n * 1024 * 1024,
            'k' => $n * 1024,
            default => $n,
        };
    }
}
