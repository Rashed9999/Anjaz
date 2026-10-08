<?php

namespace App\Services\Catalog;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * AMIAL-CATALOG-IMAGE-001 — **صورةُ الصنف، محدودةً بحجمها.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **السؤال الذي وُلدت منه:** «لنفترض مليون منتج، أين تُحفظ الصور
 * والتفاصيل؟ ألن يُخرِّب السيرفر ويُثقله؟»
 *
 * والجوابُ بنيويٌّ لا حظّ: **الصورةُ واحدةٌ لكلّ باركود، لا لكلّ منتجِ
 * تاجر.** فألفُ تاجرٍ يبيعون «شاي ليبتون» يشيرون إلى صفٍّ واحدٍ وصورةٍ
 * واحدة. و`merchant_products` ليس فيه عمودُ صورةٍ إطلاقاً — عمداً.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولماذا لا تُستعمل `Helpers::upload` هنا؟**
 *
 * قِيست فوُجدت قاتلةً لهذا الاستعمال بعينه:
 *
 *   · سقفُها **٢٥٠٠ بكسل** — وصورةٌ بهذا الحجم ٣٠٠ إلى ٨٠٠ كيلوبايت.
 *   · **وwebp يُنسخ بلا تصغيرٍ إطلاقاً** (`copy()`) — فملفُّ ٤ ميغابايت
 *     يمرّ كما هو.
 *
 * فمئةُ ألف باركود × ٥٠٠ ك.ب = **٥٠ غيغابايت**. وهي على قرص الخادم
 * نفسِه، **وكلُّ نسخةٍ احتياطيّةٍ تنسخها كاملة**.
 *
 * وبسقفِ ٤٠٠ بكسل هنا تصير الصورةُ ~٢٥ ك.ب، فالمئةُ ألف **٢٫٥ غيغابايت**
 * — عشرون ضعفاً أقلّ. وأربعُمئةِ بكسلٍ تكفي بطاقةَ صنفٍ في هاتف، ومن
 * يريد أكبرَ منها يريد ما لا تعرضه أيُّ شاشةٍ عندنا.
 * ══════════════════════════════════════════════════════════════════════
 */
class CatalogImageService
{
    /** أطولُ ضلعٍ بعد التصغير. */
    public const MAX_EDGE = 400;

    /** جودةُ webp — ٧٢ لا تُرى بالعين على ٤٠٠ بكسل وتوفّر الثلث. */
    private const QUALITY = 72;

    /** أقصى مدخلٍ يُقبل قبل المعالجة (٥ ميغابايت). */
    public const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;

    private const DIR = 'catalog';

    /**
     * يُصغّر ويحفظ، ويُعيد المسارَ النسبيّ داخل القرص العامّ.
     *
     * @throws RuntimeException على ملفٍّ ليس صورةً أو صيغةٍ غير مدعومة
     */
    public function store(UploadedFile $file): string
    {
        if ($file->getSize() > self::MAX_UPLOAD_BYTES) {
            throw new RuntimeException('الصورة أكبر من ٥ ميغابايت');
        }

        $path = $file->getRealPath();
        $info = @getimagesize($path);

        // **`getimagesize` هي الفحصُ الحقيقيّ لا الامتداد.** ملفٌّ اسمه
        // `.jpg` وفيه نصٌّ برمجيّ يمرّ من فحص الامتداد ويسقط هنا.
        if (! $info || empty($info['mime'])) {
            throw new RuntimeException('الملفّ ليس صورةً صالحة');
        }

        $src = match (strtolower($info['mime'])) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            default => null,
        };

        if (! $src) {
            throw new RuntimeException('صيغةٌ غير مدعومة — استعمل JPG أو PNG أو WEBP');
        }

        try {
            $out = $this->downscale($src);

            // ══════════════════════════════════════════════════════════
            // AMIAL-PROD-DEFECTS-001 — **WebP ليس مضموناً في كلّ بناءِ GD.**
            //
            // كان يُنادى `imagewebp()` مباشرةً، وخادمُ الإنتاج بُني بـGD
            // **بلا WebP** — فيخرج `Call to undefined function
            // imagewebp()`. وهو **خطأٌ فادحٌ لا استثناءٌ يُلتقط**: يسقط
            // رفعُ الصورة كلَّه بـ٥٠٠، وقد بلّغ عنه مركزُ الأعطال على
            // `/admin/amial/charity/uploads`.
            //
            // **ولا يظهر هنا إطلاقاً**: بناءُ GD في هذه الحاوية يدعمه،
            // فالشيفرةُ سليمةٌ محلّيّاً ومكسورةٌ على الخادم. وهذا صنفٌ
            // لا يمسكه اختبارٌ يجري في بيئةٍ واحدة.
            //
            // **والبديلُ JPEG لا رسالةُ خطأ**: صورةُ المنتج غرضُها أن
            // تُعرَض؛ ورفضُ الرفع لأنّ صيغةَ الضغط غيرُ متاحةٍ يُعطّل
            // الكتالوجَ كلَّه على أمرٍ لا يخصّ صاحبَه.
            // ══════════════════════════════════════════════════════════
            $webp = function_exists('imagewebp');
            $name = Str::ulid() . ($webp ? '.webp' : '.jpg');
            $dir = storage_path('app/public/' . self::DIR);

            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $saved = $webp
                ? imagewebp($out, $dir . '/' . $name, self::QUALITY)
                : imagejpeg($out, $dir . '/' . $name, self::QUALITY);

            if (! $saved) {
                throw new RuntimeException('تعذّر حفظُ الصورة');
            }

            return self::DIR . '/' . $name;
        } finally {
            // **يُحرَّر دائماً** — ولو سقط الحفظ. وصورةُ GD تبقى في ذاكرة
            // العمليّة، فتسريبٌ في مسارٍ يُستدعى ألفَ مرّةٍ يُنهي الذاكرة.
            if (isset($out) && $out !== $src) {
                imagedestroy($out);
            }
            imagedestroy($src);
        }
    }

    /** يحذف صورةً سابقة — ولا يشكو إن كانت غائبة. */
    public function forget(?string $path): void
    {
        if ($path && str_starts_with($path, self::DIR . '/')) {
            Storage::disk('public')->delete($path);
        }
    }

    /**
     * @param  \GdImage  $src
     * @return \GdImage
     */
    private function downscale($src)
    {
        $w = imagesx($src);
        $h = imagesy($src);
        $max = max($w, $h);

        // **الصغيرةُ لا تُكبَّر.** تكبيرُ صورةِ ١٥٠ بكسل إلى ٤٠٠ يزيد
        // الحجمَ ولا يزيد التفصيل — بايتاتٌ ثمنُها لا شيء.
        if ($max <= self::MAX_EDGE) {
            return $src;
        }

        $ratio = self::MAX_EDGE / $max;
        $nw = max(1, (int) round($w * $ratio));
        $nh = max(1, (int) round($h * $ratio));

        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        return $dst;
    }
}
