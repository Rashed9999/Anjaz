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
    .merchant-logo { max-width: 92px; max-height: 58px; margin-bottom: 5px; }
    .title { text-align: left; font-size: 24pt; font-weight: bold; color: #053391; }
    .muted { color: #667085; font-size: 9pt; }
    .meta td, .party td { border: 1px solid #d9e0ec; padding: 8px; vertical-align: top; }
    .meta .k { color: #667085; font-size: 8.5pt; }
    .meta .v { font-weight: bold; margin-top: 2px; }
    .items { margin-top: 14px; }
    .items th { background: #053391; color: white; padding: 8px 6px; text-align: center; }
    .items td { border: 1px solid #d9e0ec; padding: 7px 6px; }
    .center { text-align: center; }
    .left { text-align: left; direction: ltr; }
    .summary { margin-top: 14px; width: 44%; margin-right: auto; }
    .summary td { padding: 6px 8px; border-bottom: 1px solid #e5e7eb; }
    .summary .grand td { background: #eaf1ff; border-top: 2px solid #053391; font-size: 13pt; color: #053391; font-weight: bold; }
    .summary .remaining td { color: #9b2c2c; font-weight: bold; }
    .notice { margin-top: 16px; padding: 10px; background: #f7f9fc; border-right: 3px solid #f4b223; color: #475467; }
    .notice.paid { border-right-color: #059669; background: #ecfdf5; color: #065f46; }
    .verify { margin-top: 18px; padding: 10px; border: 1px solid #d9e0ec; text-align: center; }
    .verify img { width: 105px; height: 105px; }
    .verify .code { direction: ltr; font-family: monospace; font-size: 8pt; word-break: break-all; }
    .footer { margin-top: 22px; border-top: 1px solid #d9e0ec; padding-top: 8px; color: #667085; text-align: center; font-size: 8.5pt; }
  </style>
</head>
<body>
  <table class="header">
    <tr>
      <td>
        @if(!empty($merchantLogoData))
          <img class="merchant-logo" src="{{ $merchantLogoData }}" alt="شعار المنشأة">
        @endif
        <div class="brand">{{ $merchant?->store_name ?: 'الصيدلية' }}</div>
        @if(!empty($merchant?->merchant_number))
          <div class="muted">رقم التاجر: {{ $merchant->merchant_number }}</div>
        @endif
        @if(!empty($merchant?->address))
          <div class="muted">{{ $merchant->address }}</div>
        @endif
      </td>
      <td class="title">فاتورة صيدلية</td>
    </tr>
  </table>

  <table class="meta">
    <tr>
      <td>
        <div class="k">رقم الفاتورة</div>
        <div class="v left">{{ $sale->invoice_number ?: $sale->sale_ulid }}</div>
      </td>
      <td>
        <div class="k">تاريخ الإصدار</div>
        <div class="v left">{{ $sale->created_at?->copy()->setTimezone('Asia/Riyadh')->format('Y-m-d H:i') }}</div>
      </td>
      <td>
        <div class="k">طريقة الدفع</div>
        <div class="v">{{ $paymentLabel }}</div>
      </td>
      <td>
        <div class="k">الحالة</div>
        <div class="v">{{ $creditLabel }}</div>
      </td>
    </tr>
  </table>

  <table class="party" style="margin-top:12px">
    <tr>
      <td>
        <strong>العميل</strong><br>
        {{ $displayCustomerName }}
        @if(!empty($displayCustomerPhone))
          <br><span class="muted">{{ $displayCustomerPhone }}</span>
        @endif
        @if(!empty($creditDueDate))
          <br><span class="muted">الاستحقاق: {{ $creditDueDate }}</span>
        @endif
      </td>
      <td>
        <strong>مرجع البيع</strong><br>
        <span class="left">{{ $sale->sale_ulid }}</span>
        @if(!empty($sale->prescription_number))
          <br><span class="muted">الوصفة: {{ $sale->prescription_number }}</span>
        @endif
        @if(!empty($sale->prescribing_doctor))
          <br><span class="muted">الطبيب: {{ $sale->prescribing_doctor }}</span>
        @endif
        @if(!empty($sale->paid_transaction_id))
          <br><strong>مرجع دفع أميال</strong><br>
          <span class="left">{{ $sale->paid_transaction_id }}</span>
        @endif
      </td>
    </tr>
  </table>

  <table class="items">
    <thead>
      <tr>
        <th style="width:5%">#</th>
        <th>الصنف</th>
        <th style="width:13%">التشغيلة</th>
        <th style="width:13%">الانتهاء</th>
        <th style="width:9%">الكمية</th>
        <th style="width:13%">سعر الوحدة</th>
        <th style="width:14%">الإجمالي</th>
      </tr>
    </thead>
    <tbody>
      @forelse($items as $i => $item)
        <tr>
          <td class="center">{{ $i + 1 }}</td>
          <td>
            {{ $item['name'] }}
            @if($item['requires_prescription'])
              <br><span class="muted">بوصفة طبية</span>
            @endif
          </td>
          <td class="center">{{ $item['batch_number'] ?: '—' }}</td>
          <td class="center">{{ $item['expiry_date'] ?: '—' }}</td>
          <td class="center">{{ rtrim(rtrim(number_format((float)$item['quantity'], 3, '.', ''), '0'), '.') }}</td>
          <td class="left">{{ number_format((float)$item['unit_price'], 2) }}</td>
          <td class="left"><strong>{{ number_format((float)$item['total'], 2) }}</strong></td>
        </tr>
      @empty
        <tr><td colspan="7" class="center">لا توجد بنود مسجلة</td></tr>
      @endforelse
    </tbody>
  </table>

  <table class="summary">
    <tr>
      <td>المجموع الفرعي</td>
      <td class="left">{{ number_format((float)$sale->subtotal, 2) }} ر.ي</td>
    </tr>
    @if((float)$sale->discount_amount > 0)
      <tr>
        <td>الخصم</td>
        <td class="left">- {{ number_format((float)$sale->discount_amount, 2) }} ر.ي</td>
      </tr>
    @endif
    <tr class="grand">
      <td>الإجمالي</td>
      <td class="left">{{ number_format((float)$sale->total_amount, 2) }} ر.ي</td>
    </tr>
    @if($sale->payment_method === 'credit')
      <tr class="remaining">
        <td>المتبقي في فواتيري الآجلة</td>
        <td class="left">{{ number_format((float)$creditRemaining, 2) }} ر.ي</td>
      </tr>
    @endif
    @if($sale->payment_method === 'cash' && $sale->amount_received !== null)
      <tr>
        <td>المبلغ المستلم</td>
        <td class="left">{{ number_format((float)$sale->amount_received, 2) }} ر.ي</td>
      </tr>
      <tr>
        <td>الباقي</td>
        <td class="left">{{ number_format(max(0, (float)$sale->amount_received - (float)$sale->total_amount), 2) }} ر.ي</td>
      </tr>
    @endif
  </table>

  @if($sale->payment_method === 'credit')
    @if($creditStateCode === 'paid')
      <div class="notice paid">تم سداد هذه الفاتورة بالكامل من دفتر الديون الموحّد.</div>
    @elseif($creditStateCode === 'partial')
      <div class="notice">تم سداد جزء من هذه الفاتورة، والمتبقي ظاهر أعلاه ويمكن سداده من تطبيق أميال.</div>
    @elseif($creditStateCode === 'unknown')
      <div class="notice">هذه فاتورة آجلة تاريخية، وتعذّر ربط حالة سدادها الحالية بدفتر الديون الموحّد.</div>
    @else
      <div class="notice">فاتورة بيع آجل مرتبطة بدفتر ديون العميل ويمكن سدادها جزئياً أو كلياً من تطبيق أميال.</div>
    @endif
  @endif

  <div class="verify">
    @if(!empty($qrDataUri))
      <img src="{{ $qrDataUri }}" alt="QR تحقق">
    @endif
    <div><strong>تحقق من أصالة الفاتورة</strong></div>
    <div class="code">{{ $sale->sale_ulid }}</div>
    @if(!empty($verificationUrl))
      <div class="code">{{ $verificationUrl }}</div>
    @endif
  </div>

  <div class="footer">فاتورة صيدلية إلكترونية. إعادة التنزيل أو الطباعة لا تنشئ بيعاً أو دفعاً جديداً.</div>
</body>
</html>
