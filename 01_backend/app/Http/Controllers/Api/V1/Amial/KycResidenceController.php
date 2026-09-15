<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Services\Kyc\ResidenceVerificationService;
use App\Services\KycDocumentService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** AMIAL-RESIDENCE-API-001 — صاحب الحساب يثبت مكان إقامته الحالي. */
class KycResidenceController extends Controller
{
    public function show(Request $request, ResidenceVerificationService $residence): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $residence->forUser($request->user()),
            'evidence_options' => $residence->evidenceOptions(),
        ]);
    }

    public function submit(
        Request $request,
        ResidenceVerificationService $residence,
        KycDocumentService $documents,
    ): JsonResponse {
        $data = $request->validate([
            'residence_governorate' => ['required', 'string', 'max:64'],
            'evidence_type' => ['required', 'string', Rule::in(array_keys(ResidenceVerificationService::EVIDENCE_TYPES))],
            'evidence_date' => ['nullable', 'date', 'before_or_equal:today'],
            'evidence' => [
                'required', 'file', 'max:8192',
                'mimetypes:image/jpeg,image/png,image/heic,image/heif,application/pdf',
            ],
        ]);

        try {
            $doc = $documents->upload(
                $request->user(),
                \App\Models\KycDocument::TYPE_ADDRESS_PROOF,
                $request->file('evidence'),
            );

            $state = $residence->submit(
                $request->user(),
                (string) $data['residence_governorate'],
                (string) $data['evidence_type'],
                $doc,
                $data['evidence_date'] ?? null,
            );
        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'code' => $e->getMessage(),
                'message' => match ($e->getMessage()) {
                    'RESIDENCE_GOVERNORATE_INVALID' => 'محافظة السكن غير معروفة.',
                    'RESIDENCE_EVIDENCE_TYPE_INVALID' => 'نوع دليل السكن غير مدعوم.',
                    default => 'تعذر إرسال إثبات السكن. راجع البيانات وحاول مرة أخرى.',
                },
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'تم إرسال إثبات السكن للمراجعة. أصل الهوية لا يؤثر على أهلية الإقامة.',
            'data' => $state,
        ], 201);
    }
}
