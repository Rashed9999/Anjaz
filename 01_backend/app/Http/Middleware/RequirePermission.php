<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AMIAL-RBAC-001 (v1.0-A)
 *
 * RequirePermission — middleware يفحص الصلاحية.
 *
 * **استخدام في routes:**
 *   Route::get('/admin/users', [UserController::class, 'index'])
 *       ->middleware('rbac:users.view');
 *
 *   Route::post('/admin/transactions/{id}/refund', [TxController::class, 'refund'])
 *       ->middleware('rbac:transactions.refund');
 *
 * **متعدد:** فصل بـ `|` للـ OR، بـ `,` للـ AND
 *   ->middleware('rbac:users.view|users.export')   // OR (واحدة كافية)
 *   ->middleware('rbac:users.view,users.edit')      // AND (لازم كلها)
 *
 * **Super admin** يتجاوز كل فحص.
 */
class RequirePermission
{
    public function handle(Request $request, Closure $next, string $permissionExpr): Response
    {
        $user = $request->user();

        if (!$user) {
            return new \Illuminate\Http\JsonResponse([
                'success' => false,
                'code' => 'AUTH_REQUIRED',
                'message' => 'يجب تسجيل الدخول',
                'errors' => (object)[],
                'meta' => (object)[],
            ], 401);
        }

        // Super admin يتجاوز
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return $next($request);
        }

        $allowed = $this->checkPermissions($user, $permissionExpr);

        if (!$allowed) {
            // سجل المحاولة (security event)
            \Log::warning('RBAC: permission denied', [
                'user_id' => $user->id,
                'required' => $permissionExpr,
                'route' => $request->path(),
                'ip' => $request->ip(),
            ]);

            return new \Illuminate\Http\JsonResponse([
                'success' => false,
                'code' => 'PERMISSION_DENIED',
                'message' => 'ليس لديك صلاحية لهذا الإجراء',
                'errors' => (object)[],
                'meta' => [
                    'required_permission' => $permissionExpr,
                ],
            ], 403);
        }

        return $next($request);
    }

    /**
     * يحلل expression:
     *   "users.view"                    → عنده users.view
     *   "users.view|users.export"       → عنده أي منهما
     *   "users.view,users.edit"         → عنده الاثنتين
     */
    private function checkPermissions($user, string $expr): bool
    {
        if (!method_exists($user, 'hasAnyPermission')) {
            return false;
        }

        // AND: كلها
        if (str_contains($expr, ',')) {
            $perms = array_map('trim', explode(',', $expr));
            return $user->hasAllPermissions($perms);
        }

        // OR: واحدة كافية
        if (str_contains($expr, '|')) {
            $perms = array_map('trim', explode('|', $expr));
            return $user->hasAnyPermission($perms);
        }

        // single
        return $user->hasPermission(trim($expr));
    }
}
