<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MuwathaqAccountOpeningApplication;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * مرآة قراءة فقط داخل أميال باي.
 * الاعتماد أو الرفض لا يتم هنا؛ القرار داخل موثّق عبر قناته الموقّعة.
 */
class MuwathaqOpeningApprovalController extends Controller
{
    public function index(): View
    {
        return view('admin-views.amial.muwathaq.openings');
    }

    public function list(): JsonResponse
    {
        $rows = MuwathaqAccountOpeningApplication::query()
            ->with(['user:id,f_name,l_name,phone,email,type', 'evidences:id,application_id,kind,state,submitted_at'])
            ->whereIn('status', [
                MuwathaqAccountOpeningApplication::STATUS_SUBMITTED,
                MuwathaqAccountOpeningApplication::STATUS_UNDER_REVIEW,
                MuwathaqAccountOpeningApplication::STATUS_NEEDS_MORE_INFO,
            ])
            ->orderBy('submitted_at')
            ->get()
            ->map(fn (MuwathaqAccountOpeningApplication $application) => [
                'application_ulid' => $application->application_ulid,
                'subject_kind' => $application->subject_kind,
                'verification_path' => $application->verification_path,
                'requested_tier' => $application->requested_tier,
                'business_type' => $application->business_type,
                'status' => $application->status,
                'submitted_at' => $application->submitted_at?->toIso8601String(),
                'applicant' => [
                    'name' => trim(($application->user?->f_name ?? '').' '.($application->user?->l_name ?? '')),
                    'phone' => $application->user?->phone,
                    'email' => $application->user?->email,
                ],
                // لا يمر أي مستند أو رابط مستند إلى أميال باي؛ هذه حالة الدليل فقط.
                'evidence' => $application->evidences->map(fn ($evidence) => [
                    'kind' => $evidence->kind,
                    'state' => $evidence->state,
                    'submitted_at' => $evidence->submitted_at?->toIso8601String(),
                ])->values(),
            ]);

        return response()->json(['data' => $rows]);
    }

    /**
     * AMIAL-MUWATHAQ-REGISTRY-001 — سجلُّ كلِّ حسابات موثّق (عميل · تاجر · وكيل) بكلّ حالاتها.
     *
     * **كيف ترى إدارةُ المشروع عملاءَها:** هنا الحالةُ والمستوى والمحافظة والرصيد والمرجع وحالةُ الأدلة؛ والوثائقُ نفسُها
     * **لا تُنسخ إلى أميال باي** (فصلُ الملكية: موثّق يملك الدليل، وأميال باي يحمل الحالة والمرجع). فزرّ «الوثائق في موثّق»
     * يفتح القضيّةَ هناك، حيث يُفرض دورُ المشاهد ويُوسَم الدليل بعلامة فالكون ويُسجَّل من فتحه.
     */
    public function registry(): View
    {
        return view('admin-views.amial.muwathaq.registry');
    }

    public function registryList(Request $request): JsonResponse
    {
        $kind = in_array($request->query('kind'), ['customer', 'merchant', 'agent'], true) ? (string) $request->query('kind') : null;
        $status = in_array($request->query('status'), [
            MuwathaqAccountOpeningApplication::STATUS_SUBMITTED, MuwathaqAccountOpeningApplication::STATUS_UNDER_REVIEW,
            MuwathaqAccountOpeningApplication::STATUS_NEEDS_MORE_INFO, MuwathaqAccountOpeningApplication::STATUS_AWAITING_ADMIN_APPROVAL,
            MuwathaqAccountOpeningApplication::STATUS_APPROVED, MuwathaqAccountOpeningApplication::STATUS_REJECTED,
        ], true) ? (string) $request->query('status') : null;
        $tier = in_array((int) $request->query('tier'), [1, 2, 3], true) ? (int) $request->query('tier') : null;
        $search = trim((string) $request->query('q', ''));

        $query = MuwathaqAccountOpeningApplication::query()
            ->with(['user:id,f_name,l_name,phone,email,type,is_active,is_kyc_verified,kyc_tier,residence_governorate,zone_code', 'evidences:id,application_id,kind,state'])
            ->orderByDesc('submitted_at')->orderByDesc('id');

        if ($kind !== null) $query->where('subject_kind', $kind);
        if ($status !== null) $query->where('status', $status);
        if ($tier !== null) $query->where('requested_tier', $tier);
        if ($search !== '') {
            $digits = preg_replace('/\D+/', '', $search);
            $query->whereHas('user', function ($u) use ($search, $digits) {
                $u->where(function ($w) use ($search, $digits) {
                    $w->where('f_name', 'like', "%{$search}%")->orWhere('l_name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%");
                    if ($digits !== '') $w->orWhere('phone', 'like', '%'.substr($digits, -9).'%');
                });
            });
        }

        $page = $query->paginate(30);
        $balances = \App\Models\EMoney::query()->whereIn('user_id', $page->getCollection()->pluck('user_id')->filter()->all())
            ->pluck('current_balance', 'user_id');
        $muwathaqUrl = rtrim(trim((string) config('muwathaq.base_url')), '/');

        $rows = $page->getCollection()->map(function (MuwathaqAccountOpeningApplication $a) use ($balances, $muwathaqUrl) {
            $u = $a->user;
            $evidence = $a->evidences->groupBy('state')->map->count();

            return [
                'application_ulid' => $a->application_ulid,
                'subject_kind' => $a->subject_kind,
                'verification_path' => $a->verification_path,
                'business_type' => $a->business_type,
                'requested_tier' => $a->requested_tier,
                'status' => $a->status,
                'submitted_at' => $a->submitted_at?->toDateTimeString(),
                'decided_at' => ($a->muwathaq_decided_at ?? $a->decided_at)?->toDateTimeString(),
                'decision_reference' => $a->decision_reference,
                'user' => $u === null ? null : [
                    'id' => $u->id,
                    'name' => trim(($u->f_name ?? '').' '.($u->l_name ?? '')) ?: '—',
                    'phone' => $u->phone,
                    'email' => $u->email,
                    'active' => (bool) $u->is_active,
                    'kyc_verified' => (int) $u->is_kyc_verified === 1,
                    'tier' => (int) $u->kyc_tier,
                    'governorate' => \App\Support\YemenGovernorates::name($u->residence_governorate),
                    'zone' => $u->zone_code,
                    'balance' => (string) ($balances[$u->id] ?? '0'),
                    'account_url' => route('admin.amial.hub.account', $u->id),
                ],
                // حالةُ الأدلة فقط (وصل/مُراجَع/…): لا ملفَّ ولا رابطَ ملفٍّ يمرّ إلى أميال باي.
                'evidence_states' => $evidence,
                'muwathaq_url' => $muwathaqUrl === '' ? null : $muwathaqUrl.'/cases/by-application/'.$a->application_ulid,
            ];
        })->values();

        return response()->json(['data' => $rows, 'meta' => [
            'page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
        ]]);
    }
}
