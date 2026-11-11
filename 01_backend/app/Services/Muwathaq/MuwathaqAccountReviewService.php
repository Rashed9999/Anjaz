<?php

namespace App\Services\Muwathaq;

use App\Http\Controllers\Admin\MerchantVerificationAdminController;
use App\Models\MerchantVerificationRequest;
use App\Models\MuwathaqReviewRequest as Req;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Kyc\KycPrivacyService;
use App\Services\Kyc\ProfileChangeRequestService;
use App\Services\MerchantVerificationService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * نقل مراجعتين إضافيتين إلى موثّق بالصندوق والعقد نفسيهما:
 *  - profile_change: طلب تغيير بيان (حقل، قديم ← جديد، وثيقة داعمة إن لزمت).
 *  - merchant_verification: توثيق منشأة التاجر (بيانات النشاط + وثائقه).
 * أميال باي يبقى مالك التطبيق: القرار يُطبَّق بخدمته نفسها (decide / approve / reject /
 * requestResubmission) بحساب الجسر، فلا منطق قرار ثانٍ. كلتاهما OFF افتراضياً.
 */
class MuwathaqAccountReviewService
{
    public const PROFILE = 'profile_change';
    public const MERCHANT = 'merchant_verification';

    public function enabled(string $kind, User $user): bool
    {
        if (!app(MuwathaqBridgeService::class)->isConfigured()) {
            return false;
        }
        if ($kind === self::PROFILE && app(KycPrivacyService::class)->isRestricted($user)) {
            return false;
        }
        $mode = (string) config("muwathaq.{$kind}_mode", 'off');

        return $mode === 'on'
            || ($mode === 'canary' && in_array((int) $user->id, (array) config("muwathaq.{$kind}_user_ids", []), true));
    }

    public function openProfileChange(int $requestId): ?Req
    {
        $row = DB::table('profile_change_requests')->where('id', $requestId)->first();
        if (!$row || $row->status !== ProfileChangeRequestService::STATUS_PENDING_REVIEW) {
            return null;
        }
        $user = User::query()->find($row->user_id);
        if (!$user || !$this->enabled(self::PROFILE, $user)) {
            return null;
        }
        $docIds = $row->supporting_document_id ? [(int) $row->supporting_document_id] : [];

        return $this->open(self::PROFILE, $user, (int) $row->id, $docIds, fn (string $ulid, ?string $supersedes) => [
            'event_version' => 1,
            'event_type' => 'profile_change.submitted',
            'application' => $this->application($ulid, 'profile_change_review', $user, $supersedes),
            'applicant' => $this->applicant($user),
            'change' => [
                'field' => (string) $row->field,
                'old_value' => $row->old_value,
                'new_value' => $row->new_value,
                'reason' => $row->reason,
                'opened_by_type' => (string) ($row->opened_by_type ?? 'customer'),
                'needs_document' => in_array($row->field, ProfileChangeRequestService::NEEDS_DOCUMENT, true),
                'resets_verification' => in_array($row->field, ProfileChangeRequestService::RESETS_VERIFICATION, true),
            ],
            'documents' => array_map(fn (int $id) => ['id' => $id, 'kind' => 'supporting_document'], $docIds),
        ]);
    }

