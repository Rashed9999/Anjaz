{{--
    AMIAL-DOC-VERIFY-001 — **صفحةُ التحقّق العامّة.**

    **ولا تُعرَض بياناتٌ حسّاسة**: من يحمل الرمزَ ليس بالضرورة صاحبَ
    المستند. فلا اسمَ عميلٍ ولا هاتفَ ولا رصيد — نوعُ المستند ورقمُه
    ومُصدِرُه ومبلغُه ووقتُه وحالتُه، وكفى.

    **والملغى يُقال ملغىً** — لا «غير موجود». والفرقُ بينهما كلُّ
    المسألة: من ألغى فاتورتَه بحقٍّ يُتَّهم بالتزوير إن خُلطا.
--}}
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>التحقّق من مستند — أميال باي</title>
    <link rel="stylesheet" href="{{ asset('assets/css/amial-tokens.css') }}">
    <style>
        /* **ولا لونَ خامٌّ هنا.** كلُّها توكِنز — ولونٌ مكتوبٌ بالرقم
           يشيخ يومَ تتغيّر العلامة، ويُنتج أزرقاً ثانياً لا يعرفه أحد.
           (حارسُ الهويّة البصريّة.) */
        body{margin:0;background:var(--amial-background);
            font-family:var(--amial-font);color:var(--amial-text)}
        .wrap{max-width:560px;margin:0 auto;padding:24px 16px}
        .brand{text-align:center;margin-bottom:20px}
        .brand h1{font-size:1.25rem;margin:.25rem 0 0}
        .brand p{margin:.25rem 0 0;font-size:.8rem;color:var(--amial-text-secondary)}
        .card{background:var(--amial-surface);border:1px solid var(--amial-border);
            border-radius:var(--amial-radius);box-shadow:var(--amial-shadow);padding:18px;margin-bottom:16px}
        label{display:block;font-size:.85rem;margin-bottom:6px;color:var(--amial-text-secondary)}
        input[type=text]{width:100%;box-sizing:border-box;padding:.7rem .8rem;font-size:1.05rem;
            letter-spacing:.12em;direction:ltr;text-align:center;
            border:1px solid var(--amial-border);border-radius:var(--amial-radius-sm)}
        .row{display:flex;gap:8px;margin-top:12px}
        button{flex:1;padding:.7rem;border:0;border-radius:var(--amial-radius-sm);
            font-size:.95rem;cursor:pointer}
        .primary{background:var(--amial-primary);color:var(--amial-surface)}
        .ghost{background:transparent;color:var(--amial-primary);
            border:1px solid var(--amial-primary)}
        /* **وحالاتُ الحكم من طبقة الحالات** — لا أخضرَ سادسٌ يُخترَع. */
        .v-authentic{background:color-mix(in srgb, var(--amial-success) 12%, transparent);color:var(--amial-success)}
        .v-cancelled{background:color-mix(in srgb, var(--amial-danger) 12%, transparent);color:var(--amial-danger)}
        .v-refunded{background:color-mix(in srgb, var(--amial-warning) 12%, transparent);color:var(--amial-warning)}
        .v-not_found{background:color-mix(in srgb, var(--amial-text-muted) 12%, transparent);color:var(--amial-text-muted)}
        .verdict{text-align:center;padding:18px 12px;border-radius:var(--amial-radius);margin-bottom:14px}
        .verdict .mark{font-size:2rem;line-height:1}
        .verdict .t{font-size:1.05rem;font-weight:700;margin-top:6px}
        .verdict .s{font-size:.8rem;margin-top:4px;opacity:.85}
        dl{margin:0}
        .line{display:flex;justify-content:space-between;gap:12px;padding:.55rem 0;
            border-bottom:1px solid var(--amial-border);font-size:.9rem}
        .line:last-child{border-bottom:0}
        .line dt{color:var(--amial-text-secondary);margin:0}
        .line dd{margin:0;font-weight:600;text-align:left}
        .money{font-variant-numeric:tabular-nums;direction:ltr;display:inline-block}
        .note{font-size:.75rem;color:var(--amial-text-muted);text-align:center;margin-top:14px;line-height:1.7}
        #reader{margin-top:12px}
    </style>
