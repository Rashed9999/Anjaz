@extends('layouts.admin.app')
{{-- AMIAL-MAINT-001: لوحة الصيانة الأولية — تشغيل/إيقاف الميزات بحارس مالي --}}
@section('title', translate('Initial Maintenance'))

@section('content')
<div class="content container-fluid" id="maint-panel" data-testid="maint-panel">

    <div class="alert alert-info d-flex align-items-center">
        <span class="me-2" style="font-size:1.5rem">🛠️</span>
        <div>
            <strong>الصيانة الأولية:</strong> أوقف أو شغّل أي ميزة بزرّ واحد دون الدخول للباكند.
            <br><small class="text-muted">قاعدة أمان: لا يمكن إيقاف ميزة فيها أموال عملاء محتجزة (تراكم مالي > 0) — يظهر المبلغ ويُمنع الإيقاف حتى تُفرَّغ.</small>
        </div>
        <button class="btn btn-outline-primary btn-sm ms-auto" id="btn-refresh" data-testid="btn-refresh">تحديث</button>
    </div>

    <div id="maint-body">
        <div class="text-muted">جارٍ التحميل…</div>
    </div>
</div>

<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(function () {
    const BASE = '{{ url('admin/maintenance') }}';
    const CSRF = '{{ csrf_token() }}';
    const esc = s => String(s ?? '—').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    const catLabels = {financial:'مالية', merchant:'تجارية', channel:'قنوات', general:'عامة'};
    const catColor = {financial:'danger', merchant:'primary', channel:'info', general:'secondary'};

    async function load() {
        const box = document.getElementById('maint-body');
        box.innerHTML = '<div class="text-muted">جارٍ التحميل…</div>';
        const r = await fetch(BASE + '/list', {headers: {'Accept': 'application/json'}});
        const j = await r.json();
        if (!j.success) { box.innerHTML = `<div class="alert alert-warning">${esc(j.message)}</div>`; return; }

        // تجميع حسب الفئة
        const groups = {};
        for (const f of j.meta.features) (groups[f.category] ??= []).push(f);

        let html = '';
        for (const [cat, items] of Object.entries(groups)) {
            html += `<h5 class="mt-4 mb-2"><span class="badge bg-${catColor[cat]||'secondary'}">${esc(catLabels[cat]||cat)}</span></h5>`;
            html += '<div class="row g-3">';
            for (const f of items) {
                const on = f.enabled;
                const holdsMoney = parseFloat(f.outstanding_amount) > 0;
                html += `
                <div class="col-md-4 col-sm-6">
                  <div class="card p-3 h-100 ${on ? '' : 'border-danger'}" data-testid="feature-${esc(f.key)}">
                    <div class="d-flex justify-content-between align-items-start">
                      <div>
                        <div class="fw-bold">${esc(f.name_ar)} ${f.is_core ? '<span class="badge bg-dark">أساسية</span>' : ''}</div>
                        <div class="small text-muted">${esc(f.description_ar)}</div>
                      </div>
                      <span class="badge bg-${on ? 'success' : 'danger'}">${on ? 'مفعّلة' : 'موقوفة'}</span>
                    </div>

                    ${f.has_money_source ? `
                      <div class="mt-2 small ${holdsMoney ? 'text-danger' : 'text-success'}">
                        ${holdsMoney
                          ? `💰 محتجز: <strong>${esc(f.outstanding_amount)}</strong> ر.ي في ${f.outstanding_count} عملية — لا يمكن الإيقاف`
                          : '✓ لا أموال محتجزة'}
                      </div>` : '<div class="mt-2 small text-muted">لا تراكم مالي</div>'}

                    <div class="mt-3">
                      ${on
                        ? `<button class="btn btn-sm btn-outline-danger w-100 js-toggle" data-key="${esc(f.key)}" data-act="disable"
                             data-name="${esc(f.name_ar)}" ${f.can_disable ? '' : 'disabled'} data-testid="disable-${esc(f.key)}">
                             ${f.can_disable ? '⏸ إيقاف للصيانة' : '🔒 محجوب (توجد أموال)'}
                           </button>`
                        : `<button class="btn btn-sm btn-success w-100 js-toggle" data-key="${esc(f.key)}" data-act="enable"
                             data-name="${esc(f.name_ar)}" data-testid="enable-${esc(f.key)}">▶ إعادة التشغيل</button>`}
                    </div>
                    ${f.last_note ? `<div class="mt-2 small text-muted">آخر ملاحظة: ${esc(f.last_note)}</div>` : ''}
                  </div>
                </div>`;
            }
            html += '</div>';
        }
        box.innerHTML = html;
    }

    document.getElementById('btn-refresh').onclick = load;

    document.addEventListener('click', async function (e) {
        const b = e.target.closest('.js-toggle');
        if (!b) return;
        const {key, act, name} = b.dataset;
        const verb = act === 'disable' ? 'إيقاف' : 'تشغيل';
        const note = prompt(`سبب ${verb} «${name}» (اختياري):`) ?? '';
        b.disabled = true;
        const r = await fetch(`${BASE}/${key}/${act}`, {
            method: 'POST',
            headers: {'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':CSRF},
            body: JSON.stringify({note}),
        });
        const j = await r.json();
        if (!j.success && j.code === 'FEATURE_HAS_FUNDS') {
            alert(`⛔ ${j.message}`);
        } else {
            alert(j.message || (j.success ? 'تم' : 'فشل'));
        }
        load();
    });

    load();
})();
</script>
@endsection
