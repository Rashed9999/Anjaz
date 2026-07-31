@extends('layouts.admin.app')

{{--
    AMIAL-AML-PANEL-001 — لوحة مكافحة غسل الأموال.

    ما كان ينقص ليس المنطق بل المُشغِّل: `AdminAmlController` فيه اثنتا عشرة
    نقطة نهاية، وكلّها مسجَّلة في المسارات، ولا واحدة منها تُفتح من متصفّح.
    فكان النظام يرصد ويعلّق ويُنبّه، ولا أحد يعتمد أو يرفض — والمعلَّق يبقى
    معلّقاً بلا أجل.

    ثلاثة أشياء تُعرَض هنا عمداً ولا تُخفى خلف إعداد:

    • **وضع الظل** يُكتب على كلّ قاعدة بوضوح. قاعدةٌ في الظلّ تُحصي ولا تمنع،
      ومن يظنّها تمنع يبني قراره على حمايةٍ غير موجودة.

    • **زمن الانتظار** على كلّ عملية معلّقة. العدد وحده لا يقول شيئاً: عشر
      عمليات عمرها ساعة غير عشرٍ عمرها أسبوع.

    • **سبب التعليق** بنصّه (`matched_rules`)، لا رمزُ خطر مجرَّد. من يعتمد
      يحتاج أن يعرف أيّ قاعدةٍ أمسكت العملية.
--}}

@section('title', 'مكافحة غسل الأموال')

@section('content')
<div class="content container-fluid" id="aml-panel" data-testid="aml-panel">

    <div class="d-flex align-items-center gap-3 mb-4">
        <i class="tio-shield-outlined text-warning" style="font-size:24px"></i>
        <h2 class="page-header-title mb-0">مكافحة غسل الأموال</h2>
        <span class="badge badge-soft-secondary ms-auto">AML</span>
    </div>

    <div id="aml-shadow-banner"></div>

    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#aml-tab-flagged" data-testid="aml-tab-flagged">🚩 عمليات معلّقة <span class="badge bg-danger" id="aml-flag-count">0</span></button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#aml-tab-alerts" data-testid="aml-tab-alerts">🔔 التنبيهات</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#aml-tab-rules" data-testid="aml-tab-rules">⚖️ القواعد</button></li>
    </ul>

    <div class="tab-content">

        {{-- ============ العمليات المعلّقة ============ --}}
        <div class="tab-pane fade show active" id="aml-tab-flagged">
            <div class="card p-3">
                <div class="d-flex gap-2 mb-3 flex-wrap align-items-center">
                    <select id="aml-flag-status" class="form-select" style="max-width:220px">
                        <option value="pending_review">بانتظار المراجعة</option>
                        <option value="approved_by_admin">معتمَدة</option>
                        <option value="rejected_by_admin">مرفوضة</option>
                        <option value="">الكل</option>
                    </select>
                    <button class="btn btn-outline-primary" id="aml-btn-flagged" data-testid="aml-btn-flagged">تحديث</button>
                    <span class="text-muted small ms-auto">الاعتماد هنا قرارٌ توثيقيّ: العملية رُفضت وقت وقوعها، والاعتماد يفتح لصاحبها إعادة الإرسال.</span>
                </div>
                <div id="aml-flagged-list"></div>
            </div>
        </div>

        {{-- ============ التنبيهات ============ --}}
        <div class="tab-pane fade" id="aml-tab-alerts">
            <div class="card p-3">
                <div class="d-flex gap-2 mb-3 flex-wrap">
                    <select id="aml-alert-status" class="form-select" style="max-width:180px">
                        <option value="open">مفتوحة</option>
                        <option value="resolved">محلولة</option>
                    </select>
                    <select id="aml-alert-severity" class="form-select" style="max-width:180px">
                        <option value="">كل الدرجات</option>
                        <option value="critical">حرجة</option>
                        <option value="high">عالية</option>
                        <option value="medium">متوسطة</option>
                        <option value="low">منخفضة</option>
                    </select>
                    <button class="btn btn-outline-primary" id="aml-btn-alerts" data-testid="aml-btn-alerts">تحديث</button>
                </div>
                <div id="aml-alerts-list"></div>
            </div>
        </div>

        {{-- ============ القواعد ============ --}}
        <div class="tab-pane fade" id="aml-tab-rules">
            <div class="card p-3">
                <div class="d-flex gap-2 mb-3 align-items-center">
                    <span class="text-muted small">
                        <strong>وضع الظل</strong>: القاعدة تُحصي المخالفات ولا تمنعها. يُستعمل لقياس أثر
                        قاعدة قبل تفعيلها — لا لتأجيل حدٍّ قرَّرته السياسة.
                    </span>
                    <button class="btn btn-outline-primary btn-sm ms-auto" id="aml-btn-rules" data-testid="aml-btn-rules">تحديث</button>
                </div>
                <div id="aml-rules-list"></div>
            </div>
        </div>
    </div>
