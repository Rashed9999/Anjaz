<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RegistrationDossier;
use App\Services\Kyc\KycPrivacyService;
use App\Models\User;
use DomainException;
use App\Services\EncryptedFileStorage;
use App\Services\PiiAccessAuditService;
use App\Services\RegistrationDossierPdfService;
use App\Services\RegistrationDossierService;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class RegistrationDossierController extends Controller
{
    public function __construct(
        private readonly RegistrationDossierService $dossiers,
        private readonly RegistrationDossierPdfService $pdf,
        private readonly PiiAccessAuditService $pii,
    ) {}

    public function page() { return view('admin-views.amial.registration-dossiers.index'); }

    public function index(Request $request): JsonResponse
    {
        $viewer = $request->user();
        $privacy = app(KycPrivacyService::class);
        $canRestricted = $viewer->hasPlatformPermission('platform.customers.kyc.restricted.view');
        return response()->json(['success' => true, 'data' => RegistrationDossier::query()
            ->with('creator:id,f_name,l_name')->latest()->limit(100)->get()
            ->filter(fn (RegistrationDossier $d) => !$d->subject_user_id
                || $canRestricted || !$privacy->isRestricted((int) $d->subject_user_id))
            ->map(fn (RegistrationDossier $d) => $this->summary($d))->values()->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'أُغلق مسار الإنشاء المنفصل. افتح الملف من مركز العملاء أو مركز التجّار ليُنشأ الحساب والأرشيف معاً.',
        ], 410);
    }

    public function show(Request $request, string $reference): JsonResponse
    {
        $dossier = RegistrationDossier::where('reference', $reference)->firstOrFail();
        $this->assertDossierAccess($dossier, $request->user());
        $this->pii->logAccess($request->user()->id, 'registration_dossier', $dossier->id, 'registration_payload', 'view', 'فتح ملف تسجيل');
        return response()->json(['success' => true, 'data' => $this->summary($dossier) + ['payload' => $dossier->payload_encrypted]]);
    }

    public function pdf(Request $request, string $reference)
    {
        $dossier = RegistrationDossier::where('reference', $reference)->firstOrFail();
        $this->assertDossierAccess($dossier, $request->user(), true);
        $this->pii->logAccess($request->user()->id, 'registration_dossier', $dossier->id, 'registration_pdf', 'export', 'طباعة ملف تسجيل');
        // **والطولُ يُحسب من البايتات المُرسَلة نفسِها** — لا بنداءٍ ثانٍ
        // إلى المُصيِّر. فبلا `Content-Length` لا يميّز المتصفّحُ ملفّاً
        // اكتمل من ملفٍّ انقطع في منتصفه: يُحفَظ نصفُ ملفٍّ ويُفتح فيُرى
        // تالفاً بلا سببٍ ظاهر. ومعلَنٌ يفترق عن المُرسَل هو العطلُ نفسُه.
        $bytes = $this->pdf->render($dossier, $request->user());

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Length' => (string) strlen($bytes),
            'Content-Disposition' => 'inline; filename="amial-registration-'.$dossier->reference.'.pdf"',
        ]);
    }

    public function paper(Request $request, string $reference)
    {
        $dossier = RegistrationDossier::where('reference', $reference)->firstOrFail();
        $this->assertDossierAccess($dossier, $request->user());
        abort_unless($dossier->paper_form_encrypted_path, 404);
        $this->pii->logAccess($request->user()->id, 'registration_dossier', $dossier->id, 'signed_paper_form', 'view', 'فتح نموذج ورقي مؤرشف');
        return response(app(EncryptedFileStorage::class)->decryptToBinary($dossier->paper_form_encrypted_path), 200, ['Content-Type' => $dossier->paper_form_mime, 'Content-Disposition' => 'inline; filename="signed-registration-'.$dossier->reference.'"']);
    }


    /** أرشيف الهوية يحتوي بيانات حساسة؛ لا يُفتح من قائمة التسجيل وحدها. */
    private function assertDossierAccess(RegistrationDossier $dossier, User $viewer, bool $images = false): void
    {
        if (!$dossier->subject_user_id) return;
        $subject = User::find($dossier->subject_user_id);
        if (!$subject) return;
        try {
            app(KycPrivacyService::class)->assertReviewerAccess($subject, $viewer, false);
        } catch (DomainException) {
            abort(403, 'ملف العميل ضمن المراجعة المقيدة.');
        }
        if ($images && in_array($dossier->source, RegistrationDossier::VERIFICATION_SOURCES, true)) {
            abort_unless($viewer->hasPlatformPermission('platform.customers.freeze'), 403);
        }
    }

    private function summary(RegistrationDossier $d): array
    {
        return ['reference' => $d->reference, 'type' => $d->subject_type, 'source' => $d->source, 'state' => $d->state, 'has_paper_form' => (bool) $d->paper_form_encrypted_path, 'subject_user_id' => $d->subject_user_id, 'created_at' => optional($d->created_at)->toIso8601String(), 'creator' => trim((string) ($d->creator?->f_name.' '.$d->creator?->l_name)) ?: '—'];
    }
}
