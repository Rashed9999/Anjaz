<?php

namespace App\Services\Verification;

use App\Models\KycDocument;
use App\Models\MerchantProfile;
use App\Models\MerchantVerificationRequest;
use App\Models\User;
use App\Models\VerificationCase;
use App\Models\VerificationCaseStep;
use App\Models\VerificationRequirementPolicy;
use App\Services\Kyc\KycOwnershipGuardService;
use App\Services\Kyc\ResidenceVerificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * AMIAL-UNIFIED-VERIFICATION-001 — the workflow source of truth.
 *
 * This service never moves or duplicates identity files.  It evaluates the
 * evidence already held by the specialised KYC/residence/merchant services,
 * stores a dated step snapshot, and only exposes the next permitted step.
 */
class VerificationCaseService
{
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_ACTION_REQUIRED = 'action_required';
    public const STATUS_REVIEW = 'review';
    public const STATUS_COMPLETE = 'complete';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    public function __construct(
        private readonly ResidenceVerificationService $residence,
        private readonly KycOwnershipGuardService $ownership,
    ) {}

    /** @return array<string,mixed> */
    public function snapshot(User $subject): array
    {
        $case = $this->ensure($subject);
        $policy = $this->policyFor($case);
        $requirements = $policy?->requirements ?? [];
        $evaluated = $this->evaluate($subject, $case, $requirements);
        $steps = $this->syncSteps($case, $evaluated);

        $firstOpen = collect($steps)->first(fn (array $step) => !in_array(
            $step['status'], [self::STATUS_COMPLETE, self::STATUS_APPROVED], true
        ));
        $workflow = $case->workflow_status;
        if (!in_array($workflow, [self::STATUS_APPROVED, self::STATUS_REJECTED], true)) {
            $workflow = $firstOpen ? (string) $firstOpen['status'] : self::STATUS_REVIEW;
            $case->forceFill([
                'workflow_status' => $workflow,
                'current_step' => $firstOpen['key'] ?? null,
            ])->save();
        }

        return [
            'case_ulid' => $case->case_ulid,
            'subject_kind' => $case->subject_kind,
            'merchant_vertical' => $case->merchant_vertical,
            'policy_version' => $case->policy_version,
            'status' => $workflow,
            'current_step' => $case->current_step,
            'final_decision' => [
                'status' => $case->workflow_status,
                'decided_at' => $case->final_decided_at?->toIso8601String(),
                'reason' => $case->final_reason,
            ],
            'steps' => $steps,
            'ready_for_final_review' => $this->readyForFinalReview($steps),
        ];
    }

    public function ensure(User $subject): VerificationCase
    {
        if (!Schema::hasTable('kyc_verification_cases')) {
            throw new \DomainException('UNIFIED_VERIFICATION_SCHEMA_UNAVAILABLE');
        }

        $kind = $this->subjectKind($subject);
        $vertical = $this->merchantVertical($subject);
        $policy = $this->resolvePolicy($kind, $vertical);

        return DB::transaction(function () use ($subject, $kind, $vertical, $policy) {
            $case = VerificationCase::query()->firstOrCreate(
                ['user_id' => (int) $subject->id],
                [
                    'case_ulid' => (string) Str::ulid(),
                    'subject_kind' => $kind,
                    'merchant_vertical' => $vertical,
                    'target_level' => $policy?->target_level ?? 1,
                    'policy_version' => $policy?->policy_version,
                    'workflow_status' => 'collecting',
                ],
            );

            // Cases made by the privacy module pre-date the unified workflow.
            // Enrich them in place to retain their biometric/privacy audit trail.
            $changes = [];
            if (!$case->case_ulid) $changes['case_ulid'] = (string) Str::ulid();
            if (!$case->subject_kind) $changes['subject_kind'] = $kind;
            if ($case->merchant_vertical !== $vertical) $changes['merchant_vertical'] = $vertical;
            if (!$case->policy_version || $case->policy_version !== ($policy?->policy_version)) {
                $changes['policy_version'] = $policy?->policy_version;
                $changes['target_level'] = $policy?->target_level ?? 1;
            }
            if ($changes !== []) $case->forceFill($changes)->save();

            return $case->fresh();
        });
    }

    /** @return array<int,string> */
    public function merchantRequiredDocuments(User $merchant): array
    {
        if ($this->merchantVertical($merchant) === 'quick_sale') {
            return ['id_card_front', 'id_card_back', 'store_photo'];
        }

        return ['id_card_front', 'id_card_back', 'commercial_register', 'store_photo'];
    }

