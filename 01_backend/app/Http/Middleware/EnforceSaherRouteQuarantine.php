<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Fail-closed on a DB lookup failure; never silently bypass an active security
 * quarantine. Only named administrative WRITE routes are checked.
 */
final class EnforceSaherRouteQuarantine
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        $route = $request->route();
        $name = $route?->getName();
        if (! is_string($name) || ! str_starts_with($name, 'admin.')
            || ! str_starts_with((string) $route?->uri(), 'admin/')) {
            return $next($request);
        }

        try {
            // A fresh deployment may briefly start before database migration.
            if (! Schema::hasTable('saher_route_quarantines')) {
                return $next($request);
            }

            $blocked = DB::table('saher_route_quarantines')
                ->where('route_name', $name)->where('status', 'ACTIVE')->exists();
        } catch (\Throwable $error) {
            report($error);

            return response()->json([
                'success' => false,
                'code' => 'SAHER_GUARD_UNAVAILABLE',
                'message' => 'تعذّر التحقق من الحماية الأمنية؛ العملية موقوفة مؤقتاً',
            ], 503);
        }

        if (! $blocked) {
            return $next($request);
        }

        Log::warning('Saher route quarantine blocked request', [
            'route_name' => $name,
            'actor_id' => $request->user()?->id,
            'method' => $request->method(),
            'ip' => $request->ip(),
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'code' => 'SAHER_ROUTE_QUARANTINED',
                'message' => 'أوقف ساهر هذا الإجراء مؤقتاً لأسباب أمنية',
            ], 403);
        }

        abort(403, 'أوقف ساهر هذا الإجراء مؤقتاً لأسباب أمنية');
    }
}
