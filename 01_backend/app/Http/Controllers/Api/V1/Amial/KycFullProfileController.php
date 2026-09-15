<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Support\Kyc\KycProfileFields;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * AMIAL-KYC-COMPLETE-ACCOUNT-001 — إكمال بيانات Tier 3 للعميل الفرد.
 *
 * لا يُعاد تسجيل الحساب ولا تُطلب الحقول المكتملة من جديد. Flutter يقرأ
 * missing_fields من مركز التحقق، وهذا الباب يقبل فقط حقول KYC المعروفة.
 */
class KycFullProfileController extends Controller
{
    public function update(Request $request, AuditService $audit): JsonResponse
    {
        $user = $request->user();
        if (!$user || (int) $user->type !== 2) {
            return response()->json([
                'success' => false,
                'code' => 'INDIVIDUAL_CUSTOMER_REQUIRED',
                'message' => 'إكمال ملف توثيق الأفراد متاح لحساب العميل فقط.',
            ], 403);
        }

        if ((int) ($user->kyc_tier ?? 0) < 2 || (int) ($user->is_kyc_verified ?? 0) !== 1) {
            return response()->json([
                'success' => false,
                'code' => 'TIER2_REQUIRED',
                'message' => 'أكمل توثيق الهوية أولاً قبل ملف التوثيق الكامل.',
            ], 409);
        }

        $data = $request->validate([
            'name_en' => ['sometimes', 'string', 'min:2', 'max:180'],
            'father_name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'grandfather_name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'country_of_birth' => ['sometimes', 'nullable', 'string', 'max:100'],
            'dual_nationality' => ['sometimes', 'nullable', 'string', 'max:100'],
            'id_place_of_issue' => ['sometimes', 'nullable', 'string', 'max:180'],
            'marital_status' => ['sometimes', 'nullable', 'string', 'max:50'],
            'residence_district' => ['sometimes', 'string', 'min:2', 'max:180'],
            'residence_area' => ['sometimes', 'nullable', 'string', 'max:180'],
            'residence_landmark' => ['sometimes', 'nullable', 'string', 'max:255'],
            'housing_type' => ['sometimes', 'nullable', 'string', 'max:80'],
            'employer_name' => ['sometimes', 'nullable', 'string', 'max:180'],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'work_address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'income_source' => ['sometimes', 'string', Rule::in(KycProfileFields::INCOME_SOURCES)],
            'account_purpose' => ['sometimes', 'string', Rule::in(KycProfileFields::ACCOUNT_PURPOSES)],
            'monthly_income' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'monthly_income_currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'is_pep' => ['sometimes', 'boolean'],
            'pep_position' => ['sometimes', 'nullable', 'string', 'max:180'],
            'kin2_name' => ['sometimes', 'nullable', 'string', 'max:180'],
            'kin2_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'kin2_relation' => ['sometimes', 'nullable', 'string', 'max:80'],
        ]);

        if ($data === []) {
            return response()->json([
                'success' => false,
                'code' => 'NO_KYC_FIELDS',
                'message' => 'لم تُرسل بيانات لإكمالها.',
            ], 422);
        }

        KycProfileFields::fill($user, $request);
        $user->save();
        $missing = KycProfileFields::missingFor($user->fresh());

        $audit->record([
            'actor_type' => 'customer',
            'actor_user_id' => (int) $user->id,
            'subject_type' => 'user',
            'subject_id' => (string) $user->id,
            'action' => 'KYC_FULL_PROFILE_UPDATED',
            'decision_code' => $missing === [] ? 'KYC_PROFILE_COMPLETE' : 'KYC_PROFILE_PARTIAL',
            'severity' => 'info',
            'context' => [
                'fields' => array_values(array_keys($data)),
                'remaining_count' => count($missing),
            ],
        ]);

        return response()->json([
            'success' => true,
            'code' => $missing === [] ? 'KYC_PROFILE_COMPLETE' : 'KYC_PROFILE_PARTIAL',
            'message' => $missing === []
                ? 'اكتملت بيانات ملف التوثيق.'
                : 'تم حفظ البيانات. بقيت حقول مطلوبة قبل التوثيق الكامل.',
            'data' => [
                'complete' => $missing === [],
                'missing_fields' => $missing,
            ],
        ]);
    }
}
