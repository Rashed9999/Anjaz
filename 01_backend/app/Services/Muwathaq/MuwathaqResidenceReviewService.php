<?php

namespace App\Services\Muwathaq;

use App\Models\KycDocument;
use App\Models\MuwathaqReviewRequest as Req;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Kyc\KycPrivacyService;
use App\Services\Kyc\ResidenceDecisionApplier;
use App\Services\Kyc\ResidenceVerificationService;
use App\Services\NotificationService;
use App\Support\YemenGovernorates;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * CAP-02: مراجعة إثبات السكن يملكها موثّق. أميال باي يحتفظ بطلب السكن (residence_verifications)
 * وبتطبيق القرار عبر ResidenceDecisionApplier نفسه الذي يستعمله المسار الإداري — لا منطق ثانٍ.
 * التسليم والإعادة عبر الصندوق نفسه (MuwathaqTierUpgradeService::deliver/dispatchPending، عامّان بالمعرّف).
 */
class MuwathaqResidenceReviewService
{
    public function enabledFor(User $user): bool
    {
        if (!app(MuwathaqBridgeService::class)->isConfigured()) {
            return false;
        }
        if (app(KycPrivacyService::class)->isRestricted($user)) {
            return false;
        }

        return MuwathaqCanary::allows('residence_review', $user);
    }

    /** null = المسار الإداري القديم يبقى المالك. */
    public function openFor(User $user, int $verificationId): ?Req
    {
        if (!$this->enabledFor($user)) {
            return null;
        }
        $row = DB::table('residence_verifications')->where('id', $verificationId)->where('user_id', $user->id)->first();
        if (!$row || (string) $row->status !== ResidenceVerificationService::STATUS_PENDING || !$row->kyc_document_id) {
            return null;
        }
        $doc = KycDocument::query()->whereKey((int) $row->kyc_document_id)->where('user_id', $user->id)->first();
        if (!$doc) {
            return null;
        }

        $req = DB::transaction(function () use ($user, $row, $doc): Req {
            $old = Req::query()->where('kind', Req::KIND_RESIDENCE)->where('user_id', $user->id)
                ->whereIn('status', Req::OPEN)->lockForUpdate()->first();
            if ($old) {
                $old->update(['status' => 'superseded', 'open_key' => null]);
            }
            $ulid = (string) Str::ulid();

            return Req::query()->create([
                'request_ulid' => $ulid,
                'kind' => Req::KIND_RESIDENCE,
                'subject_id' => (int) $row->id,
                'user_id' => $user->id,
                'target_tier' => null,
                'status' => 'submitted',
                'open_key' => 'residence:'.$user->id,
                'document_ids' => [(int) $doc->id],
                'event_ulid' => (string) Str::ulid(),
                'payload' => $this->payload($user->fresh(), $ulid, $row, $doc, $old?->request_ulid),
            ]);
        });
        DB::afterCommit(fn () => \App\Jobs\DeliverMuwathaqTierUpgradeJob::dispatch($req->id));

        return $req;
    }

    private function payload(User $user, string $ulid, object $row, KycDocument $doc, ?string $supersedes): array
    {
        return [
            'event_version' => 1,
            'event_type' => 'residence_review.submitted',
            'application' => [
                'application_ulid' => $ulid,
                'subject_kind' => 'customer',
                'verification_path' => 'residence_review',
                'requested_tier' => null,
                'current_tier' => (int) ($user->kyc_tier ?? 0),
                'submitted_at' => now()->toIso8601String(),
                'supersedes_application_ulid' => $supersedes,
            ],
            'applicant' => [
                'amial_user_id' => $user->id,
                'legal_name' => (string) ($user->declared_legal_name ?: trim(implode(' ', array_filter([$user->f_name, $user->father_name, $user->grandfather_name, $user->family_name ?: $user->l_name])))),
                'phone' => $user->phone,
                'email' => $user->email,
                'gender' => $user->gender,
            ],
            'residence' => [
                'declared_governorate' => (string) $row->declared_governorate,
                'declared_governorate_name' => YemenGovernorates::name($row->declared_governorate),
                'birth_governorate_name' => YemenGovernorates::name($user->birth_governorate ?? null),
                'district' => (string) ($user->residence_district ?? ''),
                'area' => (string) ($user->residence_area ?? ''),
                'landmark' => (string) ($user->residence_landmark ?? ''),
                'evidence_type' => (string) $row->evidence_type,
                'evidence_label' => ResidenceVerificationService::EVIDENCE_TYPES[$row->evidence_type]['label'] ?? (string) $row->evidence_type,
                'evidence_strength' => (string) $row->evidence_strength,
                'evidence_date' => $row->evidence_date ? substr((string) $row->evidence_date, 0, 10) : null,
            ],
            'prechecks' => (function () use ($user, $doc) {
                try {
                    return app(MuwathaqPrecheckService::class)->for($user, [$doc]);
                } catch (\Throwable) {
                    return null;
                }
            })(),
            'documents' => [[
                'id' => (int) $doc->id, 'kind' => 'address_proof', 'mime' => $doc->original_mime,
                'sha256' => $doc->content_sha256, 'size' => (int) $doc->size_bytes,
            ]],
        ];
    }

