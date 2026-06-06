<?php

namespace App\Http\Middleware;

use App\Models\PosUser;
use App\Services\BranchResolverService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * P1-RBAC — يفحص صلاحية POS user.
 *
 * مختلف عن RequirePermission (الذي للأدمن المركزي):
 *   - هذا للـ POS users + التجار.
 *   - مالك المنشأة دائماً مسموح.
 *   - POS user يخضع لـ roles + branch_scope.
 *
 * استخدام:
 *   Route::post('/products', ...)->middleware('amial.pos-permission:products.create');
 */
class PosPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();
        if (!$user) {
            return $this->forbidden('UNAUTHENTICATED', 'يجب تسجيل الدخول', 401);
        }

        // 1) المالك (التاجر مباشرة) — مسموح بكل شيء
        $isOwner = \App\Models\MerchantProfile::where('user_id', $user->id)->exists();
        if ($isOwner) return $next($request);

        // 2) POS User
        $pos = PosUser::where('user_id', $user->id)->where('is_active', true)->first();
        if (!$pos) {
            return $this->forbidden('NO_ACCESS', 'الوصول غير مسموح');
        }

        // فرع الـ scope
        $branchId = null;
        try {
            $merchant = \App\Models\User::find($pos->merchant_user_id);
            if ($merchant) {
                $branchId = app(BranchResolverService::class)
                    ->resolveBranchId($request, $merchant, $user);
            }
        } catch (\Throwable) {}

        if (!$pos->hasPermission($permission, $branchId)) {
            return $this->forbidden('PERMISSION_DENIED',
                "الصلاحية مطلوبة: {$permission}");
        }

        return $next($request);
    }

    private function forbidden(string $code, string $message, int $status = 403): Response
    {
        return new \Illuminate\Http\JsonResponse([
            'success' => false,
            'code' => $code,
            'message' => $message,
            'errors' => (object)[],
            'meta' => (object)[],
        ], $status);
    }
}
