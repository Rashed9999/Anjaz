<?php

namespace App\Services\BusinessGateway;

/**
 * صيغة التوقيع الوحيدة للقناة، في الاتجاهين. تُعرَّف في مكانٍ واحد لئلّا
 * يوقّع طرفٌ بصيغة ويتحقق الآخر بأخرى.
 *
 * وارد (أميال أعمال → أميال باي):
 *   HMAC-SHA256( secret, "{timestamp}\n{nonce}\n{METHOD}\n{path}\n{query}\n{sha256(body)}" )
 *
 * صادر (أميال باي → أميال أعمال، الأحداث):
 *   HMAC-SHA256( secret, "{timestamp}.{body}" )
 */
final class BusinessGatewaySignature
{
    public static function inboundCanonical(
        string $timestamp,
        string $nonce,
        string $method,
        string $path,
        string $query,
        string $body,
    ): string {
        return implode("\n", [
            $timestamp,
            $nonce,
            strtoupper($method),
            '/'.ltrim($path, '/'),
            $query,
            hash('sha256', $body),
        ]);
    }

    public static function sign(string $canonical, string $secret): string
    {
        return hash_hmac('sha256', $canonical, $secret);
    }

    public static function outbound(string $timestamp, string $body, string $secret): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }
}
