<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>أمر شراء {{ $order->po_number }}</title><style>
*{box-sizing:border-box} body{font-family:dejavusans,sans-serif;direction:rtl;color:#172033;font-size:10pt;line-height:1.55}
.header{width:100%;border-bottom:3px solid #053391;padding-bottom:10px;margin-bottom:14px}
.brand{font-size:18pt;font-weight:bold;color:#053391}.logo{max-width:88px;max-height:55px;margin-bottom:4px}
.title{text-align:left;font-size:22pt;font-weight:bold;color:#053391}.muted{color:#667085;font-size:8.5pt}
.meta,.items,.ledger{width:100%;border-collapse:collapse}.meta td{border:1px solid #d9e0ec;padding:7px;vertical-align:top}
.k{color:#667085;font-size:8pt}.v{font-weight:bold;margin-top:2px}.ltr{direction:ltr;text-align:left}
.items,.ledger{margin-top:12px}.items th,.ledger th{background:#053391;color:white;padding:7px 5px;text-align:center}
.items td,.ledger td{border:1px solid #e1e6ef;padding:6px 5px;vertical-align:top}.center{text-align:center}.num{direction:ltr;text-align:left}
.total{margin-top:12px;width:42%;margin-right:auto;border-collapse:collapse}.total td{padding:7px;border-bottom:1px solid #e1e6ef}.total .grand td{font-size:13pt;font-weight:bold;color:#053391;background:#eef4ff;border-top:2px solid #053391}
.note{margin-top:12px;padding:9px;background:#f7f9fc;border-right:3px solid #f4b223;color:#475467}
.verify{text-align:center;margin-top:16px;border-top:1px solid #d9e0ec;padding-top:10px}.verify img{width:100px;height:100px}.code{direction:ltr;font-family:monospace;font-size:8pt;word-break:break-all}
.footer{text-align:center;color:#667085;font-size:8pt;margin-top:14px}
</style></head><body>
<table class="header"><tr><td>
@if(!empty($merchantLogoData))<img class="logo" src="{{ $merchantLogoData }}" alt="شعار المنشأة">@endif
<div class="brand">{{ $merchant?->store_name ?: 'منشأة التاجر' }}</div>
@if($merchant?->merchant_number)<div class="muted">رقم التاجر: {{ $merchant->merchant_number }}</div>@endif
</td><td class="title">أمر شراء</td></tr></table>

<table class="meta"><tr>
<td><div class="k">رقم الأمر</div><div class="v ltr">{{ $order->po_number }}</div></td>
<td><div class="k">الحالة</div><div class="v">{{ $statusLabel }}</div></td>
<td><div class="k">تاريخ الإنشاء</div><div class="v ltr">{{ $order->created_at?->copy()->setTimezone('Asia/Riyadh')->format('Y-m-d H:i') }}</div></td>
<td><div class="k">المورد</div><div class="v">{{ $supplier?->name ?: '—' }}</div></td>
</tr></table>

<table class="items"><thead><tr><th>#</th><th>البند</th><th>النوع</th><th>الكمية</th><th>المستلم</th><th>تكلفة الوحدة</th><th>الإجمالي</th></tr></thead><tbody>
@foreach($order->items as $i=>$item)
<tr>
<td class="center">{{ $i+1 }}</td>
<td>{{ $item->name }}</td>
<td>{{ $item->item_type === 'fixed_asset' ? 'أصل ثابت' : ($item->item_type === 'other' ? 'شراء آخر' : 'مخزون') }}
@if($item->item_type === 'fixed_asset')<div class="muted">{{ $item->asset_category ?: 'other' }} · {{ $item->useful_life_months }} شهر</div>@endif</td>
<td class="num">{{ rtrim(rtrim(number_format((float)$item->quantity,3,'.',''),'0'),'.') }}</td>
<td class="num">{{ rtrim(rtrim(number_format((float)$item->received_quantity,3,'.',''),'0'),'.') }}</td>
<td class="num">{{ number_format((float)$item->unit_cost,2) }} ر.ي</td>
<td class="num">{{ number_format((float)$item->quantity*(float)$item->unit_cost,2) }} ر.ي</td>
</tr>
@endforeach
</tbody></table>

<table class="total"><tr class="grand"><td>إجمالي الأمر</td><td class="num">{{ number_format((float)$order->total_amount,2) }} ر.ي</td></tr></table>
@if($order->notes)<div class="note"><strong>ملاحظات:</strong> {{ $order->notes }}</div>@endif
<div class="note">أمر الشراء لا يحرّك المخزون أو مديونية المورد بمجرد إنشائه. الأثر المالي والمخزني ينشأ عند الاستلام الفعلي.</div>

<div class="verify">
@if(!empty($qrDataUri))<img src="{{ $qrDataUri }}" alt="QR تحقق">@endif
<div><strong>تحقق من أصالة أمر الشراء</strong></div>
<div class="code">{{ $order->document_ulid }}</div>
<div class="code">{{ $verificationUrl }}</div>
</div>
<div class="footer">مستند منشأة إلكتروني. إعادة تنزيله أو طباعته لا تنشئ شراءً أو سداداً جديداً.</div>
</body></html>