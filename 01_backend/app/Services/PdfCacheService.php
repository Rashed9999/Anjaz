<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * AMIAL-PDF-CACHE-001 — يُصيَّر المستند مرّة ويُخدَم بعدها كقراءة ملفّ.
 *
 * **العطل الذي يعالجه، وقد وقع فعلاً:**
 * تنزيل الإيصال كان يفشل بـ «Connection closed while receiving data». والسبب
 * أن كل تنزيل يُصيّر المستند كاملاً داخل الطلب — بطيء على خادم صغير، فيُقطع
 * الاتصال على شبكة جوّال قبل أن يكتمل الإرسال.
 *
 * وحين فُحص مسار الإيصال وحده تبيّن أن الخلل ليس فيه وحده: WholesaleInvoice
 * و CreditStatement و FuelReceipt جميعها تُصيّر في كل طلب ولا تُخزَّن قطّ،
 * ومعها `Cache-Control: no-store` فلا يحتفظ العميل بنسخة أيضاً. أي أن فاتورة
 * واحدة يفتحها التاجر عشر مرّات تُصيَّر عشراً.
 *
 * فبدل إصلاح كل نقطة على حدة، هذه الطبقة تُصيّر مرّة وتحفظ. والمرّات التالية
 * قراءةُ ملفّ — أجزاء من الثانية. وهو ما يفعله كل نظام فوترة.
 *
 * **المفتاح يجب أن يتغيّر متى تغيّر المحتوى.** فاتورة تُعدَّل ثم تُفتح يجب
 * أن تُصيَّر من جديد، وإلّا خُدمت النسخة القديمة — وهي أسوأ من بطء: رقمٌ
 * خاطئ يظهر واثقاً. ولهذا يدخل في المفتاح ما يتبدّل بتبدّل المحتوى
 * (`updated_at`، ومدى التاريخ في الكشوف).
 */
class PdfCacheService
{
    /** المجلّد تحت disk('local') — مذكور في Dockerfile و entrypoint معاً. */
    private const DIR = 'documents';

    /**
     * يُعيد بايتات المستند: من القرص إن وُجد، وإلّا يُصيّره ويحفظه.
     *
     * @param  string   $key        معرّف يشمل كل ما يغيّر المخرجات.
     * @param  callable $render     يُنتج البايتات — لا يُستدعى إلا عند الحاجة.
     */
    public function remember(string $key, callable $render): string
    {
        $path = self::DIR . '/' . $this->safeName($key) . '.pdf';
        $disk = Storage::disk('local');

        try {
            if ($disk->exists($path)) {
                $bytes = $disk->get($path);
                // ملفّ فارغ أو مبتور من تصيير سابق انقطع: يُعامَل كغائب.
                // خدمتُه تُنتج نفس العطل الذي نعالجه، وبلا سبب ظاهر هذه المرّة.
                if (strlen($bytes) > 100 && str_starts_with($bytes, '%PDF')) {
                    return $bytes;
                }
                $disk->delete($path);
            }
        } catch (\Throwable $e) {
            // قرص غير قابل للقراءة لا يمنع التصيير — يمنع التسريع وحده.
            Log::warning('PDF cache read failed', ['path' => $path, 'error' => $e->getMessage()]);
        }

        $bytes = $render();

        try {
            $disk->put($path, $bytes);
        } catch (\Throwable $e) {
            // الحفظ تحسينٌ لا شرط. فشلُه يُسجَّل ويُخدَم المستند من الذاكرة،
            // إذ إسقاط تنزيل ناجح لأن التخزين تعذّر خسارةٌ بلا مقابل.
            Log::warning('PDF cache write failed', ['path' => $path, 'error' => $e->getMessage()]);
        }

        return $bytes;
    }

    /**
     * يُبطل نسخةً محفوظة — يُنادى عند تعديل مستند لا يحمل `updated_at`.
     */
    public function forget(string $key): void
    {
        try {
            Storage::disk('local')->delete(self::DIR . '/' . $this->safeName($key) . '.pdf');
        } catch (\Throwable $e) {
            Log::warning('PDF cache delete failed', ['key' => $key]);
        }
    }

    /**
     * اسم ملفّ آمن مهما كان المفتاح.
     *
     * المفاتيح تحوي معرّفات وتواريخ وقد تحوي عربية (اسم عميل في كشف حساب).
     * والتمرير الحرفي يفتح باب `../` ويكسر أنظمة ملفّات لا تقبل بعض المحارف.
     * فيُبقى المقروء منها ويُلحق بها تلبيدٌ يضمن التمييز.
     */
    private function safeName(string $key): string
    {
        $readable = preg_replace('/[^A-Za-z0-9_\-]/', '_', $key);
        return mb_substr($readable, 0, 60) . '_' . substr(sha1($key), 0, 12);
    }
}
