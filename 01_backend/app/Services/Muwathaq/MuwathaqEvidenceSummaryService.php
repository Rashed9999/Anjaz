<?php

namespace App\Services\Muwathaq;

use App\Models\MuwathaqAccountOpeningApplication;
use App\Models\MuwathaqOpeningEvidence;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Read-only, server-to-server view of Muwathaq-owned evidence.
 *
 * Important: originals stay exclusively in Muwathaq. This method returns
 * only kinds, submission states and whether a document was received. It must
 * not return URLs, hashes, photos, ID numbers or encrypted file paths.
 */
final class MuwathaqEvidenceSummaryService
{
    private const KINDS = [
        MuwathaqOpeningEvidence::OWNER_IDENTITY => 'هوية مالك المنشأة',
        MuwathaqOpeningEvidence::BUSINESS_REGISTRATION => 'السجل أو الترخيص التجاري',
        MuwathaqOpeningEvidence::OPERATING_ADDRESS => 'إثبات الموقع التشغيلي',
    ];

    /** @return array{availability:string,items:array<int,array<string,mixed>>} */
    public function forMerchant(MuwathaqAccountOpeningApplication $application): array
    {
        if ($application->subject_kind !== 'merchant') {
            return ['availability' => 'not_applicable', 'items' => []];
        }

        if ($application->origin !== 'muwathaq') {
            $application->loadMissing('evidences');

            return [
                'availability' => 'local',
                'items' => $application->evidences
                    ->filter(fn (MuwathaqOpeningEvidence $e) => isset(self::KINDS[$e->kind]))
                    ->map(fn (MuwathaqOpeningEvidence $e) => [
                        'kind' => $e->kind,
                        'label' => self::KINDS[$e->kind],
                        'state' => $e->state,
                        'received' => $e->file_encrypted_path !== null,
                        'delivered_to_muwathaq' => $e->muwathaq_delivered_at !== null,
                        'submitted_at' => $e->submitted_at?->format('Y-m-d H:i'),
                    ])->values()->all(),
            ];
        }

        $baseUrl = trim((string) config('muwathaq.base_url'));
        if ($baseUrl === '' || MuwathaqTransportSecrets::amialToMuwathaq() === '') {
            return ['availability' => 'unavailable', 'items' => []];
        }

        try {
            $body = json_encode(
                ['application_ulid' => $application->application_ulid],
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
            $stamp = (string) time();
            $signature = hash_hmac(
                'sha256', $stamp.'.'.$body, MuwathaqTransportSecrets::amialToMuwathaq(),
            );
            $response = Http::acceptJson()
                ->withHeaders([
                    'X-Amial-Timestamp' => $stamp,
                    'X-Amial-Signature' => $signature,
                    'Content-Type' => 'application/json',
                ])
                ->timeout(min(5, max(1, (int) config('muwathaq.timeout_seconds', 10))))
                ->send('POST', rtrim($baseUrl, '/').'/api/v1/internal/evidence-summary', [
                    'body' => $body,
                ]);

            $data = $response->json('data');
            if (!$response->successful() || !is_array($data)
                || ($data['application_ulid'] ?? null) !== $application->application_ulid
                || ($data['origin'] ?? null) !== 'muwathaq'
                || !is_array($data['items'] ?? null)) {
                return ['availability' => 'unavailable', 'items' => []];
            }

            $items = [];
            foreach (array_slice($data['items'], 0, 12) as $row) {
                if (!is_array($row) || !isset(self::KINDS[$row['kind'] ?? ''])) {
                    continue;
                }
                $state = (string) ($row['state'] ?? '');
                $items[] = [
                    'kind' => $row['kind'],
                    'label' => self::KINDS[$row['kind']],
                    'state' => in_array($state, ['submitted', 'verified', 'rejected'], true)
                        ? $state : 'unknown',
                    'received' => ($row['document_received'] ?? false) === true,
                    // Locally unmirrored; the source here is Muwathaq itself.
                    'delivered_to_muwathaq' => true,
                    'submitted_at' => is_string($row['submitted_at'] ?? null)
                        ? mb_substr($row['submitted_at'], 0, 19) : null,
                ];
            }

            return ['availability' => 'muwathaq', 'items' => $items];
        } catch (Throwable $error) {
            report($error);
            // Unreachable is NOT an empty evidence record, and is NOT a
            // reason to change or revoke the approved merchant's account.
            return ['availability' => 'unavailable', 'items' => []];
        }
    }
}
