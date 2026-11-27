<?php

namespace App\Exceptions;

use RuntimeException;

/** Fail closed when held funds cannot be measured reliably. */
final class FeatureBalanceUnknownException extends RuntimeException
{
    public function __construct(public readonly string $featureKey)
    {
        parent::__construct('تعذّر التحقق من الأموال المحتجزة؛ رُفض إيقاف الخدمة حتى تعود مراقبة الأرصدة للعمل.');
    }
}
