<?php

namespace App\Services\Muwathaq;

use App\Models\KycDocument;
use App\Models\MuwathaqReviewRequest as Req;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Verification\AccountVerificationDecisionService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * CAP-01: مراجعة ترقية Tier 2 يملكها موثّق. أميال باي: الطلب + الصندوق + تطبيق القرار فقط.
 * بيانات الهوية المُصرَّح بها (الرقم والنوع والميلاد والإصدار والانتهاء) تُرسل في الحمولة الموقّعة
 * بقرار المالك (2026-11-11) ليطابقها المراجع بالوثيقة؛ موثّق يخزّن الحمولة مشفّرة. الوثيقتان
 * نفسهما لا تُنسخان: تُسحبان موقّعتين عند العرض.
 */
class MuwathaqTierUpgradeService
{
    public const TIER = 2;

    public function enabledFor(User $user): bool
    {
        if (!app(MuwathaqBridgeService::class)->isConfigured()) {
            return false;
        }
        // طابور المراجعة المقيّد (خصوصية مختارة) لم يُبنَ في موثّق بعد؛ يبقى في المسار القديم بصلاحيته المستقلة.
        if (app(\App\Services\Kyc\KycPrivacyService::class)->isRestricted($user)) {
            return false;
        }

        return MuwathaqCanary::allows('tier_upgrade', $user);
    }

    /** null = المسار القديم يبقى مالك الطلب (الميزة مغلقة لهذا المستخدم)؛ وإلا طلب موثّق بصندوقه. */
    public function openFor(User $user, array $documents): ?Req
    {
        if (!$this->enabledFor($user)) {
            return null;
        }
        $req = DB::transaction(function () use ($user, $documents): Req {
            $old = Req::query()->where('kind', Req::KIND_TIER_UPGRADE)->where('user_id', $user->id)->whereIn('status', Req::OPEN)->lockForUpdate()->first();
            if ($old) {
                $old->update(['status' => 'superseded', 'open_key' => null]);
            }
            $ulid = (string) Str::ulid();

            return Req::query()->create([
                'request_ulid' => $ulid,
                'kind' => Req::KIND_TIER_UPGRADE,
                'user_id' => $user->id,
                'target_tier' => self::TIER,
                'status' => 'submitted',
                'open_key' => 'tier:'.$user->id.':'.self::TIER,
                'document_ids' => array_map(fn (KycDocument $d) => (int) $d->id, $documents),
                'event_ulid' => (string) Str::ulid(),
                'payload' => $this->payload($user, $ulid, $documents, $old?->request_ulid),
            ]);
        });
        DB::afterCommit(fn () => \App\Jobs\DeliverMuwathaqTierUpgradeJob::dispatch($req->id));

        return $req;
    }

    private function payload(User $user, string $ulid, array $documents, ?string $supersedes): array
    {
        return [
            'event_version' => 1,
            'event_type' => 'tier_upgrade.submitted',
            'application' => [
                'application_ulid' => $ulid,
                'subject_kind' => 'customer',
                'verification_path' => 'progressive_tier_upgrade',
                'requested_tier' => self::TIER,
                'current_tier' => (int) ($user->kyc_tier ?? 0),
                'submitted_at' => now()->toIso8601String(),
                'supersedes_application_ulid' => $supersedes,
            ],
            'applicant' => [
                'amial_user_id' => $user->id,
                'legal_name' => trim(implode(' ', array_filter([$user->f_name, $user->father_name, $user->grandfather_name, $user->family_name ?: $user->l_name]))),
                'phone' => $user->phone,
                'email' => $user->email,
                'gender' => $user->gender,
            ],
            'identity' => [
                'identification_type' => $user->identification_type,
                'identification_number' => $user->identification_number,
                'date_of_birth' => $this->day($user->date_of_birth),
                'place_of_issue' => $user->id_place_of_issue,
                'issue_date' => $this->day($user->identification_issue_date),
                'expiry_date' => $this->day($user->identification_expiry_date),
            ],
            'prechecks' => $this->prechecks($user, $documents),
            'documents' => array_map(fn (KycDocument $d) => [
                'id' => (int) $d->id, 'kind' => $d->doc_type, 'mime' => $d->original_mime,
                'sha256' => $d->content_sha256, 'size' => (int) $d->size_bytes,
            ], $documents),
        ];
    }

    /** فحوص أميال باي الآلية إشاراتٍ للمراجع؛ فشلها لا يمنع إنشاء الطلب. */
    private function prechecks(User $user, array $documents): ?array
    {
        try {
            return app(MuwathaqPrecheckService::class)->for($user, $documents);
        } catch (\Throwable) {
            return null;
        }
    }

    private function day(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : substr((string) $value, 0, 10);
    }

