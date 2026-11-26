<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransactionLimit extends Model
{
    protected $casts = [
        'user_id' => 'integer',
        'todays_count' => 'integer',
        'todays_amount' => 'float',
        'this_months_count' => 'integer',
        'this_months_amount' => 'float',
        'type' => 'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $fillable = [
        'user_id',
        'todays_count',
        'todays_amount',
        'this_months_count',
        'this_months_amount',
        'type'
    ];

    /** Called inside the money transaction. The user row also serializes first-time counters. */
    public static function consumeCustomerTransfer(int $senderId, string $amount): void
    {
        $policy = \App\CentralLogics\Helpers::get_business_settings('customer_send_money_limit');
        if ((int) ($policy['status'] ?? 0) !== 1) {
            return;
        }

        User::whereKey($senderId)->lockForUpdate()->firstOrFail();
        $key = ['user_id' => $senderId, 'type' => 'send_money'];
        $rows = self::where($key)->limit(2)->lockForUpdate()->get();
        if ($rows->count() > 1) {
            throw new \RuntimeException('تحتاج عدّادات التحويل المكرّرة إلى مطابقة');
        }
        $row = $rows->first() ?? new self($key);
        $now = now();
        $sameDay = $row->updated_at?->isSameDay($now);
        $sameMonth = $row->updated_at?->format('Y-m') === $now->format('Y-m');

        $row->todays_count = ($sameDay ? $row->todays_count : 0) + 1;
        $row->this_months_count = ($sameMonth ? $row->this_months_count : 0) + 1;
        $row->todays_amount = bcadd($sameDay ? (string) ($row->getRawOriginal('todays_amount') ?? '0') : '0', $amount, 4);
        $row->this_months_amount = bcadd($sameMonth ? (string) ($row->getRawOriginal('this_months_amount') ?? '0') : '0', $amount, 4);
        $checks = [
            ['max_amount_per_transaction', $amount, 'maximum amount per transaction exceeded'],
            ['transaction_limit_per_day', $row->todays_count, 'transaction limit per day exceeded'],
            ['total_transaction_amount_per_day', $row->getAttributes()['todays_amount'], 'total transaction amount per day exceeded'],
            ['transaction_limit_per_month', $row->this_months_count, 'transaction limit per month exceeded'],
            ['total_transaction_amount_per_month', $row->getAttributes()['this_months_amount'], 'total transaction amount per month exceeded'],
        ];
        foreach ($checks as [$setting, $value, $message]) {
            $limit = (string) ($policy[$setting] ?? 0);
            if (bccomp($limit, '0', 4) > 0 && bccomp((string) $value, $limit, 4) > 0) {
                throw new \App\Exceptions\TransferLimitExceeded(translate($message));
            }
        }
        $row->save();
    }
}
