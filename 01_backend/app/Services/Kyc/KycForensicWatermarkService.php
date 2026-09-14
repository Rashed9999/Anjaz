<?php

namespace App\Services\Kyc;

use App\Models\KycDocument;
use App\Models\User;
use App\Services\KycDocumentService;
use App\Services\PiiAccessAuditService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * AMIAL-KYC-FORENSIC-001 — كل نسخةٍ يراها موظف تحمل بصمته هو.
 *
 * الأصل المشفّر لا يتغيّر أبداً. نفكّه في الذاكرة، نحرق علامةً مائية داخل
 * البكسلات، ثم نرسل نسخة المشاهدة فقط. لذلك لا يستطيع الموظف إزالة العلامة
 * بإخفاء طبقة HTML من أدوات المطوّر، وحتى تصوير الشاشة بهاتف آخر يحتفظ
 * برمز المشاهدة وموظفها.
 */
class KycForensicWatermarkService
{
    public const VERSION = 'fw-1';

    public function __construct(
        private readonly KycDocumentService $documents,
        private readonly PiiAccessAuditService $pii,
    ) {}

    /**
     * @return array{bytes:string,mime:string,trace_code:string}
     */
    public function render(KycDocument $doc, User $viewer, string $reason): array
    {
        $this->pii->logAccess(
            actorUserId: (int) $viewer->id,
            subjectType: 'user',
            subjectId: (int) $doc->user_id,
            fieldName: 'kyc_document:' . $doc->doc_type,
            accessType: 'view',
            accessReason: $reason,
        );

        $trace = $this->newTraceCode();
        $binary = $this->documents->decrypt($doc);
        $mime = strtolower((string) ($doc->original_mime ?: ''));

        [$bytes, $outputMime] = $this->burn($binary, $mime, $viewer, $trace);

        DB::table('kyc_forensic_views')->insert([
            'trace_code' => $trace,
            'document_id' => (int) $doc->id,
            'subject_user_id' => (int) $doc->user_id,
            'actor_user_id' => (int) $viewer->id,
            'doc_type' => (string) $doc->doc_type,
            'watermark_version' => self::VERSION,
            'source_mime' => $mime ?: null,
            'session_fingerprint' => $this->sessionFingerprint(),
            'ip_address' => request()?->ip(),
            'user_agent' => mb_substr((string) (request()?->userAgent() ?? ''), 0, 500),
            'access_reason' => mb_substr(trim($reason), 0, 500),
            'viewed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['bytes' => $bytes, 'mime' => $outputMime, 'trace_code' => $trace];
    }

    private function newTraceCode(): string
    {
        // 80 بت من العشوائية تقريباً، قصير بما يكفي ليبقى مقروءاً داخل الصورة.
        do {
            $trace = 'AM-' . strtoupper(bin2hex(random_bytes(10)));
        } while (DB::table('kyc_forensic_views')->where('trace_code', $trace)->exists());

        return $trace;
    }

    private function sessionFingerprint(): ?string
    {
        try {
            $id = session()->getId();
            return $id !== '' ? hash('sha256', $id) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{0:string,1:string}
     */
    private function burn(string $binary, string $sourceMime, User $viewer, string $trace): array
    {
        if (!function_exists('imagecreatefromstring')) {
            throw new RuntimeException('KYC_SECURE_PREVIEW_GD_UNAVAILABLE');
        }

        // لا نعيد الأصل غير المعلّم عند صيغة لا يستطيع الخادم فكها. الفشل
        // المغلق هنا مقصود: المعاينة المعطوبة أفضل من تسريب أصل بلا بصمة.
        $image = @imagecreatefromstring($binary);
        if ($image === false) {
            throw new RuntimeException('KYC_SECURE_PREVIEW_UNSUPPORTED_FORMAT');
        }

        $width = imagesx($image);
        $height = imagesy($image);
        if ($width < 1 || $height < 1 || ($width * $height) > 40_000_000) {
            imagedestroy($image);
            throw new RuntimeException('KYC_SECURE_PREVIEW_INVALID_DIMENSIONS');
        }

        imagealphablending($image, true);
        imagesavealpha($image, true);

        $dark = imagecolorallocatealpha($image, 0, 0, 0, 78);
        $light = imagecolorallocatealpha($image, 255, 255, 255, 62);
        $accent = imagecolorallocatealpha($image, 180, 0, 0, 48);

        $stamp = sprintf(
            'AMIAL PAY | EMP#%d | %s | VIEW %s',
            (int) $viewer->id,
            now()->format('Y-m-d H:i:s'),
            $trace,
        );

        // تتكرر العلامة في كامل الصورة؛ قص الحواف لا يزيلها.
        $stepY = max(54, (int) floor($height / 7));
        $stepX = max(240, (int) floor($width / 2));
        for ($y = 18; $y < $height; $y += $stepY) {
            for ($x = -80 + (($y / $stepY) % 2 ? 80 : 0); $x < $width; $x += $stepX) {
                imagestring($image, 3, (int) $x + 1, $y + 1, $stamp, $light);
                imagestring($image, 3, (int) $x, $y, $stamp, $dark);
            }
        }

        // شريط سفلي أقوى يبقى مقروءاً في لقطات الشاشة المضغوطة.
        $barHeight = min(44, max(28, (int) floor($height * 0.08)));
        imagefilledrectangle($image, 0, max(0, $height - $barHeight), $width, $height, $accent);
        imagestring(
            $image,
            4,
            8,
            max(4, $height - $barHeight + 7),
            sprintf('EMP#%d | %s | %s', (int) $viewer->id, $trace, now()->format('Y-m-d H:i:s')),
            $light,
        );

        ob_start();
        if ($sourceMime === 'image/png') {
            imagepng($image, null, 6);
            $outMime = 'image/png';
        } else {
            imagejpeg($image, null, 90);
            $outMime = 'image/jpeg';
        }
        $out = ob_get_clean();
        imagedestroy($image);

        if (!is_string($out) || $out === '') {
            throw new RuntimeException('KYC_SECURE_PREVIEW_RENDER_FAILED');
        }

        return [$out, $outMime];
    }
}
