<?php

namespace App\Services\Muwathaq;

use App\Models\KycDocument;
use App\Models\MerchantVerificationRequest;
use App\Models\MuwathaqReviewRequest;
use App\Services\EncryptedFileStorage;
use DomainException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * P0 evidence handoff: after Muwathaq accepts a review case, the original
 * document is delivered over the signed server channel and encrypted there.
 *
 * Pay keeps no new review copy as the authority; it only retains its existing
 * record during the approved compatibility/retention window. A failed handoff
 * leaves the review request pending so the job retries the whole idempotent
 * transfer instead of opening a case with silently missing evidence.
 */
final class MuwathaqEvidenceHandoffService
{
    public function deliver(MuwathaqReviewRequest $request): void
    {
        foreach ($this->documents($request) as $document) {
            $this->send($request, $document);
        }
    }

    /** @return list<array{kind:string,id:int,body:string,mime:string}> */
    private function documents(MuwathaqReviewRequest $request): array
    {
        if ($request->kind === MuwathaqAccountReviewService::MERCHANT) {
            return $this->merchantDocuments($request);
        }

        $documents = KycDocument::query()
            ->where('user_id', $request->user_id)
            ->whereIn('id', array_map('intval', (array) $request->document_ids))
            ->get()
            ->keyBy('id');

        $result = [];
        foreach ((array) $request->payload['documents'] as $declared) {
            $id = (int) ($declared['id'] ?? 0);
            $document = $documents->get($id);
            if ($document === null || !$document->encrypted_path) {
                throw new DomainException('REVIEW_EVIDENCE_NOT_FOUND');
            }
            $result[] = [
                'kind' => (string) ($declared['kind'] ?? ''),
                'id' => $id,
                'body' => app(EncryptedFileStorage::class)->decryptToBinary($document->encrypted_path),
                'mime' => (string) ($document->original_mime ?: 'application/octet-stream'),
            ];
        }

        return $result;
    }

    /** @return list<array{kind:string,id:int,body:string,mime:string}> */
    private function merchantDocuments(MuwathaqReviewRequest $request): array
    {
        $verification = MerchantVerificationRequest::query()
            ->whereKey($request->subject_id)
            ->where('merchant_user_id', $request->user_id)
            ->first();

        if ($verification === null) {
            throw new DomainException('MERCHANT_REVIEW_NOT_FOUND');
        }

        $result = [];
        foreach ((array) $request->document_ids as $offset => $kind) {
            $column = \App\Http\Controllers\Admin\MerchantVerificationAdminController::DOCUMENTS[$kind][0] ?? null;
            $path = $column ? (string) $verification->{$column} : '';
            if ($path === '' || !Storage::disk('local')->exists($path)) {
                throw new DomainException('MERCHANT_REVIEW_EVIDENCE_NOT_FOUND');
            }
            $result[] = [
                'kind' => (string) $kind,
                'id' => (int) $offset + 1,
                'body' => Storage::disk('local')->get($path),
                'mime' => (string) (Storage::disk('local')->mimeType($path) ?: 'application/octet-stream'),
            ];
        }

        return $result;
    }

    /** @param array{kind:string,id:int,body:string,mime:string} $document */
    private function send(MuwathaqReviewRequest $request, array $document): void
    {
        $body = $document['body'];
        if ($body === '' || strlen($body) > 5 * 1024 * 1024) {
            throw new DomainException('REVIEW_EVIDENCE_SIZE_INVALID');
        }

        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, MuwathaqTransportSecrets::amialToMuwathaq());
        $response = Http::withBody($body, $document['mime'])
            ->withHeaders([
                'Accept' => 'application/json',
                'X-Amial-Timestamp' => $timestamp,
                'X-Amial-Signature' => $signature,
                'X-Amial-Evidence-Id' => (string) $document['id'],
                'X-Amial-Evidence-Sha256' => hash('sha256', $body),
                'Idempotency-Key' => $request->event_ulid.':'.$document['kind'],
            ])
            ->timeout((int) config('muwathaq.timeout_seconds', 10))
            ->post(rtrim((string) config('muwathaq.base_url'), '/').'/api/v1/internal/applications/'
                .rawurlencode($request->request_ulid).'/evidence/'.rawurlencode($document['kind']).'/document');

        if (!$response->successful()) {
            throw new DomainException('MUWATHAQ_EVIDENCE_HTTP_'.$response->status());
        }
    }
}
