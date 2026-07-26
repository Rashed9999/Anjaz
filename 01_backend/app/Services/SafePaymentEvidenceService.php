<?php

namespace App\Services;

use App\Models\SafePayment;
use App\Models\SafePaymentEvidence;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * AMIAL-SAFEPAY-EVIDENCE-001 — أدلّة الدفع الآمن.
 *
 * **ما الذي يجعل الصورة دليلاً:**
 *   أن تُرفع فعلاً (لا نصّاً يدّعيه العميل)، وأن يُعرف من رفعها ومتى ومن
 *   أين، وأن تُحفظ بحيث لا يستطيع أحد تبديلها لاحقاً، وأن تُقرأ فقط ممّن
 *   له حقّ. أربعتها كانت غائبة.
 *
 * **قرارات مقصودة:**
 *   - قرص خاص لا عام: رابط عام لصورة نزاع يعني أن من يخمّن المسار يرى
 *     بضاعة الناس وفواتيرهم.
 *   - بصمة SHA-256 لكل ملفّ: تُثبت أنه لم يُبدَّل بعد الرفع. بلا بصمة،
 *     من يملك القرص يملك تغيير الأدلّة.
 *   - لا حذف ولا تعديل: طرف يستطيع سحب دليله بعد رؤية دليل خصمه لا يقدّم
 *     دليلاً بل يقامر. الإضافة مسموحة، السحب لا.
 *   - حدّ لكل مرحلة لا لكل العملية: خمس صور عند الشحن وخمس عند التسليم
 *     وخمس مع النزاع — لأن كلّ مرحلة تُثبت شيئاً مختلفاً.
 */
class SafePaymentEvidenceService
{
    public const STAGE_CREATED = 'created';
    public const STAGE_IN_DELIVERY = 'in_delivery';
    public const STAGE_DELIVERED = 'delivered';
    public const STAGE_DISPUTE = 'dispute';
    public const STAGE_ADMIN = 'admin_review';

    public const STAGES = [
        self::STAGE_CREATED, self::STAGE_IN_DELIVERY,
        self::STAGE_DELIVERED, self::STAGE_DISPUTE, self::STAGE_ADMIN,
    ];

    /** خمس صور لكل مرحلة. */
    public const MAX_PER_STAGE = 5;

    /** 8 ميغابايت — صورة هاتف حديثة تدخل، وملفّ ضخم لا يُعطّل الخادم. */
    public const MAX_BYTES = 8 * 1024 * 1024;

