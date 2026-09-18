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
            'residence_uzlah_id' => ['nullable', 'integer', 'min:1'],
            'residence_village_id' => ['nullable', 'integer', 'min:1'],
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

            $selection = $regions->resolveSelection(
                (string) $data['residence_governorate'],
                (int) $data['residence_district_id'],
                isset($data['residence_uzlah_id']) ? (int) $data['residence_uzlah_id'] : null,
                isset($data['residence_village_id']) ? (int) $data['residence_village_id'] : null,
            );

            $state = $residence->submit(
                $request->user(),
                (string) $data['birth_governorate'],
                $selection,
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
                    'UZLAH_NOT_IN_DISTRICT' => 'العزلة/المنطقة المختارة لا تتبع المديرية.',
                    'VILLAGE_NOT_IN_UZLAH' => 'القرية/الحي المختار لا يتبع العزلة.',
                    'VILLAGE_REQUIRES_UZLAH' => 'اختر العزلة/المنطقة قبل القرية/الحي.',
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
