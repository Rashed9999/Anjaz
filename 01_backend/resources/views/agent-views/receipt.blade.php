{{-- AMIAL-COUNTER-RECEIPT-001 — إيصال الشبّاك، صفحةٌ تُطبَع.

     **ورقتان في مستندٍ واحد**: A5 لطابعة المكتب، و٨٠ملم لطابعة الحرارة
     التي تقف على الشبّاك. والاختيار زرٌّ لا نسختان من الصفحة — فنسختان
     تفترقان يوماً، وتصير الورقة التي بيد العميل غير التي في الملفّ.

     ولا شيء هنا من شبكةٍ خارجيّة: فرعٌ ينقطع إنترنته يجب أن يظلّ يطبع. --}}
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>إيصال {{ $r['receipt_number'] }}</title>
    {{-- الخطُّ يُحمَّل من التوكِنز لا يُطلَب بالاسم: كان `'Tajawal'` مذكوراً
         ولا يُحمَّل، فيُطبع الإيصالُ بخطّ النظام — ويختلف من جهازٍ لآخر. --}}
    <link href="{{ asset('assets/css/amial-tokens.css') }}" rel="stylesheet">
    <style>
        :root { --ink:#111; --muted:#6b7280; --line:#d1d5db; --brand:var(--amial-primary-dark); }

        * { box-sizing: border-box; }
        body {
            margin: 0; padding: 16px; background: var(--amial-background); color: var(--ink);
            font-family: var(--amial-font);
            font-size: 13px; direction: rtl;
        }

        .sheet {
            background: #fff; margin: 0 auto; padding: 18px 20px;
            border: 1px solid var(--line); border-radius: 8px;
            width: 148mm; max-width: 100%;
        }

        /* ٨٠ ملم: عرضٌ أضيق وخطٌّ أكبر — تُقرأ على ورقٍ حراريٍّ باهت. */
        body.thermal .sheet { width: 76mm; padding: 8px 6px; font-size: 12px; border: none; }
        body.thermal .hide-thermal { display: none; }
        body.thermal table td { padding: 3px 0; }

        .head { text-align: center; border-bottom: 2px solid var(--brand); padding-bottom: 10px; margin-bottom: 12px; }
        .brand { font-weight: 800; font-size: 17px; color: var(--brand); }
        .kind { display: inline-block; margin-top: 6px; padding: 3px 12px; border-radius: 20px;
                font-weight: 700; font-size: 13px; }
        .kind.in  { background: #dcfce7; color: #166534; }
        .kind.out { background: #dbeafe; color: #1e40af; }

        table { width: 100%; border-collapse: collapse; }
        td { padding: 5px 0; vertical-align: top; }
        td.k { color: var(--muted); width: 42%; }
        td.v { font-weight: 600; }
        .num { font-variant-numeric: tabular-nums; }
        .ltr { direction: ltr; unicode-bidi: embed; text-align: left; }

        .total { border-top: 1px dashed var(--line); border-bottom: 1px dashed var(--line);
                 margin: 10px 0; padding: 8px 0; }
        .total .amount { font-size: 22px; font-weight: 800; }

        .verify { margin-top: 12px; padding-top: 10px; border-top: 1px solid var(--line);
                  text-align: center; color: var(--muted); font-size: 11px; }
        .code { font-family: ui-monospace, Menlo, monospace; font-size: 15px; font-weight: 700;
                letter-spacing: 2px; color: var(--ink); direction: ltr; }

        .bar { max-width: 148mm; margin: 0 auto 12px; display: flex; gap: 8px; flex-wrap: wrap; }
        .btn { padding: 8px 14px; border: 1px solid var(--line); border-radius: 6px;
               background: #fff; cursor: pointer; font-family: inherit; font-size: 13px; }
        .btn.primary { background: var(--brand); color: #fff; border-color: var(--brand); }

        @media print {
            body { background: #fff; padding: 0; }
            .bar { display: none !important; }
            .sheet { border: none; border-radius: 0; width: auto; padding: 0; }
            @page { margin: 10mm; }
        }
        /* الطابعة الحرارية بلا هوامش: كلّ ملّيمترٍ ورقٌ يُهدر. */
        body.thermal { padding: 0; }
        @media print { body.thermal .sheet { padding: 0 2mm; } body.thermal { } }
    </style>
</head>
<body>

<div class="bar">
    <button class="btn primary" id="btn-print">🖨️ طباعة</button>
    <button class="btn" id="btn-thermal">↔ تبديل: ٨٠ ملم / A5</button>
    <button class="btn" id="btn-close">إغلاق</button>
</div>

<div class="sheet">
    <div class="head">
        <div class="brand">أميال باي</div>
        <div style="color:var(--muted)">{{ $r['branch_name'] }}@if($r['branch_city']) — {{ $r['branch_city'] }}@endif</div>
        <div class="kind {{ $r['direction'] === 'credit' ? 'in' : 'out' }}">
            {{ $r['kind_label'] }}
        </div>
    </div>

    <table>
        <tr><td class="k">رقم الإيصال</td>
            <td class="v ltr num">{{ $r['receipt_number'] }}</td></tr>
        <tr><td class="k">رقم العملية</td>
            <td class="v ltr num">{{ $r['reference'] }}</td></tr>
        <tr><td class="k">التاريخ والوقت</td>
            <td class="v num">{{ $r['issued_at'] }}</td></tr>
        <tr><td class="k">العميل</td>
            <td class="v">{{ $r['customer_name'] }}</td></tr>
        <tr><td class="k">هاتف العميل</td>
            <td class="v ltr num">{{ $r['customer_phone'] }}</td></tr>
        @if($r['teller_name'])
        <tr><td class="k">الصرّاف</td>
            <td class="v">{{ $r['teller_name'] }}@if($r['teller_code']) <span class="ltr">({{ $r['teller_code'] }})</span>@endif</td></tr>
        @endif
        <tr><td class="k">الفرع</td>
            <td class="v">{{ $r['branch_name'] }} <span class="ltr">{{ $r['branch_code'] }}</span></td></tr>
    </table>

    <div class="total">
        <table>
            <tr><td class="k">{{ $r['direction'] === 'credit' ? 'المبلغ المضاف إلى المحفظة' : 'المبلغ المستلَم نقداً' }}</td>
                <td class="v"><span class="amount num">{{ number_format((float) $r['amount'], 2) }}</span> ر.ي</td></tr>
            <tr><td class="k">رسم العملية</td>
                <td class="v num">{{ number_format((float) $r['fee'], 2) }} ر.ي</td></tr>
            <tr><td class="k">{{ $r['direction'] === 'credit' ? 'النقد المسلَّم للصرّاف' : 'المخصوم من المحفظة' }}</td>
                <td class="v num">{{ number_format((float) $r['gross'], 2) }} ر.ي</td></tr>
        </table>
    </div>

    @if($r['note'])
        <div style="color:var(--muted)">ملاحظة: {{ $r['note'] }}</div>
    @endif

    <div class="verify">
        {{-- **رمزُ التحقّق هو ما يجعل الورقة مستنداً.** ورقةٌ بلا وسيلةٍ
             للتأكّد منها لا تُثبت شيئاً — تُطبَع مثلُها في أيّ مكان. --}}
        <div>للتحقّق من صحّة هذا الإيصال:</div>
        <div class="code">{{ $r['verification_code'] }}</div>
        <div class="ltr" style="margin-top:4px">{{ $r['verify_url'] }}</div>
        <div class="hide-thermal" style="margin-top:8px">
            هذا إيصالٌ صادرٌ إلكترونيّاً ولا يحتاج ختماً. ونسختُه محفوظةٌ في حساب العميل بالتطبيق.
        </div>
    </div>
</div>

<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(function () {
    const KEY = 'amial_receipt_thermal';

    // تفضيلُ الطابعة يُحفظ: صرّافٌ يبدّل القياس في كلّ إيصالٍ خمسين مرّةً
    // يومياً يتركه بعد أسبوع.
    if (localStorage.getItem(KEY) === '1') document.body.classList.add('thermal');

    document.getElementById('btn-thermal').addEventListener('click', () => {
        const on = document.body.classList.toggle('thermal');
        localStorage.setItem(KEY, on ? '1' : '0');
    });

    document.getElementById('btn-print').addEventListener('click', () => window.print());
    document.getElementById('btn-close').addEventListener('click', () => window.close());

    // الطباعة تبدأ وحدها حين يُفتح الإيصال بعد عمليّةٍ للتوّ: الصرّاف
    // والعميل واقفان، ونقرةٌ إضافيّة في كلّ عمليّةٍ عبءٌ حقيقيّ.
    if (new URLSearchParams(location.search).get('auto') === '1') {
        window.addEventListener('load', () => setTimeout(() => window.print(), 250));
    }
})();
</script>
</body>
</html>
