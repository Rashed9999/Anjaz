<?php

$positiveIntOrNull = static function ($value): ?int {
    if ($value === null || $value === '' || ! is_numeric($value)) {
        return null;
    }

    $minutes = (int) $value;
    return $minutes > 0 ? $minutes : null;
};

return [
    /*
     * AMIAL-REPORTING-P2-001
     *
     * Support SLA is policy, not a technical guess. Keep every target null until
     * management formally approves it. Once all four priority targets are set,
     * the reporting catalog can truthfully promote support SLA from partial to ready.
     * Values are wall-clock resolution minutes from ticket creation to resolution,
     * or to the current time while the ticket remains unresolved.
     */
    'support_sla' => [
        'resolution_minutes' => [
            'urgent' => $positiveIntOrNull(env('AMIAL_SUPPORT_SLA_URGENT_MINUTES')),
            'high' => $positiveIntOrNull(env('AMIAL_SUPPORT_SLA_HIGH_MINUTES')),
            'normal' => $positiveIntOrNull(env('AMIAL_SUPPORT_SLA_NORMAL_MINUTES')),
            'low' => $positiveIntOrNull(env('AMIAL_SUPPORT_SLA_LOW_MINUTES')),
        ],
        'clock' => 'wall_clock',
        'waiting_customer_pauses_clock' => false,
    ],
];
