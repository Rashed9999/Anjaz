<?php

namespace App\Services\BusinessGateway;

use App\Services\MoneyService;
use Illuminate\Database\Eloquent\Model;

/**
 * هل غيّر هذا الحفظ رصيد دين فعلاً؟ «متّسخ» لا يكفي: 100 و100.0000 قيمة واحدة،
 * وحفظ اسم المورد لا يلمس رصيده. وعند الإنشاء يُعدّ الرصيد غير الصفري تغيّراً
 * (رصيد افتتاحي).
 */
final class CreditBalanceChange
{
    /** @param list<string> $columns */
    public static function changed(Model $model, array $columns): bool
    {
        foreach ($columns as $column) {
            $new = MoneyService::normalize((string) ($model->getAttribute($column) ?? '0'));
            if (!$model->exists) {
                if (MoneyService::compare($new, '0') !== 0) {
                    return true;
                }
                continue;
            }
            if (!$model->isDirty($column)) {
                continue;
            }
            $old = MoneyService::normalize((string) ($model->getOriginal($column) ?? '0'));
            if (MoneyService::compare($old, $new) !== 0) {
                return true;
            }
        }

        return false;
    }
}
