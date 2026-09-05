{{--
  AMIAL-ACCOUNT-PRINT-001 — ملفُّ الحساب للطباعة، ومعه صورُ الوثائق.

  ألوانٌ خامٌ عمداً: dompdf لا يفهم `var()`، والقوالبُ تحت `pdf/` مستثناةٌ
  صراحةً في `VisualIdentityGuardTest` لهذا السبب بعينه.
--}}
<!doctype html>
<html lang="ar" dir="rtl">
<head><meta charset="utf-8"><style>
body{font-family:dejavusans,sans-serif;color:#172033;font-size:10px}
.head{border-bottom:2px solid #053391;padding-bottom:9px;margin-bottom:12px}
.badge{background:#eef2ff;padding:3px 7px;border-radius:4px}
h3{font-size:12px;background:#f1f5f9;padding:6px;margin:14px 0 0}
table{width:100%;border-collapse:collapse}
td,th{border:1px solid #cbd5e1;padding:5px;text-align:right;width:25%}
.muted{color:#64748b;font-size:9px}
.warn{background:#fff8e1;border:1px solid #f0b429;padding:7px;margin:8px 0}
.doc{border:1px solid #cbd5e1;padding:6px;margin:8px 0;page-break-inside:avoid}
.doc img{max-width:100%;max-height:330px}
.doc .cap{font-size:9px;color:#475569;margin-bottom:4px}
</style></head>
<body>

@php
  $labels = ['gender'=>'الجنس','name_en'=>'الاسم بالإنجليزية','father_name'=>'اسم الأب','grandfather_name'=>'اسم الجد','date_of_birth'=>'تاريخ الميلاد','dial_country_code'=>'مفتاح الدولة','phone'=>'رقم الجوال','phone_canonical'=>'رقم الجوال الموحّد','email'=>'البريد الإلكتروني','identification_type'=>'نوع الهوية','identification_number'=>'رقم الهوية','identification_issue_date'=>'تاريخ الإصدار','identification_expiry_date'=>'تاريخ الانتهاء','id_place_of_issue'=>'مكان الإصدار','country_of_birth'=>'بلد الميلاد','dual_nationality'=>'جنسية أخرى','marital_status'=>'الحالة الاجتماعية','address'=>'العنوان','origin_governorate'=>'محافظة الأصل','residence_governorate'=>'محافظة السكن','residence_district'=>'المديرية','residence_area'=>'المنطقة/الحي','residence_landmark'=>'علامة مميزة','housing_type'=>'نوع السكن','occupation'=>'المهنة','employer_name'=>'جهة العمل','job_title'=>'المسمى الوظيفي','work_address'=>'عنوان العمل','income_source'=>'مصدر الدخل','monthly_income'=>'الدخل الشهري','monthly_income_currency'=>'عملة الدخل','account_purpose'=>'الغرض من الحساب','is_pep'=>'شخص سياسي بارز','pep_position'=>'المنصب أو الصلة','kin_name'=>'المرجع الأول','kin_phone'=>'جوال المرجع الأول','kin_relation'=>'صلة المرجع الأول','kin2_name'=>'المرجع الثاني','kin2_phone'=>'جوال المرجع الثاني','kin2_relation'=>'صلة المرجع الثاني','store_name'=>'اسم المنشأة','business_type'=>'نوع النشاط','plan'=>'الباقة','business_registration_number'=>'رقم السجل/الترخيص','business_legal_form'=>'الشكل القانوني','business_category'=>'فئة النشاط','authorized_signatory_name'=>'المفوض بالتوقيع','authorized_signatory_id'=>'هوية المفوض'];
  $sections = ['بيانات صاحب الحساب والاتصال'=>['full_name','name_en','father_name','grandfather_name','gender','date_of_birth','dial_country_code','phone','phone_canonical','email'],'الهوية والعنوان'=>['identification_type','identification_number','identification_issue_date','identification_expiry_date','id_place_of_issue','country_of_birth','dual_nationality','marital_status','address','origin_governorate','residence_governorate','residence_district','residence_area','residence_landmark','housing_type'],'العمل والامتثال والمراجع'=>['occupation','employer_name','job_title','work_address','income_source','monthly_income','monthly_income_currency','account_purpose','is_pep','pep_position','kin_name','kin_phone','kin_relation','kin2_name','kin2_phone','kin2_relation'],'هوية المنشأة'=>['store_name','business_type','plan','business_registration_number','business_legal_form','business_category','authorized_signatory_name','authorized_signatory_id']];
@endphp

<div class="head">
  <h2>أميال باي — ملفّ الحساب للطباعة</h2>
  <div>
    حساب رقم <strong>{{ $user->account_number ?: $user->id }}</strong>
    <span class="badge">{{ $identity[3][1] }}</span>
    &nbsp; طُبع: {{ $printed_at }} &nbsp; بواسطة: {{ $printed_by }}
  </div>
</div>

<h3>هوية الحساب — من سجلّ المستخدمين</h3>
<table><tbody>
  @foreach(array_chunk($identity, 2) as $pair)
    <tr>
      @foreach($pair as $row)<th>{{ $row[0] }}</th><td>{{ $row[1] }}</td>@endforeach
      @if(count($pair) === 1)<th></th><td></td>@endif
    </tr>
  @endforeach
</tbody></table>

{{-- **لقطةُ التسجيل — وغيابُها يُقال ولا يُترك فراغاً بلا سبب.** --}}
@if($dossier)
  <h3>لقطة فتح الحساب — المرجع {{ $dossier->reference }}</h3>
  <p class="muted">
    الحالة: {{ $dossier->state }} &nbsp;·&nbsp;
    المصدر: {{ $dossier->source }} &nbsp;·&nbsp;
    أُنشئت: {{ optional($dossier->created_at)->format('Y-m-d H:i') }} &nbsp;·&nbsp;
    نسخة ورقية موقّعة: {{ $dossier->paper_form_encrypted_path ? 'مرفقة ومشفّرة' : 'غير مرفقة' }}
  </p>

  @foreach($sections as $title => $fields)
    @php $visible = array_values(array_filter($fields, fn($k) => array_key_exists($k, $payload) && $payload[$k] !== null && $payload[$k] !== '')); @endphp
    @if(count($visible))
      <h3>{{ $title }}</h3>
      <table><tbody>
        @foreach(array_chunk($visible, 2) as $pair)
          <tr>
            @foreach($pair as $key)
              <th>{{ $labels[$key] ?? $key }}</th>
              <td>{{ $key === 'is_pep' ? ((string) $payload[$key] === '1' ? 'نعم' : 'لا') : $payload[$key] }}</td>
            @endforeach
            @if(count($pair) === 1)<th></th><td></td>@endif
          </tr>
        @endforeach
      </tbody></table>
    @endif
  @endforeach
@else
  <div class="warn">
    <strong>لا لقطةَ تسجيلٍ مؤرشفةٌ لهذا الحساب.</strong>
    فُتح الحسابُ قبل تأسيس الأرشيف، أو من مسارٍ لا يؤرشف. وهذا
    <em>غيابُ لقطةٍ لا نقصُ بيانات</em> — وما فوق مأخوذٌ من سجلّ
    المستخدمين مباشرةً، وما تحت من سجلّ الوثائق.
  </div>
@endif

{{-- **حالةُ الملفّ من مصدر القرار نفسِه** — لا قائمةٌ موازيةٌ تشيخ. --}}
<h3>حالة ملفّ التوثيق</h3>
<table><tbody>
  <tr>
    <th>الاكتمال</th>
    <td>{{ $evidence['complete'] ? 'مكتمل' : 'ناقص' }}</td>
    <th>المعتمَد</th>
    <td>{{ $evidence['approved'] ? implode('، ', $evidence['approved']) : 'لا شيء' }}</td>
  </tr>
  <tr>
    <th>الناقص</th>
    <td>{{ $evidence['missing'] ? implode('، ', $evidence['missing']) : 'لا شيء' }}</td>
    <th>ما يمنع الاعتماد</th>
    <td>{{ $evidence['blockers'] ? implode(' · ', $evidence['blockers']) : 'لا مانع' }}</td>
  </tr>
</tbody></table>

<h3>صور الوثائق</h3>
@if(count($images) === 0)
  <div class="warn">
    <strong>لا وثيقةَ واحدةً مرفوعةً لهذا الحساب</strong> — لا في سجلّ
    الوثائق ولا في العمود القديم.
  </div>
@else
  @foreach($images as $img)
    <div class="doc">
      <div class="cap">
        <strong>{{ $img['label'] }}</strong> —
        {{ $img['status'] }} · {{ $img['source'] }} · رُفعت: {{ $img['uploaded_at'] }}
      </div>
      @if($img['data_uri'])
        <img src="{{ $img['data_uri'] }}" alt="{{ $img['label'] }}">
      @else
        <div class="muted">{{ $img['note'] ?: 'لا صورة قابلة للتضمين.' }}</div>
      @endif
    </div>
  @endforeach

  @if(count($images) >= \App\Services\Admin\AccountDossierPrintService::MAX_IMAGES)
    <p class="muted">
      عُرضت أوّلُ {{ \App\Services\Admin\AccountDossierPrintService::MAX_IMAGES }}
      وثيقةٍ فقط — وبقيّتُها تُفتَح من لوحة التحقّق.
    </p>
  @endif
@endif

<p class="muted">
  هذه ورقةٌ إداريّةٌ تحمل بياناتٍ شخصيّةً وصورَ هويّة. فتحُها وطباعتُها
  مسجَّلان في سجلّ الوصول إلى البيانات الشخصيّة باسم من طبعها ووقتِه.
  ولا تعني الطباعةُ اعتماداً: قرارُ التوثيق يُتَّخذ من لوحة التحقّق وحدَها.
</p>

</body>
</html>
