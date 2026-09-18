<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><style>
body{font-family:dejavusans,sans-serif;color:#172033;font-size:10px}.doc{border:1px solid #cbd5e1;padding:7px;margin:8px 0;page-break-inside:avoid}.doc img{max-width:100%;max-height:330px}.head{border-bottom:2px solid #167a74;padding-bottom:9px;margin-bottom:12px}.badge{background:#e7f5f3;padding:4px 8px;border-radius:4px}h3{font-size:12px;background:#f1f5f9;padding:6px;margin:14px 0 0}table{width:100%;border-collapse:collapse}td,th{border:1px solid #cbd5e1;padding:6px;text-align:right;width:50%}.muted{color:#64748b;font-size:9px}.yes{font-weight:bold}
</style></head><body>
@php
  $labels = ['gender'=>'الجنس','name_en'=>'الاسم بالإنجليزية','father_name'=>'اسم الأب','grandfather_name'=>'اسم الجد','date_of_birth'=>'تاريخ الميلاد','dial_country_code'=>'مفتاح الدولة','phone'=>'رقم الجوال','phone_canonical'=>'رقم الجوال الموحّد','email'=>'البريد الإلكتروني','identification_type'=>'نوع الهوية','identification_number'=>'رقم الهوية','identification_issue_date'=>'تاريخ الإصدار','identification_expiry_date'=>'تاريخ الانتهاء','id_place_of_issue'=>'مكان الإصدار','country_of_birth'=>'بلد الميلاد','birth_governorate'=>'محافظة الميلاد','dual_nationality'=>'جنسية أخرى','marital_status'=>'الحالة الاجتماعية','address'=>'العنوان','origin_governorate'=>'محافظة الأصل','residence_governorate'=>'محافظة السكن','residence_district'=>'المديرية','residence_area'=>'المنطقة/الحي','residence_landmark'=>'علامة مميزة','housing_type'=>'نوع السكن','occupation'=>'المهنة','employer_name'=>'جهة العمل','job_title'=>'المسمى الوظيفي','work_address'=>'عنوان العمل','income_source'=>'مصدر الدخل','monthly_income'=>'الدخل الشهري','monthly_income_currency'=>'عملة الدخل','account_purpose'=>'الغرض من الحساب','is_pep'=>'شخص سياسي بارز','pep_position'=>'المنصب أو الصلة','kin_name'=>'المرجع الأول','kin_phone'=>'جوال المرجع الأول','kin_relation'=>'صلة المرجع الأول','kin2_name'=>'المرجع الثاني','kin2_phone'=>'جوال المرجع الثاني','kin2_relation'=>'صلة المرجع الثاني','store_name'=>'اسم المنشأة','business_type'=>'نوع النشاط','plan'=>'الباقة','business_registration_number'=>'رقم السجل/الترخيص','business_legal_form'=>'الشكل القانوني','business_category'=>'فئة النشاط','authorized_signatory_name'=>'المفوض بالتوقيع','authorized_signatory_id'=>'هوية المفوض','verification_target_label'=>'حالة التوثيق المطلوبة','confirmed_by_customer_at'=>'وقت تأكيد العميل','residence_evidence_type'=>'نوع إثبات السكن','ownership_method'=>'طريقة إثبات صاحب الحساب','review_mode'=>'نمط المراجعة'];
  $sections = ['بيانات صاحب الحساب والاتصال'=>['full_name','name_en','father_name','grandfather_name','gender','date_of_birth','dial_country_code','phone','phone_canonical','email'],'الهوية والعنوان'=>['identification_type','identification_number','identification_issue_date','identification_expiry_date','id_place_of_issue','country_of_birth','birth_governorate','dual_nationality','marital_status','address','origin_governorate','residence_governorate','residence_district','residence_area','residence_landmark','housing_type'],'العمل والامتثال والمراجع'=>['occupation','employer_name','job_title','work_address','income_source','monthly_income','monthly_income_currency','account_purpose','is_pep','pep_position','kin_name','kin_phone','kin_relation','kin2_name','kin2_phone','kin2_relation'],'بيانات طلب التوثيق'=>['verification_target_label','confirmed_by_customer_at','residence_evidence_type','review_mode','ownership_method'],'هوية المنشأة'=>['store_name','business_type','plan','business_registration_number','business_legal_form','business_category','authorized_signatory_name','authorized_signatory_id']];
@endphp
@php
  $isVerification = in_array($dossier->source, \App\Models\RegistrationDossier::VERIFICATION_SOURCES, true);
  $documentTitle = $isVerification
    ? 'أميال باي — استمارة توثيق العميل'
    : 'أميال باي — ملف فتح حساب وأرشفة';
@endphp
<div class="head"><h2>{{ $documentTitle }}</h2><div>المرجع: <strong>{{ $dossier->reference }}</strong> <span class="badge">{{ $dossier->subject_type === 'merchant' ? 'منشأة / تاجر' : 'عميل فرد' }}</span></div></div>
<p>@if($isVerification && !empty($payload['verification_target_label']))<strong>طلب العميل:</strong> {{ $payload['verification_target_label'] }} &nbsp; @endif<strong>الحالة:</strong> {{ $dossier->state }} &nbsp; <strong>أُنشئ:</strong> {{ optional($dossier->created_at)->format('Y-m-d H:i') }} &nbsp; <strong>نسخة ورقية موقعة:</strong> {{ $dossier->paper_form_encrypted_path ? 'مرفقة ومشفرة' : 'غير مرفقة' }}</p>
@foreach($sections as $title => $fields)
  @php $visible = array_filter($fields, fn($key) => array_key_exists($key, $payload) && $payload[$key] !== null && $payload[$key] !== ''); @endphp
  @if(count($visible))<h3>{{ $title }}</h3><table><tbody>@foreach(array_chunk($visible, 2) as $pair)<tr>@foreach($pair as $key)
    @php
      $value = $payload[$key];
      if (in_array($key, ['birth_governorate','origin_governorate','residence_governorate'], true)) {
        $value = \App\Support\YemenGovernorates::name(
          \App\Support\YemenGovernorates::codeFromName((string) $value)
        ) ?: $value;
      }
      if ($key === 'is_pep') {
        $value = ((string) $value === '1') ? 'نعم' : 'لا';
      }
      if ($key === 'review_mode') {
        $value = ((string) $value === 'restricted_review') ? 'خصوصية إضافية' : 'مراجعة محمية';
      }
      if ($key === 'ownership_method' && (string) $value === 'selfie') {
        $value = 'صورة سيلفي حديثة';
      }
    @endphp
    <th>{{ $labels[$key] ?? $key }}</th><td>{{ $value }}</td>
  @endforeach @if(count($pair) === 1)<th></th><td></td>@endif</tr>@endforeach</tbody></table>@endif
@endforeach
@if($isVerification && count($verification_images ?? []))
<h3>المستندات التي أكدها العميل مع الطلب</h3>
@foreach($verification_images as $image)
  <div class="doc">
    <strong>{{ $image['label'] }}</strong>
    @if($image['data_uri'])
      <div style="margin-top:6px"><img src="{{ $image['data_uri'] }}" alt="{{ $image['label'] }}"></div>
    @else
      <p class="muted">{{ $image['note'] ?? 'تعذر عرض المستند.' }}</p>
    @endif
  </div>
@endforeach
@endif
<h3>الإقرار وسلامة السجل</h3><table><tbody><tr><th>إقرار مقدم البيانات</th><td>{{ !empty($payload['declaration_accepted']) ? 'مقبول' : 'غير مسجل' }}</td><th>نسخة النموذج</th><td>{{ $dossier->paper_form_encrypted_path ? 'ورقية موقعة ومشفرة' : 'إلكترونية' }}</td></tr></tbody></table>
<p class="muted">@if($isVerification) هذه لقطة أرشيفية غير قابلة للتعديل لما راجعه العميل وأكده قبل إرسال طلب التوثيق. يمكن طباعتها وحفظها في الملف الورقي، ولا تعني وحدها صدور قرار الاعتماد من المراجع. @else هذه لقطة أرشيفية غير قابلة للتعديل لبيانات فتح الحساب وقت إدخالها. لا تعني اعتماد الهوية أو تحقق ملكية الهاتف؛ القرار يتم من خلال مراجعة الامتثال والتحقق المناسب. @endif</p>
</body></html>
