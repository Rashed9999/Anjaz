<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Services\Kyc\KycPrivacyService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** AMIAL-KYC-PRIVACY-API-001 — الخصوصية اختيار صاحب الحساب، لا تخمين النظام. */
class KycPrivacyController extends Controller
{
    public function show(Request $request, KycPrivacyService $privacy): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $privacy->ensure($request->user()),
            'options' => $this->options($privacy),
        ]);
    }

    public function update(Request $request, KycPrivacyService $privacy): JsonResponse
    {
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
                        'التحقق الآلي لم يُربط بعد بمزوّد بيومتري معتمد. اختر المراجعة العادية أو الخصوصية الإضافية حالياً.',
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
                'description' => 'صورة الوجه لا تُعرض إلا لمراجع يملك صلاحية بيومترية مستقلة، مع تتبع جنائي لكل مشاهدة.',
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
