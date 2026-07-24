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
        @ini_set('memory_limit', '512M');
        @set_time_limit(120);

        $format = $opts['format'] ?? 'A4';
        $margin = (int) ($opts['margin'] ?? 10);

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => $format,
            'tempDir' => storage_path('app/mpdf'),
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
        $mpdf->SetTitle('Amyal Pay');
        // لا رأس/تذييل تلقائي — القالب يتحكّم بالتصميم كاملاً.
        $mpdf->WriteHTML($html);

        return (string) $mpdf->Output('', 'S');
    }

    /** مقاس الطابعة الحرارية بالمليمتر (عرض × ارتفاع سخيّ يُقصّ). */
    public static function thermalFormat(int $widthMm): array
    {
        return [$widthMm, 300];
    }
}