    /** @return array<int,array<string,mixed>> */
    private function evaluate(User $subject, VerificationCase $case, array $requirements): array
    {
        $docs = KycDocument::query()->where('user_id', $subject->id)->get()
            ->groupBy('doc_type');
        $usable = static fn (string $type): bool => ($docs->get($type, collect()))
            ->contains(fn (KycDocument $doc) => $doc->isUsable());
        $pending = static fn (string $type): bool => ($docs->get($type, collect()))
            ->contains(fn (KycDocument $doc) => $doc->status === KycDocument::STATUS_PENDING);
        $merchantRequest = MerchantVerificationRequest::query()
            ->where('merchant_user_id', $subject->id)->latest('id')->first();

        $result = [];
        foreach ($requirements as $order => $requirement) {
            $key = (string) ($requirement['key'] ?? 'unknown');
            $assessment = match ($key) {
                'contact' => $this->contactAssessment($subject),
                'residence' => $this->residenceAssessment($subject),
                'identity', 'owner_identity' => $this->identityAssessment($usable, $pending),
                'ownership' => $this->ownershipAssessment($subject),
                'establishment' => $this->establishmentAssessment($merchantRequest, false),
                'quick_sale_location' => $this->establishmentAssessment($merchantRequest, true),
                'risk_review' => $this->riskAssessment($subject),
                'final_activation' => $this->finalAssessment($case),
                default => ['status' => self::STATUS_ACTION_REQUIRED, 'reason' => 'متطلب غير معروف في السياسة.'],
            };
            $result[] = [
                'key' => $key,
                'label' => (string) ($requirement['label'] ?? $key),
                'order' => $order + 1,
                'status' => $assessment['status'],
                'reason' => $assessment['reason'] ?? null,
                'evidence' => $assessment['evidence'] ?? [],
            ];
        }

        // A person cannot jump a later step merely by opening its API route.
        $unlocked = true;
        foreach ($result as &$step) {
            if (!$unlocked && $step['status'] === self::STATUS_ACTION_REQUIRED) {
                $step['status'] = self::STATUS_BLOCKED;
                $step['reason'] = 'أكمل الخطوة السابقة أولاً.';
            }
            if (!in_array($step['status'], [self::STATUS_COMPLETE, self::STATUS_APPROVED], true)) {
                $unlocked = false;
            }
        }
        unset($step);

        return $result;
    }

    /** @return array<string,mixed> */
    private function contactAssessment(User $user): array
    {
        $phone = (bool) ($user->is_phone_verified ?? false);
        $email = (bool) ($user->is_email_verified ?? false);
        return [
            'status' => $phone && $email ? self::STATUS_COMPLETE : self::STATUS_ACTION_REQUIRED,
            'reason' => $phone && $email ? null : 'يلزم إثبات رقم الهاتف والبريد الإلكتروني المرتبطين بالحساب.',
            'evidence' => ['phone_verified' => $phone, 'email_verified' => $email],
        ];
    }

    /** @return array<string,mixed> */
    private function residenceAssessment(User $user): array
    {
        $state = $this->residence->forUser($user);
        $verified = ($state['status'] ?? null) === ResidenceVerificationService::STATUS_VERIFIED;
        return [
            'status' => $verified ? self::STATUS_COMPLETE : (($state['status'] ?? '') === ResidenceVerificationService::STATUS_PENDING ? self::STATUS_REVIEW : self::STATUS_ACTION_REQUIRED),
            'reason' => $verified ? null : (string) ($state['decision_reason'] ?? 'أدخل بيانات السكن وارفع إثباتاً صالحاً.'),
            'evidence' => ['residence_status' => $state['status'] ?? 'not_submitted'],
        ];
    }

    /** @param callable(string):bool $usable @param callable(string):bool $pending */
    private function identityAssessment(callable $usable, callable $pending): array
    {
        $complete = $usable(KycDocument::TYPE_ID_FRONT) && $usable(KycDocument::TYPE_ID_BACK);
        $underReview = $pending(KycDocument::TYPE_ID_FRONT) || $pending(KycDocument::TYPE_ID_BACK);
        return [
            'status' => $complete ? self::STATUS_COMPLETE : ($underReview ? self::STATUS_REVIEW : self::STATUS_ACTION_REQUIRED),
            'reason' => $complete ? null : ($underReview ? 'وثائق الهوية بانتظار المراجعة.' : 'ارفع وجهي وثيقة الهوية.'),
            'evidence' => ['front_usable' => $usable(KycDocument::TYPE_ID_FRONT), 'back_usable' => $usable(KycDocument::TYPE_ID_BACK)],
        ];
    }

    /** @return array<string,mixed> */
    private function ownershipAssessment(User $user): array
    {
        $state = $this->ownership->assess($user, 3);
        return [
            'status' => ($state['ready'] ?? false) ? self::STATUS_COMPLETE : self::STATUS_ACTION_REQUIRED,
            'reason' => ($state['ready'] ?? false) ? null : 'يلزم إثبات حديث بأن صاحب الحساب هو صاحب الهوية.',
            'evidence' => ['ready' => (bool) ($state['ready'] ?? false)],
        ];
    }

