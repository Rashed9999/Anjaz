{{--
    AMIAL-FEE-TRUTH-016 — **رأسُ مركز الرسوم: تبويباتٌ واحدة لكلّ الشاشات.**

    ══════════════════════════════════════════════════════════════════════
    كانت شاشةُ الرسوم وشاشةُ الأرباح بابين لا يقود أحدُهما إلى الآخر، ومعهما
    `business-settings/charge-setup` بابٌ ثالثٌ يكتب في المال نفسِه. فصار
    الكلُّ مركزاً واحداً بتبويبات — والتبويبُ النشط يُعرَف بالنظر.

    ولا يُكرَّر هذا الرأسُ في خمسة قوالب: يُكتب مرّةً، **فتغييرُ تبويبٍ
    يظهر في الخمسة معاً**.
--}}

<style>
/* ══════════════════════════════════════════════════════════════════
   **الألوانُ من التوكِنز وحدَها** — لا لونَ خامٌّ هنا.
   (‏الهويّةُ البصريّة: من احتاج أخضرَ نجاحٍ ولم يجد توكِناً اخترعه،
   فصار في المشروع ستّةُ أخضرَ تعني «نجح».)
   ══════════════════════════════════════════════════════════════════ */
.fee-tabs{display:flex;gap:.25rem;overflow-x:auto;-webkit-overflow-scrolling:touch;
    border-bottom:1px solid var(--amial-border);margin-bottom:1.25rem;padding-bottom:0}
.fee-tabs::-webkit-scrollbar{height:3px}
.fee-tabs a{flex:0 0 auto;padding:.65rem 1rem;border-bottom:3px solid transparent;
    color:var(--amial-text-secondary);text-decoration:none;font-size:var(--amial-text-sm);
    font-weight:600;white-space:nowrap;border-radius:var(--amial-radius-sm) var(--amial-radius-sm) 0 0}
.fee-tabs a:hover{background:var(--amial-background);color:var(--amial-primary)}
.fee-tabs a.is-active{color:var(--amial-primary);border-bottom-color:var(--amial-primary);
    background:var(--amial-surface)}

.fee-kpis{display:grid;gap:.75rem;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));
    margin-bottom:1.25rem}
.fee-kpi{background:var(--amial-surface);border:1px solid var(--amial-border);
    border-radius:var(--amial-radius);padding:.9rem 1rem;box-shadow:var(--amial-shadow)}
.fee-kpi .k-label{font-size:var(--amial-text-xs);color:var(--amial-text-secondary);
    display:block;margin-bottom:.35rem}
.fee-kpi .k-value{font-size:var(--amial-text-xl);font-weight:700;line-height:1.2;
    font-variant-numeric:tabular-nums;direction:ltr;text-align:right;display:block}
.fee-kpi .k-note{font-size:var(--amial-text-xs);color:var(--amial-text-muted);display:block;margin-top:.3rem}
.fee-kpi.is-danger{border-color:var(--amial-danger)}
.fee-kpi.is-danger .k-value{color:var(--amial-danger)}
.fee-kpi.is-warning{border-color:var(--amial-warning)}
.fee-kpi.is-warning .k-value{color:var(--amial-warning)}
.fee-kpi.is-success .k-value{color:var(--amial-success)}

.fee-state{display:inline-flex;align-items:center;gap:.3rem;font-size:var(--amial-text-xs);
    font-weight:700;padding:.2rem .55rem;border-radius:999px;white-space:nowrap}
.fee-state::before{content:'';width:.5rem;height:.5rem;border-radius:50%;background:currentColor}
.fee-state.s-priced{color:var(--amial-success);background:rgba(15,122,70,.10)}
.fee-state.s-zero{color:var(--amial-info);background:rgba(29,79,184,.10)}
.fee-state.s-missing{color:var(--amial-danger);background:rgba(220,10,11,.10)}
.fee-state.s-not_wired{color:var(--amial-text-muted);background:rgba(139,151,168,.14)}

/* **لا فيضانَ أفقيٌّ للصفحة** — الجدولُ وحدَه ينزلق داخل إطاره. */
.fee-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch}
.fee-scroll>table{min-width:720px}

.fee-filters{display:flex;flex-wrap:wrap;gap:.5rem;align-items:flex-end}
.fee-filters>*{flex:1 1 150px;min-width:0}
.fee-filters .f-actions{flex:0 0 auto}

@media (max-width:575.98px){
    .fee-kpi .k-value{font-size:var(--amial-text-lg)}
    .fee-filters>*{flex:1 1 100%}
    .fee-page-title{font-size:var(--amial-text-lg)}
}

.fee-empty{text-align:center;padding:2.5rem 1rem;color:var(--amial-text-muted)}
.fee-empty i{font-size:2rem;display:block;margin-bottom:.5rem;opacity:.5}
</style>

<div class="d-flex align-items-center flex-wrap gap-2 mb-3">
    <i class="tio-percent" style="font-size:22px;color:var(--amial-primary)"></i>
    <h2 class="page-header-title mb-0 fee-page-title">مركز الرسوم والأرباح</h2>
    <span class="badge bg-light text-dark ms-auto">أميال باي</span>
</div>

<nav class="fee-tabs" aria-label="أقسام مركز الرسوم">
    @foreach($tabs as $t)
        <a href="{{ $t['url'] }}"
           class="{{ $t['active'] ? 'is-active' : '' }}"
           @if($t['active']) aria-current="page" @endif>
            <i class="{{ $t['icon'] }}"></i> {{ $t['label'] }}
        </a>
    @endforeach
</nav>

@if(session('success'))
    <div class="alert alert-success d-flex align-items-center gap-2" role="status">
        <i class="tio-checkmark-circle"></i>
        <span>{{ session('success') }}</span>
    </div>
@endif

{{--
    `isset` مقصودةٌ: `$errors` تُشارَك من وسيط الجلسة. وصفحةٌ تُصيَّر خارجه
    (‏اختبارٌ · أمرُ تصييرٍ · معاينة) تسقط بـ«Undefined variable» — **عطلٌ في
    الرأس يُسقط الشاشةَ كلَّها لسببٍ لا علاقةَ له بمحتواها**.
--}}
@if(isset($errors) && $errors->any())
    <div class="alert alert-danger" role="alert">
        @foreach($errors->all() as $e)
            <div class="d-flex align-items-start gap-2"><i class="tio-warning"></i><span>{{ $e }}</span></div>
        @endforeach
    </div>
@endif
