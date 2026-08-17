@extends('layouts.admin.app')

@section('title', 'نسخةُ رسمٍ جديدة — أميال باي')

@section('content')
{{--
    AMIAL-FEE-TRUTH-021 — **§9: نموذجٌ يعرض ما ينطبق فقط.**

    ══════════════════════════════════════════════════════════════════════
    كان النموذجُ يعرض **كلَّ الحقول لكلّ العمليّات**:

      · قائمةٌ منسدلةٌ بالرموز الخام — `FAMILY_FUND_CONTRIB` بحروفٍ لاتينيّة
      · «تُطبَّق على» بالخيارات الثلاثة كلِّها ولو كانت العمليّةُ للتاجر وحده
      · «يتحمّلها» بالثلاثة ولو كان المتحمّلُ محدَّداً
      · و**«حصّةُ الوكيل» في كلّ نسخة** — ومنها التحويلُ بين محفظتين

    وآخرُها أخطرُها: من ملأ حصّةَ وكيلٍ على `SEND_MONEY` ظنّ أنّه منح
    الوكلاءَ عمولةً، **والرقمُ يُقتطع من ربح المنصّة ويُقيَّد لحصّةٍ لا صاحبَ
    لها** — والدفترُ متوازنٌ فلا يُنبّه أحد.

    فصار النموذجُ يقرأ **سجلَّ العمليّات**: كلُّ حقلٍ يظهر إن كان ينطبق،
    ويختفي مع سببِ اختفائه إن لم ينطبق.
--}}
@php
    $old = fn ($k, $d = null) => old($k, $d);
    $cur = $current;
@endphp

