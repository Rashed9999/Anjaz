<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\MerchantProfile;
use App\Models\PosUser;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AMIAL-FIX-005 — Base Controller لكل Amial API controllers.
 *
 * يحلّ مشكلة Code Duplication:
 *   قبل: ok() و error() و resolveMerchant() مكرّرة في 33+ controller
 *   بعد: مرّة واحدة هنا، الكل يرثها
 *
 * الاستخدام:
 *   class BranchController extends AmialApiController { ... }
 */
abstract class AmialApiController extends Controller
{
    // ============ Response Helpers ============

    protected function ok(
        array $meta = [],
        string $code = 'OK',
        string $message = '',
        int $status = 200
    ): JsonResponse {
        return new JsonResponse([
            'success' => true,
            'code' => $code,
            'message' => $message,
            'errors' => (object)[],
            'meta' => $meta,
        ], $status);
    }

    protected function error(
        string $code,
        string $message,
        int $status = 400
    ): JsonResponse {
        return new JsonResponse([
            'success' => false,
            'code' => $code,
            'message' => $message,
            'errors' => (object)[],
            'meta' => (object)[],
        ], $status);
    }

    protected function validationError($validator): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'code' => 'VALIDATION',
            'message' => 'بيانات غير صالحة',
            'errors' => $validator->errors(),
            'meta' => (object)[],
        ], 422);
    }

    // ============ Auth Helpers ============

    /**
     * يُحلّ التاجر المالك من الـ request.
     * يدعم: مالك مباشر + POS user تابع لتاجر.
     *
     * @return User|JsonResponse — JsonResponse = فشل (أرسله مباشرة)
     */
    protected function resolveMerchant(Request $request): User|JsonResponse
    {
        $authUser = $request->user();
        if (!$authUser) {
            return $this->error('UNAUTHENTICATED', 'يجب تسجيل الدخول', 401);
        }

        // POS user → أرجع التاجر المالك
        $pos = PosUser::where('user_id', $authUser->id)
            ->where('is_active', true)->first();
        if ($pos) {
            $merchant = User::find($pos->merchant_user_id);
            if (!$merchant) {
                return $this->error('MERCHANT_NOT_FOUND', 'لم يُعثَر على التاجر', 404);
            }
            return $merchant;
        }

        // مالك مباشر
        if (!MerchantProfile::where('user_id', $authUser->id)->exists()) {
            return $this->error('NOT_A_MERCHANT', 'متاح للتجّار فقط', 403);
        }

        return $authUser;
    }

    /**
     * يتحقّق هل المستخدم أدمن.
     */
    protected function isAdmin(?User $user): bool
    {
        if (!$user) return false;
        // AMIAL-FIX(C1): الأدمن = النوع 0 (ADMIN_TYPE) أو دور admin/super_admin — وليس
        // النوع 1 (وهو الوكيل!). الخطأ السابق كان يمنح كل وكيل صلاحية أدمن.
        return (int) ($user->type ?? -1) === 0
            || in_array($user->role ?? null, ['admin', 'super_admin'], true);
    }
}
