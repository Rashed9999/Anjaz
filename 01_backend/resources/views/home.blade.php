{{-- AMIAL-ROOT-DOOR-001 — واجهة `amialpay.com` نفسه.

     **العطل الذي أدخل هذا:** من كتب `amialpay.com` رأى «404 NOT FOUND»
     بالإنجليزيّة على صفحةٍ بيضاء. والجذر لم يكن له مسارٌ إطلاقاً —
     `routes/web.php` فيه `/home` لا `/`. وقالبُ `landing/` الموروث من
     6cash محذوفٌ منذ تنظيفٍ سابق، فمتحكّمه ميّتٌ لا يُوصَل.

     **ولماذا لا يُحوَّل الجذر إلى لوحة الإدارة؟**

     لأنّ من يكتب اسم النطاق ليس الأدمن غالباً: هو صرّافٌ يبحث عن بوّابته،
     أو عميلٌ يبحث عن التطبيق. وصفحةُ دخولٍ للإدارة تقول لكليهما «أنت في
     المكان الخطأ» بلا أن تدلّهما أين المكان الصحيح. وتجعل لوحة الإدارة
     وجهَ النطاق لكلّ من يمرّ — وهو ما لا يُراد.

     فثلاثة أبوابٍ صريحة، وكلٌّ يعرف بابه من السطر تحته.

     ولا شيء من شبكةٍ خارجيّة هنا: لا خطّ ولا إطار. صفحةٌ تعتمد على CDN
     تسقط عند أوّل انقطاع — وهي أوّل ما يراه الزائر. --}}
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>أميال باي — المحفظة والدفع في اليمن</title>
    <meta name="description" content="أميال باي: محفظة إلكترونيّة ونقاط بيع وشبكة صرافة في اليمن.">
    <style>
        * { box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, "Segoe UI", Tahoma, sans-serif;
               background: #f5f7fa; color: #1f2937; margin: 0; padding: 24px;
               display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .wrap { max-width: 560px; width: 100%; }
        .brand { text-align: center; margin-bottom: 26px; }
        .brand h1 { font-size: 30px; margin: 0 0 6px; color: #053391; }
        .brand p { color: #6b7280; font-size: 15px; margin: 0; }
        .door { display: block; background: #fff; border-radius: 14px; padding: 18px 20px;
                margin-bottom: 12px; text-decoration: none; color: inherit;
                box-shadow: 0 4px 16px rgba(0,0,0,.06); border: 1px solid #e5e7eb;
                transition: box-shadow .15s, transform .15s; }
        .door:hover { box-shadow: 0 8px 26px rgba(0,0,0,.10); transform: translateY(-1px); }
        .door .t { font-size: 17px; font-weight: 700; color: #053391; margin-bottom: 3px; }
        .door .s { font-size: 13.5px; color: #6b7280; line-height: 1.8; }
        .door .icon { font-size: 22px; margin-left: 8px; }
        .app { background: #053391; color: #fff; border-color: #053391; }
        .app .t { color: #fff; }
        .app .s { color: #c7d2fe; }
        .foot { text-align: center; color: #9ca3af; font-size: 12.5px; margin-top: 22px;
                line-height: 2; }
        .foot a { color: #6b7280; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="brand">
        <h1>🏦 أميال باي</h1>
        <p>المحفظة الإلكترونيّة ونقاط البيع وشبكة الصرافة</p>
    </div>

    {{-- التطبيق أوّلاً: هو باب الأغلبيّة. --}}
    <div class="door app">
        <div class="t"><span class="icon">📱</span> العملاء والتجّار</div>
        <div class="s">
            الخدمات كلّها في تطبيق أميال باي: التحويل والدفع بالـ QR
            ونقاط البيع والإيصالات.
        </div>
    </div>

    <a class="door" href="{{ url('/agent/login') }}">
        <div class="t"><span class="icon">🏪</span> بوّابة شركات الصرافة</div>
        <div class="s">
            للشركة وفروعها وموظّفيها: الشبّاك والورديّات والخزنة والتسويات.
        </div>
    </a>

    <a class="door" href="{{ url('/admin/auth/login') }}">
        <div class="t"><span class="icon">🛡️</span> لوحة الإدارة</div>
        <div class="s">إدارة المنصّة — لفريق أميال باي وحده.</div>
    </a>

    <div class="foot">
        © {{ date('Y') }} أميال باي — الجمهوريّة اليمنيّة
    </div>
</div>
</body>
</html>
