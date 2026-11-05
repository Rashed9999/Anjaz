<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Services\Kyc\Biometric\BiometricVerificationService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * AMIAL-KYC-BIOMETRIC-WEBHOOK-001 — callback عام، لكن الثقة تشفيرية لا جلسة مستخدم.
 */
final class KycBiometricWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        string $provider,
        BiometricVerificationService $biometrics,
    ): JsonResponse {
        try {
            $outcome = $biometrics->applyWebhook(
                trim($provider),
                $request->getContent(),
                $request->headers->all(),
            );
        } catch (DomainException $e) {
            $code = $e->getMessage();

            return response()->json([
                'success' => false,
                'code' => $code,
                'message' => match ($code) {
                    'KYC_BIOMETRIC_WEBHOOK_SIGNATURE_INVALID' => 'Webhook signature invalid.',
                    'KYC_BIOMETRIC_PROVIDER_UNKNOWN' => 'Biometric provider is not registered.',
                    'KYC_BIOMETRIC_PROVIDER_UNAVAILABLE' => 'Biometric provider verification is unavailable.',
                    'KYC_BIOMETRIC_SCHEMA_UNAVAILABLE' => 'Biometric runtime schema is unavailable.',
                    default => 'Biometric webhook rejected.',
                },
            ], match ($code) {
                'KYC_BIOMETRIC_WEBHOOK_SIGNATURE_INVALID' => 401,
                'KYC_BIOMETRIC_PROVIDER_UNKNOWN' => 404,
                'KYC_BIOMETRIC_PROVIDER_UNAVAILABLE', 'KYC_BIOMETRIC_SCHEMA_UNAVAILABLE' => 503,
                default => 422,
            });
        } catch (InvalidArgumentException) {
            return response()->json([
                'success' => false,
                'code' => 'KYC_BIOMETRIC_EVENT_INVALID',
                'message' => 'Biometric event payload is invalid.',
            ], 422);
        } catch (\Throwable) {
            // لا نعيد body أو رسالة المزود/الاستثناء إلى الإنترنت.
            return response()->json([
                'success' => false,
                'code' => 'KYC_BIOMETRIC_WEBHOOK_ERROR',
                'message' => 'Biometric webhook could not be processed.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'code' => $outcome['duplicate'] ? 'KYC_BIOMETRIC_EVENT_DUPLICATE' : 'KYC_BIOMETRIC_EVENT_ACCEPTED',
            'data' => $outcome,
        ], $outcome['matched_attempt'] ? 200 : 202);
    }
}