</div>

<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(function () {
    const BASE = '{{ url('admin/amial/aml') }}';
    const CSRF = '{{ csrf_token() }}';
    const esc = s => String(s ?? '—').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    async function get(path) {
        const r = await fetch(BASE + path, {headers: {'Accept': 'application/json'}});
        return r.json();
    }
    async function send(path, body, method) {
        const r = await fetch(BASE + path, {
            method: method || 'POST',
            headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF},
            body: JSON.stringify(body || {}),
        });
        return r.json();
    }

    // الانتظار هو ما يُشتكى منه — يُعرض بالساعات لا بالتاريخ وحده.
    function waited(iso) {
        if (!iso) return '—';
        const h = Math.floor((Date.now() - new Date(iso).getTime()) / 3600000);
        if (h < 1) return 'أقل من ساعة';
        if (h < 48) return h + ' ساعة';
        return Math.floor(h / 24) + ' يوم';
    }
    function waitClass(iso) {
        if (!iso) return '';
        const h = (Date.now() - new Date(iso).getTime()) / 3600000;
        return h > 72 ? 'text-danger fw-bold' : (h > 24 ? 'text-warning fw-bold' : 'text-muted');
    }

    // ---------- العمليات المعلّقة ----------
    document.getElementById('aml-btn-flagged').onclick = loadFlagged;
    document.getElementById('aml-flag-status').onchange = loadFlagged;

    async function loadFlagged() {
        const st = document.getElementById('aml-flag-status').value;
        const box = document.getElementById('aml-flagged-list');
        box.innerHTML = '<div class="text-muted">جارٍ التحميل…</div>';
        const j = await get('/flagged' + (st ? '?status=' + st : ''));
        if (!j.success) { box.innerHTML = `<div class="alert alert-warning">${esc(j.message)}</div>`; return; }

        const items = j.meta.items || [];
        if (st === 'pending_review') document.getElementById('aml-flag-count').textContent = j.meta.pagination.total;

        const rows = items.map(f => {
            const pending = f.current_status === 'pending_review';
            // سبب التعليق بنصّه: من يعتمد يحتاج أن يعرف أيّ قاعدةٍ أمسكت العملية.
            let matched = f.matched_rules;
            if (typeof matched === 'string') { try { matched = JSON.parse(matched); } catch (e) { matched = null; } }
            const reasons = Array.isArray(matched)
                ? matched.map(m => esc(m.name_ar || m.code || m)).join('، ')
                : '—';
            return `
            <tr>
                <td class="font-monospace small">${esc(f.flag_ulid)}</td>
                <td>${esc(f.actor ? (f.actor.f_name || '') + ' ' + (f.actor.phone_masked || '') : f.actor_user_id)}</td>
                <td class="fw-bold">${esc(f.amount)}</td>
                <td><span class="badge bg-${f.total_risk_score >= 70 ? 'danger' : (f.total_risk_score >= 40 ? 'warning text-dark' : 'secondary')}">${esc(f.total_risk_score)}</span></td>
                <td class="small">${reasons}</td>
                <td class="small ${waitClass(f.created_at)}">${waited(f.created_at)}</td>
                <td>${pending
                    ? `<button class="btn btn-sm btn-success js-aml-flag" data-do="approve" data-ulid="${esc(f.flag_ulid)}">اعتماد</button>
                       <button class="btn btn-sm btn-outline-danger js-aml-flag" data-do="reject" data-ulid="${esc(f.flag_ulid)}">رفض</button>`
                    : `<span class="badge bg-${f.current_status === 'approved_by_admin' ? 'success' : 'dark'}">${f.current_status === 'approved_by_admin' ? 'معتمَدة' : 'مرفوضة'}</span>`}</td>
            </tr>`;
        }).join('');

        box.innerHTML = `<div class="table-responsive"><table class="table table-sm table-hover" data-testid="aml-flagged-table">
            <thead><tr><th>المرجع</th><th>العميل</th><th>المبلغ</th><th>الخطر</th><th>سبب التعليق</th><th>الانتظار</th><th></th></tr></thead>
            <tbody>${rows || '<tr><td colspan="7" class="text-muted text-center py-3">لا عمليات في هذه الحالة</td></tr>'}</tbody></table></div>`;
    }

    document.addEventListener('click', async function (e) {
        const b = e.target.closest('.js-aml-flag');
        if (!b) return;
        const isApprove = b.dataset.do === 'approve';
        // السبب إلزاميّ في الطرفين: القرار يُراجَع لاحقاً من مدقّق لا يعرف
        // ما دار في الغرفة يوم اتُّخذ.
        const note = prompt((isApprove ? 'سبب الاعتماد' : 'سبب الرفض') + ' (إلزامي — 10 أحرف على الأقل، يُسجَّل في التدقيق):');
        if (!note || note.trim().length < 10) { alert('السبب إلزامي (10 أحرف على الأقل)'); return; }
        const j = await send(`/flagged/${b.dataset.ulid}/${b.dataset.do}`, {note: note.trim()});
        alert(j.message || (j.success ? 'تم' : 'فشل'));
        loadFlagged();
    });

    // ---------- التنبيهات ----------
    document.getElementById('aml-btn-alerts').onclick = loadAlerts;

    async function loadAlerts() {
        const st = document.getElementById('aml-alert-status').value;
        const sev = document.getElementById('aml-alert-severity').value;
        const box = document.getElementById('aml-alerts-list');
        box.innerHTML = '<div class="text-muted">جارٍ التحميل…</div>';
        const j = await get('/alerts?status=' + encodeURIComponent(st) + (sev ? '&severity=' + sev : ''));
        if (!j.success) { box.innerHTML = `<div class="alert alert-warning">${esc(j.message)}</div>`; return; }

        const sevBadge = {critical: 'danger', high: 'warning text-dark', medium: 'info', low: 'secondary'};
        const rows = (j.meta.items || []).map(a => `
            <tr>
                <td><span class="badge bg-${sevBadge[a.severity] || 'secondary'}">${esc(a.severity)}</span></td>
                <td>${esc(a.alert_type)}</td>
                <td class="small">${esc(a.description_ar || a.description || '—')}</td>
                <td class="small ${waitClass(a.created_at)}">${waited(a.created_at)}</td>
                <td>${a.status === 'open'
                    ? `<button class="btn btn-sm btn-outline-secondary js-aml-alert" data-ulid="${esc(a.alert_ulid)}">حلّ التنبيه</button>`
                    : '<span class="badge bg-success">محلول</span>'}</td>
            </tr>`).join('');

        box.innerHTML = `<div class="table-responsive"><table class="table table-sm table-hover" data-testid="aml-alerts-table">
            <thead><tr><th>الدرجة</th><th>النوع</th><th>الوصف</th><th>العمر</th><th></th></tr></thead>
            <tbody>${rows || '<tr><td colspan="5" class="text-muted text-center py-3">لا تنبيهات</td></tr>'}</tbody></table></div>`;
    }

    document.addEventListener('click', async function (e) {
        const b = e.target.closest('.js-aml-alert');
        if (!b) return;
        const note = prompt('ماذا فُعل بشأن هذا التنبيه؟ (إلزامي — 5 أحرف على الأقل):');
        if (!note || note.trim().length < 5) { alert('الملاحظة إلزامية (5 أحرف على الأقل)'); return; }
        const j = await send(`/alerts/${b.dataset.ulid}/resolve`, {note: note.trim()});
        alert(j.message || (j.success ? 'تم' : 'فشل'));
        loadAlerts();
    });

    // ---------- القواعد ----------
    document.getElementById('aml-btn-rules').onclick = loadRules;

    async function loadRules() {
        const box = document.getElementById('aml-rules-list');
        box.innerHTML = '<div class="text-muted">جارٍ التحميل…</div>';
        const j = await get('/rules');
        if (!j.success) { box.innerHTML = `<div class="alert alert-warning">${esc(j.message)}</div>`; return; }

        const items = j.meta.items || [];

        // قاعدةٌ مفعَّلة في الظلّ تبدو حمايةً وهي إحصاء. تُقال بصراحة في أعلى
        // الصفحة لا في عمودٍ يُمرَّر عليه البصر.
        const shadowed = items.filter(r => r.is_active && r.shadow_mode);
        document.getElementById('aml-shadow-banner').innerHTML = shadowed.length
            ? `<div class="alert alert-warning" data-testid="aml-shadow-banner">
                 <strong>${shadowed.length} قاعدة تعمل في وضع الظل</strong> — تُحصي المخالفات ولا تمنعها:
                 ${shadowed.map(r => esc(r.name_ar || r.code)).join('، ')}.
               </div>`
            : '';

        const actLabel = {allow: 'يسمح', flag: 'يعلّق', hold: 'يحجز', block: 'يمنع'};
        const rows = items.map(r => `
            <tr class="${r.is_active ? '' : 'opacity-50'}">
                <td class="font-monospace small">${esc(r.code)}</td>
                <td>${esc(r.name_ar)}<div class="small text-muted">${esc(r.description_ar || '')}</div></td>
                <td><span class="badge bg-${r.action_on_match === 'block' ? 'danger' : (r.action_on_match === 'hold' ? 'warning text-dark' : 'secondary')}">${esc(actLabel[r.action_on_match] || r.action_on_match)}</span></td>
                <td>${esc(r.risk_score_contribution)}</td>
                <td>${r.is_active
                    ? '<span class="badge bg-success">مفعَّلة</span>'
                    : '<span class="badge bg-secondary">موقوفة</span>'}</td>
                <td>${r.shadow_mode
                    ? '<span class="badge bg-warning text-dark">ظلّ — لا تمنع</span>'
                    : '<span class="badge bg-primary">نافذة</span>'}</td>
                <td class="text-nowrap">
                    <button class="btn btn-sm btn-outline-secondary js-aml-rule" data-do="toggle" data-id="${r.id}">${r.is_active ? 'إيقاف' : 'تفعيل'}</button>
                    <button class="btn btn-sm btn-outline-${r.shadow_mode ? 'primary' : 'warning'} js-aml-rule" data-do="shadow" data-id="${r.id}" data-shadow="${r.shadow_mode ? 1 : 0}" data-name="${esc(r.name_ar || r.code)}">
                        ${r.shadow_mode ? 'إخراج من الظل' : 'إعادة إلى الظل'}</button>
                </td>
            </tr>`).join('');

        box.innerHTML = `<div class="table-responsive"><table class="table table-sm" data-testid="aml-rules-table">
            <thead><tr><th>الرمز</th><th>القاعدة</th><th>الإجراء</th><th>وزن الخطر</th><th>الحالة</th><th>النفاذ</th><th></th></tr></thead>
            <tbody>${rows || '<tr><td colspan="7" class="text-muted text-center py-3">لا قواعد</td></tr>'}</tbody></table></div>`;
    }

    document.addEventListener('click', async function (e) {
        const b = e.target.closest('.js-aml-rule');
        if (!b) return;
        let j;
        if (b.dataset.do === 'toggle') {
            j = await send(`/rules/${b.dataset.id}/toggle`, {});
        } else {
            const wasShadow = b.dataset.shadow === '1';
            // إخراج قاعدةٍ من الظل يبدأ منعاً فوريّاً لعملياتٍ كانت تمرّ أمس.
            // يُؤكَّد لأنّ أثره على العملاء لا على اللوحة.
            const msg = wasShadow
                ? `«${b.dataset.name}» ستبدأ المنع فوراً — عملياتٌ كانت تمرّ أمس تُرفض اليوم. متابعة؟`
                : `«${b.dataset.name}» ستتوقّف عن المنع وتكتفي بالإحصاء. متابعة؟`;
            if (!confirm(msg)) return;
            j = await send(`/rules/${b.dataset.id}`, {shadow_mode: !wasShadow}, 'PATCH');
        }
        alert(j.message || (j.success ? 'تم' : 'فشل'));
        loadRules();
    });

    // أوّل ما يُفتح: المعلَّق. هو ما ينتظر قراراً.
    loadFlagged();
    loadRules();
})();
</script>
@endsection
