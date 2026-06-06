<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>كشف حساب — {{ $account->customer_name }}</title>
    <style>
        @page { margin: 20mm 15mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #222; }
        h1 { color: #053391; font-size: 22px; margin: 0 0 8px; text-align: center; }
        .subtitle { color: #5F6B7C; text-align: center; margin-bottom: 24px; font-size: 13px; }
        .header { display: table; width: 100%; margin-bottom: 24px; }
        .header > div { display: table-cell; padding: 8px 0; }
        .header .right { text-align: right; }
        .header .left { text-align: left; }
        .info { background: #F8F9FB; padding: 12px; border-radius: 6px; margin-bottom: 20px; }
        .info-row { display: table; width: 100%; margin: 4px 0; }
        .info-row span { display: table-cell; }
        .info-row .label { color: #5F6B7C; text-align: right; width: 30%; }
        .info-row .value { font-weight: bold; text-align: left; }
        .summary { display: table; width: 100%; margin-bottom: 16px; border-collapse: collapse; }
        .summary > div { display: table-cell; padding: 10px; text-align: center; border: 1px solid #E5E7EB; }
        .summary .label { color: #5F6B7C; font-size: 11px; }
        .summary .value { font-size: 16px; font-weight: bold; margin-top: 4px; }
        .summary .red { color: #DC0A0B; }
        .summary .green { color: #16A34A; }
        .summary .navy { color: #053391; }
        table.movements { width: 100%; border-collapse: collapse; margin-top: 12px; }
        table.movements th, table.movements td { border: 1px solid #E5E7EB; padding: 8px; text-align: right; }
        table.movements th { background: #053391; color: #fff; font-weight: bold; }
        table.movements tr:nth-child(even) { background: #F8F9FB; }
        .debit { color: #DC0A0B; font-weight: bold; }
        .credit { color: #16A34A; font-weight: bold; }
        .footer { margin-top: 30px; padding-top: 12px; border-top: 1px solid #E5E7EB; color: #5F6B7C; font-size: 10px; text-align: center; }
        .badge {
            display: inline-block; padding: 3px 10px; border-radius: 12px;
            font-size: 11px; font-weight: bold;
        }
        .badge.gold { background: #FECA1E; color: #5C4400; }
        .badge.silver { background: #D1D5DB; color: #374151; }
        .badge.bronze { background: #CD7F32; color: #fff; }
    </style>
</head>
<body>

<h1>كشف حساب عميل</h1>
<div class="subtitle">أميال باي — Amyal Pay</div>

<div class="header">
    <div class="right">
        <div style="color:#053391; font-weight:bold; font-size:16px">{{ $account->customer_name }}</div>
        <div style="color:#5F6B7C">{{ $account->customer_phone }}</div>
    </div>
    <div class="left">
        <div>تاريخ الإصدار: {{ now()->format('Y-m-d H:i') }}</div>
        @if($period['from'] || $period['to'])
            <div>الفترة: {{ $period['from'] ? \Carbon\Carbon::parse($period['from'])->format('Y-m-d') : 'البداية' }}
                — {{ $period['to'] ? \Carbon\Carbon::parse($period['to'])->format('Y-m-d') : 'اليوم' }}</div>
        @endif
    </div>
</div>

<div class="info">
    <div class="info-row">
        <span class="value">
            <span class="badge {{ $account->classification }}">
                {{ ['gold' => '⭐ ذهبي', 'silver' => 'فضّي', 'bronze' => 'برونزي'][$account->classification] ?? $account->classification }}
            </span>
        </span>
        <span class="label">التصنيف</span>
    </div>
    <div class="info-row">
        <span class="value">{{ number_format((float)$account->credit_limit, 2) }} ر.ي</span>
        <span class="label">حد الائتمان</span>
    </div>
    <div class="info-row">
        <span class="value">{{ $account->last_payment_at?->format('Y-m-d') ?? '—' }}</span>
        <span class="label">آخر سداد</span>
    </div>
</div>

<div class="summary">
    <div>
        <div class="label">رصيد افتتاحي</div>
        <div class="value navy">{{ number_format((float)$opening_balance, 2) }}</div>
    </div>
    <div>
        <div class="label">إجمالي مدين (+)</div>
        <div class="value red">{{ number_format((float)$totals['debit'], 2) }}</div>
    </div>
    <div>
        <div class="label">إجمالي دائن (−)</div>
        <div class="value green">{{ number_format((float)$totals['credit'], 2) }}</div>
    </div>
    <div>
        <div class="label">رصيد إقفالي</div>
        <div class="value navy">{{ number_format((float)$closing_balance, 2) }}</div>
    </div>
</div>

<table class="movements">
    <thead>
        <tr>
            <th>التاريخ</th>
            <th>النوع</th>
            <th>المرجع</th>
            <th>مدين (+)</th>
            <th>دائن (−)</th>
            <th>الرصيد بعد</th>
        </tr>
    </thead>
    <tbody>
        @forelse($movements as $m)
            @php
                $isNeg = str_starts_with((string)$m->amount, '-');
                $abs = ltrim((string)$m->amount, '-');
                $typeLabel = [
                    'sale' => 'بيع آجل',
                    'payment' => 'سداد',
                    'return' => 'مرتجع',
                    'adjustment' => 'تعديل',
                ][$m->type] ?? $m->type;
            @endphp
            <tr>
                <td>{{ $m->created_at->format('Y-m-d H:i') }}</td>
                <td>{{ $typeLabel }}</td>
                <td>{{ $m->reference_number ?? '—' }}</td>
                <td class="debit">{{ $isNeg ? '—' : number_format((float)$abs, 2) }}</td>
                <td class="credit">{{ $isNeg ? number_format((float)$abs, 2) : '—' }}</td>
                <td><strong>{{ number_format((float)$m->balance_after, 2) }}</strong></td>
            </tr>
            @if($m->note)
                <tr style="background:#F8F9FB">
                    <td colspan="6" style="font-size:10px; color:#5F6B7C; font-style:italic">
                        ملاحظة: {{ $m->note }}
                        @if($m->due_date)
                            • تاريخ الاستحقاق: {{ \Carbon\Carbon::parse($m->due_date)->format('Y-m-d') }}
                        @endif
                    </td>
                </tr>
            @endif
        @empty
            <tr><td colspan="6" style="text-align:center; padding:30px; color:#5F6B7C">لا توجد حركات في هذه الفترة</td></tr>
        @endforelse
    </tbody>
</table>

<div class="footer">
    <div>هذا الكشف صادر تلقائياً من نظام أميال باي</div>
    <div style="margin-top:4px">للتحقّق من صحة هذا المستند، تواصل مع الإدارة برقم العميل: {{ $account->id }}</div>
    <div style="margin-top:4px; color:#FECA1E; font-weight:bold">Amyal Pay © {{ now()->year }}</div>
</div>

</body>
</html>
