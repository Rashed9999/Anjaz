<?php

namespace App\Services\Kyc;

use App\Models\KycAiReview;
use App\Models\KycDocument;
use App\Models\User;
use App\Services\AuditService;
use App\Services\KycDocumentService;
use App\Services\KycOcrService;
use App\Services\PiiAccessAuditService;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-KYC-AI-001 — مساعد تحليلي فقط، لا يملك حق اعتماد/رفض/ترقية حساب.
 * الاستدعاء البشري يدوي؛ لا إرسال إلى مزود خارجي عند الرفع، ولا إرسال للسيلفي.
 * الصورة والبيانات لا ترسلان إطلاقاً دون تفعيل صريح في إعدادات الخادم.
 */
class KycAiReviewService
{
    public function __construct(
        private readonly KycPrivacyService $privacy,
        private readonly KycDocumentService $documents,
        private readonly KycOcrService $ocr,
        private readonly DocumentReuseService $reuse,
        private readonly AuditService $audit,
        private readonly PiiAccessAuditService $pii,
    ) {}

    public function configured(): bool
    {
        return (bool) config('amial.kyc.ai.enabled', false)
            && trim((string) config('amial.kyc.ai.key', '')) !== ''
            && trim((string) config('amial.kyc.ai.model', '')) !== '';
    }

    /** @return array<string,mixed> */
    public function latest(User $subject, User $reviewer): array
    {
        $this->assertAccess($subject, $reviewer);
        $this->pii->logAccess(
            (int) $reviewer->id, 'user', (int) $subject->id,
            'kyc_ai_report', 'view', 'عرض تقرير المساعد الذكي من ملف التحقق'
        );

        $last = Schema::hasTable('kyc_ai_reviews')
            ? KycAiReview::where('user_id', $subject->id)
                ->where('status', 'complete')->latest('id')->first()
            : null;

        return [
            'configured' => $this->configured(),
            'can_run' => $this->configured() && !$this->privacy->isRestricted($subject),
            'image_analysis' => (bool) config('amial.kyc.ai.send_images', false),
            'status' => $last ? 'complete' : 'not_run',
            'latest' => $last ? $this->serialize($last) : null,
        ];
    }

