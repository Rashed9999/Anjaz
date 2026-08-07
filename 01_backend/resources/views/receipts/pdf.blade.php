{{--
    AMIAL-RECEIPTS-001 (v0.9-A)

    قالب PDF للإيصال. يُحمَّل بواسطة DomPDF.
    خصائص مهمة:
      - RTL للنصوص العربية
      - استخدام inline styles فقط (DomPDF محدود في CSS)
      - QR code يُولَّد عبر library إن وجد، أو يُستبدَل بـ verification_code text

    Variables المُمررة:
      - $receipt: Receipt model
      - $user: مالك الإيصال
      - $counterparty: الطرف الآخر (nullable)
      - $qrUrl: URL للتحقق
--}}
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>إيصال {{ $receipt->receipt_number }}</title>
    <style>
        @page { margin: 20mm 15mm; size: A5 portrait; }
        * { box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 11px;
            color: #1a1a1a;
            margin: 0;
            padding: 0;
            direction: rtl;
        }

        .header {
            background: #FECA1E;
            color: #053391;
            padding: 14px 16px;
            text-align: center;
            border-radius: 8px;
            margin-bottom: 16px;
        }
        .brand { font-size: 22px; font-weight: bold; letter-spacing: 1px; }
        .tagline { font-size: 10px; margin-top: 2px; opacity: 0.85; }

        .receipt-no {
            background: #f5f5f5;
            padding: 8px 12px;
            border-radius: 4px;
            border-right: 3px solid #053391;
            font-family: monospace;
            font-size: 12px;
            margin-bottom: 12px;
        }

        .type-badge {
            display: inline-block;
            padding: 4px 10px;
            background: #053391;
            color: white;
            border-radius: 4px;
            font-size: 11px;
            font-weight: bold;
        }

        .amount-section {
            text-align: center;
            padding: 18px;
            background: #FFF8E1;
            border-radius: 8px;
            margin: 16px 0;
        }
        .amount-label { font-size: 11px; color: #666; }
        .amount-value {
            font-size: 28px;
            font-weight: bold;
            color: #053391;
            margin: 4px 0;
        }
        .amount-direction {
            font-size: 11px;
            color: #888;
        }
        .amount-direction.debit { color: #DC0A0B; }
        .amount-direction.credit { color: #2E7D32; }

        table.details {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
        }
        table.details td {
            padding: 6px 4px;
            border-bottom: 1px solid #eee;
            vertical-align: top;
        }
        table.details td.label {
            color: #666;
            width: 35%;
            font-size: 10px;
        }
        table.details td.value {
            font-weight: 600;
        }

        .footer {
            margin-top: 24px;
            padding-top: 12px;
            border-top: 1px dashed #ccc;
            text-align: center;
            font-size: 9px;
            color: #888;
        }
        .qr-section {
            text-align: center;
            margin: 16px 0;
        }
        .verification-code {
            font-family: monospace;
            font-size: 14px;
            letter-spacing: 1px;
            background: #f5f5f5;
            padding: 8px 16px;
            display: inline-block;
            border-radius: 4px;
            color: #053391;
            font-weight: bold;
        }
        .qr-help {
            font-size: 9px;
            color: #888;
            margin-top: 6px;
        }

        .badge-status {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 9px;
            font-weight: bold;
        }
    </style>
</head>
<body>

<div class="header">
    <div class="brand">AMIAL PAY</div>
    <div class="tagline">دفع سريع وآمن</div>
</div>

<div class="receipt-no">
    رقم الإيصال:
    <strong>{{ $receipt->receipt_number }}</strong>
</div>

<div style="text-align:center;margin-bottom:12px">
    <span class="type-badge">
        @switch($receipt->receipt_type)
            @case('send_money') تحويل أموال @break
            @case('cash_in') إيداع نقدي @break
            @case('cash_out') سحب نقدي @break
            @case('add_money') إضافة رصيد @break
            @case('withdraw') طلب سحب @break
            @case('pay_merchant') دفع لتاجر @break
            @case('pos_payment') دفع نقطة بيع @break
            @case('qr_payment') دفع QR @break
            @case('refund') استرجاع @break
            @case('safe_payment_funded') تجميد دفع آمن @break
            @case('safe_payment_released') إفراج دفع آمن @break
            @case('safe_payment_refunded') إعادة دفع آمن @break
            @case('split_bill_payment') تقسيم فاتورة @break
            @case('family_fund_contribute') مساهمة في صندوق عائلي @break
            @case('family_fund_disburse') صرف من صندوق عائلي @break
            @case('bank_settlement') تسوية بنكية @break
            @case('fee_charge') رسوم خدمة @break
            @default {{ $receipt->receipt_type }}
        @endswitch
    </span>
</div>

<div class="amount-section">
    <div class="amount-label">المبلغ</div>
    <div class="amount-value">
        {{ number_format((float)$receipt->amount, 2) }}
        <span style="font-size:14px">ر.س</span>
    </div>
    <div class="amount-direction {{ $receipt->direction }}">
        @if($receipt->direction === 'debit')
            ▼ مخصوم من حسابك
        @else
            ▲ مضاف إلى حسابك
        @endif
    </div>
</div>

<table class="details">
    @if($user)
        <tr>
            <td class="label">صاحب الإيصال:</td>
            <td class="value">
                {{ $user->f_name }} {{ $user->l_name }}
                <br><small style="color:#888">{{ $user->phone }}</small>
            </td>
        </tr>
    @endif

    @if($counterparty)
        <tr>
            <td class="label">
                @if($receipt->direction === 'debit') المستفيد @else المرسل @endif:
            </td>
            <td class="value">
                {{ $counterparty->f_name }} {{ $counterparty->l_name }}
                <br><small style="color:#888">{{ substr($counterparty->phone ?? '', 0, 4) }}***{{ substr($counterparty->phone ?? '', -3) }}</small>
            </td>
        </tr>
    @endif

    @if((float)$receipt->fee > 0)
        <tr>
            <td class="label">رسوم الخدمة:</td>
            <td class="value">{{ number_format((float)$receipt->fee, 2) }} ر.س</td>
        </tr>
        <tr>
            <td class="label">الإجمالي:</td>
            <td class="value" style="color:#053391">
                {{ number_format((float)$receipt->net_amount, 2) }} ر.س
            </td>
        </tr>
    @endif

    <tr>
        <td class="label">المعاملة:</td>
        <td class="value" style="font-family:monospace;font-size:10px">{{ $receipt->reference_transaction_id }}</td>
    </tr>

    <tr>
        <td class="label">التاريخ:</td>
        <td class="value">
            {{ $receipt->issued_at?->format('Y-m-d H:i:s') }}
            <br><small style="color:#888">{{ $receipt->issued_at?->locale('ar')->diffForHumans() }}</small>
        </td>
    </tr>

    <tr>
        <td class="label">المنطقة:</td>
        <td class="value">{{ $receipt->zone_code }}</td>
    </tr>
</table>

@if($receipt->metadata && is_array($receipt->metadata) && !empty($receipt->metadata))
    <table class="details">
        @foreach($receipt->metadata as $key => $value)
            @if(is_scalar($value) && !empty($value))
                <tr>
                    <td class="label">{{ str_replace('_', ' ', $key) }}:</td>
                    <td class="value">{{ $value }}</td>
                </tr>
            @endif
        @endforeach
    </table>
@endif

<div class="qr-section">
    <div class="verification-code">{{ $receipt->verification_code }}</div>
    <div class="qr-help">
        كود التحقق من صحة الإيصال<br>
        امسح أو افتح: {{ $qrUrl }}
    </div>
</div>

<div class="footer">
    <strong>Amial Pay</strong> — نظام دفع رقمي معتمد
    <br>
    هذا الإيصال صادر إلكترونياً ومحفوظ في سجلاتنا.
    <br>
    للاستفسار: support@amialpay.com
</div>

</body>
</html>
