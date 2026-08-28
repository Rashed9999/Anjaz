<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><style>
body{font-family:dejavusans,sans-serif;color:#172033;font-size:12px}.head{border-bottom:2px solid #167a74;padding-bottom:10px;margin-bottom:15px}.badge{background:#e7f5f3;padding:4px 8px;border-radius:4px}table{width:100%;border-collapse:collapse;margin-top:12px}td,th{border:1px solid #cbd5e1;padding:8px;text-align:right}.muted{color:#64748b;font-size:10px}
</style></head><body>
<div class="head"><h2>أميال باي — ملف تسجيل وأرشفة</h2><div>المرجع: <strong>{{ $dossier->reference }}</strong> <span class="badge">{{ $dossier->subject_type === 'merchant' ? 'منشأة/تاجر' : 'عميل فرد' }}</span></div></div>
<p><strong>طريقة الإدخال:</strong> {{ $dossier->source === 'paper_archive' ? 'نموذج ورقي مؤرشف' : 'تسجيل بمساعدة موظف أميال' }} &nbsp; <strong>الحالة:</strong> {{ $dossier->state }}</p>
<table><tbody>
@foreach(['full_name'=>'الاسم الكامل','phone'=>'رقم الجوال','gender'=>'الجنس','identification_type'=>'نوع الهوية','identification_number'=>'رقم الهوية','address'=>'العنوان','business_name'=>'اسم المنشأة','business_type'=>'نوع النشاط'] as $key => $label)
@if(!empty($payload[$key]))<tr><th>{{ $label }}</th><td>{{ $payload[$key] }}</td></tr>@endif
@endforeach
</tbody></table>
<p class="muted">هذا الملف سجلّ إدخال وأرشفة فقط. لا يعني فتح محفظة أو اعتماد هوية. يتطلب فتح الحساب تأكيد العميل لرقمه عبر OTP ومراجعة الامتثال وفق سياسة أميال.</p>
<p class="muted">أُنشئ: {{ optional($dossier->created_at)->format('Y-m-d H:i') }} — يحفظ النظام النسخة الموقعة، عند إرفاقها، بصورة مشفّرة وسجلّ وصول.</p>
</body></html>
