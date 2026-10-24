@extends('layouts.admin.app')

{{--
    AMIAL-VERTICAL-COMPOSE-001 — مركز قطاعات التجّار.

    سأل صاحبُ المشروع: «ماذا لو أردتُ إضافةَ قطاعٍ جديد؟ يبدو ذلك
    مستحيلاً — يحتاج ترميزاً، بينما البنيةُ التحتيّةُ موجودة».

    وقِيس فإذا القطاعُ أربعةُ أسئلةٍ لا أكثر: رمزُه، واسمُه، ونواتُه،
    وما تبيعه كلُّ باقةٍ فوقها. **وأربعتُها بياناتٌ تُملأ من هذه الصفحة.**

    والدليلُ في المنتج نفسِه: `quick_sale` قطاعٌ عاملٌ **بصفر شاشةٍ خاصّة**
    — تركيبٌ من الكاشير المشترك.

    وثلاثةُ حدودٍ تقولها الصفحةُ بنصّها ولا تتركها للاكتشاف: الستّةُ
    المبنيّةُ تُقرأ ولا تُعدَّل · ومحرّكاتُ القطاعات الخاصّة لا تُركَّب ·
    وقطاعٌ فيه تجّارٌ يُعطَّل ولا يُحذَف.
--}}

@section('title', 'قطاعات التجّار')

