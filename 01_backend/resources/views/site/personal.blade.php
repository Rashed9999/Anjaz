@extends('site.layout')

@section('title', 'للأفراد')
@section('desc', 'محفظة أميال باي للأفراد: تحويل فوريّ، دفع بالـ QR، فواتير، وسحب نقديّ من أيّ فرع.')

@section('body')

<section class="page-head">
    <div class="wrap">
        <h1>👤 محفظتك في هاتفك</h1>
        <p>حوّل وادفع واستلم، واسحب نقداً حين تحتاجه — من أيّ فرعٍ في شبكتنا.</p>
    </div>
</section>

<section class="section">
    <div class="wrap">
        <div class="grid grid-3">
            <div class="card">
                <div class="ico">💸</div>
                <h3>تحويلٌ فوريّ</h3>
                <p>أرسل إلى أيّ رقمٍ في أميال باي. يصل في ثوانٍ، ويصلكما إشعارٌ معاً.</p>
            </div>
            <div class="card">
                <div class="ico">📷</div>
                <h3>ادفع بالـ QR</h3>
                <p>امسح رمز التاجر وادفع. بلا نقدٍ، وبلا فكّةٍ مفقودة.</p>
            </div>
            <div class="card">
                <div class="ico">🏧</div>
                <h3>إيداعٌ وسحبٌ نقديّ</h3>
                <p>من شبّاك أيّ فرعٍ في شبكة وكلائنا، برمزِ سحبٍ صالحٍ لمرّةٍ واحدة.</p>
            </div>
            <div class="card">
                <div class="ico">🧾</div>
                <h3>إيصالٌ لكلّ عمليّة</h3>
                <p>يُحفَظ في التطبيق، ويُنزَّل PDF، ويُتحقَّق منه برمزٍ من أيّ جهاز.</p>
            </div>
            <div class="card">
                <div class="ico">📊</div>
                <h3>كشفُ حسابك</h3>
                <p>كلّ ما دخل وخرج، بفلترةٍ بالتاريخ والنوع، وتنزيلٍ حين تحتاجه.</p>
            </div>
            <div class="card">
                <div class="ico">👨‍👩‍👧</div>
                <h3>صندوقُ العائلة</h3>
                <p>صندوقٌ مشترك: تساهمون فيه، ويُصرَف بموافقةِ أكثر من طرف.</p>
            </div>
        </div>
    </div>
</section>

<section class="section alt">
    <div class="wrap">
        <div class="grid grid-2" style="align-items:center;gap:44px">
            <div>
                <h2 style="font-size:26px;margin:0 0 14px;font-weight:800">كيف تبدأ؟</h2>
                <ul class="ticks">
                    <li>حمّل تطبيق أميال باي على هاتفك</li>
                    <li>سجّل برقمك، وأكّده برمزٍ يصلك</li>
                    <li>وثّق هويّتك من داخل التطبيق (صورة وثيقةٍ وصورتك)</li>
                    <li>اشحن محفظتك من أقرب فرعٍ لك، وابدأ</li>
                </ul>
            </div>
            <div class="card">
                <h3 style="margin:0 0 8px">لماذا التوثيق؟</h3>
                <p style="color:var(--text-2);font-size:15px;margin:0 0 14px">
                    لأنّ المحفظة تحمل مالاً. والتوثيق يحمي حسابك من أن يُنتحل،
                    ويُبقي المنصّة ملتزمةً بمتطلّبات مكافحة غسل الأموال.
                </p>
                <p style="color:var(--text-3);font-size:14px;margin:0">
                    ومستنداتُك تُعمَّى قبل أن تُخزَّن، ولا يراها إلّا مراجعٌ مخوَّل.
                </p>
            </div>
        </div>
    </div>
</section>

<section class="section">
    <div class="wrap">
        <div class="cta-band">
            <h2>التطبيق هو بابك</h2>
            <p>خدمات الأفراد كلّها من تطبيق أميال باي — لا من المتصفّح.</p>
            <div class="cta">
                <a class="btn btn-gold btn-lg" href="{{ route('site.contact') }}">اسأل عن التطبيق</a>
            </div>
        </div>
    </div>
</section>

@endsection
