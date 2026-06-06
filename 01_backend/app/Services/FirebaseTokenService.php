<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AMIAL-REFACTOR-CORE-001
 *
 * FirebaseTokenService — cache + توليد لـ FCM OAuth access_token.
 *
 * يستبدل: Helpers::getAccessToken الذي كان يستدعي https://oauth2.googleapis.com
 *         في كل إشعار (راجع AUDIT_v0.6.md 1.10).
 *
 * Google token TTL = 3600s. نطبع TTL = 3300s (60s safety margin).
 *
 * Cache driver: Redis في production (atomic لـ multi-worker).
 * في فشل توليد token، نعيد null ولا نرمي — الإشعارات اختيارية لا حرجة.
 */
class FirebaseTokenService
{
    private const CACHE_KEY = 'amial:fcm:access_token';
    private const CACHE_TTL_SECONDS = 3300; // 55 min < 60 min

    /**
     * يعيد access_token صالح. يستخدم cache.
     *
     * @param array $serviceAccountKey decoded JSON من business_settings
     */
    public function getAccessToken(array $serviceAccountKey): ?string
    {
        // مفتاح cache يربط بـ project_id حتى لا نخلط mocks
        $projectId = $serviceAccountKey['project_id'] ?? 'default';
        $cacheKey = self::CACHE_KEY . ':' . $projectId;

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($serviceAccountKey) {
            return $this->fetchFreshToken($serviceAccountKey);
        });
    }

    /**
     * يجبر تجديد الـ token (مثلاً بعد فشل 401 من FCM).
     */
    public function invalidate(string $projectId = 'default'): void
    {
        Cache::forget(self::CACHE_KEY . ':' . $projectId);
    }

    /**
     * نفس منطق getAccessToken الأصلي لكن مع validation و timeout.
     */
    private function fetchFreshToken(array $key): ?string
    {
        if (empty($key['client_email']) || empty($key['private_key'])) {
            Log::warning('FirebaseTokenService: invalid service account key (missing fields)');
            return null;
        }

        try {
            $jwtPayload = [
                'iss' => $key['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'exp' => time() + 3600,
                'iat' => time(),
            ];

            $jwtHeaderEncoded = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $jwtPayloadEncoded = $this->base64UrlEncode(json_encode($jwtPayload));
            $unsignedJwt = $jwtHeaderEncoded . '.' . $jwtPayloadEncoded;

            $signature = '';
            $signed = openssl_sign($unsignedJwt, $signature, $key['private_key'], OPENSSL_ALGO_SHA256);
            if (!$signed) {
                Log::error('FirebaseTokenService: openssl_sign failed', [
                    'openssl_error' => openssl_error_string(),
                ]);
                return null;
            }

            $jwt = $unsignedJwt . '.' . $this->base64UrlEncode($signature);

            // timeout 5s — لا نريد block طويل
            $response = Http::timeout(5)->retry(2, 200)->asForm()->post(
                'https://oauth2.googleapis.com/token',
                [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt,
                ]
            );

            if (!$response->ok()) {
                Log::warning('FirebaseTokenService: token endpoint returned non-OK', [
                    'status' => $response->status(),
                ]);
                return null;
            }

            return $response->json('access_token');

        } catch (\Throwable $e) {
            Log::error('FirebaseTokenService: unexpected error', [
                'error' => $e->getMessage(),
                'class' => get_class($e),
            ]);
            return null;
        }
    }

    /**
     * Google JWT يتطلب base64url (لا base64 العادي).
     * نحول '+/' → '-_' ونحذف padding '='.
     */
    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
