<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MuwathaqAccountOpeningApplication;
use App\Services\Muwathaq\MuwathaqAccountOpeningService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * البوابة النهائية بعد قرار موثّق: هنا فقط يبدأ التفعيل وإنشاء المحفظة.
 * لا يصل هذا المتحكم إلى صور الهوية؛ مراجعتها محصورة داخل موثّق.
 */
class MuwathaqOpeningApprovalController extends Controller
{
    public function __construct(private readonly MuwathaqAccountOpeningService $applications) {}

    public function index(): View
    {
        return view('admin-views.amial.muwathaq.openings');
    }

    public function list(): JsonResponse
    {
        $rows = MuwathaqAccountOpeningApplication::query()
            ->with(['user:id,f_name,l_name,phone,email,type', 'evidences:id,application_id,kind,state,file_encrypted_path,submitted_at'])
            ->where('status', MuwathaqAccountOpeningApplication::STATUS_AWAITING_ADMIN_APPROVAL)
            ->orderBy('decided_at')
            ->get()
            ->map(fn (MuwathaqAccountOpeningApplication $application) => [
                'application_ulid' => $application->application_ulid,
                'subject_kind' => $application->subject_kind,
                'verification_path' => $application->verification_path,
                'requested_tier' => $application->requested_tier,
                'business_type' => $application->business_type,
                'muwathaq_reference' => $application->decision_reference,
                'muwathaq_reason' => $application->decision_reason,
                'muwathaq_decided_at' => $application->decided_at?->toIso8601String(),
                'applicant' => [
                    'name' => trim(($application->user?->f_name ?? '').' '.($application->user?->l_name ?? '')),
                    'phone' => $application->user?->phone,
                    'email' => $application->user?->email,
                ],
                'evidence' => $application->evidences->map(fn ($evidence) => [
                    'kind' => $evidence->kind,
                    'state' => $evidence->state,
                    'document_received' => $evidence->file_encrypted_path !== null,
                    'submitted_at' => $evidence->submitted_at?->toIso8601String(),
                ])->values(),
            ]);

        return response()->json(['data' => $rows]);
    }

    public function approve(Request $request, string $applicationUlid): RedirectResponse
    {
        try {
            $this->applications->receiveAdminDecision(
                $applicationUlid,
                true,
                (int) auth('admin')->id(),
                trim((string) $request->input('reason')) ?: null,
            );
            return back()->with('success', 'اعتمدت الإدارة الطلب؛ فُعّل الحساب وأنشئت المحفظة عند الحاجة.');
        } catch (DomainException $exception) {
            return back()->withErrors(['muwathaq' => $exception->getMessage()]);
        }
    }

    public function reject(Request $request, string $applicationUlid): RedirectResponse
    {
        $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);

        try {
            $this->applications->receiveAdminDecision(
                $applicationUlid,
                false,
                (int) auth('admin')->id(),
                trim((string) $request->input('reason')),
            );
            return back()->with('success', 'رفضت الإدارة التفعيل؛ لم تُنشأ محفظة.');
        } catch (DomainException $exception) {
            return back()->withErrors(['muwathaq' => $exception->getMessage()]);
        }
    }
}
