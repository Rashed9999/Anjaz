@extends('layouts.admin.app')

@section('title', 'الخصومات والسياسات — أميال باي')

@section('content')
{{--
    AMIAL-FEE-TRUTH-002 — **الخصمُ سياسةٌ فوق الرسم، ويُرى حيث تُرى الرسوم.**

    ══════════════════════════════════════════════════════════════════════
    كان خصمُ «الأرقام المفضّلة» يُضبط في `business-settings/charge-setup`
    ويُحسب داخل متحكّم المعاملات — بعيداً عن مركز الرسوم تماماً. فمن يضبط
    رسمَ التحويل هنا **لا يعلم أنّ خصماً بنسبةٍ يعمل فوقه**، فيرى المحصَّلَ
    أقلَّ ممّا سعّر ولا يجد له سبباً.

    فصار يُعرَض هنا: **حالتُه، ونسبتُه، وأين يُعدَّل** — بلا محرّرٍ ثانٍ
    يكتب في المال نفسِه.
--}}
<div class="content container-fluid">

    @include('admin-views.amial.fees._shell')

    <div class="fee-kpis">
        <div class="fee-kpi {{ $enabled ? 'is-success' : '' }}">
            <span class="k-label">خصمُ الأرقام المفضّلة</span>
            <span class="k-value">{{ $enabled ? 'مفعَّل' : 'مطفأ' }}</span>
            <span class="k-note">يُطبَّق على الرسم بعد حسابه</span>
        </div>

        <div class="fee-kpi">
            <span class="k-label">خصمُ التحويل</span>
            <span class="k-value">{{ rtrim(rtrim(number_format($sendPercent, 2, '.', ''), '0'), '.') ?: '0' }}%</span>
            <span class="k-note">من رسم «تحويل بين المحافظ»</span>
        </div>

        <div class="fee-kpi">
            <span class="k-label">خصمُ السحب النقديّ</span>
            <span class="k-value">{{ rtrim(rtrim(number_format($cashOutPercent, 2, '.', ''), '0'), '.') ?: '0' }}%</span>
            <span class="k-note">من رسم «سحب نقديّ من وكيل»</span>
        </div>

        <div class="fee-kpi">
            <span class="k-label">حدُّ الأرقام المفضّلة</span>
            <span class="k-value">{{ $limit > 0 ? $limit : '—' }}</span>
            <span class="k-note">{{ $limit > 0 ? 'رقماً لكلّ عميل' : 'بلا حدٍّ مضبوط' }}</span>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">
            <h5 class="card-header-title mb-0">كيف يعمل الخصم</h5>
        </div>
        <div class="card-body">
            <p class="mb-3">
                الخصمُ <strong>لا يُنشئ رسماً</strong> ولا يقرأ تسعيرةً موازية:
                يأخذ الرسمَ كما حسبه محرّكُ الرسوم، ويُنقصه بنسبةٍ معلومة، ويقول كم أنقص
                ولماذا — فيظهر في الإيصال وفي التدقيق بدل أن يكون فرقاً غامضاً.
            </p>

            <div class="fee-scroll">
                <table class="table table-bordered table-align-middle mb-0" style="max-width:620px">
                    <tbody>
                    <tr>
                        <th class="bg-light" style="width:45%">١) محرّكُ الرسوم</th>
                        <td>يجيب <strong>«كم الرسم؟»</strong> — مصدرُ الحقيقة الوحيد</td>
                    </tr>
                    <tr>
                        <th class="bg-light">٢) سياسةُ الخصم</th>
                        <td>تجيب <strong>«أيُخصَم منه؟»</strong> — طبقةٌ فوق الناتج</td>
                    </tr>
                    <tr>
                        <th class="bg-light">٣) الحدُّ الصلب</th>
                        <td>لا يزيد الخصمُ على الرسم — فلا رسمَ سالبٌ مهما ضُبطت النسبة</td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h5 class="card-header-title mb-0">أين يُعدَّل</h5>
        </div>
        <div class="card-body">
            <p class="mb-3">
                إعداداتُ الخصم تُحفَظ في إعدادات النظام، وتُعدَّل من شاشة
                <strong>«إعدادات الأعمال ← ضبط الرسوم»</strong>. وهي محروسةٌ بالصلاحيّة نفسِها
                (<code>platform.fees.update</code>) فلا يكون بابٌ خلفيٌّ يغيّر المالَ بلا حارس.
            </p>

            <a class="btn btn-outline-primary" href="{{ url('admin/business-settings/charge-setup') }}">
                <i class="tio-settings"></i> فتحُ إعدادات الخصم
            </a>

            <div class="alert alert-warning small mt-3 mb-0">
                <strong>ولا تُضبط الرسومُ من تلك الشاشة.</strong>
                حقولُ الرسوم فيها لم تعد تكتب شيئاً: المسارُ الماليُّ كلُّه يقرأ من
                مركز الرسوم هنا. وكانت الشاشتان تكتبان في المال نفسِه، فيغيّر المديرُ
                الرقمَ في إحداهما ولا يتغيّر شيء.
            </div>
        </div>
    </div>

</div>
@endsection
