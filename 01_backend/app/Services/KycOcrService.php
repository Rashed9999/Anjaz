<?php

namespace App\Services;

use App\Models\KycDocument;
use App\Models\User;
use App\Services\Ocr\IdFieldExtractor;
use App\Services\Ocr\OcrDriverInterface;
use App\Services\Ocr\OcrResult;
use DomainException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * AMIAL-KYC-OCR-001 — قراءة وثيقة الهوية وربط ناتجها بالمراجعة.
 *
 * **الحدّ الذي لا يُتجاوز:** OCR **يقترح ولا يقرّر**. اعتمادُ هويّةٍ يفتح
 * حدوداً مالية أعلى، ومحرّكٌ يقرأ صورةً بهاتفٍ في إضاءةٍ رديئة ليس ما
 * يُتّخذ عنده هذا القرار. فالمستخرَج يُعرَض للمراجع في مربّعاتٍ قابلة
 * للتعديل، وما يُعتمَد هو **ما أقرّه هو** لا ما قالته الآلة.
 *
 * ولذلك يُحفَظ الاثنان منفصلين: `ocr_extracted` قولُ المحرّك،
 * و`verified_fields` إقرارُ الإنسان. وخلطُهما يجعل قراءةَ آلةٍ تصير إقراراً
 * موثّقاً بلا أن يقرّ أحد.
 *
 * **والملاحظات الحتميّة تُحسب ولا تُقرّر:** «الوثيقة منتهية» حقيقةٌ تُقارن
 * بالتقويم، و«الاسم لا يطابق الحساب» **ليست** كذلك — الأسماء تُكتب بصيغٍ
 * مختلفة، وتتغيّر، وتُنقل حرفيّاً بطرقٍ شتّى. فالأولى تُغلق الطريق،
 * والثانية تُنبّه المراجع وتترك له القرار.
 */
class KycOcrService
{
    public function __construct(
        private readonly EncryptedFileStorage $storage,
        private readonly IdFieldExtractor $extractor,
        private readonly OcrDriverInterface $driver,
    ) {
    }

    public function enabled(): bool
    {
        return (bool) config('amial.kyc.ocr.enabled', true);
    }

    private function minConfidence(): float
    {
        return (float) config('amial.kyc.ocr.min_confidence', 70);
    }

    /**
     * يقرأ المستند ويُخزّن الناتج عليه.
     *
     * **لا يرمي استثناءً أبداً.** فشلُ القراءة لا يجوز أن يُفشل الرفع: العميل
     * رفع وثيقته، والوثيقة محفوظة، وعطلُ محرّكٍ مساعد شأنُنا لا شأنه. تُسجَّل
     * الحالة ويمضي المسار.
     */
    public function process(KycDocument $doc): KycDocument
    {
        if (!$this->enabled()) {
            return $doc;
        }

        // PDF متعدّد الصفحات وغيره ممّا لا يقرؤه المحرّك مباشرةً: يُترك
        // للمراجعة اليدوية بحالةٍ صريحة، لا يُدّعى فشلاً في الوثيقة.
        if (!$this->readable($doc)) {
            return $this->store($doc, new OcrResult(
                status: OcrResult::STATUS_FAILED,
                error: 'صيغة لا يقرؤها المحرّك مباشرةً — تُراجَع يدويّاً',
            ), []);
        }

        $temp = null;

        try {
            $temp = $this->storage->decryptToTemp($doc->encrypted_path);
            $result = $this->driver->read($temp);

            $fields = $result->usable()
                ? $this->extractor->extract($result->rawText)
                : [];

            return $this->store($doc, $result, $fields);
        } catch (\Throwable $e) {
            Log::warning('KYC OCR failed', ['doc' => $doc->id, 'error' => $e->getMessage()]);

            return $this->store($doc, OcrResult::failed('خطأ غير متوقّع أثناء القراءة'), []);
        } finally {
            if ($temp !== null && is_file($temp)) {
                // الملفّ المفكوك لا يبقى على القرص لحظةً زائدة: صورةُ هويّةٍ
                // في `/tmp` تنجو من التشفير كلّه.
                @unlink($temp);
            }
        }
    }

    private function readable(KycDocument $doc): bool
    {
        return in_array((string) $doc->original_mime, [
            'image/jpeg', 'image/png', 'image/heic', 'image/heif',
        ], true);
    }

