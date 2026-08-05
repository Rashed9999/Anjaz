@extends('site.layout')

@section('title', 'لشركات الصرافة')
@section('desc', 'بوّابة أميال باي لشركات الصرافة: فروع وشبابيك وخزائن وورديّات وتسويات يوميّة.')

@section('body')

<section class="page-head">
    <div class="wrap">
        <h1>🏪 بوّابة شركات الصرافة</h1>
        <p>شركةٌ واحدة، فروعٌ كثيرة، وموظّفون لكلٍّ صلاحيّتُه — في بوّابةٍ واحدة على المتصفّح.</p>
    </div>
</section>

<section class="section">
    <div class="wrap">
        <div class="grid grid-3">
            <div class="card"><div class="ico">🏢</div><h3>الفروع</h3><p>كلّ فرعٍ بخزنته وحدوده وموظّفيه — والشركة الأمّ ترى الجميع.</p></div>
            <div class="card"><div class="ico">🪟</div><h3>الشبّاك</h3><p>إيداعٌ وسحبٌ للعملاء، بإيصالٍ وطباعةٍ فوريّة.</p></div>
            <div class="card"><div class="ico">⏱️</div><h3>الورديّات</h3><p>ساعاتٌ ودقائق: يوميّةٌ وأسبوعيّةٌ وشهريّة، وسجلٌّ لا يُعدَّل.</p></div>
            <div class="card"><div class="ico">💰</div><h3>خزنة النقد</h3><p>عهدةٌ لكلّ شبّاك، وجردٌ عند الإغلاق، وفرقٌ يُحسب من مصدره.</p></div>
            <div class="card"><div class="ico">🔄</div><h3>التسويات</h3><p>تسويةٌ يوميّةٌ مع المنصّة، وتمويلٌ للفروع باتّجاهين.</p></div>
            <div class="card"><div class="ico">🛡️</div><h3>حدودٌ وصلاحيّات</h3><p>حدٌّ يوميّ لكلّ موظّف، وطلبُ موافقةِ المدير عند تجاوزه.</p></div>
        </div>
    </div>
</section>

<section class="section alt">
    <div class="wrap">
        <div class="grid grid-2" style="align-items:center;gap:44px">
            <div>
                <h2 style="font-size:24px;margin:0 0 14px;font-weight:800">لا تلاعب في ساعات العمل</h2>
                <p style="color:var(--text-2);margin:0 0 18px">
                    سجلُّ الحضور مربوطٌ بسلسلةٍ مُعمّاة: كلّ حدثٍ يحمل بصمةَ ما قبله.
                    فتعديلُ ساعةٍ واحدةٍ في الماضي يكسر السلسلة كلَّها ويظهر فوراً.
                </p>
                <ul class="ticks">
                    <li>الوقت من الخادم لا من جهاز الموظّف</li>
                    <li>وقتُ الخمول يُعرَض ولا يُخصَم</li>
                    <li>العملُ الإضافيّ يُحسب، ووقوعُه ليس استحقاقَه</li>
                    <li>سجلٌّ مكسورٌ يُصرَّح به فوق الأرقام لا تحتها</li>
                </ul>
            </div>
            <div class="card">
                <h3 style="margin:0 0 10px">زرُّ الطوارئ</h3>
                <p style="color:var(--text-2);font-size:15px;margin:0">
                    للصرّاف زرُّ بلاغٍ صامتٍ يصل إدارتَه فوراً — بواتساب — بلا أن
                    يظهر شيءٌ على شاشته.
                </p>
            </div>
        </div>
    </div>
</section>

<section class="section">
    <div class="wrap">
        <div class="cta-band">
            <h2>انضمّ إلى شبكة أميال باي</h2>
            <p>سجّل شركتك، وأنشئ فروعك وموظّفيك، وابدأ الخدمة.</p>
            <div class="cta">
                <a class="btn btn-gold btn-lg" href="{{ route('site.contact') }}">تقدّم بطلب</a>
                <a class="btn btn-ghost btn-lg" style="color:#fff;border-color:rgba(255,255,255,.35)"
                   href="{{ route('login') }}">لديّ حساب — دخول</a>
            </div>
        </div>
    </div>
</section>

@endsection
