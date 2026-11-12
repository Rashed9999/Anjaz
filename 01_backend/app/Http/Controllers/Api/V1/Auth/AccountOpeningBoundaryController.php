<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Support\Muwathaq\AccountOpeningBoundary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Compatibility endpoints for retired Amial Pay registration routes.
 *
 * They deliberately return a machine-readable 410 response instead of
 * forwarding request bodies, credentials, or identity material across
 * services. The client opens Muwathaq and starts a fresh, scoped case there.
 */
final class AccountOpeningBoundaryController extends Controller
{
    public function __construct(private readonly AccountOpeningBoundary $boundary)
    {
    }

    public function customer(Request $request): JsonResponse
    {
        return $this->boundary->retired($this->requestedSubject($request, 'customer'));
    }

    public function merchant(): JsonResponse
    {
        return $this->boundary->retired('merchant');
    }

    public function agent(): JsonResponse
    {
        return $this->boundary->retired('agent');
    }

    public function operator(string $slug): JsonResponse
    {
        return $this->boundary->retired(match ($slug) {
            'merchants' => 'merchant',
            'agents' => 'agent',
            default => 'customer',
        });
    }

    private function requestedSubject(Request $request, string $fallback): string
    {
        $subject = (string) $request->input('account_type', $fallback);

        return in_array($subject, ['customer', 'merchant', 'agent'], true)
            ? $subject
            : $fallback;
    }
}