    private function store(KycDocument $doc, OcrResult $result, array $fields): KycDocument
    {
        $status = $result->status;

        // الثقة دون الحدّ: يُعرَض النصّ ولا تُملأ الحقول. انظر شرح
        // `IdFieldExtractor` — حقلٌ مملوءٌ خطأً أخطر من حقلٍ فارغ.
        if ($status === OcrResult::STATUS_SUCCESS && $result->confidence < $this->minConfidence()) {
            $status = OcrResult::STATUS_LOW_CONFIDENCE;
            $fields = [];
        }

        $payload = [
            'raw_text' => mb_substr($result->rawText, 0, 4000),
            'fields' => $fields,
            'error' => $result->error,
        ];

        $doc->ocr_status = $status;
        $doc->ocr_confidence = round($result->confidence, 2);
        $doc->ocr_engine = $result->engine;
        $doc->ocr_ran_at = now();
        // مُشفَّر: الاسم ورقم الهوية بياناتٌ شخصية كالصورة. وحفظُها صريحةً
        // بجانب ملفٍّ مشفَّر يُبطل تشفير الملفّ.
        $doc->ocr_extracted = Crypt::encryptString(json_encode($payload, JSON_UNESCAPED_UNICODE));
        $doc->ocr_findings = $this->findings($doc, $fields);
        $doc->save();

        return $doc->fresh();
    }

    /**
     * ملاحظاتٌ تُحسب آليّاً وتُعرَض — ولا تقرّر.
     *
     * @return list<array{code: string, severity: string, message: string}>
     */
    private function findings(KycDocument $doc, array $fields): array
    {
        $out = [];

        $expiry = $fields['dates']['expiry']['value'] ?? null;
        if ($expiry !== null) {
            if (strtotime($expiry) < time()) {
                // حقيقةٌ تُقارن بالتقويم لا رأي: وثيقةٌ منتهية لا توثّق
                // شيئاً مهما كانت واضحة.
                $out[] = [
                    'code' => 'DOCUMENT_EXPIRED',
                    'severity' => 'critical',
                    'message' => "الوثيقة منتهية منذ {$expiry} — لا تصلح للتوثيق",
                ];
            } elseif (strtotime($expiry) < strtotime('+60 days')) {
                $out[] = [
                    'code' => 'DOCUMENT_EXPIRING',
                    'severity' => 'warning',
                    'message' => "تنتهي الوثيقة في {$expiry} — أقلّ من شهرين",
                ];
            }
        }

        $name = $fields['full_name']['value'] ?? null;
        if ($name !== null) {
            $user = User::find($doc->user_id);
            $account = trim((string) ($user?->f_name . ' ' . $user?->l_name));

            if ($account !== '' && !$this->namesLookAlike($name, $account)) {
                // تنبيهٌ لا رفض: الأسماء تُكتب بصيغٍ مختلفة وتُنقل حرفيّاً
                // بطرقٍ شتّى، ورفضٌ آليّ هنا يحجب عملاء صادقين.
                $out[] = [
                    'code' => 'NAME_MISMATCH',
                    'severity' => 'warning',
                    'message' => "اسم الوثيقة «{$name}» يختلف عن اسم الحساب «{$account}» — تحقّق",
                ];
            }
        }

        if ($fields === [] && $doc->ocr_status !== OcrResult::STATUS_UNAVAILABLE) {
            $out[] = [
                'code' => 'NO_FIELDS',
                'severity' => 'info',
                'message' => 'لم تُستخرَج حقول — أدخلها يدويّاً من الصورة',
            ];
        }

        return $out;
    }

    /** مقارنةٌ متساهلة: تطابقُ كلمتين من الاسم يكفي لعدّه متوافقاً. */
    private function namesLookAlike(string $a, string $b): bool
    {
        $clean = static fn (string $s): array => array_values(array_filter(
            preg_split('/\s+/u', preg_replace('/[^\p{Arabic}\p{L}\s]/u', ' ', $s) ?? '') ?: [],
            static fn ($w) => mb_strlen($w) >= 2,
        ));

        $wa = $clean($a);
        $wb = $clean($b);

        if ($wa === [] || $wb === []) {
            return true;   // لا بيانات للمقارنة — لا تُثار ملاحظة
        }

        return count(array_intersect($wa, $wb)) >= 2
            || (count($wa) === 1 && in_array($wa[0], $wb, true));
    }

    // ── ما يُعرَض للمراجع ───────────────────────────────────────────────

