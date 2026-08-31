<!doctype html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8">
  <style>
    * { box-sizing: border-box; }
    body { font-family: dejavusans, sans-serif; direction: rtl; color: #172033; font-size: 10pt; }
    table { width: 100%; border-collapse: collapse; }
    .header { border-bottom: 3px solid #053391; padding-bottom: 12px; margin-bottom: 14px; }
    .brand { font-size: 20pt; font-weight: bold; color: #053391; }
    .title { text-align: left; font-size: 24pt; font-weight: bold; color: #053391; }
    .muted { color: #667085; font-size: 9pt; }
    .meta td, .party td { border: 1px solid #d9e0ec; padding: 8px; vertical-align: top; }
    .meta .k { color: #667085; font-size: 8.5pt; }
    .meta .v { font-weight: bold; margin-top: 2px; }
    .items { margin-top: 14px; }
    .items th { background: #053391; color: white; padding: 8px 6px; text-align: center; }
    .items td { border: 1px solid #d9e0ec; padding: 7px 6px; }
    .center { text-align: center; } .left { text-align: left; direction: ltr; }
    .summary { margin-top: 14px; width: 42%; margin-right: auto; }
    .summary td { padding: 6px 8px; border-bottom: 1px solid #e5e7eb; }
    .summary .grand td { background: #eaf1ff; border-top: 2px solid #053391; font-size: 13pt; color: #053391; font-weight: bold; }
    .notice { margin-top: 20px; padding: 10px; background: #f7f9fc; border-right: 3px solid #f4b223; color: #475467; }
    .footer { margin-top: 22px; border-top: 1px solid #d9e0ec; padding-top: 8px; color: #667085; text-align: center; font-size: 8.5pt; }
  </style>
</head>
<body>
  <table class="header"><tr>
    <td>
      <div class="brand">{{ $merchant?->store_name ?: 'منشأة التاجر' }}</div>
      @if(!empty($merchant?->merchant_number))<div class="muted">رقم التاجر: {{ $merchant->merchant_number }}</div>@endif
      @if(!empty($merchant?->address))<div class="muted">{{ $merchant->address }}</div>@endif
    </td>
    <td class="title">{{ $title }}</td>
  </tr></table>

  <table class="meta"><tr>
    <td><div class="k">رقم الفاتورة</div><div class="v left">SALE-{{ strtoupper(substr($sale->sale_ulid, -10)) }}</div></td>
    <td><div class="k">تاريخ الإصدار</div><div class="v left">{{ $sale->created_at?->format('Y-m-d H:i') }}</div></td>
    <td><div class="k">طريقة الدفع</div><div class="v">{{ $paymentLabel }}</div></td>
    <td><div class="k">الحالة</div><div class="v">{{ $statusLabel }}</div></td>
  </tr></table>

  <table class="party" style="margin-top:12px"><tr>
    <td><strong>العميل</strong><br>{{ $sale->customer_name ?: 'عميل نقدي' }}@if($sale->customer_phone)<br><span class="muted">{{ $sale->customer_phone }}</span>@endif</td>
    <td><strong>مرجع البيع</strong><br><span class="left">{{ $sale->sale_ulid }}</span></td>
  </tr></table>

  <table class="items"><thead><tr>
    <th style="width:6%">#</th><th>الصنف</th>
    @if($vertical === 'retail')<th style="width:16%">الباركود</th>@endif
    <th style="width:11%">الكمية</th><th style="width:16%">سعر الوحدة</th><th style="width:16%">الإجمالي</th>
  </tr></thead><tbody>
    @forelse($items as $i => $item)<tr>
      <td class="center">{{ $i + 1 }}</td><td>{{ $item['name'] }}</td>
      @if($vertical === 'retail')<td class="left">{{ $item['barcode'] ?: '—' }}</td>@endif
      <td class="center">{{ rtrim(rtrim(number_format((float)$item['quantity'], 3, '.', ''), '0'), '.') }}</td>
      <td class="left">{{ number_format((float)$item['unit_price'], 2) }}</td><td class="left"><strong>{{ number_format((float)$item['total'], 2) }}</strong></td>
    </tr>@empty<tr><td colspan="{{ $vertical === 'retail' ? 6 : 5 }}" class="center">لا توجد بنود مسجلة</td></tr>@endforelse
  </tbody></table>

  <table class="summary">
    <tr><td>المجموع الفرعي</td><td class="left">{{ number_format((float)$subtotal, 2) }} ر.ي</td></tr>
    @if((float)$discount > 0)<tr><td>الخصم</td><td class="left">- {{ number_format((float)$discount, 2) }} ر.ي</td></tr>@endif
    <tr class="grand"><td>الإجمالي</td><td class="left">{{ number_format((float)$total, 2) }} ر.ي</td></tr>
  </table>

  @if($sale->status === 'credit_unpaid')<div class="notice">هذه فاتورة بيع آجل. يبقى السداد والتسوية مرتبطين بسجل الدين ولا تنشئ إعادة طباعة الفاتورة التزاماً جديداً.</div>@endif
  <div class="footer">فاتورة إلكترونية محفوظة في سجل المنشأة. إعادة التنزيل أو الطباعة لا تنشئ عملية بيع أو دفع جديدة.</div>
</body></html>
