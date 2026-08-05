@extends('site.layout')

@section('title', 'المحفظة الإلكترونيّة ونقاط البيع')
@section('desc', 'أميال باي: حوّل وادفع واستلم في اليمن — للأفراد والتجّار وشركات الصرافة وفروعها.')

@section('body')

<section class="hero">
    <div class="wrap">
        <span class="eyebrow">🇾🇪 صُنع في اليمن — لليمن</span>
        <h1>المال يتحرّك بسرعة الرسالة</h1>
        <p class="lede">
            حوّل وادفع واستلم من هاتفك. وإن أردتَ نقداً، فأقربُ فرع صرافةٍ
            في شبكة أميال باي يصرف لك — بإيصالٍ يمكن التحقّق منه.
        </p>
        <div class="cta">
            <a class="btn btn-gold btn-lg" href="{{ route('site.personal') }}">ابدأ كفرد</a>
            <a class="btn btn-ghost btn-lg" style="color:#fff;border-color:rgba(255,255,255,.35)"
               href="{{ route('site.exchange') }}">أنا شركة صرافة</a>
        </div>
    </div>
</section>

{{-- الأبواب الثلاثة: أوّل سؤالٍ في ذهن الزائر «هل هذا لي؟» --}}
<section class="section">
    <div class="wrap">
        <div class="section-head">
            <h2>لمن أميال باي؟</h2>
            <p>ثلاثة أبواب، ولكلٍّ أدواتُه — لا لوحةٌ واحدةٌ يُحشر فيها الجميع.</p>
        </div>

        <div class="grid grid-3">
            <a class="card audience" href="{{ route('site.personal') }}">
                <div class="ico">👤</div>
                <h3>للأفراد</h3>
                <p>محفظةٌ في هاتفك: تحويلٌ فوريّ، دفعٌ بالـ QR، فواتير، وسحبٌ نقديّ من أيّ فرع.</p>
                <span class="go">اعرف المزيد ←</span>
            </a>

            <a class="card audience" href="{{ route('site.business') }}">
                <div class="ico">🛒</div>
                <h3>للتجّار ونقاط البيع</h3>
                <p>اقبل الدفع بالـ QR، أدِر منتجاتك وموظّفيك ووردياتهم، واطبع الإيصالات.</p>
                <span class="go">اعرف المزيد ←</span>
            </a>

            <a class="card audience" href="{{ route('site.exchange') }}">
                <div class="ico">🏪</div>
                <h3>لشركات الصرافة</h3>
                <p>بوّابةٌ لإدارة فروعك وشبابيكك وخزائنك وورديّات موظّفيك وتسوياتك اليوميّة.</p>
                <span class="go">اعرف المزيد ←</span>
            </a>
        </div>
    </div>
</section>

<section class="section alt">
    <div class="wrap">
        <div class="section-head">
            <h2>ما يميّز أميال باي</h2>
            <p>بُنيت للواقع اليمنيّ: شبكةٌ متقطّعة، ونقدٌ ما زال سيّد المعاملات.</p>
        </div>

        <div class="grid grid-4">
            <div class="card">
                <div class="ico">⚡</div>
                <h3>تحويلٌ في ثوانٍ</h3>
                <p>بين محافظ أميال باي فوراً، وبإشعارٍ للطرفين.</p>
            </div>
            <div class="card">
                <div class="ico">🧾</div>
                <h3>إيصالٌ يُتحقَّق منه</h3>
                <p>لكلّ عمليّة إيصالٌ برمزٍ يُفتح من أيّ جهاز — فلا نزاع على ما وقع.</p>
            </div>
            <div class="card">
                <div class="ico">🏧</div>
                <h3>نقدٌ حين تحتاجه</h3>
                <p>إيداعٌ وسحبٌ من شبّاك أيّ فرعٍ في شبكة وكلائنا.</p>
            </div>
            <div class="card">
                <div class="ico">🔒</div>
                <h3>رقابةٌ مبنيّة في الأساس</h3>
                <p>حدودٌ يوميّة، ومراقبةُ عمليّاتٍ مشبوهة، وسجلٌّ لا يُعدَّل.</p>
            </div>
        </div>
    </div>
</section>

<section class="section">
    <div class="wrap">
        <div class="grid grid-2" style="align-items:center;gap:44px">
            <div>
                <h2 style="font-size:clamp(22px,3.2vw,29px);margin:0 0 14px;font-weight:800">
                    نقدٌ ورقميّ في نظامٍ واحد
                </h2>
                <p style="color:var(--text-2);margin:0 0 20px">
                    أكثر أنظمة المحافظ تفترض أنّ المال كلّه رقميّ. ونحن لا نفترض ذلك:
                    خزنةُ الفرع، وعهدةُ الصرّاف، وجردُ الورديّة — كلُّها جزءٌ من
                    النظام لا ملحقٌ به.
                </p>
                <ul class="ticks">
                    <li>خزنةُ نقدٍ لكلّ فرع، وعهدةٌ لكلّ شبّاك</li>
                    <li>ورديّاتٌ تُفتح وتُغلق بجردٍ فعليّ لا برصيدٍ مخزَّن</li>
                    <li>تسويةٌ يوميّةٌ بين الشركة الأمّ وفروعها والمنصّة</li>
                    <li>فرقُ الجرد يُحسب من مصدره — فلا يخرج صفراً دائماً</li>
                </ul>
            </div>

            <div class="card" style="padding:30px">
                <div class="stats">
                    <div class="stat"><b>٢٤/٧</b><span>خدمةٌ لا تنام</span></div>
                    <div class="stat"><b>ثوانٍ</b><span>زمن التحويل</span></div>
                    <div class="stat"><b>٣</b><span>بوّابات مستقلّة</span></div>
                </div>
                <hr style="border:0;border-top:1px solid var(--border);margin:26px 0">
                <p style="color:var(--text-2);font-size:14.6px;margin:0">
                    كلّ عمليّةٍ تمرّ بدفترِ قيدٍ مزدوج، ويُطابَق الدفتر بالمحافظ
                    آليّاً. فالرقم في شاشتك مبنيٌّ من مصدره، لا منقولٌ من عمود.
                </p>
            </div>
        </div>
    </div>
</section>

<section class="section alt">
    <div class="wrap">
        <div class="cta-band">
            <h2>جاهزٌ للبدء؟</h2>
            <p>حمّل التطبيق إن كنت فرداً أو تاجراً، وادخل البوّابة إن كنت شركة صرافة.</p>
            <div class="cta">
                <a class="btn btn-gold btn-lg" href="{{ route('site.personal') }}">تطبيق أميال باي</a>
                <a class="btn btn-ghost btn-lg" style="color:#fff;border-color:rgba(255,255,255,.35)"
                   href="{{ route('login') }}">تسجيل الدخول</a>
            </div>
        </div>
    </div>
</section>

@endsection
