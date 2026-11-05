<?php

namespace App\Services\Merchant;

use App\Models\Merchant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * هويةُ التاجر البصرية مصدرٌ واحدٌ للتطبيق والفاتورة والطباعة.
 *
 * لا يُحفظ الشعار كما أرسله الهاتف؛ فصورة عريضة أو ضخمة تجعل الفاتورة
 * مختلفة بين الورق الحراري وA4. نحوله دائماً إلى PNG مربع شفاف 1024×1024
 * مع هامش آمن، من غير قصّ العلامة أو تشويه نسبها.
 */
class MerchantLogoService
{
    public const CANVAS_SIZE = 1024;
    public const CONTENT_BOX = 880;
    public const MIN_SOURCE_SIDE = 256;
    public const MAX_SOURCE_PIXELS = 16_000_000;
    public const MAX_UPLOAD_BYTES = 2 * 1024 * 1024;

    /** @return array{canvas:int,content_box:int,max_upload_bytes:int,formats:list<string>} */
    public static function specification(): array
    {
        return [
            'canvas' => self::CANVAS_SIZE,
            'content_box' => self::CONTENT_BOX,
            'max_upload_bytes' => self::MAX_UPLOAD_BYTES,
            'formats' => ['PNG', 'JPG'],
        ];
    }

    public function replaceFromBase64(Merchant $merchant, string $encoded): string
    {
        $payload = preg_replace('~^data:[^,]*,~i', '', trim($encoded)) ?? '';
        $bytes = base64_decode($payload, true);

        if ($bytes === false || $bytes === '') {
            throw new \InvalidArgumentException('الشعار ليس بصورة صالحة');
        }

        return $this->replaceFromBytes($merchant, $bytes);
    }

    public function replaceFromUploadedFile(Merchant $merchant, UploadedFile $file): string
    {
        if (! $file->isValid()) {
            throw new \InvalidArgumentException('ملف الشعار تالف');
        }

        return $this->replaceFromBytes($merchant, (string) file_get_contents($file->getRealPath()));
    }

    /** صورة مضمنة آمنة لقوالب PDF؛ لا تتصل القوالب بعنوان خارجي. */
    public function dataUri(?Merchant $merchant): ?string
    {
        $filename = $merchant?->logo ? basename((string) $merchant->logo) : null;
        if (! $filename) return null;

        $path = 'merchant/' . $filename;
        $disk = Storage::disk('public');
        if (! $disk->exists($path)) return null;

        $mime = match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'image/png',
        };

        return 'data:' . $mime . ';base64,' . base64_encode((string) $disk->get($path));
    }

    private function replaceFromBytes(Merchant $merchant, string $bytes): string
    {
        if (strlen($bytes) > self::MAX_UPLOAD_BYTES) {
            throw new \InvalidArgumentException('حجم الشعار يتجاوز ٢ ميجابايت');
        }

        $info = @getimagesizefromstring($bytes);
        if ($info === false || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
            throw new \InvalidArgumentException('يقبل الشعار بصيغة PNG أو JPG فقط');
        }

        [$width, $height] = $info;
        if (min($width, $height) < self::MIN_SOURCE_SIDE) {
            throw new \InvalidArgumentException('أصغر ضلع في الشعار يجب أن يكون ٢٥٦ بكسل على الأقل');
        }
        if ($width * $height > self::MAX_SOURCE_PIXELS) {
            throw new \InvalidArgumentException('أبعاد الشعار كبيرة جداً');
        }

        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            throw new \InvalidArgumentException('تعذّرت قراءة صورة الشعار');
        }

        $canvas = imagecreatetruecolor(self::CANVAS_SIZE, self::CANVAS_SIZE);
        imagealphablending($canvas, false);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 255, 255, 255, 127));
        imagesavealpha($canvas, true);

        $scale = min(self::CONTENT_BOX / $width, self::CONTENT_BOX / $height);
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));
        $targetX = (int) floor((self::CANVAS_SIZE - $targetWidth) / 2);
        $targetY = (int) floor((self::CANVAS_SIZE - $targetHeight) / 2);

        imagecopyresampled(
            $canvas,
            $source,
            $targetX,
            $targetY,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $width,
            $height,
        );
        imagedestroy($source);

        $filename = 'logo-' . now()->format('Ymd') . '-' . Str::lower(Str::random(20)) . '.png';
        $relativePath = 'merchant/' . $filename;
        $disk = Storage::disk('public');
        if (! $disk->exists('merchant')) {
            $disk->makeDirectory('merchant');
        }

        $path = $disk->path($relativePath);
        $written = imagepng($canvas, $path, 6);
        imagedestroy($canvas);

        if (! $written) {
            throw new \RuntimeException('تعذّر حفظ الشعار');
        }

        $old = $merchant->logo;
        $merchant->logo = $filename;
        $merchant->save();

        if ($old && $old !== $filename && $disk->exists('merchant/' . $old)) {
            $disk->delete('merchant/' . $old);
        }

        return $filename;
    }
}