    /** @return array<string,mixed> */
    public function run(User $subject, User $reviewer): array
    {
        $this->assertAccess($subject, $reviewer);
        // الحالات المقيدة لا تخرج بياناتها لمزود خارجي، حتى إذا كان
        // المراجع نفسه يحمل صلاحية الاطلاع عليها.
        if ($this->privacy->isRestricted($subject)) {
            throw new DomainException('الحالة مقيدة: التحليل الخارجي محظور. استخدم أدوات الفحص المحلية.');
        }
        if (!$this->configured()) {
            throw new DomainException('المساعد غير مفعل: اضبط المفتاح والنموذج والتفعيل في إعدادات الخادم.');
        }
        if (!Schema::hasTable('kyc_ai_reviews')) {
            throw new DomainException('جدول تقارير المساعد غير مهيأ؛ نفّذ ترحيل قاعدة البيانات أولاً.');
        }

        $lock = Cache::lock('amial:kyc-ai:account:'.$subject->id, 65);
        if (!$lock->get()) {
            throw new DomainException('يجري فحص هذا الحساب الآن؛ انتظر انتهاء العملية.');
        }
        try {
            $docs = KycDocument::where('user_id', $subject->id)
                ->whereIn('doc_type', [
                    KycDocument::TYPE_ID_FRONT,
                    KycDocument::TYPE_ID_BACK,
                    KycDocument::TYPE_PASSPORT,
                    KycDocument::TYPE_ADDRESS_PROOF,
                ])
                ->where('status', '!=', KycDocument::STATUS_SUPERSEDED)
                ->orderByDesc('id')->get()->unique('doc_type')->take(4)->values();

            if ($docs->isEmpty()) {
                throw new DomainException('لا توجد مستندات هوية أو سكن حديثة لهذا الحساب.');
            }

            $model = (string) config('amial.kyc.ai.model');
            $digest = hash('sha256', json_encode([
                'model' => $model,
                'user_revision' => (string) $subject->updated_at,
                'documents' => $docs->map(fn (KycDocument $d) => [
                    $d->id, $d->content_sha256, $d->status, (string) $d->updated_at,
                ])->all(),
            ], JSON_UNESCAPED_UNICODE));

            $cached = KycAiReview::where('user_id', $subject->id)
                ->where('input_digest', $digest)->where('status', 'complete')
                ->latest('id')->first();
            if ($cached) {
                return $this->serialize($cached) + ['reused' => true];
            }

            $rateKey = 'amial:kyc-ai:daily:'.now()->format('Y-m-d');
            $max = max(1, (int) config('amial.kyc.ai.daily_limit', 30));
            if (RateLimiter::tooManyAttempts($rateKey, $max)) {
                throw new DomainException('بلغ الفحص اليومي الحد المضبوط لحماية ميزانية المشروع.');
            }

            $materials = [];
            $images = [];
            $sendImages = (bool) config('amial.kyc.ai.send_images', false);
            foreach ($docs as $doc) {
                $ocr = $this->ocr->forReviewer($doc);
                $materials[] = [
                    'document_id' => (int) $doc->id,
                    'kind' => (string) $doc->doc_type,
                    'review_status' => (string) $doc->status,
                    'ocr_status' => $ocr['status'] ?? 'not_run',
                    'ocr_confidence' => $ocr['confidence'] ?? null,
                    'ocr_fields' => $ocr['fields'] ?? [],
                    'ocr_findings' => $ocr['findings'] ?? [],
                ];
                // لا صور وجه/سيلفي هنا مطلقاً؛ الصور للمستندات المصرح بها فقط.
                $mime = strtolower((string) $doc->original_mime);
                if ($sendImages && count($images) < 3 && in_array($mime, ['image/jpeg', 'image/png'], true)) {
                    $binary = $this->documents->decrypt($doc);
                    if (strlen($binary) <= 2 * 1024 * 1024) {
                        $images[] = ['type' => 'image_url', 'image_url' => [
                            'url' => 'data:'.$mime.';base64,'.base64_encode($binary),
                        ]];
                    }
                    unset($binary);
                }
            }

            $local = $this->reuse->findingsFor($subject);
            $system = 'أنت مساعد مراجعة وثائق لموظف بشري في أميال باي. '
                .'مهمتك استخراج التناقضات المحتملة وجودة الوثائق من الأدلة المرسلة فقط. '
                .'لا تصدر قرار اعتماد أو رفض، ولا تقل إن الوثيقة أصلية أو مزورة قطعاً، '
                .'ولا تنشئ هوية أو رقماً أو تاريخاً غير ظاهر. '
                .'أعد JSON فقط بالمفاتيح: overview (نص عربي موجز)، '
                .'findings (مصفوفة عناصر تحوي document_id رقماً أو null وseverity بقيمة info أو review '
                .'وtext ملاحظة مدعومة بالدليل)، human_review (مصفوفة إجراءات على المراجع). '
                .'لا تكرر أرقام الهوية كاملة في الملخص. أظهر نقص الصورة أو ضعف الثقة بوضوح.';
            $content = [[
                'type' => 'text',
                'text' => json_encode([
                    'declared_legal_name' => (string) ($subject->declared_legal_name ?? ''),
                    'registered_identity_number' => (string) ($subject->identification_number ?? ''),
                    'documents' => $materials,
                    'local_duplicate_findings' => [
                        'blockers' => $local['blockers'] ?? [],
                        'warnings' => $local['warnings'] ?? [],
                    ],
                    'images_included' => count($images),
                    'important' => 'التطابق المحتمل معلومة للمراجع، وليس سبب رفض آلياً.',
                ], JSON_UNESCAPED_UNICODE),
            ]];
            array_push($content, ...$images);

            // يحجز الطلب قبل إرساله حتى لا تنفق الطلبات المتزامنة السقف اليومي.
            RateLimiter::hit($rateKey, 86400);
            $this->pii->logAccess((int) $reviewer->id, 'user', (int) $subject->id,
                'kyc_ai_external_processing', 'export', 'إرسال الأدلة المصرّح بها إلى مزود ZDR للتحليل الاستشاري');
            try {
                $response = Http::withToken((string) config('amial.kyc.ai.key'))
                    ->acceptJson()->timeout(45)->connectTimeout(10)
                    ->post('https://openrouter.ai/api/v1/chat/completions', [
                        'model' => $model,
                        'temperature' => 0,
                        'max_tokens' => 1300,
                        'provider' => ['data_collection' => 'deny', 'zdr' => true],
                        'messages' => [
                            ['role' => 'system', 'content' => $system],
                            ['role' => 'user', 'content' => $content],
                        ],
                    ]);
            } catch (\Throwable) {
                // لا نسجل الاستجابة ولا الصور ولا معلومات العميل عند فشل المزود.
                throw new DomainException('تعذر الاتصال بمزود الذكاء الاصطناعي؛ لم يتغير توثيق الحساب.');
            }
            if (!$response->successful()) {
                throw new DomainException('رفض المزود الطلب أو لم تتوفر نقطة معالجة تحقق شروط الخصوصية.');
            }
            $message = $response->json('choices.0.message.content');
            if (!is_string($message)) {
                throw new DomainException('لم يقدم المزود تقريراً نصياً صالحاً للمراجعة.');
            }
            $decoded = json_decode(trim(preg_replace('/^\\x60{3}(?:json)?\\s*|\\s*\\x60{3}$/iu', '', trim($message))), true);
            if (!is_array($decoded)) {
                throw new DomainException('تعذر قراءة التقرير المنظم؛ يمكن إعادة المحاولة لاحقاً.');
            }
            $report = $this->normalizeReport($decoded, $docs->pluck('id')->map(fn ($id) => (int) $id)->all());
            $usage = $response->json('usage') ?: [];
            $row = KycAiReview::create([
                'user_id' => $subject->id,
                'requested_by' => $reviewer->id,
                'model' => $model,
                'input_digest' => $digest,
                'status' => 'complete',
                'report_encrypted' => $report,
                'prompt_tokens' => is_numeric($usage['prompt_tokens'] ?? null) ? (int) $usage['prompt_tokens'] : null,
                'completion_tokens' => is_numeric($usage['completion_tokens'] ?? null) ? (int) $usage['completion_tokens'] : null,
            ]);
            $this->audit->record([
                'actor_type' => 'admin',
                'actor_user_id' => (int) $reviewer->id,
                'subject_type' => 'user',
                'subject_id' => (string) $subject->id,
                'action' => 'KYC_AI_ADVISORY_CREATED',
                'decision_code' => 'ADVISORY_ONLY',
                'severity' => 'notice',
                'context' => ['report_id' => $row->id, 'model' => $model, 'images' => count($images)],
            ]);

            return $this->serialize($row) + ['reused' => false];
        } finally {
            $lock->release();
        }
    }

