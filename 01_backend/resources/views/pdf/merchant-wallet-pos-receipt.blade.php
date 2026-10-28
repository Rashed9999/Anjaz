<!doctype html><html lang="ar" dir="rtl"><head><meta charset="UTF-8">
<style>body{direction:rtl;font-family:"DejaVu Sans",sans-serif;color:#1c303a;font-size:12px}
h1{font-size:20px}h2{font-size:16px;color:#137e65}table{border-collapse:collapse;width:100%;margin-top:18px}
td,th{border:1px solid #cddfda;padding:8px;text-align:right}
th{background:#edf5f2}.summary{margin-top:15px;font-size:18px;font-weight:bold}
.ref{direction:ltr;text-align:left}.footer{margin-top:24px;border-top:1px solid #cddfda;padding-top:12px}</style></head><body>
<h1>{{ $storeName }}</h1><h2>إيصال بيع مدفوع بمحفظة أميال باي</h2>
<p>العملية مدفوعة ومسجلة في أميال التاجر وتم خصم المخزون.</p>
<p>رقم الإيصال: <span dir="ltr">{{ $receipt['invoice_number'] }}</span></p>
<p>رقم الطلب: <span dir="ltr">{{ $receipt['order_id'] }}</span></p>
<p>مرجع دفع أميال: <span dir="ltr">{{ $receipt['paid_transaction_id'] }}</span></p>
<table><thead><tr><th>الصنف</th><th>العدد</th><th>سعر الوحدة</th><th>الإجمالي</th></tr></thead><tbody>
@foreach($receipt['items'] as $item)<tr>
<td>{{ $item['name'] }}</td><td>{{ $item['qty'] }}</td>
<td>{{ $item['price'] }}</td><td>{{ $item['line_total'] }}</td>
</tr>@endforeach
</tbody></table>
<p class="summary">المجموع: {{ $receipt['total_amount'] }} {{ $receipt['currency'] }}</p>
<p class="footer">إيصال نقطة بيع، وليس فاتورة ضريبية مستقلة.</p>
</body></html>