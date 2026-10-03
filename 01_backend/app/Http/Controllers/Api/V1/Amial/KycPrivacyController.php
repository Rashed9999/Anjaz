<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Services\Kyc\Biometric\BiometricVerificationService;
use App\Services\Kyc\KycPrivacyService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * AMIAL-KYC-PRIVACY-API-001 — الخصوصية اختيار صاحب الحساب، لا تخمين النظام.
 *
 * AMIAL-CUSTOMER-KYC-SCOPE-001 — هذا المسار خاص بالعميل الفرد فقط، رجلاً
 * أو امرأة. توثيق التاجر والوكيل وموظفي POS وموظفي الإدارة له نماذج أعمال
 * وصلاحيات مستقلة ولا يجوز خلطها بمستويات توثيق العميل الشخصي.
 */
class KycPrivacyController extends Controller
{
    public function show(Request $request, KycPrivacyService $privacy): JsonResponse
    {
        if ($denied = $this->customerOnly($request)) return $denied;

        return response()->json([
            'success' => true,
            'data' => $privacy->ensure($request->user()),
            'options' => $this->options($privacy),
        ]);
    }

    public function update(Request $request, KycPrivacyService $privacy): JsonResponse
    {
        if ($denied = $this->customerOnly($request)) return $denied;

        $data = $request->validate([
            'review_mode' => ['required', 'string', Rule::in(KycPrivacyService::MODES)],
        ]);

        try {
            $state = $privacy->choose($request->user(), (string) $data['review_mode']);
        } catch (DomainException $e) {
            $code = $e->getMessage();
            return response()->json([
                'success' => false,
                'code' => $code,
                'message' => match ($code) {
                    'KYC_BIOMETRIC_PROVIDER_NOT_CONFIGURED' =>
                        'التحقق الآلي غير متاح حتى يكتمل ربط مزوّد بيومتري حقيقي ومفاتيح التوقيع الخاصة به.',
                    'KYC_PRIVACY_SCHEMA_UNAVAILABLE' =>
                        'خدمة خصوصية التحقق غير متاحة على هذا الخادم حتى اكتمال الترحيل.',
                    default => 'طريقة التحقق المطلوبة غير متاحة.',
                },
            ], 409);
        }

        return response()->json([
            'success' => true,
            'message' => 'تم حفظ طريقة التحقق والخصوصية.',
            'data' => $state,
            'options' => $this->options($privacy),
        ]);
    }

    /** يبدأ جلسة مزود حقيقي فقط بعد أن يختار العميل المسار automated. */
    public function startBiometric(Request $request, BiometricVerificationService $biometrics): JsonResponse
    {
        if ($denied = $this->customerOnly($request)) return $denied;

        try {
            $data = $biometrics->start($request->user());
        } catch (DomainException $e) {
            $code = $e->getMessage();

            return response()->json([
                'success' => false,
                'code' => $code,
                'message' => match ($code) {
                    'KYC_BIOMETRIC_MODE_REQUIRED' => 'اختر «تحقق آلي خاص» أولاً قبل بدء الجلسة البيومترية.',
                    'KYC_BIOMETRIC_ATTEMPT_ALREADY_ACTIVE' => 'توجد محاولة تحقق بيومتري نشطة بالفعل. أكملها أو أعد المحاولة بعد انتهاء مهلة الحماية.',
                    'KYC_BIOMETRIC_PROVIDER_DISABLED',
                    'KYC_BIOMETRIC_PROVIDER_NOT_CONFIGURED',
                    'KYC_BIOMETRIC_PROVIDER_UNKNOWN',
                    'KYC_BIOMETRIC_PROVIDER_UNAVAILABLE' => 'مزوّد التحقق البيومتري غير متاح حالياً.',
                    'KYC_BIOMETRIC_SCHEMA_UNAVAILABLE' => 'خدمة التحقق البيومتري لم يكتمل ترحيلها على هذا الخادم.',
                    default => 'تعذر بدء جلسة التحقق البيومتري حالياً.',
                },
            ], in_array($code, ['KYC_BIOMETRIC_ATTEMPT_ALREADY_ACTIVE'], true) ? 409 : 503);
        }

        return response()->json([
            'success' => true,
            'code' => 'KYC_BIOMETRIC_SESSION_STARTED',
            'message' => 'تم إنشاء جلسة التحقق لدى المزود المعتمد.',
            'data' => $data,
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

    private function options(KycPrivacyService $privacy): array
    {
        return [
            [
                'code' => KycPrivacyService::MODE_STANDARD,
                'label' => 'مراجعة عادية',
                'description' => 'مستنداتك لا يراها إلا فريق التحقق المخول، وكل مشاهدة مائية ومسجلة.',
                'available' => true,
            ],
            [
                'code' => KycPrivacyService::MODE_RESTRICTED,
                'label' => 'خصوصية إضافية',
                'description' => 'المستندات الحساسة لا تُعرض إلا لمراجع يملك صلاحية مستقلة، مع تتبع جنائي لكل مشاهدة.',
                'available' => true,
            ],
            [
                'code' => KycPrivacyService::MODE_AUTOMATED,
                'label' => 'تحقق آلي خاص',
                'description' => 'Liveness ومطابقة وجه آلية؛ لا تُفعّل إلا بعد ربط مزود حقيقي معتمد.',
                'available' => $privacy->biometricConfigured(),
            ],
            [
                'code' => KycPrivacyService::MODE_IN_PERSON,
                'label' => 'تحقق حضوري',
                'description' => 'طلب مراجعة حضورية مقيدة؛ الاعتماد النهائي يخضع للسياسة الرقابية المعتمدة.',
                'available' => true,
            ],
        ];
    }
}