    private function assertAccess(User $subject, User $reviewer): void
    {
        if (!$reviewer->hasPlatformPermission('platform.customers.kyc.view')
            || !$reviewer->hasPlatformPermission('platform.customers.freeze')) {
            throw new DomainException('لا تملك صلاحية فحص المستندات أو عرض تقريرها الذكي.');
        }
        $this->privacy->assertReviewerAccess($subject, $reviewer, false);
    }

    /** @param array<string,mixed> $raw @param list<int> $ids */
    private function normalizeReport(array $raw, array $ids): array
    {
        $findings = [];
        foreach (array_slice((array) ($raw['findings'] ?? []), 0, 12) as $item) {
            if (!is_array($item)) continue;
            $docId = (int) ($item['document_id'] ?? 0);
            $findings[] = [
                'document_id' => in_array($docId, $ids, true) ? $docId : null,
                'severity' => ($item['severity'] ?? '') === 'review' ? 'review' : 'info',
                'text' => mb_substr((string) ($item['text'] ?? ''), 0, 700),
            ];
        }
        return [
            'overview' => mb_substr((string) ($raw['overview'] ?? ''), 0, 1400),
            'findings' => $findings,
            'human_review' => array_values(array_map(
                fn ($x) => mb_substr((string) $x, 0, 450),
                array_slice(array_filter((array) ($raw['human_review'] ?? []), 'is_string'), 0, 8)
            )),
            'disclaimer' => 'تحليل آلي استشاري؛ لا يثبت أصالة الهوية ولا يعتمد أو يرفض الحساب.',
        ];
    }

    private function serialize(KycAiReview $row): array
    {
        return [
            'id' => (int) $row->id,
            'model' => (string) $row->model,
            'created_at' => $row->created_at?->format('Y-m-d H:i'),
            'usage' => [
                'input_tokens' => $row->prompt_tokens,
                'output_tokens' => $row->completion_tokens,
            ],
            'report' => (array) $row->report_encrypted,
        ];
    }
}