<div class="content container-fluid">

    @include('admin-views.amial.fees._shell')

    <form method="POST" action="{{ route('admin.amial.fees.store') }}" id="fee-form">
        @csrf

        <div class="row g-3">
            {{-- ══════════ الحقول ══════════ --}}
            <div class="col-lg-7">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-header-title mb-0">النسخةُ الجديدة</h5>
                    </div>

                    <div class="card-body">
                        {{-- ① العمليّة — بأسمائها العربيّة ومجموعةً بالتصنيف --}}
                        <div class="mb-3">
                            <label class="form-label" for="f-code">
                                العمليّة <span class="text-danger">*</span>
                            </label>
                            <select class="form-select" id="f-code" name="code" required>
                                <option value="">— اختر عمليّة —</option>
                                @foreach($categories as $catKey => $catLabel)
                                    @php($inCat = array_filter($operations, fn ($o) => $o->category === $catKey))
                                    @if($inCat !== [])
                                        <optgroup label="{{ $catLabel }}">
                                            @foreach($inCat as $o)
                                                <option value="{{ $o->code }}"
                                                        @selected($old('code', $prefillCode) === $o->code)
                                                        @disabled(! $o->isLive())>
                                                    {{ $o->labelAr }}{{ $o->isLive() ? '' : ' — غيرُ موصولة' }}
                                                </option>
                                            @endforeach
                                        </optgroup>
                                    @endif
                                @endforeach
                            </select>
                            <div class="form-text" id="op-hint">
                                العمليّاتُ غيرُ الموصولة معطَّلةٌ عمداً: تسعيرُها لا يُخصم منه ريال.
                            </div>
                        </div>

                        <div class="row g-3">
                            {{-- ② الجهة — من جهات هذه العمليّة وحدَها --}}
                            <div class="col-sm-6">
                                <label class="form-label" for="f-applies">
                                    تُطبَّق على <span class="text-danger">*</span>
                                </label>
                                <select class="form-select" id="f-applies" name="applies_to" required>
                                    @foreach(\App\Models\FeeScheme::APPLIES_TO as $a)
                                        <option value="{{ $a }}" @selected($old('applies_to', $appliesTo) === $a)>
                                            @switch($a)
                                                @case('customer') عميل @break
                                                @case('merchant') تاجر @break
                                                @case('agent') وكيل @break
                                            @endswitch
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            {{-- ③ المنطقة — من `ZonePolicyService` لا من قائمةٍ مكتوبة --}}
                            <div class="col-sm-6">
                                <label class="form-label" for="f-zone">
                                    المنطقة <span class="text-danger">*</span>
                                </label>
                                <select class="form-select" id="f-zone" name="zone_code" required>
                                    @foreach($zones as $z => $zLabel)
                                        <option value="{{ $z }}" @selected($old('zone_code', $zone) === $z)>{{ $zLabel }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <hr class="my-4">

                        {{-- ④ نوعُ الرسم — والحقولُ تتبعه --}}
                        <div class="mb-3">
                            <label class="form-label" for="f-type">
                                طريقةُ الحساب <span class="text-danger">*</span>
                            </label>
                            <select class="form-select" id="f-type" name="fee_type" required>
                                @foreach($feeTypes as $ft)
                                    <option value="{{ $ft }}" @selected($old('fee_type', $cur->fee_type ?? 'percent') === $ft)>
                                        @switch($ft)
                                            @case('percent') نسبةٌ من المبلغ @break
                                            @case('fixed') مبلغٌ ثابت @break
                                            @case('percent_plus_fixed') نسبة + مبلغٌ ثابت @break
                                        @endswitch
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="row g-3">
                            <div class="col-sm-6" data-field="percent">
                                <label class="form-label" for="f-percent">النسبة %</label>
                                <input type="number" step="0.0001" min="0" max="100" class="form-control"
                                       id="f-percent" name="percent_rate"
                                       value="{{ $old('percent_rate', $cur->percent_rate ?? '0') }}" required>
                            </div>

                            <div class="col-sm-6" data-field="fixed">
                                <label class="form-label" for="f-fixed">المبلغُ الثابت (ر.ي)</label>
                                <input type="number" step="0.0001" min="0" class="form-control"
                                       id="f-fixed" name="fixed_amount"
                                       value="{{ $old('fixed_amount', $cur->fixed_amount ?? '0') }}" required>
                            </div>

                            <div class="col-sm-6">
                                <label class="form-label" for="f-min">أدنى رسم <span class="text-muted small">(اختياريّ)</span></label>
                                <input type="number" step="0.0001" min="0" class="form-control"
                                       id="f-min" name="min_fee"
                                       value="{{ $old('min_fee', $cur->min_fee ?? '') }}">
                                <div class="form-text">حدٌّ أدنى كبيرٌ على مبلغٍ صغير قد يفوق المبلغَ نفسَه.</div>
                            </div>

                            <div class="col-sm-6">
                                <label class="form-label" for="f-max">أعلى رسم <span class="text-muted small">(اختياريّ)</span></label>
                                <input type="number" step="0.0001" min="0" class="form-control"
                                       id="f-max" name="max_fee"
                                       value="{{ $old('max_fee', $cur->max_fee ?? '') }}">
                            </div>
                        </div>

                        <hr class="my-4">

                        {{-- ⑤ المتحمّل — مرشَّحٌ بحسب العمليّة --}}
                        <div class="mb-3">
                            <label class="form-label" for="f-bearer">
                                من يتحمّل الرسم <span class="text-danger">*</span>
                            </label>
                            <select class="form-select" id="f-bearer" name="bearer" required>
                                @foreach(\App\Models\FeeScheme::BEARERS as $b)
                                    <option value="{{ $b }}" @selected($old('bearer', $cur->bearer ?? 'sender') === $b)>
                                        @switch($b)
                                            @case('sender') المرسِل — يدفع المبلغَ + الرسم @break
                                            @case('receiver') المستلِم — يُخصَم الرسمُ من المبلغ @break
                                            @case('merchant') التاجر — يُخصَم من حصيلته @break
                                        @endswitch
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        {{--
                            ⑥ **حصّةُ الوكيل — تُخفى حيث لا وكيل.**

                            ولا تُعطَّل فحسب: تُخفى ويُقال لماذا. فحقلٌ معطَّلٌ
                            بلا سببٍ يُقرأ عطلاً في الشاشة، ويُبحَث عن حيلةٍ
                            لتفعيله.
                        --}}
                        <div id="agent-block">
                            <div class="alert alert-warning small py-2">
                                <strong>حصّةُ الوكيل تُقتطع من الرسم نفسِه</strong> —
                                فما يأخذه الوكيلُ ينقص من ربح المنصّة، ومجموعُهما يبقى الرسمَ بالضبط.
                            </div>

                            <div class="row g-3">
                                <div class="col-sm-6">
                                    <label class="form-label" for="f-comm-p">حصّةُ الوكيل %</label>
                                    <input type="number" step="0.0001" min="0" max="100" class="form-control"
                                           id="f-comm-p" name="agent_commission_percent"
                                           value="{{ $old('agent_commission_percent', $cur->agent_commission_percent ?? '0') }}" required>
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label" for="f-comm-f">حصّةٌ ثابتة (ر.ي)</label>
                                    <input type="number" step="0.0001" min="0" class="form-control"
                                           id="f-comm-f" name="agent_commission_fixed"
                                           value="{{ $old('agent_commission_fixed', $cur->agent_commission_fixed ?? '0') }}" required>
                                </div>
                            </div>
                        </div>

                        <div id="agent-na" class="alert alert-light small d-none">
                            <i class="tio-info-outined"></i>
                            <strong>لا حصّةَ وكيلٍ في هذه العمليّة</strong> — لا يقف فيها إنسانٌ خلف شبّاكٍ
                            يسلّم نقداً. ورقمٌ هنا يُقتطع من ربح المنصّة ويُقيَّد لمن لم يعمل.
                        </div>

                        <hr class="my-4">

                        <div class="mb-3">
                            <label class="form-label" for="f-label">اسمٌ داخليّ <span class="text-muted small">(اختياريّ)</span></label>
                            <input type="text" maxlength="120" class="form-control" id="f-label" name="label"
                                   value="{{ $old('label', $cur->label ?? '') }}">
                        </div>

                        <div class="mb-0">
                            <label class="form-label" for="f-notes">سببُ التغيير / ملاحظات</label>
                            <textarea class="form-control" id="f-notes" name="notes" rows="2"
                                      maxlength="1000">{{ $old('notes', '') }}</textarea>
                            <div class="form-text">يُحفَظ في سجلّ التغييرات — ويقرؤه من يراجع بعد أشهر.</div>
                        </div>
                    </div>

                    <div class="card-footer d-flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-primary" id="fee-submit">
                            <i class="tio-save"></i> حفظُ النسخة
                        </button>
                        <a href="{{ route('admin.amial.fees.index', ['zone' => $zone]) }}"
                           class="btn btn-outline-secondary" id="fee-cancel">تراجُع</a>
                    </div>
                </div>
            </div>

            {{-- ══════════ التسعيرةُ الحاليّة + المحاكي ══════════ --}}
            <div class="col-lg-5">
                @if($cur)
                    <div class="card mb-3">
                        <div class="card-header">
                            <h5 class="card-header-title mb-0">التسعيرةُ السارية الآن</h5>
                        </div>
                        <div class="card-body">
                            <dl class="row small mb-0">
                                <dt class="col-6">النسخة</dt>
                                <dd class="col-6 font-monospace">v{{ $cur->version }}</dd>
                                <dt class="col-6">طريقةُ الحساب</dt>
                                <dd class="col-6">{{ $cur->fee_type }}</dd>
                                <dt class="col-6">النسبة</dt>
                                <dd class="col-6 money">{{ rtrim(rtrim((string) $cur->percent_rate, '0'), '.') }}%</dd>
                                <dt class="col-6">الثابت</dt>
                                <dd class="col-6 money">{{ rtrim(rtrim((string) $cur->fixed_amount, '0'), '.') }}</dd>
                            </dl>
                            <div class="alert alert-warning small mt-3 mb-0">
                                الحفظُ يُنشئ <strong>v{{ $cur->version + 1 }}</strong> ويُلغي هذه — ولا تُحذف،
                                فالعمليّاتُ التي طُبّقت عليها تبقى مفسَّرةً بها.
                            </div>
                        </div>
                    </div>
                @endif

                {{--
                    §10 — **المحاكي يحسب بما في الشاشة، لا بقيمةٍ مثبَّتة.**

                    وكانت المنطقةُ تُرسَل خاماً وتُقرأ `SOUTH` إن لم تُفهَم،
                    فيُجرَّب تسعيرُ منطقةٍ ويُعرَض رقمُ أخرى.
                --}}
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-header-title mb-0">محاكي التسعيرة</h5>
                    </div>
                    <div class="card-body">
                        <label class="form-label" for="sim-amount">المبلغ</label>
                        <div class="input-group mb-2">
                            <input type="number" step="0.0001" min="0" class="form-control"
                                   id="sim-amount" value="1000">
                            <button class="btn btn-outline-primary" type="button" id="sim-run">احسب</button>
                        </div>

                        <div class="d-flex flex-wrap gap-1 mb-3">
                            @foreach([100, 1000, 10000, 50000] as $preset)
                                <button type="button" class="btn btn-sm btn-outline-secondary sim-preset"
                                        data-amount="{{ $preset }}">{{ number_format($preset) }}</button>
                            @endforeach
                        </div>

                        <div id="sim-out" class="small text-muted">
                            اضغط «احسب» لترى الرسمَ وتوزيعَه بالقيم المكتوبة الآن.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
(function () {
    'use strict';

    /** سجلُّ العمليّات — **من الخادم لا مكتوبٌ هنا**، فلا قائمتان تفترقان. */
    var OPS = @json($operationsJson);
    var BY_CODE = {};
    OPS.forEach(function (o) { BY_CODE[o.code] = o; });

    var $ = function (id) { return document.getElementById(id); };

    var codeSel   = $('f-code');
    var appliesSel= $('f-applies');
    var bearerSel = $('f-bearer');
    var typeSel   = $('f-type');
    var agentBlock= $('agent-block');
    var agentNa   = $('agent-na');
    var hint      = $('op-hint');

    /** يُبقي الخيارات المسموحة ويُخفي غيرَها — ويُصحّح المختار إن سقط. */
    function restrict(sel, allowed) {
        var keep = null;
        Array.prototype.forEach.call(sel.options, function (opt) {
            var ok = allowed.indexOf(opt.value) !== -1;
            opt.hidden = !ok;
            opt.disabled = !ok;
            if (ok && keep === null) keep = opt.value;
            if (ok && opt.value === sel.value) keep = sel.value;
        });
        if (keep !== null) sel.value = keep;
    }

    function applyOperation() {
        var op = BY_CODE[codeSel.value];

        if (!op) {
            hint.textContent = 'اختر عمليّةً لتظهر خياراتُها المشروعة.';
            agentBlock.classList.remove('d-none');
            agentNa.classList.add('d-none');
            return;
        }

        restrict(appliesSel, op.actors);
        restrict(bearerSel, op.bearers);

        // **حصّةُ الوكيل: تظهر حيث تنطبق وحدَها.**
        if (op.agent_commission) {
            agentBlock.classList.remove('d-none');
            agentNa.classList.add('d-none');
        } else {
            agentBlock.classList.add('d-none');
            agentNa.classList.remove('d-none');
            // **وتُصفَّر فعلاً لا تُخفى فحسب** — حقلٌ مخفيٌّ يحمل قيمةً
            // قديمةً يُرسلها المتصفّحُ كما هي، فيرفضها المحرّكُ بعد ملء
            // النموذج كلِّه.
            $('f-comm-p').value = '0';
            $('f-comm-f').value = '0';
        }

        hint.textContent = op.label_ar + ' — ' + op.category_ar
            + (op.live ? '' : ' · غيرُ موصولةٍ بمسارٍ ماليّ: ' + (op.not_wired_reason || ''));
    }

    /** حقولُ النسبة/الثابت تتبع طريقةَ الحساب. */
    function applyType() {
        var t = typeSel.value;
        document.querySelectorAll('[data-field="percent"]').forEach(function (el) {
            el.style.display = (t === 'percent' || t === 'percent_plus_fixed') ? '' : 'none';
        });
        document.querySelectorAll('[data-field="fixed"]').forEach(function (el) {
            el.style.display = (t === 'fixed' || t === 'percent_plus_fixed') ? '' : 'none';
        });
    }

    codeSel.addEventListener('change', applyOperation);
    typeSel.addEventListener('change', applyType);
    applyOperation();
    applyType();

    // ══════════════════════════════════════════════════════════════════
    // المحاكي
    // ══════════════════════════════════════════════════════════════════

    var out = $('sim-out');

    function money(v) {
        return Number(v).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 4});
    }

    async function simulate() {
        var amount = $('sim-amount').value;

        if (amount === '' || Number(amount) < 0) {
            out.innerHTML = '<div class="text-danger">اكتب مبلغاً موجباً.</div>';
            return;
        }

        out.innerHTML = '<div class="text-muted">جارٍ الحساب…</div>';

        var body = new FormData();
        body.append('_token', document.querySelector('input[name=_token]').value);
        body.append('amount', amount);
        body.append('code', codeSel.value || 'SEND_MONEY');
        // **من الشاشة لا من قيمةٍ مثبَّتة** — وهذا كان العطل.
        body.append('zone_code', $('f-zone').value);
        body.append('applies_to', appliesSel.value);
        body.append('fee_type', typeSel.value);
        body.append('percent_rate', $('f-percent').value || '0');
        body.append('fixed_amount', $('f-fixed').value || '0');
        body.append('min_fee', $('f-min').value);
        body.append('max_fee', $('f-max').value);
        body.append('agent_commission_percent', $('f-comm-p').value || '0');
        body.append('agent_commission_fixed', $('f-comm-f').value || '0');
        body.append('bearer', bearerSel.value);

        try {
            var res = await fetch('{{ route('admin.amial.fees.simulate') }}',
                {method: 'POST', body: body, headers: {'Accept': 'application/json'}});
            var j = await res.json();

            if (!j.success) {
                out.innerHTML = '<div class="alert alert-danger py-2 mb-0 small">'
                    + (j.message || 'تعذّر الحساب') + '</div>';
                return;
            }

            var r = j.result;
            out.innerHTML =
                '<table class="table table-sm mb-0"><tbody>'
                + row('المبلغ', money(r.amount))
                + row('<strong>الرسم</strong>', '<strong>' + money(r.fee) + '</strong>')
                + row('ربحُ المنصّة', money(r.platform_profit))
                + row('حصّةُ الوكيل', money(r.agent_commission))
                + row('يُخصَم من الدافع', money(r.total_debit))
                + row('يصل المستلِم', money(r.net_credit))
                + row('المنطقة', r.zone_code)
                + '</tbody></table>';
        } catch (e) {
            out.innerHTML = '<div class="alert alert-danger py-2 mb-0 small">'
                + 'تعذّر الوصولُ إلى الخادم. تحقّق من الاتّصال ثمّ أعِد المحاولة.</div>';
        }
    }

    function row(k, v) {
        return '<tr><td class="small">' + k + '</td>'
            + '<td class="small money text-start">' + v + '</td></tr>';
    }

    $('sim-run').addEventListener('click', simulate);
    document.querySelectorAll('.sim-preset').forEach(function (b) {
        b.addEventListener('click', function () {
            $('sim-amount').value = b.dataset.amount;
            simulate();
        });
    });

    // ══════════════════════════════════════════════════════════════════
    // **§23 — تحذيرُ المغادرة بتغييراتٍ غيرِ محفوظة.**
    //
    // فنموذجٌ ماليٌّ يُملأ ثمّ تُضغط «تراجُع» بالخطأ يضيع كلُّه بلا سؤال.
    // ══════════════════════════════════════════════════════════════════
    var form = $('fee-form');
    var dirty = false;
    var saving = false;

    form.addEventListener('input', function () { dirty = true; });
    form.addEventListener('change', function () { dirty = true; });
    form.addEventListener('submit', function () { saving = true; });

    window.addEventListener('beforeunload', function (e) {
        if (dirty && !saving) {
            e.preventDefault();
            e.returnValue = '';
        }
    });
})();
</script>
@endsection
