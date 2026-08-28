<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $receipt ? 'تحقق من سند أميال باي' : 'تعذر التحقق من السند' }}</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #f3f6fb; color: #14213d; font-family: Tahoma, Arial, sans-serif; }
        main { width: min(92vw, 470px); padding: 26px; }
        .card { background: #fff; border-radius: 24px; padding: 30px 24px; box-shadow: 0 12px 36px rgba(5, 51, 145, .11); text-align: center; }
        .brand { color: #053391; font-weight: 800; letter-spacing: .2px; margin-bottom: 24px; }
        .mark { width: 66px; height: 66px; border-radius: 50%; display: grid; place-items: center; margin: 0 auto 16px; font-size: 34px; }
        .ok .mark { background: #e8f8ee; color: #087a55; }
        .bad .mark { background: #fff2f2; color: #b42318; }
        h1 { font-size: 21px; margin: 0 0 9px; }
        p { color: #62718a; line-height: 1.8; margin: 0; font-size: 14px; }
        .rows { margin-top: 24px; border: 1px solid #e4e9f2; border-radius: 15px; overflow: hidden; text-align: right; }
        .row { display: flex; justify-content: space-between; gap: 14px; padding: 13px 15px; border-bottom: 1px solid #e4e9f2; font-size: 14px; }
        .row:last-child { border-bottom: 0; }
        .label { color: #77849a; }
        .value { color: #14213d; font-weight: 700; text-align: left; direction: ltr; }
        .amount { color: #087a55; font-size: 18px; }
        .code { margin-top: 20px; color: #77849a; font-size: 12px; direction: ltr; }
        footer { text-align: center; color: #8c98aa; font-size: 12px; margin-top: 18px; }
    </style>
</head>
<body>
<main>
    <section class="card {{ $receipt ? 'ok' : 'bad' }}">
        <div class="brand">أميال باي</div>
        @if($receipt)
            <div class="mark">✓</div>
            <h1>السند صحيح</h1>
            <p>هذا السند صادر من أميال باي ولم يتم إلغاؤه.</p>
            <div class="rows">
                <div class="row"><span class="label">رقم السند</span><span class="value">{{ $receipt['receipt_number'] }}</span></div>
                <div class="row"><span class="label">نوع العملية</span><span>{{ $receipt['receipt_type_label'] }}</span></div>
                <div class="row"><span class="label">المبلغ</span><span class="value amount">{{ number_format((float) $receipt['amount'], 2) }} ر.ي</span></div>
                <div class="row"><span class="label">التاريخ</span><span class="value">{{ \Illuminate\Support\Carbon::parse($receipt['issued_at'])->format('Y-m-d H:i') }}</span></div>
            </div>
        @else
            <div class="mark">!</div>
            <h1>{{ $invalidFormat ? 'رمز التحقق غير صحيح' : 'لم نعثر على سند صالح' }}</h1>
            <p>تأكد من نسخ رمز التحقق كاملاً من السند ثم حاول مرة أخرى.</p>
        @endif
        @if(!empty($code))
            <div class="code">رمز التحقق: {{ $code }}</div>
        @endif
    </section>
    <footer>تُعرض معلومات التحقق فقط ولا تُكشف بيانات الأطراف.</footer>
</main>
</body>
</html>