    /** @return array{http:int, body:array} — العقد نفسه لقرار الترقية. */
    public function receiveDecision(Req $req, string $decision, string $reference, ?int $seq, ?string $reason, array $context): array
    {
        return DB::transaction(function () use ($req, $decision, $reference, $seq, $reason, $context): array {
            $r = Req::query()->lockForUpdate()->findOrFail($req->id);
            $out = fn (int $http, string $code, bool $applied = false, ?string $apply = null): array => ['http' => $http, 'body' => [
                'success' => $http < 300, 'code' => $code,
                'meta' => ['kind' => Req::KIND_RESIDENCE, 'request_ulid' => $r->request_ulid, 'status' => $r->status, 'applied' => $applied, 'apply_code' => $apply],
            ]];

            if (in_array($r->status, Req::FINAL, true) || $r->status === 'needs_more_info') {
                // needs_more_info في السكن نهائي في Pay (needs_more_evidence)؛ العميل يعيد الإرسال بطلب جديد.
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
            if ($decision === 'under_review') {
                $r->status = 'under_review';
                $r->save();

                return $out(200, 'MUWATHAQ_DECISION_APPLIED', true);
            }

            $reviewer = trim((string) ($context['reviewer_reference'] ?? ''));
            $proposer = trim((string) ($context['proposer_reference'] ?? ''));
            $final = in_array($decision, ['approved', 'rejected'], true);
            if ($final && ($reviewer === '' || $proposer === '' || $reviewer === $proposer)) {
                return $out(409, 'INDEPENDENT_REVIEW_REQUIRED');
            }
            if ($decision === 'approved' && ($context['verified_residence'] ?? false) !== true) {
                return $out(409, 'RESIDENCE_NOT_AFFIRMED');
            }
            if ($decision !== 'approved' && mb_strlen(trim((string) $reason)) < 5) {
                return $out(422, 'REASON_REQUIRED');
            }

            $status = match ($decision) {
                'approved' => ResidenceVerificationService::STATUS_VERIFIED,
                'needs_more_info' => ResidenceVerificationService::STATUS_NEEDS_MORE,
                default => ResidenceVerificationService::STATUS_REJECTED,
            };
            try {
                app(ResidenceDecisionApplier::class)->apply(
                    (int) $r->subject_id,
                    app(MuwathaqBridgeReviewer::class)->resolve(),
                    $status,
                    $reason,
                    isset($context['document_name']) ? (string) $context['document_name'] : null,
                    isset($context['name_review_note']) ? (string) $context['name_review_note'] : null,
                );
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
                'subject_type' => 'residence_verification', 'subject_id' => (string) $r->subject_id,
                'action' => 'MUWATHAQ_RESIDENCE_DECISION', 'decision_code' => strtoupper($decision), 'severity' => 'info',
                'context' => ['request_ulid' => $r->request_ulid, 'decision_reference' => $reference,
                    'reviewer_reference' => $reviewer, 'proposer_reference' => $proposer,
                    'bridge_reviewer_user_id' => app(MuwathaqBridgeReviewer::class)->find()?->id],
            ]);
            $userId = (int) $r->user_id;
            DB::afterCommit(function () use ($userId, $decision, $reason): void {
                try {
                    [$title, $body] = match ($decision) {
                        'approved' => ['تم اعتماد محل إقامتك ✅', 'اعتمدنا إثبات السكن. أصبح حسابك موثقاً جزئياً.'],
                        'needs_more_info' => ['نحتاج دليل سكن أوضح', 'راجعنا إثبات السكن ونحتاج: '.trim((string) $reason).' — أعد الإرسال من «إثبات السكن».'],
                        default => ['تعذّر اعتماد إثبات السكن', 'السبب: '.trim((string) $reason)],
                    };
                    app(NotificationService::class)->dispatch(User::query()->findOrFail($userId), 'kyc_verification', $title, $body,
                        data: ['kyc_action' => $decision === 'approved' ? 'residence_verified' : 'resubmit_residence']);
                } catch (\Throwable) {
                    // الإشعار لا يعطّل القرار.
                }
            });

            return $out(200, 'MUWATHAQ_DECISION_APPLIED', true);
        });
    }
}