    public const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    /**
     * @param UploadedFile[] $files
     * @return array{stored: int, evidence: array}
     * @throws \RuntimeException
     */
    public function store(
        SafePayment $payment,
        User $uploader,
        string $role,
        string $stage,
        array $files,
        ?string $note = null,
    ): array {
        if (!in_array($stage, self::STAGES, true)) {
            throw new \RuntimeException('مرحلة غير معروفة');
        }

        $already = SafePaymentEvidence::where('safe_payment_id', $payment->id)
            ->where('stage', $stage)
            ->where('uploaded_by_user_id', $uploader->id)
            ->count();

        if ($already + count($files) > self::MAX_PER_STAGE) {
            throw new \RuntimeException(
                'الحدّ الأقصى ' . self::MAX_PER_STAGE . ' ملفات لكل مرحلة. رفعتَ ' . $already . ' سابقاً.'
            );
        }

        $stored = [];

        foreach ($files as $file) {
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                throw new \RuntimeException('ملفّ غير صالح');
            }

            $mime = (string) $file->getMimeType();
            if (!in_array($mime, self::ALLOWED_MIMES, true)) {
                throw new \RuntimeException('نوع الملفّ غير مسموح — صور أو PDF فقط');
            }

            if ($file->getSize() > self::MAX_BYTES) {
                throw new \RuntimeException('حجم الملفّ أكبر من 8 ميغابايت');
            }

            $contents = file_get_contents($file->getRealPath());
            $hash = hash('sha256', (string) $contents);

            // منع التكرار محصور بـ (العملية + المرحلة + الرافع).
            //
            // كان يشمل العملية كلها، فصورة يرفعها البائع عند الشحن ثم يرفعها
            // ثانيةً عند التسليم تُبتلع الثانية بصمت — وهو استعمال مشروع
            // تماماً (نفس الطرد في مرحلتين). والأسوأ: دليل المشتري لو صادف
            // أن طابق دليل البائع بايتاً ببايت لم يُسجَّل أصلاً.
            $duplicate = SafePaymentEvidence::where('safe_payment_id', $payment->id)
                ->where('stage', $stage)
                ->where('uploaded_by_user_id', $uploader->id)
                ->where('sha256', $hash)->first();
            if ($duplicate) {
                $stored[] = $duplicate;
                continue;
            }

            $extension = $file->getClientOriginalExtension() ?: 'bin';
            $path = sprintf(
                'safe-payments/%s/%s/%s.%s',
                $payment->payment_ulid,
                $stage,
                Str::ulid(),
                strtolower($extension),
            );

            $this->disk()->put($path, $contents);

            $stored[] = SafePaymentEvidence::create([
                'safe_payment_id' => $payment->id,
                'uploaded_by_user_id' => $uploader->id,
                'role' => $role,
                'stage' => $stage,
                'path' => $path,
                'original_name' => mb_substr((string) $file->getClientOriginalName(), 0, 255),
                'mime' => $mime,
                'size_bytes' => (int) $file->getSize(),
                'sha256' => $hash,
                'ip_address' => request()?->ip(),
                'user_agent' => mb_substr(request()?->userAgent() ?? '', 0, 500),
                'note' => $note,
            ]);
        }

        return [
            'stored' => count($stored),
            'evidence' => array_map(fn (SafePaymentEvidence $e) => $this->present($e), $stored),
        ];
    }

    /** أدلّة عملية مجموعةً بالمرحلة — الطرفان يريان الشيء نفسه. */
    public function timeline(SafePayment $payment): array
    {
        return SafePaymentEvidence::where('safe_payment_id', $payment->id)
            ->orderBy('created_at')
            ->get()
            ->map(fn (SafePaymentEvidence $e) => $this->present($e))
            ->groupBy('stage')
            ->toArray();
    }

    /** محتوى ملفّ — يُستدعى بعد التحقّق من الصلاحية في المتحكّم. */
    public function read(SafePaymentEvidence $evidence): ?string
    {
        return $this->disk()->exists($evidence->path)
            ? $this->disk()->get($evidence->path)
            : null;
    }

    /**
     * هل الملفّ كما رُفع؟
     *
     * تُستدعى عند العرض للإدارة: قرار مالي يُبنى على دليل، فيجب أن يُقال
     * للمراجع صراحةً إن كانت بصمة الملفّ لم تعد تطابق المسجَّلة.
     */
    public function verifyIntegrity(SafePaymentEvidence $evidence): bool
    {
        $contents = $this->read($evidence);

        return $contents !== null && hash('sha256', $contents) === $evidence->sha256;
    }

    public function present(SafePaymentEvidence $e): array
    {
        return [
            'id' => $e->id,
            'role' => $e->role,
            'stage' => $e->stage,
            'mime' => $e->mime,
            'size_bytes' => $e->size_bytes,
            'original_name' => $e->original_name,
            // البصمة تُعرض مختصرةً: تكفي للمقارنة ولا تُثقل الواجهة.
            'fingerprint' => substr($e->sha256, 0, 12),
            'uploaded_at' => optional($e->created_at)->toIso8601String(),
        ];
    }

    private function disk()
    {
        // القرص الخاصّ نفسه الذي تُخزَّن فيه الإيصالات و KYC.
        return Storage::disk(config('amial.receipts.storage_disk', 'local'));
    }
}
