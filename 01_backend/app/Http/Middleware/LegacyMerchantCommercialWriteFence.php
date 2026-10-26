<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reversible cutover fence for selected *legacy web-only* commercial editors.
 * Does not disable Amial financial APIs, cashiers, KYC, approvals, or login.
 * Opt-in only after merchant data parity and a verified independent login.
 */
final class LegacyMerchantCommercialWriteFence
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('amial.merchant_workspace.fence_legacy_commercial_writes', false)
            || $request->isMethod('GET') || $request->isMethod('HEAD')
            || $request->isMethod('OPTIONS')) {
            return $next($request);
        }

        // Explicit paths only; never a broad /merchant/* blockade.
        // Payment collection, debts, wallet, refunds and POS API stay in Amial Pay.
        $path = trim($request->path(), '/');
        $commercialEditors = [
            'merchant/data/products',
            'merchant/data/sector/products',
            'merchant/data/sector/catalog',
            'merchant/data/sector/customers',
        ];

        foreach ($commercialEditors as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return response()->json([
                    'success' => false,
                    'code' => 'MERCHANT_COMMERCIAL_EDITOR_MIGRATED',
                    'message' => 'إدارة البيانات التجارية أصبحت في أميال التاجر.',
                ], 409)->header('Cache-Control', 'no-store');
            }
        }

        return $next($request);
    }
}
