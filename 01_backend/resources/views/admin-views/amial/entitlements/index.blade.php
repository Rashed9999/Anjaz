@extends('layouts.admin.app')

{{--
    AMIAL-ENTITLEMENTS-001 — مركز الباقات والقدرات.

    التسعيرُ يُجرَّب أكثر من مرّة في الشهور الأولى، وكلُّ تجربةٍ بنشرةٍ تعني
    أنّك لن تجرّب. فالمصفوفةُ تُحرَّر من هنا وتُخزَّن فوق افتراضيّ الشيفرة.

    وأوّلُ تبويبٍ ليس المصفوفة: **صحّةُ السجلّ** — قدرةٌ بلا شاشة، ومسارٌ
    بلا قدرةٍ تحرسه. فهذه هي التي تُنتج «مبنيٌّ ولا يُوصَل إليه».
--}}

@section('title', 'الباقات والقدرات')

@section('content')
<div class="content container-fluid" id="ent-center" data-testid="ent-center">

    <div class="d-flex align-items-center gap-3 mb-3">
        <i class="tio-tune text-primary" style="font-size:24px"></i>
        <h2 class="page-header-title mb-0">الباقات والقدرات</h2>
        <span class="badge badge-soft-secondary ms-auto">مصدر واحد · <span id="ent-count">—</span> قدرة</span>
    </div>

    <div id="ent-banner"></div>

    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#ent-health"
                    data-testid="ent-tab-health">
                🩺 صحة السجل <span class="badge bg-danger" id="ent-health-count">0</span>
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#ent-matrix"
                    data-testid="ent-tab-matrix">🎚️ مصفوفة الباقات</button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#ent-merchant"
                    data-testid="ent-tab-merchant">🏪 ملف خدمات تاجر</button>
        </li>
    </ul>

    <div class="tab-content">

        {{-- ── صحة السجل ── --}}
        <div class="tab-pane fade show active" id="ent-health">
            <div id="ent-health-body">
                <div class="card"><div class="card-body text-center p-5">جارٍ الفحص…</div></div>
            </div>
        </div>

        {{-- ── المصفوفة ── --}}
        <div class="tab-pane fade" id="ent-matrix">
            <div class="card">
                <div class="card-header d-flex align-items-center gap-2 flex-wrap">
                    <h4 class="card-title mb-0">ماذا تفتح كل باقة</h4>
                    <select class="form-select form-select-sm ms-auto" style="width:auto"
                            id="ent-group" data-testid="ent-group">
                        <option value="">كل المجموعات</option>
                    </select>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover table-sm mb-0" id="ent-matrix-table">
                        <thead class="thead-light"><tr id="ent-matrix-head"></tr></thead>
                        <tbody id="ent-matrix-body">
                            <tr><td class="text-center text-muted p-4">جارٍ التحميل…</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer small text-muted">
                    الخانة الرمادية افتراضُ الشيفرة، والزرقاء قرارُ إدارة.
                    والقدرات الأساسية (🔒) لا تُقفَل — تمنع أرقاماً خاطئة ولا تُباع.
                </div>
            </div>
        </div>

        {{-- ── ملف تاجر ── --}}
        <div class="tab-pane fade" id="ent-merchant">
            <div class="card mb-3"><div class="card-body d-flex gap-2 align-items-end">
                <div>
                    <label class="form-label small mb-1">رقم التاجر (id)</label>
                    <input type="number" class="form-control form-control-sm"
                           id="ent-mid" data-testid="ent-mid" style="width:160px">
                </div>
                <button class="btn btn-sm btn-primary" id="ent-load-merchant"
                        data-testid="ent-load-merchant">عرض ملفّه</button>
            </div></div>
            <div id="ent-merchant-body"></div>
        </div>

    </div>
</div>