@section('content')
<div class="content container-fluid" id="vert-center" data-testid="vert-center">

    <div class="d-flex align-items-center gap-3 mb-3">
        <i class="tio-shop text-primary" style="font-size:24px"></i>
        <h2 class="page-header-title mb-0">قطاعات التجّار</h2>
        <span class="badge badge-soft-secondary ms-auto">
            <span id="vert-count">—</span> قطاعاً قائماً
        </span>
    </div>

    <div id="vert-banner"></div>

    <div class="alert alert-soft-info">
        <b>ما يُنشَأ من هنا يصل التاجرَ في اللحظة نفسِها</b> — لا بناءَ تطبيقٍ ولا نشرةَ متجر.
        <div class="mt-2 small">
            القطاعُ يُركَّب من <b>قدراتٍ مبنيّةٍ لها شاشاتٌ تعمل</b> (كاشير · منتجات · مخزون ·
            ديون · موردون · مصروفات · تقارير …). ومحرّكاتُ القطاعات الخاصّة —
            دفعاتُ الصيدليّة، ومضخّاتُ الوقود، وفواتيرُ الجملة، ومطبخُ المطعم —
            <b>لا تُركَّب</b>: شاشةُ كلٍّ منها تقرأ جداولَ قطاعها، فتظهر في قطاعٍ آخر
            بأرقامٍ صفريّةٍ تُقرأ «فحصنا فلم نجد» وهي غياب.
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header d-flex align-items-center gap-2 flex-wrap">
            <h4 class="card-title mb-0">القطاعات القائمة</h4>
            <button class="btn btn-primary btn-sm ms-auto" id="vert-new" data-testid="vert-new">
                ➕ قطاع جديد
            </button>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="thead-light">
                    <tr>
                        <th>القطاع</th>
                        <th>الرمز</th>
                        <th>التجّار</th>
                        <th>النواة</th>
                        <th>العمق المُباع</th>
                        <th>شاشة البداية</th>
                        <th>الحالة</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="vert-rows" data-testid="vert-rows">
                    <tr><td colspan="8" class="text-center p-4">جارٍ التحميل…</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    {{-- ── محرّرُ القطاع ── --}}
    <div class="card d-none" id="vert-editor" data-testid="vert-editor">
        <div class="card-header">
            <h4 class="card-title mb-0" id="vert-editor-title">قطاع جديد</h4>
        </div>
        <div class="card-body">

            <div class="row g-3 mb-3">
                <div class="col-md-3">
                    <label class="form-label">الرمز (إنجليزيّ)</label>
                    <input type="text" class="form-control" id="vert-code" data-testid="vert-code"
                           placeholder="bakery" maxlength="40">
                    <div class="form-text">يُخزَّن في ملفّ التاجر ولا يتغيّر بعد الإنشاء.</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">الاسم بالعربيّة</label>
                    <input type="text" class="form-control" id="vert-name" data-testid="vert-name"
                           placeholder="مخبز" maxlength="60">
                </div>
                <div class="col-md-3">
                    <label class="form-label">تلميحٌ تحت الاسم</label>
                    <input type="text" class="form-control" id="vert-hint"
                           placeholder="خبز، معجّنات" maxlength="120">
                </div>
                <div class="col-md-3">
                    <label class="form-label">شاشةُ البداية</label>
                    <select class="form-select" id="vert-home" data-testid="vert-home">
                        <option value="">— الشاشة العامّة —</option>
                    </select>
                    <div class="form-text">الشاشةُ التي يفتح عليها التاجرُ تطبيقَه.</div>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-3">
                    <label class="form-label">الأيقونة</label>
                    <select class="form-select" id="vert-icon">
                        <option value="storefront">متجر</option>
                        <option value="bakery_dining">مخبز</option>
                        <option value="checkroom">ملابس</option>
                        <option value="menu_book">مكتبة</option>
                        <option value="build">قطع غيار</option>
                        <option value="spa">تجميل</option>
                        <option value="devices">إلكترونيات</option>
                        <option value="shopping_basket">سلّة</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">اللون</label>
                    <input type="text" class="form-control" id="vert-color" value="#00A651" maxlength="9">
                </div>
                <div class="col-md-3">
                    <label class="form-label">الترتيب في القائمة</label>
                    <input type="number" class="form-control" id="vert-sort" value="100" min="0" max="999">
                </div>
                <div class="col-md-3">
                    <label class="form-label">سببُ التغيير (يُسجَّل في التدقيق)</label>
                    <input type="text" class="form-control" id="vert-reason" maxlength="255">
                </div>
            </div>

            <div class="alert alert-soft-secondary py-2 small mb-3" id="vert-shared">
                <b>ما يناله كلُّ تاجرٍ بلا اختيار:</b> <span id="vert-shared-list">—</span>
            </div>

            <div class="table-responsive mb-3" style="max-height:420px;overflow:auto">
                <table class="table table-sm table-bordered mb-0">
                    <thead class="thead-light" style="position:sticky;top:0;z-index:1">
                        <tr>
                            <th>القدرة</th>
                            <th style="width:110px">النواة</th>
                            <th style="width:110px">الأعمال</th>
                            <th style="width:110px">مؤسسة</th>
                        </tr>
                    </thead>
                    <tbody id="vert-caps" data-testid="vert-caps"></tbody>
                </table>
            </div>

            <div class="d-flex gap-2">
                <button class="btn btn-success" id="vert-save" data-testid="vert-save">حفظ</button>
                <button class="btn btn-secondary" id="vert-cancel" data-testid="vert-cancel">إلغاء</button>
            </div>
        </div>
    </div>

</div>

