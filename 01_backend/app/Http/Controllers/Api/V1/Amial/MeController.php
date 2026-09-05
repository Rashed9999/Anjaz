<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\EMoney;
use App\Models\MerchantProfile;
use App\Models\PosUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AMIAL-ME-001 — بيانات المستخدم الحالي (profile + رقم الحساب + الرصيد + الأدوار).
 *
 *   GET /api/v1/amial/me                  (الكل: profile + balance + roles)
 *   GET /api/v1/amial/me/account-number   (رقم الحساب فقط — سريع)
 *
 * استخدام أساسي: شاشة "رقم حسابي" + Profile screen.
 */
class MeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        if (empty($user->account_number)) {
            app(\App\Services\AccountNumberService::class)->assign($user);
        }

        $emoney = EMoney::where('user_id', $user->id)->first();
        $merchant = MerchantProfile::where('user_id', $user->id)->first();
        $pos = PosUser::where('user_id', $user->id)->where('is_active', true)->first();

        // الأدوار النشطة (يمكن أن يكون الواحد كل شيء)
        $roles = [
            'is_customer' => $user->type == 2,
            'is_merchant' => $merchant !== null,
            'is_pos_user' => $pos !== null,
            'is_agent' => $user->type == 4 || $user->is_agent ?? false,
        ];

        // AMIAL-VERIFIED-BADGE-001 — معلومات التوثيق
        $verification = [
            'tier' => null,        // null | verified | premium | gold
            'status' => null,      // pending | verified | rejected
            'is_verified' => false,
            'update_required' => false,
        ];
        if ((int) $user->type === CUSTOMER_TYPE) {
            $verification['status'] = match ((int) ($user->is_kyc_verified ?? 0)) {
                1 => 'verified',
                2 => 'rejected',
                default => 'pending',
            };
            $verification['is_verified'] = (int) ($user->is_kyc_verified ?? 0) === 1;
            $verification['tier'] = (string) ((int) ($user->kyc_tier ?? 0));
            $verification['update_required'] = \Illuminate\Support\Facades\Schema::hasColumn('users', 'kyc_update_required')
                && (int) ($user->kyc_update_required ?? 0) === 1;
        } elseif ($merchant) {
            $verification['status'] = $merchant->verification_status;
            $verification['is_verified'] = $merchant->verification_status === 'verified';
            if ($verification['is_verified']) {
                // ترقّي: gold للـ tier=gold، premium للـ premium، verified للباقي
                $verification['tier'] = match ($merchant->tier ?? 'standard') {
                    'gold' => 'gold',
                    'premium' => 'premium',
                    default => 'verified',
                };
            }
        }

        return $this->ok([
            'id' => $user->id,
            'phone' => $user->phone,
            'name' => trim(($user->f_name ?? '') . ' ' . ($user->l_name ?? '')) ?: $user->phone,
            'account_number' => $user->account_number,
            'zone_code' => $user->zone_code ?? 'SOUTH',
            'roles' => $roles,
            'verification' => $verification,
            'balance' => $emoney?->current_balance ?? '0.0000',
            'held_balance' => $emoney?->held_balance ?? '0.0000',
            'created_at' => $user->created_at?->toIso8601String(),
        ]);
    }

    /** نسخة سريعة لشاشة "رقم حسابي" — لا تحتاج كل البيانات. */
    public function accountNumber(Request $request): JsonResponse
    {
        $user = $request->user();
        if (empty($user->account_number)) {
            app(\App\Services\AccountNumberService::class)->assign($user);
        }
        return $this->ok([
            'account_number' => $user->account_number,
            'name' => trim(($user->f_name ?? '') . ' ' . ($user->l_name ?? '')) ?: $user->phone,
        ]);
    }

    private function ok(array $meta, string $code = 'OK', string $message = 'OK'): JsonResponse
    {
        return new JsonResponse([
            'success' => true, 'code' => $code, 'message' => $message,
            'errors' => (object)[], 'meta' => $meta,
        ]);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'success' => false, 'code' => $code, 'message' => $message,
            'errors' => (object)[], 'meta' => (object)[],
        ], $status);
    }
}
