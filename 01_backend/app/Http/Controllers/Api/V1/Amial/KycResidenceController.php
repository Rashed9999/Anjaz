<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Services\Kyc\ResidenceVerificationService;
use App\Services\Geo\YemenRegionsService;
use App\Services\KycDocumentService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * AMIAL-RESIDENCE-API-001 — العميل الفرد يثبت مكان إقامته الحالي.
 * AMIAL-CUSTOMER-KYC-SCOPE-001 — لا يُستخدم هذا المسار لتوثيق التاجر أو
 * الوكيل أو موظفي POS/الإدارة؛ لكل فئة ملف امتثال وتشغيل مستقل.
 */
class KycResidenceController extends Controller
{
    public function show(Request $request, ResidenceVerificationService $residence): JsonResponse
    {
        if ($denied = $this->customerOnly($request)) return $denied;

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
        YemenRegionsService $regions,
    ): JsonResponse {
        if ($denied = $this->customerOnly($request)) return $denied;

        $data = $request->validate([
            'birth_governorate' => ['required', 'string', 'max:64'],
            'residence_governorate' => ['required', 'string', 'max:64'],
            'residence_district_id' => ['required', 'integer', 'min:1'],
            'residence_area' => ['required', 'string', 'min:2', 'max:120'],
            'residence_landmark' => ['nullable', 'string', 'max:150'],
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

            $selection = $regions->resolveDistrict(
                (string) $data['residence_governorate'],
                (int) $data['residence_district_id'],
            );

            $state = $residence->submit(
                $request->user(),
                (string) $data['birth_governorate'],
                $selection,
                (string) $data['residence_area'],
                $data['residence_landmark'] ?? null,
                (string) $data['evidence_type'],
                $doc,
                $data['evidence_date'] ?? null,
            );
        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'code' => $e->getMessage(),
                'message' => match ($e->getMessage()) {
                    'BIRTH_GOVERNORATE_INVALID' => 'محافظة الميلاد غير معروفة.',
                    'RESIDENCE_GOVERNORATE_INVALID' => 'محافظة السكن غير معروفة.',
                    'RESIDENCE_EVIDENCE_TYPE_INVALID' => 'نوع دليل السكن غير مدعوم.',
                    'DISTRICT_NOT_IN_GOVERNORATE' => 'المديرية المختارة لا تتبع محافظة السكن.',
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

    private function customerOnly(Request $request): ?JsonResponse
    {
        $user = $request->user();
        if ($user && (int) $user->type === 2) return null;

        return response()->json([
            'success' => false,
            'code' => 'INDIVIDUAL_CUSTOMER_REQUIRED',
            'message' => 'مستويات توثيق الأفراد متاحة لحساب العميل فقط.',
        ], 403);
    }
}
