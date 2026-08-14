<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>{{ $document['title'] }} {{ $document['document_number'] }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            color: #172033;
            direction: rtl;
            font-family: dejavusans, sans-serif;
            font-size: 10.5pt;
            line-height: 1.55;
        }
        .top-rule { height: 7px; background: #0b3f98; margin-bottom: 15px; }
        table { border-collapse: collapse; width: 100%; }
        .header td { vertical-align: middle; }
        .logo { width: 116px; max-height: 66px; }
        .brand-fallback { color: #0b3f98; font-size: 23pt; font-weight: bold; }
        .doc-name { text-align: left; }
        .doc-name h1 { margin: 0; color: #0b3f98; font-size: 22pt; }
        .doc-name p { margin: 2px 0 0; color: #697386; font-size: 9pt; }
        .meta {
            margin-top: 16px;
            border: 1px solid #dce3ef;
            background: #f7f9fc;
        }
        .meta td { width: 33.333%; padding: 8px 10px; border-left: 1px solid #dce3ef; }
        .meta td:last-child { border-left: 0; }
        .k { color: #697386; font-size: 8.5pt; }
        .v { color: #172033; font-weight: bold; margin-top: 2px; }
        .ltr { direction: ltr; unicode-bidi: embed; font-family: dejavusansmono, monospace; }
        .status {
            display: inline-block;
            color: #0f6b46;
            background: #e8f7ef;
            border: 1px solid #b9e7d0;
            border-radius: 12px;
            padding: 2px 10px;
            font-size: 8.5pt;
            font-weight: bold;
        }
        .amount-card {
            margin: 18px 0;
            padding: 17px 22px;
            border: 1px solid #dce3ef;
            border-right: 6px solid #f4c430;
            background: #ffffff;
        }
        .amount-table td { vertical-align: middle; }
        .amount-main { color: #0b3f98; font-size: 27pt; font-weight: bold; }
        .amount-words { color: #4b566b; font-size: 9pt; margin-top: 5px; }
        .amount-side { text-align: left; width: 34%; }
        .amount-side .final { color: #172033; font-size: 15pt; font-weight: bold; }
        .section-title {
            color: #0b3f98;
            font-size: 11pt;
            font-weight: bold;
            margin: 17px 0 7px;
            padding-bottom: 5px;
            border-bottom: 2px solid #e8edf5;
        }
        .parties td {
            width: 50%;
            vertical-align: top;
            border: 1px solid #dce3ef;
            padding: 11px 13px;
        }
        .party-title { color: #697386; font-size: 8.5pt; margin-bottom: 4px; }
        .party-name { color: #172033; font-size: 12pt; font-weight: bold; }
        .party-line { color: #596579; font-size: 9pt; margin-top: 2px; }
        .details td { padding: 7px 9px; border-bottom: 1px solid #e8edf5; }
        .details .label { width: 34%; color: #697386; }
        .details .value { font-weight: 600; }
        .totals { margin-top: 12px; width: 46%; margin-right: 54%; }
        .totals td { padding: 5px 8px; }
        .totals .label { color: #697386; }
        .totals .value { text-align: left; font-weight: bold; }
        .totals .grand td {
            border-top: 2px solid #0b3f98;
            background: #eef4ff;
            color: #0b3f98;
            font-size: 12pt;
        }
        .verification { margin-top: 20px; border-top: 1px solid #dce3ef; padding-top: 13px; }
        .verification td { vertical-align: middle; }
        .qr { width: 88px; height: 88px; }
        .verify-title { color: #0b3f98; font-weight: bold; }
        .verify-code { font-family: dejavusansmono, monospace; font-size: 12pt; letter-spacing: 1px; }
        .notice { margin-top: 18px; color: #758095; font-size: 8pt; text-align: center; }
        .footer {
            margin-top: 14px;
            padding-top: 8px;
            border-top: 1px solid #e8edf5;
            color: #697386;
            font-size: 8pt;
            text-align: center;
        }
    </style>
</head>
<body>
<div class="top-rule"></div>

<table class="header">
    <tr>
        <td>
            @if(!empty($document['platform_logo_data']))
                <img class="logo" src="{{ $document['platform_logo_data'] }}" alt="Amial Pay">
            @else
                <div class="brand-fallback">أميال باي</div>
            @endif
        </td>
        <td class="doc-name">
            <h1>{{ $document['title'] }}</h1>
            <p>{{ $document['subtitle'] }}</p>
        </td>
    </tr>
</table>

<table class="meta">
    <tr>
        <td><div class="k">رقم السند</div><div class="v ltr">{{ $document['document_number'] }}</div></td>
        <td><div class="k">تاريخ ووقت الإصدار</div><div class="v ltr">{{ $document['issued_at']?->format('Y-m-d H:i:s') }}</div></td>
        <td><div class="k">حالة العملية</div><div class="v"><span class="status">{{ $document['status_label'] }}</span></div></td>
    </tr>
</table>

<div class="amount-card">
    <table class="amount-table">
        <tr>
            <td>
                <div class="k">مبلغ العملية</div>
                <div class="amount-main ltr">{{ number_format((float) $document['amount'], 2) }} {{ $document['currency'] }}</div>
                <div class="amount-words">{{ $document['amount_words'] }}</div>
            </td>
            <td class="amount-side">
                <div class="k">{{ $document['final_label'] }}</div>
                <div class="final ltr">{{ number_format((float) $document['final_amount'], 2) }} {{ $document['currency'] }}</div>
            </td>
        </tr>
    </table>
</div>

<div class="section-title">أطراف العملية</div>
<table class="parties">
    <tr>
        @foreach([['label' => 'من', 'party' => $document['from']], ['label' => 'إلى', 'party' => $document['to']]] as $entry)
            <td>
                <div class="party-title">{{ $entry['label'] }}</div>
                @if(!empty($entry['party']))
                    <div class="party-name">{{ $entry['party']['name'] }}</div>
                    @if(!empty($entry['party']['phone']))<div class="party-line ltr">{{ $entry['party']['phone'] }}</div>@endif
                    @if(!empty($entry['party']['account']))<div class="party-line">الحساب: <span class="ltr">{{ $entry['party']['account'] }}</span></div>@endif
                @else
                    <div class="party-name">—</div>
                @endif
            </td>
        @endforeach
    </tr>
</table>

<div class="section-title">تفاصيل القيد</div>
<table class="details">
    <tr><td class="label">نوع العملية</td><td class="value">{{ $document['operation_label'] }}</td></tr>
    <tr><td class="label">رقم العملية</td><td class="value ltr">{{ $document['transaction_number'] }}</td></tr>
    <tr><td class="label">القناة</td><td class="value">{{ $document['channel_label'] }}</td></tr>
    <tr><td class="label">المنطقة</td><td class="value">{{ $document['zone_label'] }}</td></tr>
    @foreach($document['context_fields'] as $field)
        <tr><td class="label">{{ $field['label'] }}</td><td class="value">{{ $field['value'] }}</td></tr>
    @endforeach
    @if(!empty($document['note']))
        <tr><td class="label">البيان</td><td class="value">{{ $document['note'] }}</td></tr>
    @endif
</table>

<table class="totals">
    <tr><td class="label">المبلغ</td><td class="value ltr">{{ number_format((float) $document['amount'], 2) }} {{ $document['currency'] }}</td></tr>
    <tr><td class="label">رسوم العملية</td><td class="value ltr">{{ number_format((float) $document['fee'], 2) }} {{ $document['currency'] }}</td></tr>
    <tr class="grand"><td>{{ $document['final_label'] }}</td><td class="value ltr">{{ number_format((float) $document['final_amount'], 2) }} {{ $document['currency'] }}</td></tr>
</table>

<table class="verification">
    <tr>
        <td style="width:105px">
            @if(!empty($qrDataUri))<img class="qr" src="{{ $qrDataUri }}" alt="QR">@endif
        </td>
        <td>
            <div class="verify-title">تحقق من أصالة السند</div>
            <div class="verify-code ltr">{{ $document['verification_code'] }}</div>
            <div class="k">امسح الرمز أو أدخل كود التحقق في موقع أميال باي. لا يحتوي الرمز على بيانات مالية حساسة.</div>
        </td>
    </tr>
</table>

<div class="notice">هذا السند صادر آلياً من سجل مالي مكتمل ولا يحتاج إلى ختم أو توقيع. الطباعة أو إعادة الطباعة لا تنشئ معاملة جديدة.</div>
<div class="footer">أميال باي - مستند مالي إلكتروني - {{ config('amial.support_site', 'amialpay.com') }}</div>
</body>
</html>