    /** @return array{status: string, confidence: float, engine: ?string, fields: array, raw_text: string, findings: array, error: ?string} */
    public function forReviewer(KycDocument $doc): array
    {
        $payload = ['raw_text' => '', 'fields' => [], 'error' => null];

        if ($doc->ocr_extracted) {
            try {
                $payload = json_decode(Crypt::decryptString($doc->ocr_extracted), true) ?: $payload;
            } catch (\Throwable) {
                $payload['error'] = 'تعذّر فكّ تشفير الناتج';
            }
        }

        return [
            'status' => (string) ($doc->ocr_status ?? 'not_run'),
            'confidence' => (float) ($doc->ocr_confidence ?? 0),
            'min_confidence' => $this->minConfidence(),
            'engine' => $doc->ocr_engine,
            'ran_at' => $doc->ocr_ran_at?->toIso8601String(),
            'fields' => $this->flatten($payload['fields'] ?? []),
            'raw_text' => (string) ($payload['raw_text'] ?? ''),
            'findings' => $doc->ocr_findings ?? [],
            'error' => $payload['error'] ?? null,
            'verified' => $this->verifiedFields($doc),
        ];
    }

    /** يُسطّح البنية المتشعّبة إلى الحقول السبعة التي تطلبها الوثيقة. */
    private function flatten(array $fields): array
    {
        return array_filter([
            'full_name' => $fields['full_name'] ?? null,
            'national_id' => $fields['national_id'] ?? null,
            'date_of_birth' => $fields['dates']['birth'] ?? null,
            'expiry_date' => $fields['dates']['expiry'] ?? null,
            'gender' => $fields['gender'] ?? null,
            'country' => $fields['country'] ?? null,
        ], static fn ($v) => $v !== null);
    }

    private function verifiedFields(KycDocument $doc): array
    {
        if (!$doc->verified_fields) {
            return [];
        }

        try {
            return json_decode(Crypt::decryptString($doc->verified_fields), true) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * المراجع يُقرّ الحقول — وهذا وحده ما يُعتمَد عليه.
     *
     * ورقمُ الهوية إلزاميّ في الإقرار: هو الحقل الوحيد الذي يربط الشخص
     * بوثيقته، وإقرارُ هويّةٍ بلا رقمها إقرارٌ بصورةٍ لا بشخص.
     */
    public function confirmFields(KycDocument $doc, User $reviewer, array $fields): KycDocument
    {
        $allowed = ['full_name', 'national_id', 'date_of_birth', 'expiry_date', 'gender', 'country'];
        $clean = [];

        foreach ($allowed as $key) {
            $v = trim((string) ($fields[$key] ?? ''));
            if ($v !== '') {
                $clean[$key] = mb_substr($v, 0, 120);
            }
        }

        if (($clean['national_id'] ?? '') === '') {
            throw new DomainException(
                'رقم الهوية إلزاميّ — إقرارُ هويّةٍ بلا رقمها إقرارٌ بصورةٍ لا بشخص',
            );
        }

        if (isset($clean['expiry_date']) && strtotime($clean['expiry_date']) < time()) {
            throw new DomainException('الوثيقة منتهية — لا تُعتمَد مهما كانت واضحة');
        }

        $clean['_confirmed_by'] = $reviewer->id;
        $clean['_confirmed_at'] = now()->toIso8601String();

        $doc->verified_fields = Crypt::encryptString(json_encode($clean, JSON_UNESCAPED_UNICODE));

        // تاريخ الانتهاء المُقَرّ يصير تاريخ انتهاء المستند — فالاكتمالية
        // تحسبه، والمستند المنتهي يُعدّ ناقصاً لا مكتملاً.
        if (isset($clean['expiry_date'])) {
            $doc->document_expires_at = $clean['expiry_date'];
        }

        $doc->save();

        app(AuditService::class)->record([
            'actor_type' => 'admin',
            'actor_user_id' => $reviewer->id,
            'subject_type' => 'kyc_document',
            'subject_id' => (string) $doc->id,
            'action' => 'KYC_FIELDS_CONFIRMED',
            'decision_code' => 'KYC_FIELDS',
            'severity' => 'warning',
            // لا تُكتب القيم في التدقيق: سجلّ التدقيق يُقرأ أوسع من الجدول
            // المشفَّر، وكتابةُ رقم الهوية فيه تُسرّبه إلى حيث لا يُشفَّر.
            'context' => ['fields' => array_keys($clean), 'customer_id' => $doc->user_id],
        ]);

        return $doc->fresh();
    }
}
