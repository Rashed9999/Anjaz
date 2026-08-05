@extends('site.layout')

@section('title', 'للتجّار ونقاط البيع')
@section('desc', 'اقبل الدفع بالـ QR، أدِر منتجاتك وموظّفيك ووردياتهم، واطبع الإيصالات — من تطبيق أميال باي.')

@section('body')

<section class="page-head">
    <div class="wrap">
        <h1>🛒 نقطةُ بيعٍ في جيبك</h1>
        <p>من بائع السمك إلى الصيدليّة إلى محطّة الوقود — لكلّ نشاطٍ لوحتُه.</p>
    </div>
</section>

<section class="section">
    <div class="wrap">
        <div class="section-head">
            <h2>لوحةٌ تتغيّر بنشاطك</h2>
            <p>لأنّ بائع الخضار لا يحتاج ما تحتاجه الصيدليّة. والمبدأ عندنا: «بائع السمك يتعلّم في دقيقتين».</p>
        </div>

        <div class="grid grid-4">
            <div class="card"><div class="ico">🐟</div><h3>البيع السريع</h3><p>بسطات وأسواق: بيعٌ جديد، ديون، تقرير اليوم. ثلاثة أزرار.</p></div>
            <div class="card"><div class="ico">🏪</div><h3>التجزئة</h3><p>كاشير كامل، منتجات، باركود، عملاء، وتقارير.</p></div>
            <div class="card"><div class="ico">💊</div><h3>الصيدليّات</h3><p>أصنافٌ بتواريخ صلاحيّة وتنبيهاتِ نفاد.</p></div>
            <div class="card"><div class="ico">⛽</div><h3>محطّات الوقود</h3><p>مضخّاتٌ ووردياتٌ وكميّات.</p></div>
        </div>
    </div>
</section>

<section class="section alt">
    <div class="wrap">
        <div class="grid grid-2" style="gap:44px">
            <div>
                <h2 style="font-size:24px;margin:0 0 14px;font-weight:800">إدارةٌ لا بيعٌ فقط</h2>
                <ul class="ticks">
                    <li>موظّفون بصلاحيّاتٍ محدّدة، وورديّاتٌ تُفتح وتُغلق</li>
                    <li>جردُ الصندوق عند الإغلاق، والفرقُ يظهر صريحاً</li>
                    <li>سجلُّ تدقيقٍ لكلّ عمليّةٍ حسّاسة</li>
                    <li>تقارير يوميّة وتصديرٌ إلى Excel</li>
                </ul>
            </div>
            <div>
                <h2 style="font-size:24px;margin:0 0 14px;font-weight:800">قبضٌ بلا أجهزة</h2>
                <ul class="ticks">
                    <li>رمز QR ثابتٌ لمتجرك، أو رمزٌ بمبلغٍ محدّد</li>
                    <li>إشعارٌ فوريٌّ عند وصول كلّ دفعة</li>
                    <li>إيصالٌ للعميل، وطباعةٌ إن كان لديك طابعة</li>
                    <li>استردادٌ من داخل التطبيق حين يلزم</li>
                </ul>
            </div>
        </div>
    </div>
</section>

<section class="section">
    <div class="wrap">
        <div class="cta-band">
            <h2>افتح متجرك على أميال باي</h2>
            <p>سجّل نشاطك من التطبيق، ووثّقه، وابدأ القبض في اليوم نفسه.</p>
            <div class="cta">
                <a class="btn btn-gold btn-lg" href="{{ route('site.contact') }}">تواصل معنا</a>
            </div>
        </div>
    </div>
</section>

@endsection
