<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>إيصال بيع وقود</title>
    <style>
        @page { margin: 15mm; size: A5 portrait; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1A1A1A; }
        .header { text-align: center; padding-bottom: 12px; border-bottom: 2px solid #053391; }
        .station-name { color: #053391; font-size: 20px; font-weight: bold; }
        .station-info { color: #5F6B7C; font-size: 11px; margin-top: 4px; }

        .title-band {
            background: #053391; color: white; text-align: center;
            padding: 10px; margin: 12px 0; border-radius: 6px;
            font-size: 16px; font-weight: bold;
        }

        .ulid {
            text-align: center; color: #5F6B7C; font-size: 10px;
            font-family: monospace; margin-bottom: 12px;
        }

        .info-table { width: 100%; margin-bottom: 16px; border-collapse: collapse; }
        .info-table tr { border-bottom: 1px solid #E5E7EB; }
        .info-table td { padding: 8px 4px; vertical-align: top; }
        .info-table .label { color: #5F6B7C; width: 35%; font-size: 11px; }
        .info-table .value { font-weight: bold; text-align: left; }

        .big-amount {
            background: #FECA1E; padding: 16px; border-radius: 8px;
            text-align: center; margin: 16px 0;
        }
        .big-amount .label { color: #5C4400; font-size: 11px; }
        .big-amount .value { color: #053391; font-size: 32px; font-weight: bold; margin-top: 4px; }

        .liters-band {
            background: #E0E7FF; padding: 10px; border-radius: 6px;
            text-align: center; margin-bottom: 12px;
        }
        .liters-band .liters { font-size: 22px; font-weight: bold; color: #053391; }
        .liters-band .label { font-size: 11px; color: #4338CA; }

        .payment-method {
            display: inline-block; padding: 6px 14px; border-radius: 16px;
            font-size: 12px; font-weight: bold;
        }
        .pm-cash { background: #DCFCE7; color: #166534; }
        .pm-amial { background: #DBEAFE; color: #1E40AF; }
        .pm-company { background: #FEF3C7; color: #92400E; }

        .meta-box {
            background: #F8F9FB; padding: 10px; border-radius: 6px;
            margin: 10px 0; font-size: 11px; color: #5F6B7C;
        }
        .meta-box .row { margin: 3px 0; }

        .footer {
            margin-top: 20px; padding-top: 10px; border-top: 1px dashed #CCC;
            text-align: center; color: #5F6B7C; font-size: 10px;
        }
        .verification-code {
            font-family: monospace; font-size: 14px; color: #053391;
            font-weight: bold; letter-spacing: 2px; margin-top: 6px;
        }
        .amyal-brand { color: #FECA1E; font-weight: bold; margin-top: 8px; }
    </style>
</head>
<body>

<div class="header">
    <div class="station-name">{{ $station->station_name }}</div>
    @if($station->city || $station->address)
    <div class="station-info">
        @if($station->city){{ $station->city }}@endif
        @if($station->address) — {{ $station->address }}@endif
    </div>
    @endif
    @if($station->license_number)
    <div class="station-info">رخصة: {{ $station->license_number }}</div>
    @endif
</div>

<div class="title-band">إيصال بيع وقود</div>

<div class="ulid">#{{ substr($sale->sale_ulid, -12) }}</div>

<table class="info-table">
    <tr>
        <td class="label">التاريخ</td>
        <td class="value">{{ $sale->created_at->format('Y-m-d H:i') }}</td>
    </tr>
    <tr>
        <td class="label">المضخّة</td>
        <td class="value">رقم {{ $pump->pump_number ?? '—' }}</td>
    </tr>
    <tr>
        <td class="label">نوع الوقود</td>
        <td class="value">{{ $product->name ?? '—' }}</td>
    </tr>
    <tr>
        <td class="label">السعر للّتر</td>
        <td class="value">{{ number_format((float)$sale->price_per_liter, 2) }} ر.ي</td>
    </tr>
</table>

<div class="liters-band">
    <div class="liters">{{ number_format((float)$sale->liters, 3) }} لتر</div>
    <div class="label">الكمية</div>
</div>

<div class="big-amount">
    <div class="label">الإجمالي المدفوع</div>
    <div class="value">{{ number_format((float)$sale->total_amount, 2) }} ر.ي</div>
</div>

<div style="text-align:center; margin: 12px 0">
    @php
        $pmClass = match($sale->payment_method) {
            'cash' => 'pm-cash',
            'amial_pay' => 'pm-amial',
            'company_card' => 'pm-company',
            default => '',
        };
        $pmLabel = match($sale->payment_method) {
            'cash' => '💵 نقدي',
            'amial_pay' => '📱 أميال باي',
            'company_card' => '🏢 بطاقة شركة',
            default => $sale->payment_method,
        };
    @endphp
    <span class="payment-method {{ $pmClass }}">{{ $pmLabel }}</span>
</div>

@if($sale->vehicle_plate || $sale->driver_name)
<div class="meta-box">
    @if($sale->vehicle_plate)
    <div class="row"><strong>لوحة السيارة:</strong> {{ $sale->vehicle_plate }}</div>
    @endif
    @if($sale->driver_name)
    <div class="row"><strong>السائق:</strong> {{ $sale->driver_name }}</div>
    @endif
    @if($sale->company_account_id && isset($company))
    <div class="row"><strong>الشركة:</strong> {{ $company->company_name }}</div>
    @endif
    @if($sale->company_card_id)
    <div class="row"><strong>بطاقة:</strong> {{ $sale->company_card_id }}</div>
    @endif
</div>
@endif

@if($sale->meter_reading_before !== null && $sale->meter_reading_after !== null)
<div class="meta-box">
    <div class="row"><strong>قراءة العدّاد قبل:</strong> {{ number_format((float)$sale->meter_reading_before, 2) }}</div>
    <div class="row"><strong>قراءة العدّاد بعد:</strong> {{ number_format((float)$sale->meter_reading_after, 2) }}</div>
</div>
@endif

<div class="footer">
    <div>شكراً لزيارتكم</div>
    <div class="verification-code">{{ strtoupper(substr($sale->sale_ulid, -8)) }}</div>
    <div style="font-size:9px; margin-top:4px">رمز التحقّق</div>
    <div class="amyal-brand">Amyal Pay © {{ now()->year }}</div>
</div>

</body>
</html>
