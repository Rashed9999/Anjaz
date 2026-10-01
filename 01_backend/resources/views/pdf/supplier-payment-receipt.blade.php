<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>سند سداد مورد</title><style>
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
</td><td class="title">سند سداد مورد</td></tr></table>
<table class="meta">
<tr><td><div class="k">المورد</div><div class="v">{{ $supplier->name }}</div></td><td><div class="k">التاريخ</div><div class="v ltr">{{ $payment->created_at?->copy()->setTimezone('Asia/Riyadh')->format('Y-m-d H:i') }}</div></td></tr>
<tr><td><div class="k">طريقة السداد</div><div class="v">{{ $paymentLabel }}</div></td><td><div class="k">الرصيد المتبقي للمورد</div><div class="v ltr">{{ number_format((float)$payment->debt_after,2) }} ر.ي</div></td></tr>
@if($payment->transaction_id)<tr><td colspan="2"><div class="k">مرجع عملية أميال</div><div class="v code">{{ $payment->transaction_id }}</div></td></tr>@endif
@if($payment->cashier_shift_id)<tr><td colspan="2"><div class="k">وردية الصندوق</div><div class="v ltr">#{{ $payment->cashier_shift_id }}</div></td></tr>@endif
</table>
<table class="total"><tr class="grand"><td>المبلغ المسدد</td><td class="num">{{ number_format((float)$payment->amount,2) }} ر.ي</td></tr></table>
@if($payment->note)<div class="note">{{ $payment->note }}</div>@endif
<div class="verify">
@if(!empty($qrDataUri))<img src="{{ $qrDataUri }}" alt="QR تحقق">@endif
<div><strong>تحقق من أصالة سند السداد</strong></div>
<div class="code">{{ $payment->entry_ulid }}</div>
<div class="code">{{ $verificationUrl }}</div>
</div>
<div class="footer">إعادة تنزيل أو طباعة هذا السند لا تخصم مبلغاً جديداً ولا تغيّر مديونية المورد.</div>
</body></html>