    /** تسليم موقّع؛ يعيد false إن سُلّم سابقاً. يرمي عند الفشل ليعيد الـ job المحاولة. */
    public function deliver(int $id): bool
    {
        $req = DB::transaction(function () use ($id): ?Req {
            $r = Req::query()->lockForUpdate()->find($id);
            if ($r === null || $r->delivery_status === 'delivered') {
                return null;
            }
            $r->attempts++;
            $r->last_attempted_at = now();
            $r->save();

            return $r;
        });
        if ($req === null) {
            return false;
        }
        $body = json_encode($req->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $ts = (string) time();
        try {
            $res = Http::acceptJson()->withHeaders([
                'X-Amial-Timestamp' => $ts,
                'X-Amial-Signature' => hash_hmac('sha256', $ts.'.'.$body, MuwathaqTransportSecrets::amialToMuwathaq()),
                'Idempotency-Key' => $req->event_ulid,
                'Content-Type' => 'application/json',
            ])->timeout((int) config('muwathaq.timeout_seconds', 10))
                ->send('POST', rtrim((string) config('muwathaq.base_url'), '/').'/api/v1/internal/applications', ['body' => $body]);
        } catch (\Throwable $e) {
            $req->update(['failure_code' => 'NETWORK_ERROR']);
            throw $e;
        }
        if (!$res->successful()) {
            $req->update(['failure_code' => 'HTTP_'.$res->status(), 'response_status' => $res->status()]);
            throw new DomainException('موثّق رفض طلب الترقية (HTTP '.$res->status().').');
        }
        $req->update(['delivery_status' => 'delivered', 'delivered_at' => now(), 'response_status' => $res->status(), 'failure_code' => null]);

        return true;
    }

    public function dispatchPending(int $limit = 100): int
    {
        if (!app(MuwathaqBridgeService::class)->isConfigured()) {
            return 0;
        }
        $ids = Req::query()->where('delivery_status', 'pending')->whereIn('status', Req::OPEN)->oldest('id')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            \App\Jobs\DeliverMuwathaqTierUpgradeJob::dispatch((int) $id);
        }

        return $ids->count();
    }

