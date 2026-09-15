<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Services\Kyc\KycOwnershipGuardService;
use App\Support\Kyc\KycProfileFields;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/** ما ينقص صفحة «إكمال حسابي» — أكواد، لا بيانات حساسة لموظفين أو أطراف أخرى. */
class KycCompletionController extends Controller
{
    public function show(Request $request, KycOwnershipGuardService $ownership): JsonResponse
    {
        $user = $request->user();
        if (!$user || (int) $user->type !== 2) {
            return response()->json([
                'success' => false,
                'code' => 'INDIVIDUAL_CUSTOMER_REQUIRED',
                'message' => 'إكمال توثيق الأفراد متاح لحساب العميل فقط.',
            ], 403);
        }

        $ownershipState = $ownership->assess($user, 3);

        return response()->json([
            'success' => true,
            'code' => 'KYC_COMPLETION_STATUS_OK',
            'data' => [
                'tier3_profile_missing_codes' => $this->missingCodes($user),
                'tier3_profile_missing_labels' => KycProfileFields::missingFor($user),
                'tier3_ownership' => [
                    'ready' => (bool) ($ownershipState['ready'] ?? false),
                    'method' => (string) ($ownershipState['method'] ?? 'unknown'),
                    'blockers' => array_values($ownershipState['blockers'] ?? []),
                ],
                'profile_options' => [
                    'income_sources' => [
                        ['code' => 'salary', 'label' => 'راتب'],
                        ['code' => 'business', 'label' => 'تجارة أو نشاط خاص'],
                        ['code' => 'investment', 'label' => 'استثمار'],
                        ['code' => 'rent', 'label' => 'إيجارات'],
                        ['code' => 'asset_sale', 'label' => 'بيع أصول'],
                        ['code' => 'inheritance', 'label' => 'ميراث'],
                        ['code' => 'remittance', 'label' => 'حوالات'],
                        ['code' => 'other', 'label' => 'أخرى'],
                    ],
                    'account_purposes' => [
                        ['code' => 'savings', 'label' => 'ادخار'],
                        ['code' => 'salary', 'label' => 'استلام راتب'],
                        ['code' => 'business', 'label' => 'أعمال'],
                        ['code' => 'remittance', 'label' => 'حوالات'],
                        ['code' => 'payments', 'label' => 'مدفوعات'],
                        ['code' => 'other', 'label' => 'أخرى'],
                    ],
                ],
            ],
        ]);
    }

    /** @return list<string> */
    private function missingCodes($user): array
    {
        $required = [
            'name_en',
            'father_name',
            'grandfather_name',
            'residence_district',
            'income_source',
            'account_purpose',
        ];

        $missing = [];
        foreach ($required as $field) {
            if (Schema::hasColumn('users', $field)
                && trim((string) ($user->{$field} ?? '')) === '') {
                $missing[] = $field;
            }
        }

        if (Schema::hasColumn('users', 'is_pep') && $user->is_pep === null) {
            $missing[] = 'is_pep';
        }
        if (Schema::hasColumn('users', 'is_pep') && (bool) $user->is_pep
            && Schema::hasColumn('users', 'pep_position')
            && trim((string) ($user->pep_position ?? '')) === '') {
            $missing[] = 'pep_position';
        }

        return $missing;
    }
}
