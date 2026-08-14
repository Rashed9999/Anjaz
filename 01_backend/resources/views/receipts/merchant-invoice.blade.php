<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>{{ $document['title'] }} {{ $document['document_number'] }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; color: #172033; direction: rtl; font-family: dejavusans, sans-serif; font-size: 9.3pt; line-height: 1.45; }
        table { width: 100%; border-collapse: collapse; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        .top { height: 7px; background: #0b3f98; margin-bottom: 14px; }
        .header td { vertical-align: top; }
        .logo { max-width: 92px; max-height: 58px; margin-bottom: 5px; }
        .seller-name { color: #0b3f98; font-size: 18pt; font-weight: bold; }
        .seller-meta { color: #667085; font-size: 8.5pt; margin-top: 2px; }
        .doc-title { text-align: left; }
        .doc-title h1 { color: #0b3f98; margin: 0; font-size: 21pt; }
        .vertical { color: #667085; font-size: 9pt; margin-top: 2px; }
        .status { display: inline-block; margin-top: 7px; padding: 3px 10px; border: 1px solid #b9e7d0; background: #e8f7ef; color: #0f6b46; border-radius: 11px; font-weight: bold; }
        .meta { margin: 15px 0; border: 1px solid #dce3ef; background: #f7f9fc; }
        .meta td { width: 25%; padding: 7px 9px; border-left: 1px solid #dce3ef; }
        .meta td:last-child { border-left: 0; }
        .k { color: #697386; font-size: 8pt; }
        .v { color: #172033; font-weight: bold; margin-top: 2px; }
        .ltr { direction: ltr; unicode-bidi: embed; font-family: dejavusansmono, monospace; }
        .parties { margin-bottom: 14px; }
        .parties td { width: 50%; vertical-align: top; padding: 10px 12px; border: 1px solid #dce3ef; }
        .party-title { color: #697386; font-size: 8pt; margin-bottom: 4px; }
        .party-name { font-size: 11pt; font-weight: bold; }
        .party-line { color: #5c677a; font-size: 8.5pt; margin-top: 2px; }
        .context { margin-bottom: 12px; background: #fffaf0; border: 1px solid #f0dfad; }
        .context td { padding: 6px 8px; }
        .context .label { color: #776228; width: 18%; }
        .items th { background: #0b3f98; color: #fff; padding: 7px 5px; text-align: right; font-size: 8.5pt; }
        .items td { padding: 7px 5px; border-bottom: 1px solid #e6ebf2; vertical-align: top; }
        .items tbody tr:nth-child(even) td { background: #fafbfd; }
        .items .center { text-align: center; }
        .items .money { text-align: left; direction: ltr; unicode-bidi: embed; white-space: nowrap; }
        .item-sub { color: #7b8698; font-size: 7.5pt; margin-top: 2px; }
        .summary { margin-top: 14px; }
        .summary .note-cell { width: 52%; vertical-align: top; padding-left: 14px; }
        .summary .totals-cell { width: 48%; vertical-align: top; }
        .note { border-right: 3px solid #f4c430; background: #fffaf0; padding: 8px 10px; color: #665522; }
        .totals td { padding: 5px 7px; }
        .totals .label { color: #697386; }
        .totals .money { text-align: left; direction: ltr; font-family: dejavusansmono, monospace; font-weight: bold; }
        .totals .grand td { border-top: 2px solid #0b3f98; background: #eef4ff; color: #0b3f98; font-size: 11pt; font-weight: bold; }
        .totals .balance td { color: #a93434; font-weight: bold; }
        .words { margin-top: 12px; padding: 8px 10px; background: #f7f9fc; border-right: 3px solid #0b3f98; }
        .verify { margin-top: 17px; border-top: 1px solid #dce3ef; padding-top: 10px; }
        .verify td { vertical-align: middle; }
        .qr { width: 80px; height: 80px; }
        .verify-code { color: #0b3f98; font-family: dejavusansmono, monospace; font-size: 10pt; font-weight: bold; }
        .legal { margin-top: 13px; text-align: center; color: #7b8698; font-size: 7.5pt; border-top: 1px solid #e6ebf2; padding-top: 8px; }
    </style>
</head>
<body>
<div class="top"></div>
<table class="header">
    <tr>
        <td>
            @if(!empty($document['seller']['logo_data']))
                <img class="logo" src="{{ $document['seller']['logo_data'] }}" alt="logo">
            @elseif(!empty($document['platform_logo_data']))
                <img class="logo" src="{{ $document['platform_logo_data'] }}" alt="Amial Pay">
            @endif
            <div class="seller-name">{{ $document['seller']['name'] }}</div>
            @if(!empty($document['seller']['header_note']))<div class="seller-meta">{{ $document['seller']['header_note'] }}</div>@endif
            @if(!empty($document['seller']['phone']))<div class="seller-meta ltr">{{ $document['seller']['phone'] }}</div>@endif
            @if(!empty($document['seller']['address']))<div class="seller-meta">{{ $document['seller']['address'] }}</div>@endif
        </td>
        <td class="doc-title">
            <h1>{{ $document['title'] }}</h1>
            <div class="vertical">{{ $document['vertical_label'] }}</div>
            <span class="status">{{ $document['status_label'] }}</span>
        </td>
    </tr>
</table>

<table class="meta">
    <tr>
        <td><div class="k">رقم الفاتورة</div><div class="v ltr">{{ $document['document_number'] }}</div></td>
        <td><div class="k">تاريخ الإصدار</div><div class="v ltr">{{ $document['issued_at']?->format('Y-m-d H:i') }}</div></td>
        <td><div class="k">طريقة الدفع</div><div class="v">{{ $document['payment_method'] }}</div></td>
        <td><div class="k">مرجع أميال</div><div class="v ltr">{{ $document['receipt_number'] }}</div></td>
    </tr>
</table>

<table class="parties">
    <tr>
        <td>
            <div class="party-title">البائع</div>
            <div class="party-name">{{ $document['seller']['name'] }}</div>
            @if(!empty($document['seller']['registration_number']))<div class="party-line">السجل/الترخيص: <span class="ltr">{{ $document['seller']['registration_number'] }}</span></div>@endif
            @if(!empty($document['seller']['tax_number']))<div class="party-line">الرقم الضريبي: <span class="ltr">{{ $document['seller']['tax_number'] }}</span></div>@endif
        </td>
        <td>
            <div class="party-title">العميل</div>
            @if(!empty($document['customer']))
                <div class="party-name">{{ $document['customer']['name'] }}</div>
                @if(!empty($document['customer']['phone']))<div class="party-line ltr">{{ $document['customer']['phone'] }}</div>@endif
                @if(!empty($document['customer']['address']))<div class="party-line">{{ $document['customer']['address'] }}</div>@endif
                @if(!empty($document['customer']['tax_number']))<div class="party-line">الرقم الضريبي: <span class="ltr">{{ $document['customer']['tax_number'] }}</span></div>@endif
            @else
                <div class="party-name">عميل نقدي</div>
            @endif
        </td>
    </tr>
</table>

@if(!empty($document['context_fields']))
    <table class="context">
        @foreach(array_chunk($document['context_fields'], 2) as $pair)
            <tr>
                @foreach($pair as $field)
                    <td class="label">{{ $field['label'] }}</td><td><strong>{{ $field['value'] }}</strong></td>
                @endforeach
                @if(count($pair) === 1)<td></td><td></td>@endif
            </tr>
        @endforeach
    </table>
@endif

<table class="items">
    <thead>
        <tr>
            <th style="width:5%">#</th>
            <th>الصنف / البيان</th>
            @if($document['vertical'] === 'pharmacy')<th style="width:14%">التشغيلة / الانتهاء</th>@endif
            @if(in_array($document['vertical'], ['retail', 'wholesale'], true))<th style="width:12%">الرمز / الوحدة</th>@endif
            <th style="width:10%" class="center">الكمية</th>
            <th style="width:15%">سعر الوحدة</th>
            @if((float) $document['discount'] > 0 || $document['vertical'] === 'wholesale')<th style="width:12%">الخصم</th>@endif
            <th style="width:16%">الإجمالي</th>
        </tr>
    </thead>
    <tbody>
        @foreach($document['items'] as $index => $item)
            <tr>
                <td class="center">{{ $index + 1 }}</td>
                <td>
                    <strong>{{ $item['name'] }}</strong>
                    @if(!empty($item['notes']))<div class="item-sub">{{ $item['notes'] }}</div>@endif
                </td>
                @if($document['vertical'] === 'pharmacy')
                    <td>
                        <div class="ltr">{{ $item['batch_number'] ?: '—' }}</div>
                        <div class="item-sub ltr">{{ $item['expiry_date'] ?: '—' }}</div>
                    </td>
                @endif
                @if(in_array($document['vertical'], ['retail', 'wholesale'], true))
                    <td><div class="ltr">{{ $item['sku'] ?: '—' }}</div><div class="item-sub">{{ $item['unit'] ?: '—' }}</div></td>
                @endif
                <td class="center ltr">{{ rtrim(rtrim(number_format((float) $item['quantity'], 3, '.', ''), '0'), '.') }}</td>
                <td class="money">{{ number_format((float) $item['unit_price'], 2) }}</td>
                @if((float) $document['discount'] > 0 || $document['vertical'] === 'wholesale')<td class="money">{{ number_format((float) $item['discount'], 2) }}</td>@endif
                <td class="money"><strong>{{ number_format((float) $item['line_total'], 2) }}</strong></td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="summary">
    <tr>
        <td class="note-cell">
            @if(!empty($document['note']))<div class="note"><strong>ملاحظات</strong><br>{{ $document['note'] }}</div>@endif
        </td>
        <td class="totals-cell">
            <table class="totals">
                <tr><td class="label">المجموع الفرعي</td><td class="money">{{ number_format((float) $document['subtotal'], 2) }} {{ $document['currency'] }}</td></tr>
                @if((float) $document['discount'] > 0)<tr><td class="label">الخصم</td><td class="money">- {{ number_format((float) $document['discount'], 2) }} {{ $document['currency'] }}</td></tr>@endif
                @if((float) $document['tax'] > 0)<tr><td class="label">الضريبة</td><td class="money">{{ number_format((float) $document['tax'], 2) }} {{ $document['currency'] }}</td></tr>@endif
                <tr class="grand"><td>الإجمالي</td><td class="money">{{ number_format((float) $document['total'], 2) }} {{ $document['currency'] }}</td></tr>
                @if((float) $document['balance_due'] > 0)<tr><td class="label">المدفوع</td><td class="money">{{ number_format((float) $document['paid'], 2) }} {{ $document['currency'] }}</td></tr><tr class="balance"><td>المتبقي</td><td class="money">{{ number_format((float) $document['balance_due'], 2) }} {{ $document['currency'] }}</td></tr>@endif
            </table>
        </td>
    </tr>
</table>

<div class="words"><span class="k">الإجمالي كتابةً</span><br><strong>{{ $document['amount_words'] }}</strong></div>

<table class="verify">
    <tr>
        <td style="width:96px">@if(!empty($qrDataUri))<img class="qr" src="{{ $qrDataUri }}" alt="QR">@endif</td>
        <td><strong>التحقق من الفاتورة</strong><div class="verify-code ltr">{{ $document['verification_code'] }}</div><div class="k">يرتبط الرمز بمعاملة أميال باي ولا يكشف بيانات حساسة.</div></td>
        <td style="text-align:left"><div class="k">رقم العملية</div><div class="v ltr">{{ $document['transaction_number'] }}</div></td>
    </tr>
</table>

<div class="legal">{{ $document['seller']['footer_note'] }}<br>فاتورة إلكترونية محفوظة في سجلات النشاط وأميال باي. إعادة الطباعة لا تنشئ عملية دفع جديدة.</div>
</body>
</html>
