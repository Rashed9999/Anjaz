<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>فاتورة {{ $invoice->invoice_number }}</title>
    <style>
        @page { margin: 20mm 15mm; }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 11pt;
            color: #1F2937;
            direction: rtl;
            line-height: 1.5;
        }

        /* ============ Header ============ */
        .header {
            display: table;
            width: 100%;
            margin-bottom: 14px;
            padding-bottom: 12px;
            border-bottom: 3px solid #053391;
        }
        .header-left, .header-right {
            display: table-cell;
            vertical-align: top;
        }
        .header-right { width: 60%; }
        .header-left { width: 40%; text-align: left; }
        .business-name {
            font-size: 20pt;
            font-weight: bold;
            color: #053391;
            margin: 0 0 4px 0;
        }
        .business-meta {
            font-size: 9pt;
            color: #6B7280;
            margin: 1px 0;
        }
        .invoice-title {
            font-size: 26pt;
            font-weight: bold;
            color: #053391;
            text-align: left;
            margin: 0;
        }
        .invoice-number {
            font-family: monospace;
            font-size: 12pt;
            color: #374151;
            text-align: left;
            margin: 4px 0;
        }
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 4px;
            color: white;
            font-weight: bold;
            font-size: 10pt;
            background-color: {{ $status_color }};
        }

        /* ============ Customer info ============ */
        .info-row {
            display: table;
            width: 100%;
            margin-bottom: 14px;
        }
        .info-box {
            display: table-cell;
            width: 50%;
            padding: 10px 14px;
            background-color: #F9FAFB;
            border-radius: 4px;
            vertical-align: top;
        }
        .info-box.left { margin-right: 6px; }
        .info-box.right { margin-left: 6px; }
        .info-label {
            font-size: 9pt;
            color: #6B7280;
            font-weight: bold;
            margin-bottom: 4px;
        }
        .info-value { font-size: 11pt; color: #111827; }
        .meta-line {
            font-size: 9pt;
            color: #6B7280;
            margin-top: 2px;
        }

        /* ============ Items table ============ */
        table.items {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
        }
        table.items th {
            background-color: #053391;
            color: white;
            padding: 8px 6px;
            text-align: center;
            font-size: 10pt;
            font-weight: bold;
        }
        table.items td {
            padding: 7px 6px;
            border-bottom: 1px solid #E5E7EB;
            font-size: 10pt;
            vertical-align: top;
        }
        table.items td.product { text-align: right; }
        table.items td.num { text-align: center; font-family: monospace; }
        table.items td.total {
            font-weight: bold;
            color: #053391;
            text-align: center;
            font-family: monospace;
        }
        table.items tr:nth-child(even) td { background-color: #FAFBFC; }

        /* ============ Totals box ============ */
        .totals-wrap {
            display: table;
            width: 100%;
            margin-bottom: 14px;
        }
        .notes-cell {
            display: table-cell;
            width: 55%;
            padding-left: 14px;
            vertical-align: top;
        }
        .totals-cell {
            display: table-cell;
            width: 45%;
            vertical-align: top;
        }
        .totals-table {
            width: 100%;
            border-collapse: collapse;
        }
        .totals-table td {
            padding: 5px 8px;
            font-size: 10pt;
        }
        .totals-table td.label { color: #6B7280; }
        .totals-table td.value {
            text-align: left;
            font-family: monospace;
            font-weight: bold;
        }
        .totals-table tr.grand td {
            border-top: 2px solid #053391;
            border-bottom: 2px solid #053391;
            font-size: 13pt;
            font-weight: bold;
            background-color: #EFF6FF;
            color: #053391;
        }
        .totals-table tr.balance td {
            color: #DC2626;
            font-weight: bold;
        }
        .notes-box {
            background-color: #FEF3C7;
            border-right: 4px solid #F59E0B;
            padding: 10px 14px;
            font-size: 10pt;
            color: #78350F;
            border-radius: 4px;
        }

        /* ============ Collections ============ */
        .section-title {
            font-size: 11pt;
            font-weight: bold;
            color: #053391;
            margin: 10px 0 6px 0;
            padding-bottom: 4px;
            border-bottom: 1px solid #E5E7EB;
        }
        table.collections {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
        }
        table.collections th {
            background-color: #F3F4F6;
            color: #374151;
            padding: 6px;
            text-align: center;
            font-size: 9pt;
        }
        table.collections td {
            padding: 5px 6px;
            border-bottom: 1px solid #F3F4F6;
            font-size: 9pt;
            text-align: center;
        }
        td.payment-cash { color: #059669; }
        td.amount { font-family: monospace; font-weight: bold; }

        /* ============ Footer ============ */
        .footer {
            margin-top: 20px;
            padding-top: 12px;
            border-top: 1px solid #E5E7EB;
            text-align: center;
            font-size: 9pt;
            color: #9CA3AF;
        }
        .footer .stamp {
            display: inline-block;
            padding: 6px 16px;
            border: 2px dashed #D1D5DB;
            border-radius: 6px;
            color: #6B7280;
            margin-top: 6px;
        }

        .overdue-warning {
            background-color: #FEE2E2;
            border-right: 4px solid #DC2626;
            padding: 8px 12px;
            margin-bottom: 12px;
            color: #991B1B;
            font-weight: bold;
            font-size: 11pt;
        }

        .voided-overlay {
            position: fixed;
            top: 200px;
            left: 50%;
            margin-left: -120px;
            transform: rotate(-15deg);
            font-size: 80pt;
            color: rgba(220, 38, 38, 0.18);
            font-weight: bold;
            pointer-events: none;
        }
    </style>
</head>
<body>

@if($invoice->status === 'voided')
    <div class="voided-overlay">مُبطَلة</div>
@endif

{{-- ================= Header ================= --}}
<div class="header">
    <div class="header-right">
        <div class="business-name">{{ $business->business_name ?? 'منشأة الجملة' }}</div>
        @if($business->commercial_register)
            <div class="business-meta">س.ت: {{ $business->commercial_register }}</div>
        @endif
        @if($business->tax_number)
            <div class="business-meta">الرقم الضريبي: {{ $business->tax_number }}</div>
        @endif
        @if($business->phone)
            <div class="business-meta">📞 {{ $business->phone }}</div>
        @endif
        @if($business->address)
            <div class="business-meta">📍 {{ $business->city ? $business->city.' — ' : '' }}{{ $business->address }}</div>
        @endif
    </div>
    <div class="header-left">
        <div class="invoice-title">فاتورة</div>
        <div class="invoice-number">#{{ $invoice->invoice_number }}</div>
        <div class="status-badge">{{ $status_label }}</div>
    </div>
</div>

@if($days_overdue > 0)
    <div class="overdue-warning">⚠ فاتورة متأخّرة — تجاوزت موعد الاستحقاق بـ {{ $days_overdue }} يوم</div>
@endif

{{-- ================= Customer + Dates ================= --}}
<div class="info-row">
    <div class="info-box right">
        <div class="info-label">العميل</div>
        <div class="info-value"><strong>{{ $customer->full_name }}</strong></div>
        @if($customer->company_name)
            <div class="meta-line">{{ $customer->company_name }}</div>
        @endif
        @if($customer->phone)
            <div class="meta-line">📞 {{ $customer->phone }}</div>
        @endif
        @if($customer->tax_number)
            <div class="meta-line">الرقم الضريبي: {{ $customer->tax_number }}</div>
        @endif
        @if($customer->address)
            <div class="meta-line">📍 {{ $customer->city ? $customer->city.' — ' : '' }}{{ $customer->address }}</div>
        @endif
    </div>
    <div class="info-box left">
        <div class="info-label">معلومات الفاتورة</div>
        <div class="meta-line"><strong>تاريخ الإصدار:</strong> {{ $invoice->invoice_date?->format('Y-m-d') }}</div>
        <div class="meta-line"><strong>تاريخ الاستحقاق:</strong> {{ $invoice->due_date?->format('Y-m-d') }}</div>
        <div class="meta-line"><strong>نوع الدفع:</strong> {{ $invoice->payment_type === 'cash' ? 'نقد' : 'آجل' }}</div>
        @if($salesRep)
            <div class="meta-line"><strong>المندوب:</strong> {{ $salesRep->full_name }}</div>
        @endif
    </div>
</div>

{{-- ================= Items ================= --}}
<table class="items">
    <thead>
        <tr>
            <th style="width: 6%">#</th>
            <th style="width: 38%">الصنف</th>
            <th style="width: 12%">الوحدة</th>
            <th style="width: 12%">الكمّية</th>
            <th style="width: 16%">سعر الوحدة</th>
            <th style="width: 16%">الإجمالي</th>
        </tr>
    </thead>
    <tbody>
        @foreach($items as $i => $item)
            <tr>
                <td class="num">{{ $i + 1 }}</td>
                <td class="product">
                    {{ $item->product_name }}
                    @if($item->product_sku)
                        <div style="font-size: 8pt; color: #9CA3AF;">SKU: {{ $item->product_sku }}</div>
                    @endif
                </td>
                <td class="num">{{ $item->unit }}</td>
                <td class="num">{{ rtrim(rtrim(number_format($item->quantity, 4, '.', ''), '0'), '.') }}</td>
                <td class="num">
                    {{ number_format($item->unit_price, 2) }}
                    @if($item->discount_per_unit > 0)
                        <div style="font-size: 8pt; color: #DC2626;">- {{ number_format($item->discount_per_unit, 2) }}</div>
                    @endif
                </td>
                <td class="total">{{ number_format($item->line_total, 2) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

{{-- ================= Totals + Notes ================= --}}
<div class="totals-wrap">
    <div class="notes-cell">
        @if($invoice->notes)
            <div class="notes-box">
                <strong>ملاحظات:</strong><br>
                {{ $invoice->notes }}
            </div>
        @endif
    </div>
    <div class="totals-cell">
        <table class="totals-table">
            <tr>
                <td class="label">المجموع قبل الخصم</td>
                <td class="value">{{ number_format($invoice->subtotal, 2) }} ر.ي</td>
            </tr>
            @if($invoice->discount_amount > 0)
                <tr>
                    <td class="label">الخصم ({{ $discount_pct }}%)</td>
                    <td class="value" style="color: #DC2626;">- {{ number_format($invoice->discount_amount, 2) }}</td>
                </tr>
            @endif
            @if($invoice->tax_amount > 0)
                <tr>
                    <td class="label">الضريبة ({{ $invoice->tax_rate }}%)</td>
                    <td class="value">+ {{ number_format($invoice->tax_amount, 2) }}</td>
                </tr>
            @endif
            <tr class="grand">
                <td>الإجمالي النهائي</td>
                <td class="value">{{ number_format($invoice->total_amount, 2) }} ر.ي</td>
            </tr>
            @if($invoice->paid_amount > 0)
                <tr>
                    <td class="label" style="color: #059669;">المدفوع</td>
                    <td class="value" style="color: #059669;">{{ number_format($invoice->paid_amount, 2) }}</td>
                </tr>
            @endif
            @if($invoice->balance_due > 0)
                <tr class="balance">
                    <td>المتبقّي</td>
                    <td class="value">{{ number_format($invoice->balance_due, 2) }} ر.ي</td>
                </tr>
            @endif
        </table>
    </div>
</div>

{{-- ================= Collections ================= --}}
@if($collections->count() > 0)
    <div class="section-title">سجل التحصيلات</div>
    <table class="collections">
        <thead>
            <tr>
                <th>التاريخ</th>
                <th>طريقة الدفع</th>
                <th>المرجع</th>
                <th>المبلغ</th>
            </tr>
        </thead>
        <tbody>
            @foreach($collections as $col)
                <tr>
                    <td>{{ $col->collection_date?->format('Y-m-d') }}</td>
                    <td class="payment-{{ $col->payment_method }}">
                        @switch($col->payment_method)
                            @case('cash') نقد @break
                            @case('bank_transfer') تحويل بنكي @break
                            @case('amial_pay') أميال باي @break
                            @case('check') شيك @break
                            @default {{ $col->payment_method }}
                        @endswitch
                    </td>
                    <td>{{ $col->reference_number ?? '—' }}</td>
                    <td class="amount" style="color: #059669;">{{ number_format($col->amount, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

{{-- ================= Footer ================= --}}
<div class="footer">
    <div>شكراً لتعاملكم معنا</div>
    @if($invoice->status === 'paid')
        <div class="stamp" style="border-color: #059669; color: #059669;">✓ مدفوعة بالكامل</div>
    @elseif($invoice->status === 'voided')
        <div class="stamp" style="border-color: #DC2626; color: #DC2626;">✗ فاتورة مُبطَلة</div>
    @endif
    <div style="margin-top: 8px;">
        تمّ التوليد: {{ now()->format('Y-m-d H:i') }} • أُنشئت بواسطة Amyal Pay
    </div>
</div>

</body>
</html>
