<?php

namespace App\Services\BusinessGateway;

/**
 * Canonical signed request contract for Amial Business <-> Amial Pay.
 * Inbound: HMAC-SHA256(timestamp + nonce + method + path + query + body hash).
 * Outbound webhook: HMAC-SHA256(timestamp + "." + body).
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