</head>
<body>
<div class="wrap">
    <div class="brand">
        <h1>التحقّق من مستند</h1>
        <p>أدخل رمز التحقّق المطبوع على الفاتورة أو السند، أو امسح رمز الاستجابة السريعة.</p>
    </div>

    <div class="card">
        <form method="get" action="{{ route('public.verify') }}">
            <label for="code">رمز التحقّق</label>
            <input type="text" id="code" name="code" value="{{ $code }}"
                   placeholder="1234 5678 9012 3456" autocomplete="off"
                   inputmode="latin" aria-label="رمز التحقّق">
            <div class="row">
                <button type="submit" class="primary">تحقَّق</button>
                <button type="button" class="ghost" id="scan">امسح رمز QR</button>
            </div>
        </form>
        {{-- **والماسحُ يقول إن تعذّر ولا يصمت.** كاميرا مرفوضةٌ أو متصفّحٌ
             لا يدعمها يجب أن يُقالا، وإلّا ضُغط الزرُّ ولم يحدث شيء. --}}
        <div id="reader"></div>
        <div id="scan-note" class="note" hidden></div>
    </div>

    @if($result !== null)
        @php
            $a = $result['authenticity'];
            $mark = match ($a) {
                'authentic' => '✓', 'cancelled' => '✕', 'refunded' => '↩', default => '?',
            };
        @endphp
        <div class="card">
            <div class="verdict v-{{ $a }}">
                <div class="mark">{{ $mark }}</div>
                <div class="t">{{ $result['authenticity_label'] }}</div>
                @if(! $result['found'] && $result['reason'])
                    <div class="s">{{ $result['reason'] }}</div>
                @elseif($a === 'cancelled')
                    <div class="s">أُلغي هذا المستند بعد إصداره — لا يُعتدّ به.</div>
                @endif
            </div>

            @if($result['found'])
                <dl>
                    <div class="line"><dt>نوع المستند</dt><dd>{{ $result['doc_type_label'] }}</dd></div>
                    <div class="line"><dt>رقم المستند</dt>
                        <dd><span class="money">{{ $result['document_number'] }}</span></dd></div>
                    <div class="line"><dt>الجهة المُصدِرة</dt><dd>{{ $result['issuer'] }}</dd></div>
                    <div class="line"><dt>المبلغ</dt>
                        <dd><span class="money">{{ $result['amount'] }}</span> {{ $result['currency'] }}</dd></div>
                    <div class="line"><dt>التاريخ والوقت</dt>
                        <dd><span class="money">{{ $result['issued_at'] }}</span> <span style="font-weight:400">بتوقيت مكّة</span></dd></div>
                    <div class="line"><dt>الحالة</dt><dd>{{ $result['state_label'] }}</dd></div>
                </dl>
            @endif
        </div>
    @endif

    <div class="note">
        لا تُعرَض في هذه الصفحة أسماءُ العملاء ولا أرقامُهم ولا أرصدتُهم.<br>
        الحالةُ المعروضةُ هي الحالةُ الحاليّة للمستند لحظةَ فتح الصفحة.
    </div>
</div>

<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(function () {
    var btn = document.getElementById('scan');
    var note = document.getElementById('scan-note');
    if (!btn || !note) return;

    function say(msg) { note.hidden = false; note.textContent = msg; }

    btn.addEventListener('click', function () {
        // **ولا يُوعَد بما لا يُفعَل.** متصفّحٌ بلا كاميرا، أو صفحةٌ بلا
        // HTTPS، أو إذنٌ مرفوض — كلُّها تُقال بنصّها بدل زرٍّ يُضغط ولا
        // يحدث شيء. (القاعدة التاسعة.)
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            say('متصفّحك لا يدعم فتح الكاميرا — اكتب الرمز يدويّاً في الحقل أعلاه.');
            return;
        }

        navigator.mediaDevices.getUserMedia({video: {facingMode: 'environment'}})
            .then(function (stream) {
                stream.getTracks().forEach(function (t) { t.stop(); });
                say('امسح رمز السند بكاميرا هاتفك مباشرةً — يفتح صفحة التحقّق من نفسه. '
                    + 'أو اكتب الرمز المطبوع تحته في الحقل أعلاه.');
            })
            .catch(function () {
                say('تعذّر فتح الكاميرا (رُفض الإذن أو لا كاميرا) — اكتب الرمز يدويّاً.');
            });
    });
})();
</script>
</body>
</html>
