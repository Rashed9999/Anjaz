<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Models\KycDocument;
use App\Models\MuwathaqReviewRequest;
use App\Services\AuditService;
use App\Services\EncryptedFileStorage;
use Illuminate\Http\Response;

/** عرض وثيقة طلب ترقية لمراجع موثّق: موقّع، مقيّد بوثائق الطلب نفسه، بلا تخزين مؤقت، ومسجَّل. */
class MuwathaqTierUpgradeDocumentController extends Controller
{
    public function show(string $requestUlid, int $documentId): Response
    {
        $req = MuwathaqReviewRequest::query()->where('request_ulid', $requestUlid)->firstOrFail();
        if ($req->kind === \App\Services\Muwathaq\MuwathaqAccountReviewService::MERCHANT) {
            return $this->merchantFile($req, $documentId);
        }
        abort_unless(in_array($documentId, (array) $req->document_ids, true), 404);
        $doc = KycDocument::query()->where('user_id', $req->user_id)->whereKey($documentId)->firstOrFail();
        abort_unless($doc->encrypted_path, 404);

        app(AuditService::class)->record([
            'actor_type' => 'muwathaq_reviewer', 'actor_user_id' => null,
            'subject_type' => 'kyc_document', 'subject_id' => (string) $doc->id,
            'action' => 'MUWATHAQ_TIER_UPGRADE_DOCUMENT_VIEWED', 'decision_code' => 'MUWATHAQ_SIGNED_DOCUMENT_PROXY', 'severity' => 'info',
            'context' => ['request_ulid' => $requestUlid, 'doc_type' => $doc->doc_type,
                'reviewer_reference' => (string) request()->header('X-Muwathaq-Reviewer', 'unknown')],
        ]);

        return response(app(EncryptedFileStorage::class)->decryptToBinary($doc->encrypted_path), 200, [
            'Content-Type' => $doc->original_mime ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="muwathaq-doc-'.$doc->id.'"',
            'Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** وثيقة منشأة التاجر برقم موضعها في الطلب (1..n) — مقيّدة بما أُرسل فعلاً في الطلب. */
    private function merchantFile(MuwathaqReviewRequest $req, int $position): Response
    {
        $type = ((array) $req->document_ids)[$position - 1] ?? null;
        $column = \App\Http\Controllers\Admin\MerchantVerificationAdminController::DOCUMENTS[$type][0] ?? null;
        abort_unless($column !== null, 404);
        $mvr = \App\Models\MerchantVerificationRequest::query()->whereKey($req->subject_id)->where('merchant_user_id', $req->user_id)->firstOrFail();
        $path = (string) $mvr->{$column};
        abort_unless($path !== '' && \Illuminate\Support\Facades\Storage::disk('local')->exists($path), 404);
        app(AuditService::class)->record([
            'actor_type' => 'muwathaq_reviewer', 'actor_user_id' => null,
            'subject_type' => 'merchant_verification', 'subject_id' => (string) $mvr->id,
            'action' => 'MUWATHAQ_MERCHANT_DOCUMENT_VIEWED', 'decision_code' => 'MUWATHAQ_SIGNED_DOCUMENT_PROXY', 'severity' => 'info',
            'context' => ['request_ulid' => $req->request_ulid, 'doc_type' => $type,
                'reviewer_reference' => (string) request()->header('X-Muwathaq-Reviewer', 'unknown')],
        ]);
        $disk = \Illuminate\Support\Facades\Storage::disk('local');

        return response($disk->get($path), 200, [
            'Content-Type' => $disk->mimeType($path) ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="muwathaq-merchant-'.$mvr->id.'-'.$type.'"',
            'Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
