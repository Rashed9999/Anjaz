<?php

namespace App\Http\Middleware;

use App\Models\AgentProfile;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AMIAL-AUDIT (security hardening) — فرض دور الوكيل.
 *
 * يمنع أي مستخدم غير وكيل من نداء مسارات الوكيل (float-dashboard, topup-request,
 * settlements...). دفاع-في-العمق فوق المنطق الموجود في الخدمة/المتحكّم.
 *
 * الوكيل = type 1 (0=admin, 1=agent, 2=customer, 3=merchant)، أو من يملك AgentProfile.
 *
 * للتفعيل: سجّل alias في bootstrap/app.php ثم أضِفه لمجموعة مسارات الوكيل:
 *   $middleware->alias(['amial.agent' => \App\Http\Middleware\EnsureAgent::class]);
 *   Route::prefix('agent')->middleware('amial.agent')->group(...)
 */
class EnsureAgent
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();

        $isAgent = $user !== null
            && ((int) $user->type === 1
                || AgentProfile::where('user_id', $user->id)->exists());

        if (!$isAgent) {
            return new JsonResponse([
                'success' => false,
                'code' => 'NOT_AN_AGENT',
                'message' => 'هذه العملية متاحة للوكلاء فقط',
                'errors' => (object) [],
                'meta' => (object) [],
            ], 403);
        }

        return $next($request);
    }
}
