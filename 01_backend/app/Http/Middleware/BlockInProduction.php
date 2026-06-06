<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * AMIAL-REFACTOR-CORE-001 (closes AUDIT 1.8)
 *
 * BlockInProduction — يمنع الـ routes الحساسة في production.
 *
 * الاستخدام:
 *   Route::middleware(['amial.block-in-production'])->group(function() {
 *       require __DIR__.'/install.php';
 *       require __DIR__.'/update.php';
 *   });
 *
 * منطق:
 *   - production + INSTALL_COMPLETED=true → 404 (نظهر "لا يوجد")
 *   - production + INSTALL_COMPLETED!=true → 503 (نطلب من admin إكمال التنصيب CLI)
 *   - غير production → نمرر بشكل عادي
 *
 * نعيد 404 (وليس 403) في production لتجنب الإشارة لوجود route حساس.
 */
class BlockInProduction
{
    public function handle(Request $request, Closure $next): Response
    {
        if (App::environment('production', 'prod')) {
            $installCompleted = env('INSTALL_COMPLETED') === 'true' || env('INSTALL_COMPLETED') === true;

            if ($installCompleted) {
                Log::warning('BlockInProduction: blocked access to installation/update route', [
                    'path' => $request->path(),
                    'ip' => $request->ip(),
                    'user_agent' => substr((string)$request->userAgent(), 0, 200),
                ]);

                // 404 — نخفي وجود الـ route
                abort(404);
            }

            // التنصيب لم يكتمل في production — يجب إكماله عبر CLI
            Log::critical('BlockInProduction: production environment has INSTALL_COMPLETED=false', [
                'path' => $request->path(),
                'ip' => $request->ip(),
            ]);

            abort(503, 'Installation must be completed via CLI in production');
        }

        return $next($request);
    }
}
