<?php

namespace App\Services\Verification;

use App\Models\KycDocument;
use App\Models\AgentProfile;
use App\Models\MerchantProfile;
use App\Models\MerchantVerificationRequest;
use App\Models\User;
use App\Models\VerificationCase;
use App\Models\VerificationCaseEvent;
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
        // السياسة المزروعة تخصيصٌ، لا شرط لصحة المحرك. غيابها في نشر
        // تدريجي لا يجوز أن يحوّل ملفاً بلا أدلة إلى «مكتمل».
        $requirements = $policy?->requirements ?? $this->defaultRequirementsFor($case);
        $evaluated = $this->evaluate($subject, $case, $requirements);
        $steps = $this->syncSteps($case, $evaluated);

        $firstOpen = collect($steps)->first(fn (array $step) => !in_array(
            $step['status'], [self::STATUS_COMPLETE, self::STATUS_APPROVED], true
        ));
        // لا تعني حالة خطوةٍ مرفوضة أن القضية كلها حُسمت نهائياً. هذا مهم
        // خصوصاً للتاجر والوكيل: رفض الهوية الشخصية أو طلب استكمال المنشأة
        // يظل قابلاً للتصحيح، ولا يجوز أن يتحول إلى «رفض نهائي» للقضية.
        $hasFinalDecision = $case->final_decided_at !== null;
        $workflow = $hasFinalDecision ? $case->workflow_status : null;
        if (!$hasFinalDecision) {
            $workflow = $firstOpen ? (string) $firstOpen['status'] : self::STATUS_COMPLETE;
            $case->forceFill([
                // تحفظ الحالة التشغيلية القابلة للاستئناف، أما الرفض فيظل
                // وصفاً للخطوة المعنية فقط إلى أن يصدر قرار نهائي صريح.
                'workflow_status' => $workflow === self::STATUS_REJECTED
                    ? self::STATUS_ACTION_REQUIRED : $workflow,
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
        $targetLevel = $kind === 'customer' ? $this->resolveTargetLevel($subject) : null;
        $policy = $this->resolvePolicy($kind, $vertical, $targetLevel);

        return DB::transaction(function () use ($subject, $kind, $vertical, $targetLevel, $policy) {
            $case = VerificationCase::query()->firstOrCreate(
                ['user_id' => (int) $subject->id],
                [
                    'case_ulid' => (string) Str::ulid(),
                    'subject_kind' => $kind,
                    'merchant_vertical' => $vertical,
                    'target_level' => $targetLevel ?? $policy?->target_level ?? 1,
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
            if ($targetLevel !== null && (int) $case->target_level !== $targetLevel) {
                $changes['target_level'] = $targetLevel;
            }
            if ($policy && (!$case->policy_version || $case->policy_version !== $policy->policy_version)) {
                $changes['policy_version'] = $policy->policy_version;
                $changes['target_level'] = $policy->target_level ?? 1;
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

    /**
     * Administrative-only timeline. It is intentionally separate from
     * snapshot(), which also serves customer status APIs and must not disclose
     * reviewer identities or internal risk-review events.
     *
     * @return array<int,array<string,mixed>>
     */
    public function administrativeTimeline(User $subject, int $limit = 20): array
    {
        if (!Schema::hasTable('verification_case_events')) return [];
        $case = $this->ensure($subject);
        $events = VerificationCaseEvent::query()
            ->where('verification_case_id', $case->id)
            ->latest('id')->limit(max(1, min($limit, 50)))->get();
        $actors = User::query()->whereIn('id', $events->pluck('actor_user_id')->filter()->unique())
            ->get(['id', 'f_name', 'l_name'])->keyBy('id');

        return $events->map(function (VerificationCaseEvent $event) use ($actors): array {
            $actor = $actors->get($event->actor_user_id);
            return [
                'type' => $event->event_type,
                'at' => $event->created_at?->toIso8601String(),
                'actor' => $actor
                    ? (trim((string) ($actor->f_name.' '.$actor->l_name)) ?: '#'.$event->actor_user_id)
                    : ($event->actor_user_id ? '#'.$event->actor_user_id : 'النظام'),
                'summary' => $this->eventSummary($event->event_type),
            ];
        })->values()->all();
    }

    /**
     * Records the only decision that can make an identity-verification case
     * final. The KYC service performs the document, ownership and four-eyes
     * guards first; this method makes that already-authorized result visible
     * in the unified case and its immutable timeline.
     */
    public function recordAccountDecision(
        User $subject,
        User $reviewer,
        bool $approved,
        int $targetLevel,
        ?string $reason = null,
    ): void {
        if (!$this->workflowStorageAvailable()) {
            // Deployment may briefly run application code before this additive
            // migration. Never turn a completed, guarded KYC decision into a
            // partial failure because its projection table is not present yet.
            return;
        }

        DB::transaction(function () use ($subject, $reviewer, $approved, $targetLevel, $reason) {
            $case = $this->ensure($subject);
            $case = VerificationCase::query()->lockForUpdate()->findOrFail($case->id);

            // فقط العميل الفردي يساوي قرار هويته «القرار النهائي للقضية».
            // للتاجر والوكيل يُسجل قرار الهوية في خطوته الخاصة؛ اعتماد
            // المنشأة أو تشغيل الوكيل يبقيان قرارين مستقلين ولا يُغلقان
            // تلقائياً بمجرد اعتماد الهوية.
            if (in_array((string) $case->subject_kind, ['merchant', 'agent'], true)) {
                $identityStep = $this->identityRequirementKey($case);
                VerificationCaseStep::query()->updateOrCreate(
                    ['verification_case_id' => $case->id, 'step_key' => $identityStep],
                    [
                        'step_order' => $this->requirementOrder($case, $identityStep),
                        'status' => $approved ? self::STATUS_APPROVED : self::STATUS_REJECTED,
                        'completed_at' => $approved ? now() : null,
                        'reviewed_by' => (int) $reviewer->id,
                        'reviewed_at' => now(),
                        'review_note' => $reason,
                        'evidence_snapshot' => [
                            'scope' => 'personal_identity',
                            'target_level' => $targetLevel,
                        ],
                    ],
                );
                VerificationCaseEvent::create([
                    'verification_case_id' => $case->id,
                    'actor_user_id' => (int) $reviewer->id,
                    'event_type' => $approved ? 'identity_decision_approved' : 'identity_decision_rejected',
                    'details' => [
                        'scope' => 'personal_identity',
                        'target_level' => $targetLevel,
                        'reason_provided' => filled($reason),
                    ],
                ]);
                return;
            }

            $previousStatus = (string) $case->workflow_status;
            $case->forceFill([
                'target_level' => max((int) $case->target_level, $targetLevel),
                'workflow_status' => $approved ? self::STATUS_APPROVED : self::STATUS_REJECTED,
                'current_step' => 'final_activation',
                'final_decided_by' => (int) $reviewer->id,
                'final_decided_at' => now(),
                'final_reason' => $reason,
            ])->save();

            // A manual risk review is never inferred from a document upload.
            // It is attested by the authorised final reviewer and gets its own
            // event so audit can distinguish it from automated screening.
            if ($approved) {
                VerificationCaseEvent::create([
                    'verification_case_id' => $case->id,
                    'actor_user_id' => (int) $reviewer->id,
                    'event_type' => 'risk_review_completed',
                    'details' => ['source' => 'final_identity_decision'],
                ]);
            }

            $finalOrder = $this->requirementOrder($case, 'final_activation');
            VerificationCaseStep::query()->updateOrCreate(
                ['verification_case_id' => $case->id, 'step_key' => 'final_activation'],
                [
                    'step_order' => $finalOrder,
                    'status' => $approved ? self::STATUS_APPROVED : self::STATUS_REJECTED,
                    'completed_at' => $approved ? now() : null,
                    'reviewed_by' => (int) $reviewer->id,
                    'reviewed_at' => now(),
                    'review_note' => $reason,
                ],
            );

            VerificationCaseEvent::create([
                'verification_case_id' => $case->id,
                'actor_user_id' => (int) $reviewer->id,
                'event_type' => $approved ? 'identity_case_approved' : 'identity_case_rejected',
                'details' => [
                    'target_level' => $targetLevel,
                    'previous_status' => $previousStatus,
                    'reason_provided' => filled($reason),
                ],
            ]);
        });
    }

    /**
     * Merchant registration is a scoped business decision, not personal KYC.
     * It is recorded in the same case without finalising the identity case or
     * changing financial verification state.
     */
    public function recordMerchantReview(
        User $merchant,
        User $reviewer,
        bool $approved,
        string $requestUlid,
        ?string $reason = null,
    ): void {
        if (!$this->workflowStorageAvailable()) return;

        DB::transaction(function () use ($merchant, $reviewer, $approved, $requestUlid, $reason) {
            $case = $this->ensure($merchant);
            $stepKey = $case->merchant_vertical === 'quick_sale'
                ? 'quick_sale_location' : 'establishment';

            VerificationCaseStep::query()->updateOrCreate(
                ['verification_case_id' => $case->id, 'step_key' => $stepKey],
                [
                    'step_order' => $this->requirementOrder($case, $stepKey),
                    'status' => $approved ? self::STATUS_APPROVED : self::STATUS_REJECTED,
                    'completed_at' => $approved ? now() : null,
                    'reviewed_by' => (int) $reviewer->id,
                    'reviewed_at' => now(),
                    'review_note' => $reason,
                ],
            );

            VerificationCaseEvent::create([
                'verification_case_id' => $case->id,
                'actor_user_id' => (int) $reviewer->id,
                'event_type' => $approved ? 'merchant_verification_approved' : 'merchant_verification_rejected',
                'details' => [
                    'request_ulid' => $requestUlid,
                    'reason_provided' => filled($reason),
                ],
            ]);
        });
    }

    /** يسجل طلب الاستكمال كحالة مستقلة، لا كرفض نهائي للمنشأة. */
    public function recordMerchantResubmission(
        User $merchant,
        User $reviewer,
        string $requestUlid,
        string $reason,
    ): void {
        if (!$this->workflowStorageAvailable()) return;

        DB::transaction(function () use ($merchant, $reviewer, $requestUlid, $reason) {
            $case = $this->ensure($merchant);
            $stepKey = $case->merchant_vertical === 'quick_sale'
                ? 'quick_sale_location' : 'establishment';
            VerificationCaseStep::query()->updateOrCreate(
                ['verification_case_id' => $case->id, 'step_key' => $stepKey],
                [
                    'step_order' => $this->requirementOrder($case, $stepKey),
                    'status' => self::STATUS_ACTION_REQUIRED,
                    'completed_at' => null,
                    'reviewed_by' => (int) $reviewer->id,
                    'reviewed_at' => now(),
                    'review_note' => $reason,
                ],
            );
            VerificationCaseEvent::create([
                'verification_case_id' => $case->id,
                'actor_user_id' => (int) $reviewer->id,
                'event_type' => 'merchant_verification_resubmission_required',
                'details' => ['request_ulid' => $requestUlid, 'reason_provided' => true],
            ]);
        });
    }

    /** يسجل اعتماد أو تعليق ملف التشغيل للوكيل، منفصلاً عن هوية الشخص. */
    public function recordAgentDecision(
        User $agent,
        User $reviewer,
        bool $approved,
        ?string $reason = null,
    ): void {
        if (!$this->workflowStorageAvailable()) return;

        DB::transaction(function () use ($agent, $reviewer, $approved, $reason) {
            $case = $this->ensure($agent);
            VerificationCaseStep::query()->updateOrCreate(
                ['verification_case_id' => $case->id, 'step_key' => 'agent_operating_profile'],
                [
                    'step_order' => $this->requirementOrder($case, 'agent_operating_profile'),
                    'status' => $approved ? self::STATUS_APPROVED : self::STATUS_REJECTED,
                    'completed_at' => $approved ? now() : null,
                    'reviewed_by' => (int) $reviewer->id,
                    'reviewed_at' => now(),
                    'review_note' => $reason,
                ],
            );
            VerificationCaseEvent::create([
                'verification_case_id' => $case->id,
                'actor_user_id' => (int) $reviewer->id,
                'event_type' => $approved ? 'agent_operating_approved' : 'agent_operating_suspended',
                'details' => ['reason_provided' => filled($reason)],
            ]);
        });
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
                'contact' => $this->contactAssessment($subject, $case->subject_kind !== 'customer'),
                'residence' => $this->residenceAssessment($subject),
                'identity', 'owner_identity' => $this->identityAssessment($subject, $case, $key, $usable, $pending),
                'address_proof' => $this->documentAssessment($usable, $pending, KycDocument::TYPE_ADDRESS_PROOF, 'ارفع دليل عنوان صالحاً ضمن ملف KYC.'),
                'ownership' => $this->ownershipAssessment($subject),
                'establishment' => $this->establishmentAssessment($merchantRequest, false),
                'quick_sale_location' => $this->establishmentAssessment($merchantRequest, true),
                'agent_operating_profile' => $this->agentOperatingAssessment($subject),
                'staff_security' => $this->staffSecurityAssessment($subject),
                'risk_review' => $this->riskAssessment($subject, $case),
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
    private function contactAssessment(User $user, bool $emailRequired = true): array
    {
        $phone = (bool) ($user->is_phone_verified ?? false);
        $email = (bool) ($user->is_email_verified ?? false);
        $complete = $phone && (!$emailRequired || $email);
        return [
            'status' => $complete ? self::STATUS_COMPLETE : self::STATUS_ACTION_REQUIRED,
            'reason' => $complete ? null : ($emailRequired
                ? 'يلزم إثبات رقم الهاتف والبريد الإلكتروني المرتبطين بالحساب.'
                : 'يلزم إثبات رقم الهاتف المرتبط بالحساب.'),
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
    private function identityAssessment(
        User $subject,
        VerificationCase $case,
        string $stepKey,
        callable $usable,
        callable $pending,
    ): array
    {
        $complete = $usable(KycDocument::TYPE_ID_FRONT) && $usable(KycDocument::TYPE_ID_BACK);
        $underReview = $pending(KycDocument::TYPE_ID_FRONT) || $pending(KycDocument::TYPE_ID_BACK);

        // للتاجر والوكيل قرار الهوية الشخصي مستقل عن قرار المنشأة أو ملف
        // التشغيل. نحتفظ بأثره في خطوة الهوية نفسها ولا نخلطه مع
        // final_activation للقضية التجارية/التشغيلية.
        if (in_array((int) ($subject->is_kyc_verified ?? 0), [1, 2], true)) {
            $decision = VerificationCaseStep::query()
                ->where('verification_case_id', $case->id)
                ->where('step_key', $stepKey)
                ->first();
            if ($decision && in_array($decision->status, [self::STATUS_APPROVED, self::STATUS_REJECTED], true)) {
                return [
                    'status' => $decision->status,
                    'reason' => $decision->status === self::STATUS_REJECTED ? $decision->review_note : null,
                    'evidence' => [
                        'front_usable' => $usable(KycDocument::TYPE_ID_FRONT),
                        'back_usable' => $usable(KycDocument::TYPE_ID_BACK),
                        'personal_identity_decided' => true,
                    ],
                ];
            }
        }

        return [
            'status' => $complete ? self::STATUS_COMPLETE : ($underReview ? self::STATUS_REVIEW : self::STATUS_ACTION_REQUIRED),
            'reason' => $complete ? null : ($underReview ? 'وثائق الهوية بانتظار المراجعة.' : 'ارفع وجهي وثيقة الهوية.'),
            'evidence' => ['front_usable' => $usable(KycDocument::TYPE_ID_FRONT), 'back_usable' => $usable(KycDocument::TYPE_ID_BACK)],
        ];
    }

    /** @param callable(string):bool $usable @param callable(string):bool $pending */
    private function documentAssessment(callable $usable, callable $pending, string $type, string $missingReason): array
    {
        return [
            'status' => $usable($type) ? self::STATUS_COMPLETE : ($pending($type) ? self::STATUS_REVIEW : self::STATUS_ACTION_REQUIRED),
            'reason' => $usable($type) ? null : ($pending($type) ? 'المستند بانتظار المراجعة.' : $missingReason),
            'evidence' => ['usable' => $usable($type), 'pending' => $pending($type)],
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
        if ($request->status === 'rejected') {
            return ['status' => self::STATUS_REJECTED, 'reason' => (string) ($request->admin_note ?: 'رُفض طلب توثيق النشاط.'), 'evidence' => $evidence];
        }
        if ($request->status === 'resubmission_required') {
            return ['status' => self::STATUS_ACTION_REQUIRED, 'reason' => (string) ($request->admin_note ?: 'يلزم استكمال بيانات أو أدلة النشاط.'), 'evidence' => $evidence];
        }
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
    private function agentOperatingAssessment(User $user): array
    {
        $agentNumber = trim((string) ($user->agent_number ?? ''));
        $zone = trim((string) ($user->zone_code ?? ''));
        $validZone = $zone !== '' && $zone !== 'UNKNOWN';
        $profileActive = Schema::hasTable('agent_profiles')
            && AgentProfile::query()->where('user_id', $user->id)->where('status', 'active')->exists();
        $complete = $agentNumber !== '' && $validZone && $profileActive;
        return [
            'status' => $complete ? self::STATUS_COMPLETE : self::STATUS_ACTION_REQUIRED,
            'reason' => $complete ? null : 'يلزم رقم وكيل، ملف تشغيلي نشط، ونطاق جغرافي معتمد قبل التفعيل.',
            'evidence' => [
                'agent_number_assigned' => $agentNumber !== '',
                'zone_assigned' => $validZone,
                'profile_active' => $profileActive,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function staffSecurityAssessment(User $user): array
    {
        $twoFactor = (bool) ($user->two_factor_enabled ?? false)
            && !empty($user->two_factor_confirmed_at);
        $hasRole = Schema::hasTable('admin_user_roles')
            && DB::table('admin_user_roles')->where('user_id', $user->id)->exists();
        $complete = $twoFactor && $hasRole;
        return [
            'status' => $complete ? self::STATUS_COMPLETE : self::STATUS_ACTION_REQUIRED,
            'reason' => $complete ? null : 'يلزم تفعيل المصادقة الثنائية وإسناد دور إداري قبل إتاحة العمل.',
            'evidence' => ['two_factor_confirmed' => $twoFactor, 'operator_role_assigned' => $hasRole],
        ];
    }

    /** @return array<string,mixed> */
    private function riskAssessment(User $user, VerificationCase $case): array
    {
        $status = (string) ($user->sanction_status ?? 'not_screened');
        if (in_array($status, ['clear', 'passed'], true)) return ['status' => self::STATUS_COMPLETE, 'evidence' => ['sanction_status' => $status]];
        if (in_array($status, ['blocked', 'matched'], true)) return ['status' => self::STATUS_REJECTED, 'reason' => 'الحساب يحتاج معالجة امتثال قبل التفعيل.', 'evidence' => ['sanction_status' => $status]];
        $manuallyReviewed = Schema::hasTable('verification_case_events')
            && VerificationCaseEvent::query()->where('verification_case_id', $case->id)
                ->where('event_type', 'risk_review_completed')->exists();
        if ($manuallyReviewed) {
            return ['status' => self::STATUS_COMPLETE, 'evidence' => ['sanction_status' => $status, 'manual_review' => true]];
        }
        return ['status' => self::STATUS_REVIEW, 'reason' => 'مراجعة المخاطر مطلوبة قبل القرار النهائي.', 'evidence' => ['sanction_status' => $status]];
    }

    /** @return array<string,mixed> */
    private function finalAssessment(VerificationCase $case): array
    {
        // `workflow_status` وحده قد يكون إسقاطاً مرحلياً من نسخة أقدم أو
        // من خطوة رفض قابلة للاستئناف. القرار النهائي لا يُعتد به إلا مع
        // طابعه ومراجعه في final_decided_at.
        if ($case->final_decided_at === null) {
            return ['status' => self::STATUS_REVIEW, 'reason' => 'ينتظر قرار الاعتماد النهائي من المفوض.'];
        }

        return match ((string) $case->workflow_status) {
            self::STATUS_APPROVED => ['status' => self::STATUS_APPROVED],
            self::STATUS_REJECTED => ['status' => self::STATUS_REJECTED, 'reason' => $case->final_reason],
            default => ['status' => self::STATUS_REVIEW, 'reason' => 'ينتظر قرار الاعتماد النهائي من المفوض.'],
        };
    }

    /** @return array<int,array<string,mixed>> */
    private function syncSteps(VerificationCase $case, array $evaluated): array
    {
        if (!Schema::hasTable('verification_case_steps')) return $evaluated;

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
            ->every(fn (array $step) => in_array($step['status'], [self::STATUS_COMPLETE, self::STATUS_APPROVED], true));
    }

    private function policyFor(VerificationCase $case): ?VerificationRequirementPolicy
    {
        return $this->resolvePolicy(
            (string) $case->subject_kind,
            $case->merchant_vertical,
            (int) $case->target_level,
        );
    }

    /** @return array<int,array{key:string,label:string}> */
    private function defaultRequirementsFor(VerificationCase $case): array
    {
        return match ((string) $case->subject_kind) {
            'merchant' => [
                ['key' => 'contact', 'label' => 'تأكيد قنوات الاتصال'],
                ['key' => 'owner_identity', 'label' => 'هوية مالك المنشأة'],
                [
                    'key' => $case->merchant_vertical === 'quick_sale' ? 'quick_sale_location' : 'establishment',
                    'label' => $case->merchant_vertical === 'quick_sale'
                        ? 'موقع وصورة نقطة البيع السريع' : 'بيانات ووثائق المنشأة التجارية',
                ],
                ['key' => 'risk_review', 'label' => 'فحص المخاطر والامتثال'],
                ['key' => 'final_activation', 'label' => 'قرار التفعيل النهائي'],
            ],
            'agent' => [
                ['key' => 'contact', 'label' => 'تأكيد قنوات الاتصال'],
                ['key' => 'owner_identity', 'label' => 'هوية الوكيل أو المفوض'],
                ['key' => 'agent_operating_profile', 'label' => 'ملف تشغيل الوكيل ونطاقه الجغرافي'],
                ['key' => 'risk_review', 'label' => 'فحص المخاطر والامتثال'],
                ['key' => 'final_activation', 'label' => 'قرار التفعيل النهائي'],
            ],
            'admin_staff' => [
                ['key' => 'staff_security', 'label' => 'المصادقة الثنائية والدور التشغيلي'],
            ],
            default => (int) $case->target_level >= 3 ? [
                ['key' => 'contact', 'label' => 'تأكيد الهاتف والبريد'],
                ['key' => 'residence', 'label' => 'إثبات محل الإقامة الحالي'],
                ['key' => 'identity', 'label' => 'توثيق وثيقة الهوية'],
                ['key' => 'address_proof', 'label' => 'دليل العنوان ضمن ملف KYC'],
                ['key' => 'ownership', 'label' => 'إثبات معزز لصاحب الهوية'],
                ['key' => 'risk_review', 'label' => 'مراجعة المخاطر'],
                ['key' => 'final_activation', 'label' => 'قرار الاعتماد النهائي'],
            ] : [
                ['key' => 'contact', 'label' => 'تأكيد رقم الهاتف'],
                ['key' => 'residence', 'label' => 'إثبات محل الإقامة الحالي'],
                ['key' => 'identity', 'label' => 'توثيق وجهي وثيقة الهوية'],
                ['key' => 'risk_review', 'label' => 'مراجعة المخاطر'],
                ['key' => 'final_activation', 'label' => 'قرار الاعتماد النهائي'],
            ],
        };
    }

    private function requirementOrder(VerificationCase $case, string $key): int
    {
        $requirements = $this->policyFor($case)?->requirements ?? $this->defaultRequirementsFor($case);
        foreach ($requirements as $index => $requirement) {
            if (($requirement['key'] ?? null) === $key) return $index + 1;
        }
        return count($requirements) + 1;
    }

    private function identityRequirementKey(VerificationCase $case): string
    {
        $requirements = $this->policyFor($case)?->requirements ?? $this->defaultRequirementsFor($case);
        foreach ($requirements as $requirement) {
            if (in_array($requirement['key'] ?? null, ['identity', 'owner_identity'], true)) {
                return (string) $requirement['key'];
            }
        }

        return 'identity';
    }

    private function eventSummary(string $eventType): string
    {
        return match ($eventType) {
            'risk_review_completed' => 'أُثبتت مراجعة المخاطر ضمن القرار النهائي.',
            'identity_case_approved' => 'اعتماد نهائي لتوثيق الهوية.',
            'identity_case_rejected' => 'رفض نهائي لطلب توثيق الهوية.',
            'identity_decision_approved' => 'اعتمدت هوية صاحب الحساب؛ لا يعني ذلك اعتماد المنشأة أو تشغيل الوكيل.',
            'identity_decision_rejected' => 'رُفض قرار هوية صاحب الحساب؛ يبقى مسار المنشأة أو تشغيل الوكيل مستقلاً.',
            'merchant_verification_approved' => 'اعتماد توثيق المنشأة؛ لا يعني اعتماد هوية الشخص.',
            'merchant_verification_rejected' => 'رُفض طلب توثيق المنشأة.',
            'merchant_verification_resubmission_required' => 'طُلب من التاجر استكمال بيانات أو أدلة المنشأة.',
            'agent_operating_approved' => 'اعتمد ملف تشغيل الوكيل ونطاقه الجغرافي.',
            'agent_operating_suspended' => 'عُلّق ملف تشغيل الوكيل.',
            default => 'حدث في مسار التحقق.',
        };
    }

    /** تحديد المستوى المستهدف للعميل دون إسقاطه إلى سياسة نشاط أو وكيل. */
    public function resolveTargetLevel(User $subject): int
    {
        $updateRequired = (int) ($subject->kyc_update_required ?? 0) === 1;
        if ($updateRequired && (int) ($subject->kyc_update_previous_tier ?? 0) >= 2) {
            return min(3, (int) $subject->kyc_update_previous_tier);
        }

        return (int) ($subject->kyc_tier ?? 0) >= 2 ? 3 : 2;
    }

    private function resolvePolicy(string $kind, ?string $vertical, ?int $targetLevel = null): ?VerificationRequirementPolicy
    {
        if (!Schema::hasTable('verification_requirement_policies')) return null;

        // شروط العميل الفرد منصوص عليها مركزياً في رحلة KYC (Tier 0..3).
        // لا نسمح لسياسة منشأة أو صف قديم أن يبدّل تعريف Tier 2 أو Tier 3.
        if ($kind === 'customer') return null;

        $baseQuery = VerificationRequirementPolicy::query()
            ->where('subject_kind', $kind)
            ->where('is_active', true);
        if ($vertical) {
            $exact = (clone $baseQuery)->where('merchant_vertical', $vertical)
                ->when($targetLevel !== null, fn ($query) => $query->where('target_level', $targetLevel))
                ->latest('id')->first();
            if ($exact) return $exact;
        }
        return $baseQuery->whereNull('merchant_vertical')
            ->when($targetLevel !== null, fn ($query) => $query->where('target_level', $targetLevel))
            ->latest('id')->first();
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

    private function workflowStorageAvailable(): bool
    {
        return Schema::hasTable('kyc_verification_cases')
            && Schema::hasTable('verification_case_steps')
            && Schema::hasTable('verification_case_events');
    }
}
