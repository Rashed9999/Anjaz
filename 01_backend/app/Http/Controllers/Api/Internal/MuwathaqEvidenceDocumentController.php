<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Models\MuwathaqAccountOpeningApplication;
use App\Models\MuwathaqOpeningEvidence;
use App\Services\AuditService;
use App\Services\EncryptedFileStorage;
use Illuminate\Http\Response;

/**
 * قناة خادم-إلى-خادم لعرض دليل مشفر لمراجع موثّق.
 *
 * لا يوجد رابط عام، ولا يتلقى التطبيق ملفاً من أميال مباشرة. الطلب موقّع
 * من موثّق، ومقيد بالقضية والدليل، ويصدر مع no-store لمنع بقاء الوثيقة
 * في proxy أو cache المتصفح.
 */
class MuwathaqEvidenceDocumentController extends Controller
{
    public function show(string $applicationUlid, int $evidenceId): Response
    {
        $evidence = MuwathaqOpeningEvidence::query()
            ->whereKey($evidenceId)
            ->whereHas('application', fn ($query) => $query->where('application_ulid', $applicationUlid))
            ->firstOrFail();

        abort_unless($evidence->file_encrypted_path !== null, 404);

        app(AuditService::class)->record([
            'actor_type' => 'muwathaq_reviewer',
            'actor_user_id' => null,
            'subject_type' => 'muwathaq_opening_evidence',
            'subject_id' => (string) $evidence->id,
            'action' => 'MUWATHAQ_EVIDENCE_VIEWED',
            'decision_code' => 'MUWATHAQ_SIGNED_DOCUMENT_PROXY',
            'severity' => 'info',
            'context' => [
                'application_ulid' => $applicationUlid,
                'evidence_kind' => $evidence->kind,
                'reviewer_reference' => (string) request()->header('X-Muwathaq-Reviewer', 'unknown'),
            ],
        ]);

        return response(
            app(EncryptedFileStorage::class)->decryptToBinary($evidence->file_encrypted_path),
            200,
            [
                'Content-Type' => $evidence->file_mime ?: 'application/octet-stream',
                'Content-Disposition' => 'inline; filename="muwathaq-evidence-'.$evidence->id.'"',
                'Cache-Control' => 'private, no-store, max-age=0',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