<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(function () {
    const B = '{{ url("admin/amial/verticals") }}';
    const CSRF = '{{ csrf_token() }}';

    const esc = s => String(s ?? '—').replace(/[&<>"']/g,
        m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));

    let DATA = null;      // ردُّ /list كاملاً
    let EDITING = null;   // رمزُ القطاع قيدَ التعديل، أو null لقطاعٍ جديد

    const $ = id => document.getElementById(id);

    function banner(msg, kind) {
        $('vert-banner').innerHTML = '<div class="alert alert-' + kind + '">' + esc(msg) + '</div>';
        window.scrollTo({top: 0, behavior: 'smooth'});
        setTimeout(() => { $('vert-banner').innerHTML = ''; }, 6000);
    }

    async function req(path, method, body) {
        const r = await fetch(B + path, {
            method: method || 'GET',
            headers: {'Accept': 'application/json', 'Content-Type': 'application/json',
                      'X-CSRF-TOKEN': CSRF},
            body: body ? JSON.stringify(body) : undefined,
        });
        const j = await r.json().catch(() => ({}));
        // **الفشلُ يُقال بنصّه** — و«تعذّر» بلا سببٍ لا يُصلَح.
        if (!r.ok || j.ok === false) throw new Error(j.message || ('تعذّر الطلب (' + r.status + ')'));
        return j;
    }

    // ══ القائمة ══
    async function load() {
        try {
            DATA = await req('/list');
        } catch (e) {
            $('vert-rows').innerHTML =
                '<tr><td colspan="8" class="text-danger text-center p-4">' + esc(e.message) + '</td></tr>';
            return;
        }

        $('vert-count').textContent = DATA.verticals.length;
        $('vert-shared-list').textContent = DATA.shared.join(' · ');

        const capName = {};
        DATA.capabilities.forEach(c => { capName[c.code] = c.name; });

        $('vert-rows').innerHTML = DATA.verticals.map(v => {
            const depth = Object.keys(v.paid_depth || {}).map(p =>
                (DATA.plans.find(x => x.code === p) || {}).label + ': '
                + (v.paid_depth[p] || []).map(c => capName[c] || c).join('، ')
            ).join(' — ') || '—';

            const badge = v.is_built_in
                ? '<span class="badge bg-secondary">مبنيّ</span>'
                : (v.is_active ? '<span class="badge bg-success">مُشغَّل</span>'
                               : '<span class="badge bg-warning">معطَّل</span>');

            const actions = v.is_built_in
                ? '<span class="text-muted small">يُقرأ ولا يُعدَّل</span>'
                : '<button class="btn btn-sm btn-outline-primary" data-act="edit" data-code="'
                    + esc(v.code) + '">تعديل</button> '
                  + '<button class="btn btn-sm btn-outline-warning" data-act="toggle" data-code="'
                    + esc(v.code) + '">' + (v.is_active ? 'تعطيل' : 'تشغيل') + '</button> '
                  + '<button class="btn btn-sm btn-outline-danger" data-act="delete" data-code="'
                    + esc(v.code) + '">حذف</button>';

            return '<tr>'
                + '<td><b>' + esc(v.name) + '</b>'
                    + (v.hint ? '<div class="text-muted small">' + esc(v.hint) + '</div>' : '') + '</td>'
                + '<td><code>' + esc(v.code) + '</code></td>'
                + '<td>' + v.merchants + '</td>'
                + '<td class="small">' + esc((v.core_features || []).map(c => capName[c] || c).join('، ')) + '</td>'
                + '<td class="small">' + esc(depth) + '</td>'
                + '<td class="small">' + esc(v.home_capability ? (capName[v.home_capability] || v.home_capability) : 'العامّة') + '</td>'
                + '<td>' + badge + '</td>'
                + '<td class="text-nowrap">' + actions + '</td>'
                + '</tr>';
        }).join('');
    }

    // ══ المحرّر ══
    function capRows(v) {
        const core = new Set((v && v.core_features) || []);
        const depth = (v && v.paid_depth) || {};
        const inPlan = p => new Set(depth[p] || []);
        const biz = inPlan('business'), ent = inPlan('enterprise');

        return DATA.capabilities.map(c => {
            // **المشتركُ لا يُختار** — ممنوحٌ لكلّ تاجرٍ على كلّ حال،
            // واختيارُه يوهم أنّه قرار.
            if (c.is_shared) return '';

            const box = (name, on, dis) =>
                '<input type="checkbox" class="form-check-input vert-cap" data-code="' + esc(c.code)
                + '" data-slot="' + name + '"' + (on ? ' checked' : '') + (dis ? ' disabled' : '') + '>';

            return '<tr>'
                + '<td>' + esc(c.name) + ' <code class="small text-muted">' + esc(c.code) + '</code>'
                    + (c.has_screen ? '' : ' <span class="badge bg-light text-dark">بلا شاشة</span>') + '</td>'
                + '<td class="text-center">' + box('core', core.has(c.code), false) + '</td>'
                + '<td class="text-center">' + box('business', biz.has(c.code), false) + '</td>'
                + '<td class="text-center">' + box('enterprise', ent.has(c.code), false) + '</td>'
                + '</tr>';
        }).join('');
    }

    function homeOptions(v) {
        return '<option value="">— الشاشة العامّة —</option>'
            + DATA.capabilities.filter(c => c.has_screen && !c.is_shared).map(c =>
                '<option value="' + esc(c.code) + '"'
                + (v && v.home_capability === c.code ? ' selected' : '') + '>'
                + esc(c.name) + '</option>').join('');
    }

    function openEditor(code) {
        const v = code ? DATA.verticals.find(x => x.code === code) : null;
        EDITING = code || null;

        $('vert-editor-title').textContent = v ? ('تعديل: ' + v.name) : 'قطاع جديد';
        $('vert-code').value = v ? v.code : '';
        $('vert-code').disabled = !!v;
        $('vert-name').value = v ? v.name : '';
        $('vert-hint').value = (v && v.hint) || '';
        $('vert-color').value = (v && v.color) || '#00A651';
        $('vert-sort').value = v ? v.sort_order : 100;
        $('vert-reason').value = '';
        $('vert-icon').value = (v && v.icon) || 'storefront';
        $('vert-caps').innerHTML = capRows(v);
        $('vert-home').innerHTML = homeOptions(v);
        $('vert-editor').classList.remove('d-none');
        $('vert-editor').scrollIntoView({behavior: 'smooth'});
    }

    function collect() {
        const core = [], business = [], enterprise = [];

        document.querySelectorAll('.vert-cap').forEach(el => {
            if (!el.checked) return;
            const c = el.getAttribute('data-code');
            if (el.getAttribute('data-slot') === 'core') core.push(c);
            else if (el.getAttribute('data-slot') === 'business') business.push(c);
            else enterprise.push(c);
        });

        return {
            code: $('vert-code').value.trim(),
            name_ar: $('vert-name').value.trim(),
            hint_ar: $('vert-hint').value.trim(),
            icon: $('vert-icon').value,
            color: $('vert-color').value.trim(),
            sort_order: parseInt($('vert-sort').value || '100', 10),
            reason: $('vert-reason').value.trim(),
            is_active: true,
            home_capability: $('vert-home').value,
            core_features: core,
            paid_depth: {business: business, enterprise: enterprise},
        };
    }

    // ══ الأزرار ══
    $('vert-new').addEventListener('click', () => openEditor(null));
    $('vert-cancel').addEventListener('click', () => $('vert-editor').classList.add('d-none'));

    $('vert-save').addEventListener('click', async () => {
        const body = collect();
        try {
            const r = EDITING
                ? await req('/' + encodeURIComponent(EDITING), 'POST', body)
                : await req('', 'POST', body);
            banner(r.message || 'حُفظ', 'success');
            $('vert-editor').classList.add('d-none');
            await load();
        } catch (e) {
            banner(e.message, 'danger');
        }
    });

    // **والصفُّ لا يبتلع ضغطةَ زرّه**: الاستماعُ على الجدول والفصلُ
    // بـ`data-act` على الزرّ نفسِه لا على أقرب صفّ. (القاعدة التاسعة.)
    $('vert-rows').addEventListener('click', async (ev) => {
        const btn = ev.target.closest('button[data-act]');
        if (!btn) return;

        const code = btn.getAttribute('data-code');
        const act = btn.getAttribute('data-act');

        if (act === 'edit') { openEditor(code); return; }

        if (act === 'delete' && !confirm('حذفُ «' + code + '» نهائيّ. متابعة؟')) return;

        try {
            const r = act === 'delete'
                ? await req('/' + encodeURIComponent(code), 'DELETE')
                : await req('/' + encodeURIComponent(code) + '/toggle', 'POST', {});
            banner(r.message || 'تمّ', 'success');
            await load();
        } catch (e) {
            banner(e.message, 'danger');
        }
    });

    load();
})();
</script>
@endsection
