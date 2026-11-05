<?php

namespace App\Services\BusinessGateway;

use RuntimeException;

/**
 * رفضٌ مقصود في قناة أميال أعمال: رمزٌ ثابت يقرؤه الطرف الآخر آلياً،
 * ورسالةٌ عربية، وحالة HTTP. لا يُستعمل لأعطال غير متوقعة.
 */
class BusinessGatewayException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
    ) {
        parent::__construct($message);
    }
}
