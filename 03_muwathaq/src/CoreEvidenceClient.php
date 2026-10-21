<?php

declare(strict_types=1);

namespace Muwathaq;

use RuntimeException;

/**
 * Proxy داخلي فقط: جلسة المراجع تبقى في موثّق، والمستند لا يأخذ رابطاً عاماً.
 */
final class CoreEvidenceClient
{
    public static function stream(string $applicationUlid, int $evidenceId, string $reviewer): never
    {
        $base = rtrim(Config::required('AMIAL_CORE_BASE_URL'), '/');
        $timestamp = (string) time();
        $body = '';
        $signature = hash_hmac(
            'sha256',
            $timestamp.'.'.$body,
            Config::transportSecret('MUWATHAQ_DECISION_SECRET', 'muwathaq-to-amial-v1')
        );

        $url = $base.'/api/v1/internal/muwathaq/applications/'.rawurlencode($applicationUlid)
            .'/evidences/'.rawurlencode((string) $evidenceId).'/document';
        $request = curl_init($url);
        curl_setopt_array($request, [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/pdf,image/jpeg,image/png',
                'X-Muwathaq-Timestamp: '.$timestamp,
                'X-Muwathaq-Signature: '.$signature,
                'X-Muwathaq-Reviewer: '.preg_replace('/[^A-Za-z0-9@._+-]/', '', $reviewer),
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
        ]);
        $body = curl_exec($request);
        $status = (int) curl_getinfo($request, CURLINFO_RESPONSE_CODE);
        $contentType = (string) curl_getinfo($request, CURLINFO_CONTENT_TYPE);
        $error = curl_error($request);
        curl_close($request);

        if (!is_string($body) || $status !== 200) {
            throw new RuntimeException('تعذر جلب مستند الدليل من أميال'.($status ? ' (HTTP '.$status.')' : '').($error ? ': '.$error : ''));
        }

        // Core يسمح فقط بالمرفقات التي تحقق منها عند الرفع؛ نحافظ على نوعها
        // ونمنع التخزين حتى لو فتح المراجع الرابط في تبويب منفصل.
        header('Content-Type: '.($contentType !== '' ? $contentType : 'application/octet-stream'));
        header('Content-Disposition: inline; filename="muwathaq-evidence-'.$evidenceId.'"');
        header('Cache-Control: private, no-store, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        echo $body;
        exit;
    }
}
