{{-- AMIAL-THERMAL-001: قالب إيصال حراري (58مم/80مم) لطابعات POS.
     يُمرَّر $width بالنقاط (58مم≈164، 80مم≈226) و$widthMm للعرض. --}}
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 4pt 5pt; }
    * { font-family: 'DejaVu Sans', monospace; }
    body { width: {{ $width - 12 }}pt; color:#000; font-size: {{ $widthMm >= 80 ? '10' : '8.5' }}px; line-height:1.35; }
    .c { text-align:center; }
    .b { font-weight:bold; }
    .brand { font-size: {{ $widthMm >= 80 ? '16' : '13' }}px; font-weight:bold; letter-spacing:1px; }
    .hr { border-top:1px dashed #000; margin:5px 0; }
    .row { width:100%; }
    .row td { padding:1px 0; vertical-align:top; font-size:{{ $widthMm >= 80 ? '10' : '8.5' }}px; }
    .row td.l { text-align:right; }
    .row td.r { text-align:left; }
    .amount { font-size:{{ $widthMm >= 80 ? '20' : '16' }}px; font-weight:bold; }
    .muted { color:#333; font-size:{{ $widthMm >= 80 ? '9' : '7.5' }}px; }
    .qr { margin:5px auto 0; }
</style>
</head>
<body>
    <div class="c brand">{{ \App\CentralLogics\Helpers::get_business_settings('business_name') ?? 'Amial Pay' }}</div>
    <div class="c muted">{{ translate('Payment Receipt') }}</div>
    <div class="hr"></div>

    <table class="row">
        <tr><td class="l">{{ translate('Receipt No') }}</td><td class="r b">{{ $receipt->receipt_number }}</td></tr>
        <tr><td class="l">{{ translate('Date') }}</td><td class="r">{{ $receipt->issued_at?->format('Y-m-d H:i') }}</td></tr>
        <tr><td class="l">{{ translate('Type') }}</td><td class="r">{{ translate($receipt->receipt_type) }}</td></tr>
    </table>

    <div class="hr"></div>
    <div class="c muted">{{ $receipt->direction === 'debit' ? translate('Amount Paid') : translate('Amount Received') }}</div>
    <div class="c amount">{{ number_format((float) $receipt->amount, 2) }} {{ translate('YER') }}</div>

    @if((float) $receipt->fee > 0)
    <table class="row">
        <tr><td class="l muted">{{ translate('Fee') }}</td><td class="r muted">{{ number_format((float) $receipt->fee, 2) }}</td></tr>
        <tr><td class="l muted">{{ translate('Net') }}</td><td class="r muted">{{ number_format((float) $receipt->net_amount, 2) }}</td></tr>
    </table>
    @endif

    <div class="hr"></div>
    <table class="row">
        <tr><td class="l muted">{{ translate('From') }}</td><td class="r">{{ trim(($user->f_name ?? '').' '.($user->l_name ?? '')) ?: ($user->phone ?? '—') }}</td></tr>
        @if($counterparty)
        <tr><td class="l muted">{{ translate('To') }}</td><td class="r">{{ trim(($counterparty->f_name ?? '').' '.($counterparty->l_name ?? '')) ?: '—' }}</td></tr>
        @endif
        @if($receipt->reference_transaction_id)
        <tr><td class="l muted">{{ translate('Ref') }}</td><td class="r muted">{{ $receipt->reference_transaction_id }}</td></tr>
        @endif
    </table>

    <div class="hr"></div>
    <div class="c muted">{{ translate('Verify') }}: {{ $receipt->verification_code }}</div>
    @if(!empty($qrUrl))
    <div class="c"><img class="qr" src="{{ $qrUrl }}" width="{{ $widthMm >= 80 ? 110 : 90 }}" alt="qr"></div>
    @endif
    <div class="hr"></div>
    <div class="c muted">{{ translate('Thank you') }} — {{ translate('Keep this receipt') }}</div>
</body>
</html>
