@extends('site.layout')

@section('title', 'تواصل معنا')
@section('desc', 'قنوات التواصل مع أميال باي: الدعم، والشراكات، والبلاغات الأمنيّة.')

@section('body')

<section class="page-head">
    <div class="wrap">
        <h1>تواصل معنا</h1>
        <p>اختر القناة المناسبة، ووصف مشكلتك برقم الإيصال إن وُجد — يختصر ذلك الوقت كثيراً.</p>
    </div>
</section>

<section class="section">
    <div class="wrap">
        <div class="grid grid-3">
            <div class="card">
                <div class="ico">💬</div><h3>دعم العملاء</h3>
                <p>من داخل التطبيق: القائمة ← الدعم. وهو أسرع طريقٍ لأنّ بياناتِ
                   حسابك تصل مع رسالتك.</p>
            </div>
            <div class="card">
                <div class="ico">🤝</div><h3>الشراكات</h3>
                <p>لشركات الصرافة والتجّار الراغبين في الانضمام إلى الشبكة.</p>
            </div>
            <div class="card">
                <div class="ico">🛡️</div><h3>بلاغٌ أمنيّ</h3>
                <p>إن وجدتَ ثغرةً فراسلنا قبل نشرها — نردّ على كلّ بلاغ.</p>
            </div>
        </div>

        <div class="card" style="margin-top:22px">
            <h3 style="margin:0 0 10px">قبل أن تراسلنا</h3>
            <ul class="ticks" style="margin-bottom:0">
                <li>احتفظ برقم الإيصال ووقت العمليّة</li>
                <li>لا ترسل رمز الدخول (PIN) ولا رمز التحقّق في أيّ رسالة — لن نطلبهما منك أبداً</li>
                <li>إن كان البلاغ عن فرعٍ أو شبّاك، اذكر اسم الفرع</li>
            </ul>
        </div>
    </div>
</section>

@endsection
