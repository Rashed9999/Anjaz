<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\KycDocument;
use App\Models\User;
use App\Services\Admin\KycEvidenceService;
use App\Services\Kyc\KycPrivacyService;
use App\Services\Kyc\ResidenceVerificationService;
use App\Services\KycTierService;
use App\Services\PiiAccessAuditService;
use App\Support\YemenGovernorates;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-KYC-ONE-CENTER-001 — شاشة القضية الواحدة، لا ثلاثة روابط.
 * قراءة فقط: كلّ القرارات تمرّ بالخدمات والمسارات المحروسة الموجودة.
 */
class UnifiedVerificationCenterController extends Controller
{
    public function page()
    {
        return view('admin-views.amial.kyc.center', [
            'governorates' => YemenGovernorates::all(),
        ]);
    }

    public function queue(Request $request, ResidenceVerificationService $residence): JsonResponse
    {
        $input = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
        ]);
        $reviewer = $request->user();
        $canRestricted = $reviewer->hasPlatformPermission('platform.customers.kyc.restricted.view');
        $q = trim((string) ($input['q'] ?? ''));

        // كُلّ مصدر يصل إلى نفس القائمة: تسجيل جديد، مستند، سكن، ترقية هوية.
        // الأولوية للطلبات الفعلية حتى لا تطمر حسابات Tier 0 الحديثة طابور المراجعة.
        $pendingDocumentIds = KycDocument::query()
            ->where('status', KycDocument::STATUS_PENDING)
            ->orderBy('created_at')->limit(200)->pluck('user_id')->all();
        $pendingResidence = collect($residence->pendingQueue(200));
        $pendingResidenceIds = $pendingResidence->pluck('user_id')->all();

        $newAccountIds = User::query()
            ->whereIn('type', [CUSTOMER_TYPE, AGENT_TYPE, MERCHANT_TYPE])
            ->where(fn ($builder) => $builder->whereNull('is_kyc_verified')
                ->orWhere('is_kyc_verified', 0))
            ->orderByDesc('id')->limit(150)->pluck('id')->all();

        // يظل طلب ترقية المستوى الثالث ظاهرًا بعد اعتماد آخر وثيقة.
        $identityCandidateIds = KycDocument::query()
            ->where('status', KycDocument::STATUS_APPROVED)
            ->whereIn('doc_type', [
                KycDocument::TYPE_ID_FRONT,
                KycDocument::TYPE_ID_BACK,
                KycDocument::TYPE_ADDRESS_PROOF,
            ])
            ->orderByDesc('id')->limit(220)->pluck('user_id')->all();

        $ids = array_values(array_unique(array_map('intval', array_merge(
            $pendingDocumentIds, $pendingResidenceIds, $newAccountIds, $identityCandidateIds
        ))));

        $usersQuery = User::query()
            ->whereIn('type', [CUSTOMER_TYPE, AGENT_TYPE, MERCHANT_TYPE]);

        // البحث لا يتقيّد بآخر 150 تسجيلًا: يستطيع المراجع فتح طلبٍ أقدم
        // برقم الحساب أو الهاتف، ولو خرج من نافذة الطابور السريع.
        if ($q === '') {
            $usersQuery->whereIn('id', $ids);
        } else {
            $usersQuery->where(function ($builder) use ($q) {
                if (ctype_digit($q)) {
                    $builder->orWhere('id', (int) $q);
                }
                $builder->orWhere('phone', 'like', '%'.$q.'%')
                    ->orWhere('f_name', 'like', '%'.$q.'%')
                    ->orWhere('l_name', 'like', '%'.$q.'%')
                    ->orWhere('declared_legal_name', 'like', '%'.$q.'%');
            });
        }

        $users = $usersQuery->orderByDesc('id')->limit(350)->get([
            'id', 'type', 'f_name', 'l_name', 'phone', 'is_kyc_verified',
            'kyc_tier', 'kyc_tier_updated_at', 'created_at',
        ]);
        $visibleIds = $users->pluck('id')->all();

        $documents = KycDocument::query()->whereIn('user_id', $visibleIds)
            ->whereIn('status', [
                KycDocument::STATUS_PENDING, KycDocument::STATUS_APPROVED,
            ])->get()->groupBy('user_id');
        $residenceByUser = $pendingResidence->keyBy('user_id');

        // الفلترة داخل الخادم قبل تسليم أي اسم أو رقم هاتف أو عدد تفصيلي.
        $restrictedIds = Schema::hasTable('kyc_verification_cases')
            ? DB::table('kyc_verification_cases')->whereIn('user_id', $visibleIds)
                ->where('restricted_review', true)->pluck('user_id')->mapWithKeys(
                    fn ($id) => [(int) $id => true]
                )->all()
            : [];

        $rows = [];
        foreach ($users as $user) {
            $restricted = isset($restrictedIds[(int) $user->id]);
            if ($restricted && !$canRestricted) {
                continue;
            }

            $userDocs = $documents->get($user->id, collect());
            $pending = $userDocs->where('status', KycDocument::STATUS_PENDING);
            $approved = $userDocs->filter(fn (KycDocument $doc) => $doc->isUsable())
                ->pluck('doc_type')->unique()->all();
            $hasResidence = $residenceByUser->has($user->id);
            $tier = (int) ($user->kyc_tier ?? 0);
            $verified = (int) $user->is_kyc_verified === 1;

            $hasBasicIdentity = in_array(KycDocument::TYPE_ID_FRONT, $approved, true)
                && in_array(KycDocument::TYPE_ID_BACK, $approved, true);
            $hasNewTierThreeEvidence = $tier === 2
                && $userDocs->contains(fn (KycDocument $doc) =>
                    $doc->doc_type === KycDocument::TYPE_ADDRESS_PROOF
                    && $doc->isUsable()
                    && (!$user->kyc_tier_updated_at
                        || $doc->created_at?->greaterThan($user->kyc_tier_updated_at)));

            // لا يظهر حساب قديم معتمد لمجرد وجود صورة هوية في أرشيفه.
            if ($verified && !$hasResidence && $pending->isEmpty() && !$hasNewTierThreeEvidence) {
                continue;
            }

            $stage = $hasResidence ? 'residence'
                : ($pending->isNotEmpty() ? 'documents'
                    : (($hasBasicIdentity || $hasNewTierThreeEvidence) ? 'decision' : 'new'));

            $rows[] = [
                'id' => (int) $user->id,
                'name' => trim((string) ($user->f_name.' '.$user->l_name)) ?: '—',
                'phone' => (string) ($user->phone ?? ''),
                'role' => match ((int) $user->type) {
                    MERCHANT_TYPE => 'تاجر',
                    AGENT_TYPE => 'وكيل',
                    default => 'عميل',
                },
                'stage' => $stage,
                'restricted' => $restricted,
                'pending_documents' => $pending->count(),
                'residence_pending' => $hasResidence,
                'tier' => $tier,
                'registered_at' => $user->created_at?->format('Y-m-d H:i'),
            ];
        }

        $priority = ['decision' => 0, 'residence' => 1, 'documents' => 2, 'new' => 3];
        usort($rows, static fn ($a, $b) =>
            ($priority[$a['stage']] <=> $priority[$b['stage']])
            ?: strcmp((string) $a['registered_at'], (string) $b['registered_at'])
        );
        $counts = array_count_values(array_column($rows, 'stage'));

        return response()->json([
            'success' => true,
            'data' => array_values($rows),
            'meta' => [
                'counts' => [
                    'all' => count($rows),
                    'new' => $counts['new'] ?? 0,
                    'residence' => $counts['residence'] ?? 0,
                    'documents' => $counts['documents'] ?? 0,
                    'decision' => $counts['decision'] ?? 0,
                ],
                'scope' => 'recent_candidates',
                'restricted_visible' => $canRestricted,
            ],
        ]);
    }

    public function account(
        Request $request,
        int $id,
        KycEvidenceService $evidence,
        ResidenceVerificationService $residence,
        KycPrivacyService $privacy,
        KycTierService $tiers,
        PiiAccessAuditService $pii,
    ): JsonResponse {
        $user = User::query()->whereIn(
            'type', [CUSTOMER_TYPE, AGENT_TYPE, MERCHANT_TYPE]
        )->findOrFail($id);
        $reviewer = $request->user();

        try {
            $privacy->assertReviewerAccess($user, $reviewer, false);
        } catch (DomainException) {
            abort(403, 'هذه الحالة مخصّصة لفريق المراجعة المقيدة.');
        }

        // فتح ملفّ هوية شخصية حدث حسّاس، ولو لم يفتح الموظّف صورة المستند.
        // لا يُسجّل وصولٌ إلى حالة مقيدة رُفض عرضها أعلاه.
        $pii->logAccess((int) $reviewer->id, 'user', (int) $user->id,
            'kyc_verification_dossier', 'view', 'فتح ملف التحقق والهوية الموحد');

        $restricted = $privacy->isRestricted($user);
        $updateRequired = (int) ($user->kyc_update_required ?? 0) === 1;
        $targetTier = $updateRequired && (int) ($user->kyc_update_previous_tier ?? 0) >= 2
            ? min(3, (int) $user->kyc_update_previous_tier)
            : ((int) ($user->kyc_tier ?? 0) >= 2 ? 3 : 2);

        $decisionEvidence = $evidence->for($user, $targetTier, $reviewer);
        // الصور القديمة غير المائية لا تُعرض في المركز؛ المصدر هو سجل KYC فقط.
        unset($decisionEvidence['legacy_images']);

        $docIds = array_column($decisionEvidence['documents'] ?? [], 'id');
        $mimes = KycDocument::query()->where('user_id', $user->id)
            ->whereIn('id', $docIds)->pluck('original_mime', 'id')->all();
        foreach ($decisionEvidence['documents'] as &$document) {
            $document['mime'] = $mimes[$document['id']] ?? null;
        }
        unset($document);

        $residenceState = $residence->forUser($user);
        $residenceReview = null;
        if (($residenceState['status'] ?? '') === ResidenceVerificationService::STATUS_PENDING
            && !empty($residenceState['verification_id'])) {
            $row = DB::table('residence_verifications')
                ->where('id', $residenceState['verification_id'])
                ->where('user_id', $user->id)->first();
            if ($row) {
                $residenceReview = [
                    'id' => (int) $row->id,
                    'document_id' => (int) ($row->kyc_document_id ?? 0),
                    'document_name' => (string) ($row->document_name ?? ''),
                    'name_review_note' => (string) ($row->name_review_note ?? ''),
                    'evidence_strength' => (string) $row->evidence_strength,
                    'evidence_type' => (string) $row->evidence_type,
                ];
            }
        }

        $canDecide = !$restricted
            || $reviewer->hasPlatformPermission('platform.customers.kyc.restricted.decide');
        $canReviewDocuments = $canDecide
            && $reviewer->hasPlatformPermission('platform.customers.freeze');
        $canActivate = $canDecide
            && $reviewer->hasPlatformPermission('platform.approvals.decide')
            && $decisionEvidence['complete']
            && ($decisionEvidence['blockers'] ?? []) === [];

        return response()->json([
            'success' => true,
            'data' => [
                'account' => [
                    'id' => (int) $user->id,
                    'name' => trim((string) ($user->f_name.' '.$user->l_name)) ?: '—',
                    'phone' => (string) ($user->phone ?? ''),
                    'type' => (int) $user->type,
                    'role' => match ((int) $user->type) {
                        MERCHANT_TYPE => 'تاجر',
                        AGENT_TYPE => 'وكيل',
                        default => 'عميل',
                    },
                    'tier' => (int) ($user->kyc_tier ?? 0),
                    'effective_tier' => $user->type === CUSTOMER_TYPE
                        ? $tiers->effectiveTier($user)
                        : (int) ($user->kyc_tier ?? 0),
                    'verified' => (int) $user->is_kyc_verified === 1,
                    'phone_verified' => (bool) ($user->is_phone_verified ?? false),
                    'governorate' => YemenGovernorates::codeFromName(
                        (string) ($user->residence_governorate ?? '')
                    ),
                    'registered_at' => $user->created_at?->format('Y-m-d H:i'),
                    'target_tier' => $targetTier,
                    'restricted' => $restricted,
                ],
                'evidence' => $decisionEvidence,
                'residence' => $residenceState,
                'residence_review' => $residenceReview,
                'privacy' => [
                    'review_mode' => $privacy->forUser($user)['review_mode'] ?? 'standard',
                ],
                'permissions' => [
                    'review_documents' => $canReviewDocuments,
                    'view_documents' => $reviewer->hasPlatformPermission('platform.customers.freeze'),
                    'view_biometric' => $reviewer->hasPlatformPermission(
                        'platform.customers.kyc.biometric.view'
                    ),
                    'review_residence' => $canReviewDocuments,
                    'decide_account' => $canDecide
                        && $reviewer->hasPlatformPermission('platform.approvals.decide'),
                    'activate_ready' => $canActivate,
                ],
            ],
        ]);
    }
}
