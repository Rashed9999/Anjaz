<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * AMIAL-MAINT-001 — تُرمى عند محاولة إيقاف ميزة فيها أموال محتجزة (تراكم مالي > 0).
 *
 * قاعدة الأمان: لا تُغلق ميزة إلا إذا كانت تراكماتها المالية = صفر، حتى لا
 * تُحتجَز أموال عملاء داخل ميزة معطّلة.
 */
class FeatureHasFundsException extends RuntimeException
{
    public function __construct(
        public readonly string $featureKey,
        public readonly string $outstandingAmount,   // decimal string
        public readonly int $itemsCount = 0,
    ) {
        parent::__construct(
            "لا يمكن إيقاف «{$featureKey}»: توجد أموال محتجزة قدرها {$outstandingAmount} ر.ي في {$itemsCount} عملية نشطة."
        );
    }
}