    /** @return array<string,mixed> */
    private function establishmentAssessment(?MerchantVerificationRequest $request, bool $quickSale): array
    {
        if (!$request) {
            return ['status' => self::STATUS_ACTION_REQUIRED, 'reason' => 'أدخل بيانات النشاط وارفع أدلته.'];
        }
        $base = trim((string) $request->business_name) !== '' && trim((string) $request->city) !== ''
            && trim((string) $request->address) !== '' && !empty($request->store_photo_path);
        $evidence = [
            'business_name' => trim((string) $request->business_name) !== '',
            'location' => trim((string) $request->city) !== '' && trim((string) $request->address) !== '',
            'store_photo' => !empty($request->store_photo_path),
            'commercial_register' => !empty($request->commercial_register_path),
        ];
        $complete = $quickSale ? $base : ($base && $evidence['commercial_register']);
        $review = $request->status === 'pending_review';
        return [
            'status' => $complete ? ($review ? self::STATUS_REVIEW : self::STATUS_COMPLETE) : self::STATUS_ACTION_REQUIRED,
            'reason' => $complete ? ($review ? 'بيانات النشاط مكتملة وتنتظر مراجعة الإدارة.' : null)
                : ($quickSale ? 'يلزم اسم النشاط وموقعه وصورته.' : 'يلزم اسم النشاط وموقعه وصورته والسجل التجاري.'),
            'evidence' => $evidence,
        ];
    }

    /** @return array<string,mixed> */
    private function riskAssessment(User $user): array
    {
        $status = (string) ($user->sanction_status ?? 'not_screened');
        if (in_array($status, ['clear', 'passed'], true)) return ['status' => self::STATUS_COMPLETE, 'evidence' => ['sanction_status' => $status]];
        if (in_array($status, ['blocked', 'matched'], true)) return ['status' => self::STATUS_REJECTED, 'reason' => 'الحساب يحتاج معالجة امتثال قبل التفعيل.', 'evidence' => ['sanction_status' => $status]];
        return ['status' => self::STATUS_REVIEW, 'reason' => 'مراجعة المخاطر مطلوبة قبل القرار النهائي.', 'evidence' => ['sanction_status' => $status]];
    }

    /** @return array<string,mixed> */
    private function finalAssessment(VerificationCase $case): array
    {
        return match ((string) $case->workflow_status) {
            self::STATUS_APPROVED => ['status' => self::STATUS_APPROVED],
            self::STATUS_REJECTED => ['status' => self::STATUS_REJECTED, 'reason' => $case->final_reason],
            default => ['status' => self::STATUS_REVIEW, 'reason' => 'ينتظر قرار الاعتماد النهائي من المفوض.'],
        };
    }

    /** @return array<int,array<string,mixed>> */
    private function syncSteps(VerificationCase $case, array $evaluated): array
    {
        foreach ($evaluated as $step) {
            $row = VerificationCaseStep::query()->firstOrNew([
                'verification_case_id' => $case->id,
                'step_key' => $step['key'],
            ]);
            $status = $step['status'];
            $row->fill([
                'step_order' => $step['order'],
                'status' => $status,
                'evidence_snapshot' => $step['evidence'],
                'completed_at' => in_array($status, [self::STATUS_COMPLETE, self::STATUS_APPROVED], true) ? now() : null,
                'review_note' => $step['reason'],
            ])->save();
        }

        return $evaluated;
    }

    /** @param array<int,array<string,mixed>> $steps */
    private function readyForFinalReview(array $steps): bool
    {
        return collect($steps)->filter(fn (array $step) => $step['key'] !== 'final_activation')
            ->every(fn (array $step) => $step['status'] === self::STATUS_COMPLETE);
    }

    private function policyFor(VerificationCase $case): ?VerificationRequirementPolicy
    {
        return $this->resolvePolicy((string) $case->subject_kind, $case->merchant_vertical);
    }

    private function resolvePolicy(string $kind, ?string $vertical): ?VerificationRequirementPolicy
    {
        if (!Schema::hasTable('verification_requirement_policies')) return null;
        if ($vertical) {
            $exact = VerificationRequirementPolicy::query()->where([
                'subject_kind' => $kind, 'merchant_vertical' => $vertical, 'is_active' => true,
            ])->latest('id')->first();
            if ($exact) return $exact;
        }
        return VerificationRequirementPolicy::query()->where([
            'subject_kind' => $kind, 'is_active' => true,
        ])->whereNull('merchant_vertical')->latest('id')->first();
    }

    private function subjectKind(User $user): string
    {
        return match ((int) $user->type) {
            MERCHANT_TYPE => 'merchant',
            AGENT_TYPE => 'agent',
            ADMIN_TYPE => 'admin_staff',
            default => 'customer',
        };
    }

    private function merchantVertical(User $user): ?string
    {
        if ((int) $user->type !== MERCHANT_TYPE) return null;
        return MerchantProfile::query()->where('user_id', $user->id)->value('business_type');
    }
}
