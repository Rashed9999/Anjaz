<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Verticals\VerticalRegistry;
use App\Http\Controllers\Controller;
use App\Services\Muwathaq\MuwathaqRegistrationContractService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/** واجهة قراءة عامة؛ السر واتصال موثّق يبقيان في الخادم فقط. */
class AccountOpeningContractController extends Controller
{
    public function __construct(private readonly MuwathaqRegistrationContractService $contracts) {}

    public function show(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'account_type' => ['required', 'in:customer,merchant'],
            'gender' => ['nullable', 'in:male,female'],
            'business_type' => ['nullable', 'string', 'max:64'],
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $type = (string) $request->query('account_type');
        $gender = $request->query('gender');
        $businessType = $request->query('business_type');

        if ($type === 'customer' && !in_array($gender, ['male', 'female'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'اختر الجنس أولاً ليعرض النظام سياسة فتح الحساب المناسبة.',
            ], 422);
        }

        if ($type === 'merchant') {
            $codes = array_keys(VerticalRegistry::current());
            if (!in_array($businessType, $codes, true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'اختر قطاع منشأة صالحاً أولاً.',
                ], 422);
            }
        }

        try {
            return response()->json([
                'success' => true,
                'data' => $this->contracts->resolve($type, $gender, $businessType),
            ]);
        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'code' => 'MUWATHAQ_POLICY_UNAVAILABLE',
                'message' => $e->getMessage(),
            ], 503);
        }
    }
}
