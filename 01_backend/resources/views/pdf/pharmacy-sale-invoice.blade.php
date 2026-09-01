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
    .meta .k { color: #667085; font-size: 8.5pt; }.meta .v { font-weight: bold; margin-top: 2px; }
    .items { margin-top: 14px; }.items th { background: #053391; color: white; padding: 8px 6px; text-align: center; }
    .items td { border: 1px solid #d9e0ec; padding: 7px 6px; }.center { text-align: center; }.left { text-align: left; direction: ltr; }
    .summary { margin-top: 14px; width: 42%; margin-right: auto; }.summary td { padding: 6px 8px; border-bottom: 1px solid #e5e7eb; }
    .summary .grand td { background: #eaf1ff; border-top: 2px solid #053391; font-size: 13pt; color: #053391; font-weight: bold; }
    .notice { margin-top: 16px; padding: 10px; background: #f7f9fc; border-right: 3px solid #f4b223; color: #475467; }
    .footer { margin-top: 22px; border-top: 1px solid #d9e0ec; padding-top: 8px; color: #667085; text-align: center; font-size: 8.5pt; }
  </style>
</head>
<body>
  <table class="header"><tr><td><div class="brand">{{ $merchant?->store_name ?: 'الصيدلية' }}</div>
    @if(!empty($merchant?->merchant_number))<div class="muted">رقم التاجر: {{ $merchant->merchant_number }}</div>@endif
    @if(!empty($merchant?->address))<div class="muted">{{ $merchant->address }}</div>@endif
  </td><td class="title">فاتورة صيدلية</td></tr></table>
  <table class="meta"><tr>
    <td><div class="k">رقم الفاتورة</div><div class="v left">PH-{{ strtoupper(substr($sale->sale_ulid, -10)) }}</div></td>
    <td><div class="k">تاريخ الإصدار</div><div class="v left">{{ $sale->created_at?->format('Y-m-d H:i') }}</div></td>
    <td><div class="k">طريقة الدفع</div><div class="v">{{ $paymentLabel }}</div></td>
    <td><div class="k">الحالة</div><div class="v">{{ $sale->payment_method === 'credit' ? 'آجلة — غير مسددة' : 'مكتملة' }}</div></td>
  </tr></table>
  <table class="party" style="margin-top:12px"><tr><td><strong>العميل</strong><br>{{ $sale->customer?->full_name ?: 'عميل نقدي' }}@if($sale->customer?->phone)<br><span class="muted">{{ $sale->customer->phone }}</span>@endif</td>
    <td><strong>مرجع البيع</strong><br><span class="left">{{ $sale->sale_ulid }}</span>
      @if($sale->prescription_number)<br><span class="muted">الوصفة: {{ $sale->prescription_number }}</span>@endif
      @if($sale->prescribing_doctor)<br><span class="muted">الطبيب: {{ $sale->prescribing_doctor }}</span>@endif
    </td></tr></table>
  <table class="items"><thead><tr><th style="width:5%">#</th><th>الصنف</th><th style="width:13%">التشغيلة</th><th style="width:13%">الانتهاء</th><th style="width:9%">الكمية</th><th style="width:13%">سعر الوحدة</th><th style="width:14%">الإجمالي</th></tr></thead><tbody>
  @forelse($items as $i => $item)<tr><td class="center">{{ $i + 1 }}</td><td>{{ $item['name'] }}@if($item['requires_prescription'])<br><span class="muted">بوصفة طبية</span>@endif</td><td class="center">{{ $item['batch_number'] ?: '—' }}</td><td class="center">{{ $item['expiry_date'] ?: '—' }}</td><td class="center">{{ rtrim(rtrim(number_format((float)$item['quantity'], 3, '.', ''), '0'), '.') }}</td><td class="left">{{ number_format((float)$item['unit_price'], 2) }}</td><td class="left"><strong>{{ number_format((float)$item['total'], 2) }}</strong></td></tr>
  @empty<tr><td colspan="7" class="center">لا توجد بنود مسجلة</td></tr>@endforelse</tbody></table>
  <table class="summary"><tr><td>المجموع الفرعي</td><td class="left">{{ number_format((float)$sale->subtotal, 2) }} ر.ي</td></tr>@if((float)$sale->discount_amount > 0)<tr><td>الخصم</td><td class="left">- {{ number_format((float)$sale->discount_amount, 2) }} ر.ي</td></tr>@endif<tr class="grand"><td>الإجمالي</td><td class="left">{{ number_format((float)$sale->total_amount, 2) }} ر.ي</td></tr></table>
  @if($sale->payment_method === 'credit')<div class="notice">فاتورة بيع آجل مرتبطة بدفتر ديون العميل ويمكن سدادها جزئياً أو كلياً من تطبيق أميال.</div>@endif
  <div class="footer">فاتورة صيدلية إلكترونية. إعادة التنزيل أو الطباعة لا تنشئ بيعاً أو دفعاً جديداً.</div>
</body></html>
