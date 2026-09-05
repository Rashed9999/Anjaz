<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<style>
    * { box-sizing: border-box; font-family: dejavusans, sans-serif; }
    body { width: {{ $width - 12 }}pt; margin: 0; color: #000; font-size: {{ $widthMm >= 80 ? '9.5' : '8' }}px; line-height: 1.35; direction: rtl; }
    .c { text-align: center; }
    .b { font-weight: bold; }
    .brand { font-size: {{ $widthMm >= 80 ? '15' : '12.5' }}px; font-weight: bold; }
    .title { font-size: {{ $widthMm >= 80 ? '12' : '10' }}px; font-weight: bold; margin-top: 2px; }
    .muted { font-size: {{ $widthMm >= 80 ? '8.5' : '7' }}px; }
    .hr { border-top: 1px dashed #000; margin: 5px 0; }
    table { width: 100%; border-collapse: collapse; }
    td, th { padding: 1.5px 0; vertical-align: top; font-size: {{ $widthMm >= 80 ? '9' : '7.5' }}px; }
    .left { text-align: left; direction: ltr; unicode-bidi: embed; }
    .amount { font-size: {{ $widthMm >= 80 ? '19' : '15' }}px; font-weight: bold; direction: ltr; unicode-bidi: embed; }
    .items th { border-bottom: 1px solid #000; font-weight: bold; }
    .items td { border-bottom: 1px dotted #777; }
    .item { width: 48%; }
    .qty { width: 15%; text-align: center; direction: ltr; }
    .money { width: 37%; text-align: left; direction: ltr; unicode-bidi: embed; }
    .qr { margin: 4px auto 0; }
    .logo { max-width: {{ $widthMm >= 80 ? '90' : '70' }}px; max-height: {{ $widthMm >= 80 ? '48' : '38' }}px; }
</style>
</head>
<body>
    @if($document['kind'] === 'merchant_invoice')
        @if(!empty($document['seller']['logo_data']))<div class="c"><img class="logo" src="{{ $document['seller']['logo_data'] }}" alt="logo"></div>@endif
        <div class="c brand">{{ $document['seller']['name'] }}</div>
        @if(!empty($document['seller']['header_note']))<div class="c muted">{{ $document['seller']['header_note'] }}</div>@endif
        @if(!empty($document['seller']['phone']))<div class="c muted">{{ $document['seller']['phone'] }}</div>@endif
        @if(!empty($document['seller']['address']))<div class="c muted">{{ $document['seller']['address'] }}</div>@endif
    @else
        @if(!empty($document['platform_logo_data']))<div class="c"><img class="logo" src="{{ $document['platform_logo_data'] }}" alt="Amial Pay"></div>@endif
        <div class="c brand">أميال باي</div>
    @endif

    <div class="c title">{{ $document['title'] }}</div>
    <div class="hr"></div>
    <table>
        <tr><td>الرقم</td><td class="left b">{{ $document['document_number'] }}</td></tr>
        <tr><td>التاريخ</td><td class="left">{{ $document['issued_at']?->format('Y-m-d H:i') }}</td></tr>
        <tr><td>الحالة</td><td class="left b">{{ $document['status_label'] }}</td></tr>
        @if($document['kind'] === 'merchant_invoice')<tr><td>الدفع</td><td class="left">{{ $document['payment_method'] }}</td></tr>@endif
    </table>

    <div class="hr"></div>

    @if($document['kind'] === 'merchant_invoice')
        <table class="items">
            <thead><tr><th class="item">الصنف</th><th class="qty">الكمية</th><th class="money">الإجمالي</th></tr></thead>
            <tbody>
            @foreach($document['items'] as $item)
                <tr>
                    <td class="item">{{ $item['name'] }}@if(!empty($item['unit']))<div class="muted">{{ $item['unit'] }}</div>@endif</td>
                    <td class="qty">{{ rtrim(rtrim(number_format((float) $item['quantity'], 3, '.', ''), '0'), '.') }}</td>
                    <td class="money">{{ number_format((float) $item['line_total'], 2) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        @foreach($document['context_fields'] as $field)
            <table><tr><td>{{ $field['label'] }}</td><td class="left">{{ $field['value'] }}</td></tr></table>
        @endforeach
        <div class="hr"></div>
        <table>
            @if((float) $document['discount'] > 0)<tr><td>الخصم</td><td class="left">- {{ number_format((float) $document['discount'], 2) }}</td></tr>@endif
            @if((float) $document['tax'] > 0)<tr><td>الضريبة</td><td class="left">{{ number_format((float) $document['tax'], 2) }}</td></tr>@endif
            <tr><td class="b">الإجمالي</td><td class="left b">{{ number_format((float) $document['total'], 2) }} {{ $document['currency'] }}</td></tr>
            {{-- AMIAL-CASH-TENDERED-001 — المستلَمُ والباقي على الشريط الحراريّ. --}}
            @foreach($document['tendered_lines'] ?? [] as $line)<tr><td>{{ $line['label'] }}</td><td class="left">{{ number_format((float) $line['value'], 2) }} {{ $document['currency'] }}</td></tr>@endforeach
            @if((float) $document['balance_due'] > 0)<tr><td>المتبقي</td><td class="left b">{{ number_format((float) $document['balance_due'], 2) }} {{ $document['currency'] }}</td></tr>@endif
        </table>
    @else
        <div class="c muted">{{ $document['operation_label'] }}</div>
        <div class="c amount">{{ number_format((float) $document['amount'], 2) }} {{ $document['currency'] }}</div>
        <table>
            <tr><td>من</td><td class="left">{{ $document['from']['name'] ?? '—' }}</td></tr>
            <tr><td>إلى</td><td class="left">{{ $document['to']['name'] ?? '—' }}</td></tr>
            <tr><td>الرسوم</td><td class="left">{{ number_format((float) $document['fee'], 2) }}</td></tr>
            <tr><td class="b">{{ $document['final_label'] }}</td><td class="left b">{{ number_format((float) $document['final_amount'], 2) }} {{ $document['currency'] }}</td></tr>
            @foreach($document['context_fields'] as $field)<tr><td>{{ $field['label'] }}</td><td class="left">{{ $field['value'] }}</td></tr>@endforeach
        </table>
    @endif

    <div class="hr"></div>
    <table><tr><td>مرجع العملية</td><td class="left muted">{{ $document['transaction_number'] }}</td></tr></table>
    <div class="c muted">التحقق: {{ $document['verification_code'] }}</div>
    {{-- **ورمزٌ بلا موضعٍ يُكتب فيه لا يُتحقَّق منه.** --}}
    <div class="c muted" dir="ltr">{{ rtrim(config('app.url', 'https://amialpay.com'), '/') }}/verify</div>
    @if(!empty($qrDataUri))<div class="c"><img class="qr" src="{{ $qrDataUri }}" width="{{ $widthMm >= 80 ? 92 : 74 }}" alt="QR"></div>@endif
    <div class="hr"></div>
    {{-- AMIAL-SHIFT-GATE-001 — اسمُ فاتح الوردية أسفل الفاتورة.
         ولا يُطبَع سطرٌ فارغٌ لبيعةٍ لا وردية لها: فراغٌ باسم «الوردية»
         يُقرأ «لم يقبضها أحد». (القاعدة السابعة.) --}}
    @if(!empty($document['shift_line']))
        <div class="c muted">{{ $document['shift_line']['label'] }}: {{ $document['shift_line']['value'] }}</div>
    @endif
    <div class="c muted">
        @if($document['kind'] === 'merchant_invoice')
            {{ $document['seller']['footer_note'] }}
        @else
            سند إلكتروني - الطباعة لا تنشئ معاملة جديدة
        @endif
    </div>
    <div class="c muted">أميال باي</div>
</body>
</html>
