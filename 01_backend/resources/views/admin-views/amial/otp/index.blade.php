@extends('layouts.admin.app')
{{-- AMIAL-OTP-CENTER-001 — مركز التحقّق.

     يُجيب سؤالين كانا يحتاجان فتحَ الخادم:
       ١) لماذا لا يصل رمزُ التحقّق؟   ← جدولُ صحّة المزوّدين
       ٢) من يستطيع التسجيل بالرمز الثابت؟ ← جدولُ أرقام العرض

     ولا خريطةَ هنا عمداً: التحقّقُ بلا جغرافيا، ورسمٌ للزينة يزاحم ما
     يُقرأ. والرسمُ الوحيد — محاولاتُ ١٤ يوماً — يُجيب «هل التسجيل يعمل؟»
     وهبوطُه إلى صفرٍ هو الإنذار: العميلُ الذي لم يستطع التسجيل لا يشتكي،
     يذهب. --}}
@section('title', 'مركز التحقّق')

@push('css_or_js')
<style>
    .otpc { --ok:#12694E; --warn:#B8860B; --bad:#C0392B; --pri:#053391; }
    .otpc .card { border:1px solid #eef1f5; border-radius:14px; }
    .otpc .kpi { font-size:22px; font-weight:800; line-height:1.15; }
    .otpc .kpi-l { font-size:11.5px; color:#8B97A8; }
    .otpc .door { border-radius:16px; padding:16px 18px; color:#fff; }
    .otpc .door-open { background:linear-gradient(135deg,#C0392B,#E05C4A); }
    .otpc .door-shut { background:linear-gradient(135deg,#12694E,#1B8A68); }
    .otpc .bar { display:inline-block; width:100%; max-width:22px; border-radius:5px 5px 0 0; background:var(--pri); }
    .otpc .bar-b { background:var(--bad); }
    .otpc table thead th { position:sticky; top:0; background:#f8fafc; z-index:2; }
    .otpc .srt { cursor:pointer; user-select:none; }
    .otpc .srt:hover { color:var(--pri); }
</style>
@endpush

@section('content')
<div class="content container-fluid otpc" dir="rtl">

    {{-- ==== العنوان + المسار ==== --}}
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb small mb-1">
            <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">لوحة التحكّم</a></li>
            <li class="breadcrumb-item">الحسابات والتحقّق</li>
            <li class="breadcrumb-item active">مركز التحقّق</li>
        </ol>
    </nav>

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h4 class="fw-bold mb-0" style="color:var(--pri)">🔐 مركز التحقّق</h4>
            <small class="text-muted">رمزُ التحقّق، وأرقامُ العرض، وصحّةُ بوّابات الإرسال</small>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-sm btn-outline-secondary" id="otp-help" data-testid="otp-help">؟ مساعدة</button>
            <button class="btn btn-sm btn-outline-secondary" id="otp-refresh" data-testid="otp-refresh">⟳ تحديث</button>
        </div>
    </div>

    <div id="otp-alert"></div>

    {{-- ==== حالةُ الباب — أهمُّ حقيقةٍ في الشاشة ==== --}}
    <div id="otp-door" class="door door-shut mb-3" data-testid="otp-door">
        <div class="small">…جارٍ القراءة</div>
    </div>

    {{-- ==== المؤشّرات ==== --}}
    <div class="row g-3 mb-3" id="otp-kpis" data-testid="otp-kpis"></div>

    <div class="row g-3">
        {{-- ==== الرسم ==== --}}
        <div class="col-lg-7">
            <div class="card p-3 h-100">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <strong>📈 محاولات التحقّق — ١٤ يوماً</strong>
                    <small class="text-muted">الأزرق: محاولات · الأحمر: محظورة</small>
                </div>
                <div id="otp-chart" style="height:190px" data-testid="otp-chart"></div>
                <div class="small text-muted mt-2">
                    هبوطٌ مفاجئٌ إلى صفرٍ يعني أنّ التسجيل توقّف — ومن لم يستطع التسجيل لا يشتكي.
                </div>
            </div>
        </div>

        {{-- ==== صحّةُ المزوّدين ==== --}}
        <div class="col-lg-5">
            <div class="card p-3 h-100">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <strong>📡 بوّابات الإرسال</strong>
                    <button class="btn btn-sm btn-outline-primary" id="otp-test" data-testid="otp-test">✉️ رسالةُ فحص</button>
                </div>
                <div id="otp-providers" data-testid="otp-providers">
                    <div class="text-muted small py-3">…جارٍ القراءة</div>
                </div>
            </div>
        </div>
    </div>

    {{-- ==== أرقامُ العرض ==== --}}
    <div class="card p-3 mt-3">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <strong>📱 أرقامُ العرض — تقبل الرمزَ الثابت</strong>
            <div class="d-flex gap-2 flex-wrap">
                <button class="btn btn-sm btn-primary" id="otp-add" data-testid="otp-add">＋ إضافة رقم</button>
                <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.amial.otp.export') }}" data-testid="otp-export">⬇️ تصدير CSV</a>
                <button class="btn btn-sm btn-danger" id="otp-close" data-testid="otp-close">🔒 إقفال الباب</button>
            </div>
        </div>

        {{-- بحث + فلاتر --}}
        <div class="row g-2 mb-3">
            <div class="col-md-5">
                <input type="search" class="form-control form-control-sm" id="otp-search"
                       placeholder="بحث برقمٍ أو وصف…" data-testid="otp-search">
            </div>
            <div class="col-md-3">
                <select class="form-select form-select-sm" id="otp-state" data-testid="otp-state">
                    <option value="">كلّ الحالات</option>
                    <option value="active">المُفعَّلة</option>
                    <option value="disabled">المعطَّلة</option>
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select form-select-sm" id="otp-per" data-testid="otp-per">
                    <option value="25">٢٥ صفّاً</option>
                    <option value="50">٥٠ صفّاً</option>
                    <option value="100">١٠٠ صفّ</option>
                </select>
            </div>
        </div>

        <div id="otp-env" class="alert alert-warning py-2 small d-none" data-testid="otp-env"></div>

        <div class="table-responsive" style="max-height:520px;overflow:auto">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th class="srt" data-sort="phone">الرقم</th>
                        <th>الوصف</th>
                        <th>الحالة</th>
                        <th class="srt text-end" data-sort="use_count">مرّات الاستعمال</th>
                        <th class="srt" data-sort="last_used_at">آخر استعمال</th>
                        <th class="text-end">إجراء</th>
                    </tr>
                </thead>
                <tbody id="otp-rows" data-testid="otp-rows">
                    <tr><td colspan="6" class="text-center text-muted py-4">…جارٍ التحميل</td></tr>
                </tbody>
            </table>
        </div>

        <div class="d-flex justify-content-between align-items-center mt-2">
            <small class="text-muted" id="otp-count">—</small>
            <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-secondary" id="otp-prev" data-testid="otp-prev">السابق</button>
                <button class="btn btn-outline-secondary" id="otp-next" data-testid="otp-next">التالي</button>
            </div>
        </div>
    </div>
</div>

{{-- ==================== النوافذ ==================== --}}

<div class="modal fade" id="mdAdd" tabindex="-1" aria-labelledby="mdAddT" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <div class="modal-header"><h6 class="modal-title" id="mdAddT">＋ إضافة رقم عرض</h6>
            <button class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div>
        <div class="modal-body">
            <div class="alert alert-warning py-2 small">
                هذا الرقم سيُقبل منه <strong>الرمزُ الثابت</strong> بلا رسالة. لا تُضف رقمَ عميلٍ حقيقيّ.
            </div>
            <label class="form-label small">الرقم</label>
            <input class="form-control mb-2" id="md-phone" placeholder="967777100001" data-testid="md-phone">
            <label class="form-label small">الوصف (اختياريّ)</label>
            <input class="form-control" id="md-label" placeholder="عميل تجريبيّ للعرض" data-testid="md-label">
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">إلغاء</button>
            <button class="btn btn-primary btn-sm" id="md-save" data-testid="md-save">حفظ</button>
        </div>
    </div></div>
</div>

<div class="modal fade" id="mdClose" tabindex="-1" aria-labelledby="mdCloseT" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content border-danger">
        <div class="modal-header"><h6 class="modal-title text-danger" id="mdCloseT">🔒 إقفالُ الباب كاملاً</h6>
            <button class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div>
        <div class="modal-body">
            <div class="alert alert-danger py-2 small">
                <strong>يُعطَّل كلُّ أرقام العرض.</strong> لن يُقبل الرمزُ الثابت من أيّ رقم،
                وسيحتاج كلُّ تسجيلٍ رمزاً حقيقيّاً عبر البوّابة.
                <div class="mt-1">افعلها يوم الإطلاق — <strong>وبعد أن تتأكّد أنّ بوّابة الإرسال تعمل.</strong></div>
            </div>
            <div id="md-close-ready"></div>
            <label class="form-label small">اكتب <code>إقفال</code> للتأكيد</label>
            <input class="form-control" id="md-confirm" placeholder="إقفال" data-testid="md-confirm">
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">تراجع</button>
            <button class="btn btn-danger btn-sm" id="md-close-go" data-testid="md-close-go">إقفال</button>
        </div>
    </div></div>
</div>

<div class="modal fade" id="mdTest" tabindex="-1" aria-labelledby="mdTestT" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <div class="modal-header"><h6 class="modal-title" id="mdTestT">✉️ رسالةُ فحص</h6>
            <button class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div>
        <div class="modal-body">
            <p class="small text-muted">تُرسَل رسالةٌ حقيقيّةٌ عبر أوّل مزوّدٍ مُفعَّل — لتتأكّد أنّ القناة تعمل قبل الاعتماد عليها.</p>
            <label class="form-label small">الرقم</label>
            <input class="form-control" id="md-test-phone" placeholder="967…" data-testid="md-test-phone">
            <div id="md-test-out" class="mt-2"></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">إغلاق</button>
            <button class="btn btn-primary btn-sm" id="md-test-go" data-testid="md-test-go">إرسال</button>
        </div>
    </div></div>
</div>

<div class="modal fade" id="mdHelp" tabindex="-1" aria-labelledby="mdHelpT" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content">
        <div class="modal-header"><h6 class="modal-title" id="mdHelpT">؟ كيف يعمل التحقّق</h6>
            <button class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div>
        <div class="modal-body small">
            <p><strong>الرقمُ يحدّد الطريق، لا مفتاحٌ عامّ:</strong></p>
            <ul>
                <li><strong>رقمٌ في هذه القائمة</strong> ← يُقبل منه الرمزُ الثابت، ويُعبَّأ في التطبيق تلقائياً، ولا يحتاج بوّابة.</li>
                <li><strong>أيُّ رقمٍ آخر</strong> ← رمزٌ عشوائيٌّ عبر البوّابة، <strong>ولا يُفصح عنه أبداً</strong>.</li>
            </ul>
            <p class="mb-1"><strong>يوم الإطلاق:</strong> تأكّد أنّ بوّابةً مُفعَّلة، ثمّ اضغط «إقفال الباب».</p>
            <p class="text-muted mb-0">وتُعطَّل الأرقامُ ولا تُحذف — أثرُ من فتح باباً في نظامٍ ماليٍّ لا يُمحى.</p>
        </div>
    </div></div>
</div>
@endsection

@push('script_2')
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(function () {
    const BASE  = '{{ url('admin/amial/otp') }}';
    const TOKEN = '{{ csrf_token() }}';
    const esc = s => String(s ?? '—').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const $ = id => document.getElementById(id);

    let page = 1, sort = 'created_at', dir = 'desc';

    const banner = (kind, msg) => {
        $('otp-alert').innerHTML =
            `<div class="alert alert-${kind} alert-dismissible py-2 small">${esc(msg)}
             <button class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button></div>`;
    };

    async function get(path) {
        const r = await fetch(BASE + path, {headers: {'Accept': 'application/json'}});
        return r.json();
    }

    async function post(path, body) {
        const r = await fetch(BASE + path, {
            method: 'POST',
            headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': TOKEN},
            body: JSON.stringify(body || {}),
        });
        return r.json();
    }

    const busy = (btn, on) => {
        if (!btn) return;
        btn.disabled = on;
        if (on) { btn.dataset.t = btn.innerHTML; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>'; }
        else if (btn.dataset.t) { btn.innerHTML = btn.dataset.t; }
    };

    // ---------- المؤشّرات + الباب + الرسم + المزوّدون ----------
    async function loadStats() {
        const j = await get('/stats');
        if (!j.success) { banner('warning', 'تعذّرت قراءة الحالة'); return; }
        const m = j.meta;

        // حالةُ الباب — أوّلُ ما يُقرأ.
        $('otp-door').className = 'door mb-3 ' + (m.door_open ? 'door-open' : 'door-shut');
        $('otp-door').innerHTML = m.door_open
            ? `<div class="fw-bold" style="font-size:17px">⚠️ البابُ مفتوح — ${m.demo_count} رقماً يقبل الرمزَ الثابت</div>
               <div class="small mt-1" style="opacity:.9">مقبولٌ في التجربة. وقبل الإطلاق: أقفِله.</div>`
            : `<div class="fw-bold" style="font-size:17px">✅ البابُ مقفل — لا رقمَ يقبل الرمزَ الثابت</div>
               <div class="small mt-1" style="opacity:.9">كلُّ تسجيلٍ يحتاج رمزاً حقيقيّاً عبر البوّابة.</div>`;

        const t = m.today;
        const tile = (v, l, cls) =>
            `<div class="col-lg-3 col-md-4 col-6"><div class="card p-3 h-100 ${cls || ''}">
               <div class="kpi">${v}</div><div class="kpi-l">${l}</div></div></div>`;

        $('otp-kpis').innerHTML =
            tile(m.delivery_ready ? '✅ جاهزة' : '🔴 غير مهيّأة', 'قناةُ الإيصال',
                 m.delivery_ready ? 'border-success' : 'border-danger') +
            tile(m.demo_count, 'أرقامُ العرض', m.demo_count ? 'border-warning' : '') +
            tile(t.attempts, 'محاولاتُ اليوم') +
            // «لا محاولات» ليست «٠٪ نجاح» — الغيابُ يُقال. (القاعدة السابعة)
            tile(t.success_rate === null ? '—' : t.success_rate + '٪', 'نسبةُ النجاح اليوم') +
            tile(t.blocked, 'محظورٌ مؤقّتاً', t.blocked ? 'border-warning' : '');

        // الرسم
        const max = Math.max(1, ...m.trend.map(d => d.attempts));
        $('otp-chart').innerHTML =
            '<div class="d-flex align-items-end justify-content-between h-100" style="gap:4px">' +
            m.trend.map(d => {
                const h = Math.round((d.attempts / max) * 150);
                const hb = Math.round((d.blocked / max) * 150);
                return `<div class="d-flex flex-column align-items-center flex-fill" title="${esc(d.date)} — ${d.attempts} محاولة، ${d.blocked} محظورة">
                          <small class="text-muted" style="font-size:9px">${d.attempts || ''}</small>
                          <div class="bar${d.blocked ? ' bar-b' : ''}" style="height:${Math.max(h, 2)}px;opacity:${d.attempts ? 1 : .15}"></div>
                          <small class="text-muted" style="font-size:9px">${esc(d.date).slice(5)}</small>
                        </div>`;
            }).join('') + '</div>';

        // المزوّدون — يُجيب «لماذا لا يصل الرمز؟»
        $('otp-providers').innerHTML = m.providers.length
            ? '<div class="table-responsive"><table class="table table-sm mb-0"><tbody>' +
              m.providers.map(p => `
                <tr>
                  <td>${esc(p.key)}<div class="small text-muted">${esc(p.channel)} · أولويّة ${p.priority}</div></td>
                  <td class="text-end">${p.enabled
                      ? '<span class="badge bg-success">مُفعَّل</span>'
                      : '<span class="badge bg-secondary">غير مُفعَّل</span>'}
                      ${p.free_text ? '<span class="badge bg-light text-dark ms-1">نصّ حرّ</span>' : ''}</td>
                </tr>`).join('') + '</tbody></table></div>'
            : '<div class="text-muted small py-3">لا مزوّدين.</div>';

        $('md-close-ready').innerHTML = m.delivery_ready
            ? '<div class="alert alert-success py-2 small">✅ بوّابةٌ مُفعَّلة — الإقفال آمن.</div>'
            : '<div class="alert alert-danger py-2 small">🔴 <strong>لا بوّابةَ مُفعَّلة.</strong> الإقفالُ الآن يمنع كلَّ تسجيلٍ جديد.</div>';
    }

    // ---------- الجدول ----------
    async function loadRows() {
        const q = new URLSearchParams({
            search: $('otp-search').value, state: $('otp-state').value,
            per_page: $('otp-per').value, page, sort, dir,
        });

        const j = await get('/numbers?' + q);
        if (!j.success) { banner('warning', 'تعذّر جلب الأرقام'); return; }
        const m = j.meta;

        const env = $('otp-env');
        if (m.from_env) {
            env.classList.remove('d-none');
            env.innerHTML = `الجدولُ فارغ — تعمل المنصّة الآن بقائمة <code>AMIAL_DEMO_PHONES</code>
                             (${m.env_numbers.length} رقماً). أوّلُ إضافةٍ هنا تجعل الجدولَ هو الحكم.`;
        } else {
            env.classList.add('d-none');
        }

        $('otp-count').textContent = `${m.total} رقماً · صفحة ${m.page} من ${m.pages}`;
        $('otp-prev').disabled = m.page <= 1;
        $('otp-next').disabled = m.page >= m.pages;

        $('otp-rows').innerHTML = m.rows.length ? m.rows.map(r => `
            <tr>
                <td><code>${esc(r.phone)}</code></td>
                <td>${esc(r.label)}</td>
                <td>${r.is_active
                    ? '<span class="badge bg-warning text-dark">مُفعَّل</span>'
                    : '<span class="badge bg-secondary">معطَّل</span>'}</td>
                <td class="text-end">${r.use_count ?? 0}</td>
                <td class="small text-muted">${esc(r.last_used_at)}</td>
                <td class="text-end">
                    <button class="btn btn-sm ${r.is_active ? 'btn-outline-danger' : 'btn-outline-success'}"
                            data-act="toggle" data-id="${r.id}" data-testid="otp-toggle-${r.id}">
                        ${r.is_active ? 'تعطيل' : 'تفعيل'}
                    </button>
                </td>
            </tr>`).join('')
            : '<tr><td colspan="6" class="text-center text-muted py-4">لا أرقامَ مطابقة.</td></tr>';
    }

    // ---------- الأزرار ----------
    $('otp-refresh').onclick = () => { loadStats(); loadRows(); };
    $('otp-help').onclick    = () => new bootstrap.Modal($('mdHelp')).show();
    $('otp-add').onclick     = () => { $('md-phone').value = ''; $('md-label').value = ''; new bootstrap.Modal($('mdAdd')).show(); };
    $('otp-close').onclick   = () => { $('md-confirm').value = ''; new bootstrap.Modal($('mdClose')).show(); };
    $('otp-test').onclick    = () => { $('md-test-out').innerHTML = ''; new bootstrap.Modal($('mdTest')).show(); };

    $('otp-search').oninput = () => { page = 1; loadRows(); };
    $('otp-state').onchange = () => { page = 1; loadRows(); };
    $('otp-per').onchange   = () => { page = 1; loadRows(); };
    $('otp-prev').onclick   = () => { if (page > 1) { page--; loadRows(); } };
    $('otp-next').onclick   = () => { page++; loadRows(); };

    document.querySelectorAll('.srt').forEach(th => {
        th.onclick = () => {
            const s = th.dataset.sort;
            dir = (sort === s && dir === 'desc') ? 'asc' : 'desc';
            sort = s; loadRows();
        };
    });

    $('md-save').onclick = async function () {
        busy(this, true);
        const j = await post('/numbers', {phone: $('md-phone').value, label: $('md-label').value});
        busy(this, false);
        if (!j.success) { banner('danger', j.message); return; }
        bootstrap.Modal.getInstance($('mdAdd')).hide();
        banner('success', 'أُضيف الرقم');
        loadStats(); loadRows();
    };

    $('md-close-go').onclick = async function () {
        busy(this, true);
        const j = await post('/close-door', {confirm: $('md-confirm').value});
        busy(this, false);
        if (!j.success) { banner('danger', j.message); return; }
        bootstrap.Modal.getInstance($('mdClose')).hide();
        banner('success', `أُقفل الباب — عُطّل ${j.meta.disabled} رقماً`);
        loadStats(); loadRows();
    };

    $('md-test-go').onclick = async function () {
        busy(this, true);
        const j = await post('/test-send', {phone: $('md-test-phone').value});
        busy(this, false);
        $('md-test-out').innerHTML = j.success
            ? '<div class="alert alert-success py-2 small mb-0">✅ وصلت — القناة تعمل.</div>'
            : `<div class="alert alert-danger py-2 small mb-0">${esc(j.message)}</div>`;
    };

    // **تفويضٌ على الجدول** — والصفوف تُبنى بعد التحميل، فمعالجٌ مباشرٌ
    // عليها يموت مع أوّل إعادة رسم. (القاعدة التاسعة.)
    $('otp-rows').addEventListener('click', async function (e) {
        const btn = e.target.closest('[data-act="toggle"]');
        if (!btn) return;
        busy(btn, true);
        const j = await post(`/numbers/${btn.dataset.id}/toggle`);
        busy(btn, false);
        if (!j.success) { banner('danger', j.message); return; }
        loadStats(); loadRows();
    });

    loadStats();
    loadRows();
})();
</script>
@endpush