    /**
     * @return array{http:int, body:array}
     * 409 = نهائي لا يُعاد؛ 200 مع applied=false = حجب أعمال حتمي؛ 5xx = إعادة.
     */
    public function receiveDecision(Req $req, string $decision, string $reference, ?int $seq, ?string $reason, array $context): array
    {
        return DB::transaction(function () use ($req, $decision, $reference, $seq, $reason, $context): array {
            $r = Req::query()->lockForUpdate()->findOrFail($req->id);
            $out = fn (int $http, string $code, bool $applied = false, ?string $apply = null): array => ['http' => $http, 'body' => [
                'success' => $http < 300, 'code' => $code,
                'meta' => ['kind' => 'tier_upgrade', 'request_ulid' => $r->request_ulid, 'status' => $r->status, 'applied' => $applied, 'apply_code' => $apply],
            ]];

            if (in_array($r->status, Req::FINAL, true)) {
                // تكرار الحدث نفسه آمن؛ أي قرار آخر على طلب منتهٍ يُرفض نهائياً.
                return $r->decision_reference === $reference && $r->status === $decision
                    ? $out(200, 'MUWATHAQ_DECISION_APPLIED', true)
                    : $out(409, $r->status === 'superseded' ? 'SUPERSEDED' : 'REQUEST_FINAL');
            }
            if ($seq !== null && $seq <= $r->last_decision_seq) {
                return $out(200, 'STALE_DECISION_IGNORED');
            }
            if ($seq !== null) {
                $r->last_decision_seq = $seq;
            }
            if ($decision === 'needs_more_info') {
                // ليس رفضاً: الحساب يبقى «قيد المراجعة» (is_kyc_verified=0) والطلب مفتوح حتى يعيد
                // العميل رفع الهوية من الشاشة نفسها، فيستبدل طلبٌ جديد هذا الطلب (supersedes).
                if (mb_strlen(trim((string) $reason)) < 5) {
                    return $out(422, 'REASON_REQUIRED');
                }
                $r->status = 'needs_more_info';
                $r->decision_reason = $reason;
                $r->save();
                $userId = (int) $r->user_id;
                DB::afterCommit(function () use ($userId, $reason): void {
                    try {
                        app(\App\Services\NotificationService::class)->dispatch(
                            User::query()->findOrFail($userId),
                            'kyc_verification',
                            'نحتاج استكمال وثائق الهوية',
                            'راجعنا طلب ترقية حسابك ونحتاج منك: '.trim((string) $reason).' — أعد رفع صورتي الهوية من «توثيق الهوية».',
                            data: ['is_kyc_verified' => 0, 'kyc_action' => 'resubmit_identity'],
                        );
                    } catch (\Throwable) {
                        // الإشعار لا يعطّل القرار.
                    }
                });
                app(AuditService::class)->record([
                    'actor_type' => 'muwathaq_reviewer', 'actor_user_id' => null,
                    'subject_type' => 'user', 'subject_id' => (string) $r->user_id,
                    'action' => 'MUWATHAQ_TIER_UPGRADE_DECISION', 'decision_code' => 'NEEDS_MORE_INFO', 'severity' => 'info',
                    'context' => ['request_ulid' => $r->request_ulid, 'decision_reference' => $reference,
                        'reviewer_reference' => (string) ($context['reviewer_reference'] ?? '')],
                ]);

                return $out(200, 'MUWATHAQ_DECISION_APPLIED', true);
            }
            if ($decision === 'under_review') {
                $r->status = 'under_review';
                $r->save();

                return $out(200, 'MUWATHAQ_DECISION_APPLIED', true);
            }

            $reviewer = trim((string) ($context['reviewer_reference'] ?? ''));
            $proposer = trim((string) ($context['proposer_reference'] ?? ''));
            if ($reviewer === '' || $proposer === '' || $reviewer === $proposer) {
                return $out(409, 'INDEPENDENT_REVIEW_REQUIRED');
            }
            $approve = $decision === 'approved';
            if ($approve && ($context['verified_identity'] ?? false) !== true) {
                return $out(409, 'IDENTITY_NOT_AFFIRMED');
            }
            if (!$approve && mb_strlen(trim((string) $reason)) < 5) {
                return $out(422, 'REASON_REQUIRED');
            }

            try {
                // الوثيقتان اللتان راجعهما موثّق (سُحبتا موقّعتين بمعرّفاتهما) تُعتمدان هنا باسم حساب الجسر، ثم يُطبَّق القرار؛
                // وإلا يرفض `completenessFor` كلَّ اعتمادٍ لأنّ المستندات بقيت «قيد المراجعة» في أميال باي. نقطة حفظٍ واحدة:
                // إن حُجب القرار تُعاد المستندات كما كانت.
                DB::transaction(function () use ($r, $approve, $reason): void {
                    $account = User::query()->findOrFail($r->user_id);
                    if ($approve) {
                        $this->approveReviewedDocuments($r, $account);
                    }
                    app(AccountVerificationDecisionService::class)->decideFromMuwathaq($account, $approve, $r->target_tier, $reason);
                });
            } catch (DomainException $e) {
                $r->apply_code = Str::limit($e->getMessage(), 60, '');
                $r->save();

                return $out(200, 'MUWATHAQ_DECISION_BLOCKED', false, $r->apply_code);
            }

            $r->fill([
                'status' => $decision, 'open_key' => null, 'decision_reference' => $reference, 'decision_reason' => $reason,
                'decision_context' => $context, 'decided_at' => now(), 'applied_at' => now(), 'apply_code' => null,
            ])->save();
            app(AuditService::class)->record([
                'actor_type' => 'muwathaq_reviewer', 'actor_user_id' => null,
                'subject_type' => 'user', 'subject_id' => (string) $r->user_id,
                'action' => 'MUWATHAQ_TIER_UPGRADE_DECISION', 'decision_code' => strtoupper($decision), 'severity' => 'info',
                'context' => ['request_ulid' => $r->request_ulid, 'decision_reference' => $reference,
                    'reviewer_reference' => $reviewer, 'proposer_reference' => $proposer,
                    'bridge_reviewer_user_id' => app(MuwathaqBridgeReviewer::class)->find()?->id],
            ]);

            // applied=true = القرار سُجّل كما أُرسل (اعتماداً كان أو رفضاً). applied=false محجوز للحجب الحتمي
            // وحده؛ موثّق يقرؤه «أعِد القضية للمراجعة»، فإعادته على رفضٍ سليم كانت تعيد فتح قضية محسومة.
            return $out(200, 'MUWATHAQ_DECISION_APPLIED', true);
        });
    }

    /** يعتمد في أميال باي المستندات نفسها التي أُرسلت إلى موثّق وراجعها (لا غيرها)، ما دامت قيد المراجعة. */
    private function approveReviewedDocuments(Req $r, User $account): void
    {
        $ids = array_map('intval', (array) ($r->document_ids ?? []));
        if ($ids === []) {
            return;
        }
        $bridge = app(MuwathaqBridgeReviewer::class)->resolve();
        $expires = $account->identification_expiry_date ? (string) $account->identification_expiry_date : null;
        $docs = KycDocument::query()->whereIn('id', $ids)->where('user_id', $account->id)
            ->where('status', KycDocument::STATUS_PENDING)->lockForUpdate()->get();
        foreach ($docs as $doc) {
            app(\App\Services\KycDocumentService::class)->approve($doc, $bridge, $expires);
        }
    }
}
