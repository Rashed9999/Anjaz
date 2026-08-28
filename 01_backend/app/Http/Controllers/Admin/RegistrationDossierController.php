<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RegistrationDossier;
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

    public function index(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => RegistrationDossier::query()
            ->with('creator:id,f_name,l_name')->latest()->limit(100)->get()
            ->map(fn (RegistrationDossier $d) => $this->summary($d))->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'subject_type' => 'required|in:customer,merchant',
            'source' => 'required|in:staff_assisted,paper_archive',
            'dial_country_code' => 'required|string|max:8', 'phone' => 'required|string|min:5|max:20',
            'full_name' => 'required|string|max:200', 'gender' => 'nullable|in:male,female,other',
            'identification_number' => 'nullable|string|max:50', 'identification_type' => 'nullable|in:passport,driving_licence,nid,trade_license',
            'address' => 'nullable|string|max:500', 'business_name' => 'nullable|string|max:255',
            'business_type' => 'nullable|string|max:80', 'paper_form' => 'nullable|file|max:8192|mimes:pdf,jpg,jpeg,png',
        ]);
        $v->after(function ($v) use ($request) {
            if ($request->input('subject_type') === 'merchant' && trim((string) $request->input('business_name')) === '') $v->errors()->add('business_name', 'اسم المنشأة مطلوب للتاجر');
            if ($request->input('source') === 'paper_archive' && !$request->hasFile('paper_form')) $v->errors()->add('paper_form', 'ارفع نسخة النموذج الورقي الموقّع');
        });
        if ($v->fails()) return response()->json(['success' => false, 'message' => 'تحقق من الحقول', 'errors' => $v->errors()], 422);

        $phone = Phone::canonical($request->input('dial_country_code') . $request->input('phone'));
        $payload = $request->only([
            'full_name', 'gender', 'dial_country_code', 'phone', 'identification_number',
            'identification_type', 'address', 'business_name', 'business_type',
        ]);
        try {
            $dossier = $this->dossiers->create($request->user(), (string) $request->input('subject_type'), (string) $request->input('source'), $phone, $payload, $request->file('paper_form'));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
        return response()->json(['success' => true, 'message' => 'حُفظ ملف التسجيل. على العميل تأكيد الرقم عبر OTP قبل فتح الحساب.', 'data' => $this->summary($dossier)]);
    }

    public function show(Request $request, string $reference): JsonResponse
    {
        $dossier = RegistrationDossier::where('reference', $reference)->firstOrFail();
        $this->pii->logAccess($request->user()->id, 'registration_dossier', $dossier->id, 'registration_payload', 'view', 'فتح ملف تسجيل');
        return response()->json(['success' => true, 'data' => $this->summary($dossier) + ['payload' => $dossier->payload_encrypted]]);
    }

    public function pdf(Request $request, string $reference)
    {
        $dossier = RegistrationDossier::where('reference', $reference)->firstOrFail();
        $this->pii->logAccess($request->user()->id, 'registration_dossier', $dossier->id, 'registration_pdf', 'export', 'طباعة ملف تسجيل');
        return response($this->pdf->render($dossier), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="amial-registration-'.$dossier->reference.'.pdf"']);
    }

    public function paper(Request $request, string $reference)
    {
        $dossier = RegistrationDossier::where('reference', $reference)->firstOrFail();
        abort_unless($dossier->paper_form_encrypted_path, 404);
        $this->pii->logAccess($request->user()->id, 'registration_dossier', $dossier->id, 'signed_paper_form', 'view', 'فتح نموذج ورقي مؤرشف');
        return response(app(EncryptedFileStorage::class)->decryptToBinary($dossier->paper_form_encrypted_path), 200, ['Content-Type' => $dossier->paper_form_mime, 'Content-Disposition' => 'inline; filename="signed-registration-'.$dossier->reference.'"']);
    }

    private function summary(RegistrationDossier $d): array
    {
        return ['reference' => $d->reference, 'type' => $d->subject_type, 'source' => $d->source, 'state' => $d->state, 'has_paper_form' => (bool) $d->paper_form_encrypted_path, 'subject_user_id' => $d->subject_user_id, 'created_at' => optional($d->created_at)->toIso8601String(), 'creator' => trim((string) ($d->creator?->f_name.' '.$d->creator?->l_name)) ?: '—'];
    }
}