    public function openMerchantVerification(MerchantVerificationRequest $mvr): ?Req
    {
        $mvr = $mvr->fresh();
        if (!$mvr || $mvr->status !== 'pending_review') {
            return null;
        }
        $user = User::query()->find($mvr->merchant_user_id);
        if (!$user || !$this->enabled(self::MERCHANT, $user)) {
            return null;
        }
        // وثائق التاجر مسارات ملفات لا KycDocument: تُرسل بترتيبها، والمعرّف = موضعها (1..n).
        $types = [];
        foreach (MerchantVerificationAdminController::DOCUMENTS as $type => [$column]) {
            if (!empty($mvr->{$column})) $types[] = $type;
        }

        return $this->open(self::MERCHANT, $user, (int) $mvr->id, $types, fn (string $ulid, ?string $supersedes) => [
            'event_version' => 1,
            'event_type' => 'merchant_verification.submitted',
            'application' => $this->application($ulid, 'merchant_verification_review', $user, $supersedes) + ['business_type' => $mvr->business_category],
            'applicant' => $this->applicant($user),
            'business' => [
                'business_name' => $mvr->business_name,
                'commercial_register_number' => $mvr->commercial_register_number,
                'business_category' => $mvr->business_category,
                'city' => $mvr->city,
                'address' => $mvr->address,
                'contact_phone' => $mvr->contact_phone,
                'bank_name' => $mvr->bank_name,
                // رقم الحساب البنكي لا يُرسل كاملاً: يكفي المراجع آخر 4 خانات للمطابقة.
                'bank_account_last4' => $mvr->bank_account_number ? substr(preg_replace('/\D+/', '', (string) $mvr->bank_account_number), -4) : null,
                'bank_account_holder' => $mvr->bank_account_holder,
            ],
            'documents' => array_values(array_map(fn (string $t, int $i) => ['id' => $i + 1, 'kind' => $t], $types, array_keys($types))),
        ]);
    }

    /** @param list<int|string> $docIds */
    private function open(string $kind, User $user, int $subjectId, array $docIds, callable $payload): Req
    {
        $req = DB::transaction(function () use ($kind, $user, $subjectId, $docIds, $payload): Req {
            $old = Req::query()->where('kind', $kind)->where('subject_id', $subjectId)->whereIn('status', Req::OPEN)->lockForUpdate()->first();
            if ($old) {
                $old->update(['status' => 'superseded', 'open_key' => null]);
            }
            $ulid = (string) Str::ulid();

            return Req::query()->create([
                'request_ulid' => $ulid, 'kind' => $kind, 'subject_id' => $subjectId, 'user_id' => $user->id,
                'target_tier' => null, 'status' => 'submitted', 'open_key' => $kind.':'.$subjectId,
                'document_ids' => $docIds, 'event_ulid' => (string) Str::ulid(),
                'payload' => $payload($ulid, $old?->request_ulid),
            ]);
        });
        DB::afterCommit(fn () => \App\Jobs\DeliverMuwathaqTierUpgradeJob::dispatch($req->id));

        return $req;
    }

    private function application(string $ulid, string $path, User $user, ?string $supersedes): array
    {
        return [
            'application_ulid' => $ulid, 'subject_kind' => (int) $user->type === MERCHANT_TYPE ? 'merchant' : 'customer',
            'verification_path' => $path, 'requested_tier' => null, 'current_tier' => (int) ($user->kyc_tier ?? 0),
            'submitted_at' => now()->toIso8601String(), 'supersedes_application_ulid' => $supersedes,
        ];
    }

    private function applicant(User $user): array
    {
        return [
            'amial_user_id' => $user->id,
            'legal_name' => (string) ($user->declared_legal_name ?: trim(($user->f_name ?? '').' '.($user->l_name ?? ''))),
            'phone' => $user->phone, 'email' => $user->email, 'gender' => $user->gender,
        ];
    }

