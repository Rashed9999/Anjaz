<?php

namespace App\Services\Muwathaq;

/**
 * مفاتيح قناتي الاتصال مشتقة من سر نقل واحد، مع بقاء المفاتيح المنفصلة
 * مدعومة لتدويرها مستقبلاً دون تعطيل الربط.
 */
final class MuwathaqTransportSecrets
{
    public static function amialToMuwathaq(): string
    {
        return self::resolve('bridge_secret', 'amial-to-muwathaq-v1');
    }

    public static function muwathaqToAmial(): string
    {
        return self::resolve('decision_secret', 'muwathaq-to-amial-v1');
    }

    private static function resolve(string $specificKey, string $purpose): string
    {
        $specific = trim((string) config('muwathaq.'.$specificKey));
        if ($specific !== '') {
            return $specific;
        }

        $shared = trim((string) config('muwathaq.transport_secret'));
        if ($shared === '') {
            return '';
        }

        return hash_hmac('sha256', $purpose, $shared);
    }
}