<script>
(function () {
    const B = '{{ url("admin/amial/entitlements") }}';
    const CSRF = '{{ csrf_token() }}';

    const esc = s => String(s ?? '—').replace(/[&<>"']/g,
        m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));

    let MATRIX = null;

    function banner(msg, kind) {
        document.getElementById('ent-banner').innerHTML =
            '<div class="alert alert-' + kind + '">' + esc(msg) + '</div>';
        setTimeout(() => { document.getElementById('ent-banner').innerHTML = ''; }, 4000);
    }

    async function req(path, method, body) {
        const r = await fetch(B + path, {
            method: method || 'GET',
            headers: {'Accept': 'application/json', 'Content-Type': 'application/json',
                      'X-CSRF-TOKEN': CSRF},
            body: body ? JSON.stringify(body) : undefined,
        });
        const j = await r.json().catch(() => ({}));
        if (!r.ok || j.success === false) {
            // **الفشلُ يُقال بنصّه** — و«تعذّر» بلا سببٍ لا يُصلَح.
            throw new Error(j.message || ('تعذّر الطلب (' + r.status + ')'));
        }
        return j.data;
    }

    // ══ صحة السجل ══
    async function loadHealth() {
        const box = document.getElementById('ent-health-body');
        try {
            const d = await req('/health');
            document.getElementById('ent-count').textContent = d.capabilities;

            const total = d.without_screen.length + d.unguarded_routes.length;
            document.getElementById('ent-health-count').textContent = total;

            const list = (title, rows, note, render) =>
                '<div class="card mb-3"><div class="card-header"><h4 class="card-title mb-0">'
                + esc(title) + ' <span class="badge bg-' + (rows.length ? 'warning' : 'success')
                + '">' + rows.length + '</span></h4></div>'
                + (rows.length
                    ? '<ul class="list-group list-group-flush">'
                      + rows.map(render).join('') + '</ul>'
                    : '<div class="card-body text-success small">لا شيء — فُحص فلم يوجد</div>')
                + '<div class="card-footer small text-muted">' + esc(note) + '</div></div>';

            box.innerHTML =
                list('قدرات بلا شاشة في التطبيق', d.without_screen,
                    'قدرة تُباع ولا يُوصل إليها: يدفع التاجر ولا يجد الزرّ.',
                    r => '<li class="list-group-item py-2">' + esc(r.name)
                       + ' <code class="small">' + esc(r.code) + '</code></li>')
              + list('مسارات تاجر بلا قدرة تحرسها', d.unguarded_routes,
                    'مسار بلا بوّابة يُنادى بلا اشتراك — من يعرف العنوان يدخل.',
                    r => '<li class="list-group-item py-2 font-monospace small">' + esc(r) + '</li>')
              + list('قدرات بلا مسار مُعلَن', d.without_route,
                    'قدرة تُعرض في ملفّ الخدمات ولا تحرس شيئاً — عرضٌ بلا أثر.',
                    r => '<li class="list-group-item py-2">' + esc(r.name)
                       + ' <code class="small">' + esc(r.code) + '</code></li>');
        } catch (e) {
            box.innerHTML = '<div class="alert alert-danger">' + esc(e.message) + '</div>';
        }
    }

    // ══ المصفوفة ══
    async function loadMatrix() {
        try {
            MATRIX = await req('/matrix');

            const sel = document.getElementById('ent-group');
            if (sel.options.length <= 1) {
                MATRIX.groups.forEach(g => {
                    const o = document.createElement('option');
                    o.value = g; o.textContent = g; sel.appendChild(o);
                });
            }

            document.getElementById('ent-matrix-head').innerHTML =
                '<th style="min-width:220px">القدرة</th>'
                + MATRIX.plans.map(p => '<th class="text-center small">' + esc(p.name)
                    + '<div class="text-muted">' + esc(p.price) + ' ر.س</div></th>').join('');

            renderMatrix();
        } catch (e) { banner(e.message, 'danger'); }
    }

    function renderMatrix() {
        const g = document.getElementById('ent-group').value;
        const rows = MATRIX.capabilities.filter(c => !g || c.group === g);

        document.getElementById('ent-matrix-body').innerHTML = rows.map(c => {
            const cells = MATRIX.plans.map(p => {
                const cell = c.cells[p.code];
                if (c.is_core) {
                    return '<td class="text-center"><span class="badge bg-success">دائماً</span></td>';
                }
                const cls = cell.enabled
                    ? (cell.source === 'admin' ? 'btn-primary' : 'btn-outline-success')
                    : (cell.source === 'admin' ? 'btn-danger' : 'btn-outline-secondary');
                return '<td class="text-center"><button class="btn btn-sm ' + cls
                     + '" data-cap="' + esc(c.code) + '" data-plan="' + esc(p.code)
                     + '" data-next="' + (cell.enabled ? '0' : '1')
                     + '" data-testid="ent-cell">' + (cell.enabled ? '✓' : '✕') + '</button></td>';
            }).join('');

            return '<tr><td><strong>' + esc(c.name) + '</strong>'
                 + (c.is_core ? ' 🔒' : '')
                 + (c.has_screen ? '' : ' <span class="badge bg-warning text-dark">بلا شاشة</span>')
                 + '<div class="small text-muted">' + esc(c.group) + ' · '
                 + esc(c.code) + '</div></td>' + cells + '</tr>';
        }).join('') || '<tr><td colspan="6" class="text-center text-muted p-4">لا قدرات في هذه المجموعة</td></tr>';
    }

    document.getElementById('ent-group').addEventListener('change', renderMatrix);

    document.getElementById('ent-matrix-body').addEventListener('click', async function (e) {
        // **`closest` من الزرّ لا من الصفّ** — وصعودُها إلى الصفّ يبتلع الضغطة.
        const btn = e.target.closest('button[data-cap]');
        if (!btn) return;

        const enable = btn.getAttribute('data-next') === '1';
        const reason = prompt(
            (enable ? 'فتح' : 'إقفال') + ' هذه القدرة في هذه الباقة — اكتب السبب:');
        if (!reason) return;

        try {
            const d = await req('/matrix', 'POST', {
                plan: btn.getAttribute('data-plan'),
                capability_code: btn.getAttribute('data-cap'),
                is_enabled: enable,
                reason: reason,
            });
            banner('تم الحفظ', 'success');
            await loadMatrix();
        } catch (err) { banner(err.message, 'danger'); }
    });

    // ══ ملف تاجر ══
    async function loadMerchant() {
        const id = document.getElementById('ent-mid').value;
        const box = document.getElementById('ent-merchant-body');
        if (!id) { banner('اكتب رقم التاجر', 'warning'); return; }

        box.innerHTML = '<div class="card"><div class="card-body text-center p-5">جارٍ التحميل…</div></div>';
        try {
            const d = await req('/merchants/' + id);
            const m = d.merchant, s = d.manifest.summary;

            const stateBadge = c => {
                if (c.state === 'available') return '<span class="badge bg-success">متاح</span>';
                if (c.state === 'locked_by_plan') return '<span class="badge bg-warning text-dark">الباقة: '
                    + esc(c.unlock ? c.unlock.plan_name : '—') + '</span>';
                if (c.state === 'limit_reached') return '<span class="badge bg-danger">بلغ الحدّ '
                    + esc(c.usage ? c.usage.used + '/' + c.usage.max : '') + '</span>';
                return '<span class="badge bg-secondary">الدور</span>';
            };

            box.innerHTML =
                '<div class="card mb-3"><div class="card-body">'
                + '<h4>' + esc(m.name) + ' · ' + esc(m.phone) + '</h4>'
                + '<div class="small text-muted">الباقة: <strong>' + esc(m.plan_name)
                + '</strong> · النشاط: ' + esc(m.business_type)
                + ' · تنتهي: ' + esc(m.expires_at || 'بلا تاريخ') + '</div>'
                + '<div class="mt-2">'
                + '<span class="badge bg-success">متاح ' + s.available + '</span> '
                + '<span class="badge bg-warning text-dark">مقفل بالباقة ' + s.locked_by_plan + '</span> '
                + '<span class="badge bg-secondary">مقفل بالدور ' + s.locked_by_role + '</span> '
                + '<span class="badge bg-danger">بلغ الحدّ ' + s.limit_reached + '</span>'
                + '</div></div></div>'

                + '<div class="card mb-3"><div class="card-header"><h4 class="card-title mb-0">'
                + 'استثناءات هذا التاجر</h4></div>'
                + (d.overrides.length
                    ? '<ul class="list-group list-group-flush">' + d.overrides.map(o =>
                        '<li class="list-group-item py-2 d-flex align-items-center gap-2">'
                        + '<span class="badge bg-' + (o.effect === 'grant' ? 'success' : 'danger')
                        + '">' + (o.effect === 'grant' ? 'ممنوحة' : 'ممنوعة') + '</span>'
                        + esc(o.capability)
                        + '<span class="small text-muted ms-auto">' + esc(o.expires_at)
                        + (o.expired ? ' (منتهٍ)' : '') + ' · ' + esc(o.reason) + '</span>'
                        + '<button class="btn btn-sm btn-outline-danger" data-rm="' + esc(o.id)
                        + '" data-testid="ent-rm-override">إزالة</button></li>').join('')
                      + '</ul>'
                    : '<div class="card-body small text-muted">لا استثناءات — يعمل بباقته وحدها</div>')
                + '</div>'

                + '<div class="card"><div class="card-header"><h4 class="card-title mb-0">'
                + 'ملفّ خدماته كما يراه</h4></div>'
                + '<div class="table-responsive"><table class="table table-sm mb-0"><tbody>'
                + d.manifest.capabilities.map(c =>
                    '<tr><td>' + esc(c.capability.name)
                    + '<div class="small text-muted">' + esc(c.capability.group) + '</div></td>'
                    + '<td class="text-end">' + stateBadge(c)
                    + ' <button class="btn btn-sm btn-outline-primary" data-grant="'
                    + esc(c.capability.code) + '" data-testid="ent-grant">منح</button></td></tr>'
                  ).join('')
                + '</tbody></table></div></div>';
        } catch (e) {
            box.innerHTML = '<div class="alert alert-danger">' + esc(e.message) + '</div>';
        }
    }

    document.getElementById('ent-load-merchant').addEventListener('click', loadMerchant);

    document.getElementById('ent-merchant-body').addEventListener('click', async function (e) {
        const id = document.getElementById('ent-mid').value;

        const grant = e.target.closest('button[data-grant]');
        if (grant) {
            const reason = prompt('سبب المنح (تجربة؟ تعويض؟):');
            if (!reason) return;
            const days = prompt('مدة المنحة بالأيام (اتركه فارغاً للدوام):', '14');
            try {
                await req('/merchants/' + id + '/override', 'POST', {
                    capability_code: grant.getAttribute('data-grant'),
                    effect: 'grant', reason: reason, days: days ? parseInt(days, 10) : 0,
                });
                banner('مُنحت', 'success');
                await loadMerchant();
            } catch (err) { banner(err.message, 'danger'); }
            return;
        }

        const rm = e.target.closest('button[data-rm]');
        if (rm) {
            try {
                await req('/merchants/' + id + '/override/' + rm.getAttribute('data-rm'), 'DELETE');
                banner('أُزيل', 'success');
                await loadMerchant();
            } catch (err) { banner(err.message, 'danger'); }
        }
    });

    loadHealth();
    loadMatrix();
})();
</script>
@endsection
