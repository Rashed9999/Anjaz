<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MuwathaqAccountOpeningApplication;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;

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
}
