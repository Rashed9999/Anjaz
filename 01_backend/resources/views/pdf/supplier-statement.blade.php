<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>كشف حساب مورد</title><style>
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
</td><td class="title">كشف حساب مورد</td></tr></table>
<table class="meta"><tr>
<td><div class="k">المورد</div><div class="v">{{ $supplier->name }}</div></td>
<td><div class="k">علينا للمورد</div><div class="v ltr">{{ number_format((float)$supplier->current_debt,2) }} ر.ي</div></td>
<td><div class="k">لنا عند المورد</div><div class="v ltr">{{ number_format((float)($supplier->current_credit ?? 0),2) }} ر.ي</div></td>
<td><div class="k">صافي المركز</div><div class="v ltr">{{ number_format((float)$supplier->current_debt-(float)($supplier->current_credit ?? 0),2) }} ر.ي</div></td>
<td><div class="k">وقت التوليد</div><div class="v ltr">{{ $generatedAt->format('Y-m-d H:i') }}</div></td>
</tr></table>
<table class="ledger"><thead><tr><th>التاريخ</th><th>الحركة</th><th>المبلغ</th><th>المصدر</th><th>علينا بعد</th><th>لنا بعد</th><th>المرجع</th></tr></thead><tbody>
@forelse($ledgerRows as $row)
<tr>
<td class="num">{{ $row->created_at?->copy()->setTimezone('Asia/Riyadh')->format('Y-m-d H:i') }}</td>
<td>{{ $row->entry_type === 'po_receive' ? 'استلام شراء' : ($row->entry_type === 'payment' ? 'سداد' : ($row->entry_type === 'supplier_refund' ? 'تحصيل من المورد' : ($row->entry_type === 'po_return' ? 'مرتجع شراء' : ($row->entry_type === 'opening' ? 'رصيد افتتاحي' : $row->entry_type)))) }}</td>
<td class="num">{{ number_format((float)$row->amount,2) }}</td>
<td>{{ $row->payment_method === 'amial_pay' ? 'أميال باي' : ($row->payment_method === 'cash_shift' ? 'درج وردية' : ($row->payment_method === 'cash_external' ? 'نقد خارجي' : ($row->payment_method === 'credit' ? 'آجل' : '—'))) }}</td>
<td class="num">{{ number_format((float)$row->debt_after,2) }}</td>
<td class="num">{{ number_format((float)($row->credit_after ?? 0),2) }}</td>
<td class="num">{{ $row->reference ?: '—' }}</td>
</tr>
@empty<tr><td colspan="7" class="center">لا توجد حركات</td></tr>@endforelse
</tbody></table>
<div class="note">هذا كشف حيّ يعكس سجل المورد حتى وقت التوليد. أي حركة لاحقة تظهر في كشف جديد ولا تعيد كتابة الحركات السابقة.</div>
<div class="footer">كشف داخلي للمنشأة - مصدره سجل المورد في أميال باي.</div>
</body></html>