    /** @return array{http:int, body:array} — العقد نفسه لقرار الترقية والسكن. */
    public function receiveDecision(Req $req, string $decision, string $reference, ?int $seq, ?string $reason, array $context): array
    {
        return DB::transaction(function () use ($req, $decision, $reference, $seq, $reason, $context): array {
            $r = Req::query()->lockForUpdate()->findOrFail($req->id);
            $out = fn (int $http, string $code, bool $applied = false, ?string $apply = null): array => ['http' => $http, 'body' => [
                'success' => $http < 300, 'code' => $code,
                'meta' => ['kind' => $r->kind, 'request_ulid' => $r->request_ulid, 'status' => $r->status, 'applied' => $applied, 'apply_code' => $apply],
            ]];

            if (in_array($r->status, Req::FINAL, true) || $r->status === 'needs_more_info') {
                return $r->decision_reference === $reference && $r->status === $decision
                    ? $out(200, 'MUWATHAQ_DECISION_APPLIED', true)
                    : $out(409, $r->status === 'superseded' ? 'SUPERSEDED' : 'REQUEST_FINAL');
            }
            if ($seq !== null && $seq <= $r->last_decision_seq) {
                return $out(200, 'STALE_DECISION_IGNORED');
            }
            if ($seq !== null) $r->last_decision_seq = $seq;
            if ($decision === 'under_review') {
                $r->status = 'under_review';
                $r->save();

                return $out(200, 'MUWATHAQ_DECISION_APPLIED', true);
            }
            if ($decision === 'needs_more_info' && $r->kind === self::PROFILE) {
                // طلب تغيير البيان في أميال باي له قرار ثنائي فقط؛ الاستكمال = رفض بسبب ثم طلب جديد.
                return $out(409, 'NEEDS_MORE_INFO_NOT_SUPPORTED');
            }
            $reviewer = trim((string) ($context['reviewer_reference'] ?? ''));
            $proposer = trim((string) ($context['proposer_reference'] ?? ''));
            if (in_array($decision, ['approved', 'rejected'], true) && ($reviewer === '' || $proposer === '' || $reviewer === $proposer)) {
                return $out(409, 'INDEPENDENT_REVIEW_REQUIRED');
            }
            $affirm = $r->kind === self::PROFILE ? 'verified_change' : 'verified_business';
            if ($decision === 'approved' && ($context[$affirm] ?? false) !== true) {
                return $out(409, 'NOT_AFFIRMED');
            }
            if ($decision !== 'approved' && mb_strlen(trim((string) $reason)) < 5) {
                return $out(422, 'REASON_REQUIRED');
            }

            $bridge = app(MuwathaqBridgeReviewer::class)->resolve();
            try {
                if ($r->kind === self::PROFILE) {
                    app(ProfileChangeRequestService::class)->decide((int) $r->subject_id, $bridge, $decision === 'approved', $reason);
                } else {
                    $mvr = MerchantVerificationRequest::query()->findOrFail((int) $r->subject_id);
                    $svc = app(MerchantVerificationService::class);
                    match ($decision) {
                        'approved' => $svc->approve($mvr, (int) $bridge->id),
                        'needs_more_info' => $svc->requestResubmission($mvr, (int) $bridge->id, (string) $reason),
                        default => $svc->reject($mvr, (int) $bridge->id, (string) $reason),
                    };
                }
            } catch (DomainException|\RuntimeException $e) {
                $r->apply_code = Str::limit($e->getMessage(), 60, '');
                $r->save();

                return $out(200, 'MUWATHAQ_DECISION_BLOCKED', false, $r->apply_code);
            }

            $r->fill([
                'status' => $decision, 'open_key' => null, 'decision_reference' => $reference, 'decision_reason' => $reason,
                'decision_context' => $context, 'decided_at' => now(), 'applied_at' => now(), 'apply_code' => null,
            ])->save();
            app(AuditService::class)->record([
                'actor_type' => 'muwathaq_reviewer', 'actor_user_id' => $bridge->id,
                'subject_type' => $r->kind, 'subject_id' => (string) $r->subject_id,
                'action' => 'MUWATHAQ_'.strtoupper($r->kind).'_DECISION', 'decision_code' => strtoupper($decision), 'severity' => 'info',
                'context' => ['request_ulid' => $r->request_ulid, 'decision_reference' => $reference,
                    'reviewer_reference' => $reviewer, 'proposer_reference' => $proposer],
            ]);

            return $out(200, 'MUWATHAQ_DECISION_APPLIED', true);
        });
    }

    /** يملك موثّق مراجعة هذا الطلب الآن؟ (يمنع قراراً مزدوجاً من الشاشات القديمة) */
    public static function owns(string $kind, int $subjectId): bool
    {
        return \Illuminate\Support\Facades\Schema::hasTable('muwathaq_review_requests')
            && Req::query()->where('kind', $kind)->where('subject_id', $subjectId)->whereIn('status', Req::OPEN)->exists();
    }
}
