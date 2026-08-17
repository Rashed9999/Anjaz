@extends('layouts.admin.app')
@section('title', 'مركز التحقق')

@push('css_or_js')
<style>
    .otp-center {
        --otp-primary: var(--amial-primary, #0b4da2);
        --otp-ink: #172033;
        --otp-muted: #6b778c;
        --otp-border: #e7ebf1;
        --otp-surface: #fff;
        --otp-soft: #f7f9fc;
        --otp-success: #157a5a;
        --otp-warning: #9a6a06;
        --otp-danger: #b42318;
    }
    .otp-center .otp-page-head { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; margin-bottom:18px; }
    .otp-center .otp-title { margin:0; color:var(--otp-ink); font-size:1.45rem; font-weight:800; }
    .otp-center .otp-subtitle { color:var(--otp-muted); margin-top:5px; font-size:.86rem; }
    .otp-center .otp-actions { display:flex; gap:8px; flex-wrap:wrap; }
    .otp-center .otp-card { background:var(--otp-surface); border:1px solid var(--otp-border); border-radius:16px; box-shadow:0 4px 18px rgba(28,39,60,.035); }
    .otp-center .otp-status { border-radius:16px; color:#fff; padding:16px 18px; display:flex; align-items:center; justify-content:space-between; gap:14px; }
    .otp-center .otp-status.is-open { background:linear-gradient(135deg,#b42318,#d84b3f); }
    .otp-center .otp-status.is-closed { background:linear-gradient(135deg,#116149,#1a8a68); }
    .otp-center .otp-status.is-loading { background:linear-gradient(135deg,#667085,#8b95a5); }
    .otp-center .otp-status strong { display:block; font-size:1rem; }
    .otp-center .otp-status small { opacity:.9; }
    .otp-center .otp-kpi { padding:15px; height:100%; }
    .otp-center .otp-kpi-value { font-weight:800; font-size:1.2rem; color:var(--otp-ink); }
    .otp-center .otp-kpi-label { margin-top:4px; font-size:.75rem; color:var(--otp-muted); }
    .otp-center .otp-section-head { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:15px 16px 8px; }
    .otp-center .otp-section-title { margin:0; font-size:.98rem; font-weight:800; color:var(--otp-ink); }
    .otp-center .otp-help { color:var(--otp-muted); font-size:.75rem; }
    .otp-center .otp-chart { min-height:205px; padding:8px 12px 16px; }
    .otp-center .otp-chart-grid { height:175px; display:flex; align-items:flex-end; justify-content:space-between; gap:5px; border-bottom:1px solid var(--otp-border); }
    .otp-center .otp-day { flex:1; min-width:0; display:flex; flex-direction:column; align-items:center; justify-content:flex-end; height:100%; }
    .otp-center .otp-bars { height:145px; display:flex; align-items:flex-end; gap:2px; }
    .otp-center .otp-bar { width:8px; min-height:2px; border-radius:4px 4px 0 0; background:var(--otp-primary); }
    .otp-center .otp-bar.blocked { width:5px; background:var(--otp-danger); }
    .otp-center .otp-day-label { font-size:9px; color:var(--otp-muted); margin-top:5px; white-space:nowrap; }
    .otp-center .provider-row { padding:12px 0; border-bottom:1px solid var(--otp-border); display:flex; align-items:center; justify-content:space-between; gap:12px; }
    .otp-center .provider-row:last-child { border-bottom:0; }
    .otp-center .provider-name { font-weight:700; color:var(--otp-ink); }
    .otp-center .provider-meta { color:var(--otp-muted); font-size:.72rem; margin-top:2px; }
    .otp-center .otp-toolbar { display:grid; grid-template-columns:minmax(220px,1fr) 180px 135px; gap:8px; }
    .otp-center .otp-table thead th { background:#f8fafc; color:#475467; font-size:.76rem; border-bottom:1px solid var(--otp-border); white-space:nowrap; }
    .otp-center .otp-table td { vertical-align:middle; }
    .otp-center .otp-sort { cursor:pointer; user-select:none; }
    .otp-center .otp-sort:hover { color:var(--otp-primary); }
    .otp-center .otp-mobile-list { display:none; }
    .otp-center .otp-mobile-item { padding:14px; border-bottom:1px solid var(--otp-border); }
    .otp-center .otp-mobile-item:last-child { border-bottom:0; }
    .otp-center .otp-empty { text-align:center; color:var(--otp-muted); padding:28px 14px; }
    .otp-center .otp-skeleton { height:14px; border-radius:7px; background:linear-gradient(90deg,#f0f2f5,#e5e8ed,#f0f2f5); background-size:200% 100%; animation:otpShimmer 1.2s infinite; }
    @keyframes otpShimmer { to { background-position:-200% 0; } }
    .otp-center .form-text-error { color:var(--otp-danger); font-size:.75rem; margin-top:4px; display:none; }
    .otp-center .form-text-error.show { display:block; }
    .otp-center .badge-soft-success { background:#eaf7f2; color:#12694e; }
    .otp-center .badge-soft-danger { background:#fff0ef; color:#b42318; }
    .otp-center .badge-soft-secondary { background:#f2f4f7; color:#475467; }
    .otp-center .badge-soft-warning { background:#fff7df; color:#8a5b00; }

    /* الشاشة المصورة كانت تبقي sidebar بعرض عمود على الجوال/التابلت. */
    @media (max-width:1199.98px) {
        .admin-mobile-header { display:flex !important; }
        .admin-sidebar {
            position:fixed !important; top:0 !important; right:0 !important; bottom:0 !important;
            width:min(320px,88vw) !important; max-width:none !important; height:100vh !important;
            z-index:1045 !important; transform:translateX(105%); transition:transform .22s ease;
            overflow-y:auto !important;
        }
        .admin-sidebar.show { transform:translateX(0) !important; }
        .admin-main { width:100% !important; max-width:100% !important; flex:0 0 100% !important; padding:76px 12px 24px !important; }
    }
    @media (max-width:767.98px) {
        .otp-center .otp-page-head { flex-direction:column; }
        .otp-center .otp-actions { width:100%; }
        .otp-center .otp-actions .btn { flex:1; }
        .otp-center .otp-status { align-items:flex-start; flex-direction:column; }
        .otp-center .otp-toolbar { grid-template-columns:1fr; }
        .otp-center .otp-desktop-table { display:none; }
        .otp-center .otp-mobile-list { display:block; }
        .otp-center .otp-section-head { align-items:flex-start; flex-direction:column; }
        .otp-center .otp-section-head .btn-group-wrap { width:100%; display:grid !important; grid-template-columns:1fr 1fr; }
        .otp-center .otp-section-head .btn-group-wrap .btn:last-child { grid-column:1/-1; }
        .otp-center .otp-chart { overflow-x:auto; }
        .otp-center .otp-chart-grid { min-width:620px; }
    }
</style>
@endpush

@section('content')
<div class="content container-fluid otp-center" dir="rtl">
    <nav aria-label="breadcrumb" class="mb-2">
        <ol class="breadcrumb small mb-0">
            <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">لوحة التحكم</a></li>
            <li class="breadcrumb-item">الحسابات والتحقق</li>
            <li class="breadcrumb-item active" aria-current="page">مركز التحقق</li>
        </ol>
    </nav>

    <div class="otp-page-head">
        <div>
            <h1 class="otp-title">مركز التحقق</h1>
            <div class="otp-subtitle">حالة رموز التحقق، أرقام العرض، وصحة بوابات الإرسال من مكان واحد.</div>
        </div>
        <div class="otp-actions">
            <button type="button" class="btn btn-outline-secondary btn-sm" id="otp-help" data-testid="otp-help">مساعدة</button>
            <button type="button" class="btn btn-outline-primary btn-sm" id="otp-refresh" data-testid="otp-refresh">تحديث البيانات</button>
        </div>
    </div>

    <div id="otp-alert" aria-live="polite"></div>

    <section id="otp-door" data-testid="otp-door" class="otp-status is-loading mb-3" aria-live="polite">
        <div>
            <strong>جارٍ قراءة حالة التحقق…</strong>
            <small>يتم التحقق من أرقام العرض وبوابات الإرسال.</small>
        </div>
    </section>

    <div class="row g-3 mb-3" id="otp-kpis" data-testid="otp-kpis">
        @for($i=0;$i<4;$i++)
            <div class="col-6 col-lg-3"><div class="otp-card otp-kpi"><div class="otp-skeleton mb-2"></div><div class="otp-skeleton" style="width:60%"></div></div></div>
        @endfor
    </div>

    <div class="row g-3">
        <div class="col-xl-7">
            <section class="otp-card h-100">
                <div class="otp-section-head">
                    <div>
                        <h2 class="otp-section-title">محاولات التحقق — آخر 14 يوماً</h2>
                        <div class="otp-help">الأزرق: المحاولات، الأحمر: المحاولات المحظورة مؤقتاً.</div>
                    </div>
                </div>
                <div id="otp-chart" data-testid="otp-chart" class="otp-chart" aria-live="polite">
                    <div class="otp-empty">جارٍ تحميل الرسم…</div>
                </div>
            </section>
        </div>

        <div class="col-xl-5">
            <section class="otp-card h-100">
                <div class="otp-section-head">
                    <div>
                        <h2 class="otp-section-title">بوابات الإرسال</h2>
                        <div class="otp-help">الحالة الفعلية لكل مزود والقناة التي يخدمها.</div>
                    </div>
                    <button type="button" class="btn btn-outline-primary btn-sm" id="otp-test" data-testid="otp-test">إرسال رسالة فحص</button>
                </div>
                <div class="px-3 pb-3" id="otp-providers" data-testid="otp-providers" aria-live="polite">
                    <div class="otp-empty">جارٍ قراءة المزودين…</div>
                </div>
            </section>
        </div>
    </div>

    <section class="otp-card mt-3">
        <div class="otp-section-head">
            <div>
                <h2 class="otp-section-title">أرقام العرض — الرمز الثابت</h2>
                <div class="otp-help">هذه الأرقام فقط يمكنها استعمال رمز العرض. لا تضف رقماً حقيقياً لعميل.</div>
            </div>
            <div class="d-flex gap-2 flex-wrap btn-group-wrap">
                <button type="button" class="btn btn-primary btn-sm" id="otp-add" data-testid="otp-add">إضافة رقم</button>
                <a class="btn btn-outline-secondary btn-sm" data-testid="otp-export" href="{{ route('admin.amial.otp.export') }}">تصدير CSV</a>
                <button type="button" class="btn btn-outline-danger btn-sm" id="otp-close" data-testid="otp-close">إقفال باب العرض</button>
            </div>
        </div>

        <div class="px-3 pb-3">
            <div class="otp-toolbar mb-3">
                <div>
                    <label class="form-label small mb-1" for="otp-search">بحث</label>
                    <input type="search" class="form-control form-control-sm" id="otp-search" data-testid="otp-search" placeholder="رقم الهاتف أو الوصف">
                </div>
                <div>
                    <label class="form-label small mb-1" for="otp-state">الحالة</label>
                    <select class="form-select form-select-sm" id="otp-state" data-testid="otp-state">
                        <option value="">كل الحالات</option>
                        <option value="active">مفعّلة</option>
                        <option value="disabled">معطّلة</option>
                    </select>
                </div>
                <div>
                    <label class="form-label small mb-1" for="otp-per">عدد الصفوف</label>
                    <select class="form-select form-select-sm" id="otp-per" data-testid="otp-per">
                        <option value="25">25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                </div>
            </div>

            <div id="otp-env" data-testid="otp-env" class="alert alert-warning py-2 small d-none"></div>

            <div class="otp-desktop-table table-responsive">
                <table class="table table-hover otp-table mb-0">
                    <thead>
                    <tr>
                        <th class="otp-sort" data-sort="phone">الرقم</th>
                        <th>الوصف</th>
                        <th>الحالة</th>
                        <th class="otp-sort" data-sort="use_count">مرات الاستعمال</th>
                        <th class="otp-sort" data-sort="last_used_at">آخر استعمال</th>
                        <th>الإجراء</th>
                    </tr>
                    </thead>
                    <tbody id="otp-rows" data-testid="otp-rows"><tr><td colspan="6" class="otp-empty">جارٍ تحميل الأرقام…</td></tr></tbody>
                </table>
            </div>
            <div id="otp-mobile-rows" class="otp-mobile-list border rounded"></div>

            <div class="d-flex justify-content-between align-items-center gap-2 mt-3 flex-wrap">
                <small class="text-muted" id="otp-count">جارٍ القراءة…</small>
                <div class="btn-group btn-group-sm" role="group" aria-label="التنقل بين الصفحات">
                    <button type="button" class="btn btn-outline-secondary" id="otp-prev" data-testid="otp-prev">السابق</button>
                    <button type="button" class="btn btn-outline-secondary" id="otp-next" data-testid="otp-next">التالي</button>
                </div>
            </div>
        </div>
    </section>
</div>

{{-- إضافة رقم --}}
<div class="modal fade" id="mdAdd" tabindex="-1" aria-labelledby="mdAddTitle" aria-hidden="true" dir="rtl">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title" id="mdAddTitle">إضافة رقم عرض</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div>
        <div class="modal-body">
            <div class="alert alert-warning small">الرقم المضاف سيقبل رمز العرض الثابت دون رسالة حقيقية. استخدم حسابات الاختبار فقط.</div>
            <label class="form-label" for="md-phone">رقم الهاتف</label>
            <input class="form-control" id="md-phone" data-testid="md-phone" inputmode="tel" autocomplete="off" placeholder="967777100001">
            <div class="form-text-error" id="md-phone-error"></div>
            <label class="form-label mt-3" for="md-label">الوصف <span class="text-muted">(اختياري)</span></label>
            <input class="form-control" id="md-label" data-testid="md-label" maxlength="120" placeholder="مثال: حساب العرض الرئيسي">
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">إلغاء</button><button type="button" class="btn btn-primary" id="md-save" data-testid="md-save">حفظ الرقم</button></div>
    </div></div>
</div>

{{-- تأكيد تفعيل/تعطيل رقم --}}
<div class="modal fade" id="mdToggle" tabindex="-1" aria-labelledby="mdToggleTitle" aria-hidden="true" dir="rtl">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title" id="mdToggleTitle">تأكيد تغيير الحالة</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div>
        <div class="modal-body"><p class="mb-1" id="md-toggle-text"></p><small class="text-muted">يُسجل هذا الإجراء في سجل التدقيق.</small></div>
        <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">إلغاء</button><button type="button" class="btn btn-primary" id="md-toggle-go">تأكيد</button></div>
    </div></div>
</div>

{{-- إقفال الباب --}}
<div class="modal fade" id="mdClose" tabindex="-1" aria-labelledby="mdCloseTitle" aria-hidden="true" dir="rtl">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title text-danger" id="mdCloseTitle">إقفال باب الرمز الثابت</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div>
        <div class="modal-body">
            <div class="alert alert-danger small">سيتم تعطيل جميع أرقام العرض. بعد ذلك يحتاج أي تسجيل جديد إلى قناة إرسال حقيقية.</div>
            <div id="md-close-ready"></div>
            <label class="form-label" for="md-confirm">اكتب <code>إقفال</code> للتأكيد</label>
            <input class="form-control" id="md-confirm" data-testid="md-confirm" autocomplete="off" placeholder="إقفال">
            <div class="form-text-error" id="md-confirm-error">اكتب كلمة إقفال كما هي.</div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">تراجع</button><button type="button" class="btn btn-danger" id="md-close-go" data-testid="md-close-go">إقفال الباب</button></div>
    </div></div>
</div>

{{-- رسالة الفحص --}}
<div class="modal fade" id="mdTest" tabindex="-1" aria-labelledby="mdTestTitle" aria-hidden="true" dir="rtl">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title" id="mdTestTitle">رسالة فحص حقيقية</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div>
        <div class="modal-body">
            <p class="small text-muted">اختر قناة لديها مزود مفعّل يدعم إرسال النص الحر، ثم أرسل رسالة تشخيصية إلى رقم تملكه.</p>
            <label class="form-label" for="md-test-channel">القناة</label>
            <select class="form-select" id="md-test-channel"></select>
            <div class="form-text-error" id="md-test-channel-error"></div>
            <label class="form-label mt-3" for="md-test-phone">رقم الهاتف</label>
            <input class="form-control" id="md-test-phone" data-testid="md-test-phone" inputmode="tel" autocomplete="off" placeholder="967…">
            <div class="form-text-error" id="md-test-phone-error"></div>
            <div id="md-test-out" class="mt-3" aria-live="polite"></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">إغلاق</button><button type="button" class="btn btn-primary" id="md-test-go" data-testid="md-test-go">إرسال الفحص</button></div>
    </div></div>
</div>

{{-- المساعدة --}}
<div class="modal fade" id="mdHelp" tabindex="-1" aria-labelledby="mdHelpTitle" aria-hidden="true" dir="rtl">
    <div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title" id="mdHelpTitle">كيف يعمل مركز التحقق؟</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div>
        <div class="modal-body">
            <div class="row g-3">
                <div class="col-md-6"><div class="border rounded p-3 h-100"><strong>أرقام العرض</strong><p class="small text-muted mt-2 mb-0">الأرقام الموجودة هنا فقط يمكنها قبول رمز العرض الثابت دون مزود إرسال.</p></div></div>
                <div class="col-md-6"><div class="border rounded p-3 h-100"><strong>الأرقام الحقيقية</strong><p class="small text-muted mt-2 mb-0">تأخذ رمزاً عشوائياً ويجب إيصاله عبر واتساب أو SMS. لا يُفصح عن الرمز في الاستجابة.</p></div></div>
            </div>
            <div class="alert alert-info small mt-3 mb-0">قبل إقفال باب العرض تأكد من وجود قناة إرسال جاهزة واختبرها برسالة فحص.</div>
        </div>
    </div></div>
</div>
@endsection

@push('script')
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(function () {
    'use strict';

    const BASE = @json(url('admin/amial/otp'));
    const TOKEN = @json(csrf_token());
    const $ = id => document.getElementById(id);
    const esc = value => String(value ?? '—').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
    const modal = id => bootstrap.Modal.getOrCreateInstance($(id));

    let page = 1;
    let sort = 'created_at';
    let dir = 'desc';
    let toggleTarget = null;
    let testableChannels = [];
    let searchTimer = null;

    function banner(kind, message) {
        $('otp-alert').innerHTML = `<div class="alert alert-${kind} alert-dismissible fade show py-2 small" role="alert">${esc(message)}<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button></div>`;
    }

    function setBusy(button, on, text) {
        if (!button) return;
        if (on) {
            button.dataset.originalText = button.innerHTML;
            button.disabled = true;
            button.innerHTML = `<span class="spinner-border spinner-border-sm ms-1" aria-hidden="true"></span>${esc(text || 'جارٍ التنفيذ')}`;
        } else {
            button.disabled = false;
            if (button.dataset.originalText) button.innerHTML = button.dataset.originalText;
        }
    }

    function fieldError(id, message) {
        const el = $(id);
        if (!el) return;
        el.textContent = message || '';
        el.classList.toggle('show', Boolean(message));
    }

    async function request(path, options = {}) {
        const headers = Object.assign({'Accept':'application/json'}, options.headers || {});
        if ((options.method || 'GET').toUpperCase() !== 'GET') headers['X-CSRF-TOKEN'] = TOKEN;
        if (options.body && typeof options.body !== 'string') {
            headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(options.body);
        }

        let response;
        try {
            response = await fetch(BASE + path, Object.assign({}, options, {headers}));
        } catch (error) {
            throw new Error('تعذر الاتصال بالخادم. تحقق من الشبكة ثم أعد المحاولة.');
        }

        const raw = await response.text();
        let data = null;
        try { data = raw ? JSON.parse(raw) : {}; } catch (error) {
            throw new Error(response.status === 419 ? 'انتهت جلسة الإدارة. حدّث الصفحة وسجّل الدخول من جديد.' : 'أعاد الخادم استجابة غير متوقعة.');
        }

        if (!response.ok || data.success === false) {
            const validation = data.errors && typeof data.errors === 'object'
                ? Object.values(data.errors).flat().filter(Boolean)[0]
                : null;
            throw new Error(validation || data.message || `فشل الطلب (${response.status})`);
        }

        return data;
    }

    function renderStats(meta) {
        const door = $('otp-door');
        door.className = `otp-status mb-3 ${meta.door_open ? 'is-open' : 'is-closed'}`;
        door.innerHTML = meta.door_open
            ? `<div><strong>باب العرض مفتوح</strong><small>${meta.demo_count} رقم/أرقام تقبل الرمز الثابت حالياً.</small></div><span class="badge bg-light text-danger">وضع اختبار</span>`
            : `<div><strong>باب العرض مقفل</strong><small>لا يوجد رقم يقبل الرمز الثابت؛ التسجيل يعتمد على قناة إرسال حقيقية.</small></div><span class="badge bg-light text-success">وضع آمن</span>`;

        const today = meta.today || {};
        const rate = today.non_blocked_rate === null || today.non_blocked_rate === undefined ? '—' : `${today.non_blocked_rate}%`;
        const tiles = [
            [meta.delivery_ready ? 'جاهزة' : 'غير مهيأة', 'قناة الإيصال', meta.delivery_ready ? 'text-success' : 'text-danger'],
            [meta.demo_count ?? 0, 'أرقام العرض الفعالة', ''],
            [today.attempts ?? 0, 'محاولات اليوم', ''],
            [rate, 'نسبة غير المحظور', ''],
            [today.blocked ?? 0, 'محظور مؤقتاً', (today.blocked ?? 0) > 0 ? 'text-warning' : ''],
        ];
        $('otp-kpis').innerHTML = tiles.map(([v,l,c]) => `<div class="col-6 col-lg"><div class="otp-card otp-kpi"><div class="otp-kpi-value ${c}">${esc(v)}</div><div class="otp-kpi-label">${esc(l)}</div></div></div>`).join('');

        const trend = Array.isArray(meta.trend) ? meta.trend : [];
        if (!trend.length) {
            $('otp-chart').innerHTML = '<div class="otp-empty">لا توجد بيانات للرسم.</div>';
        } else {
            const max = Math.max(1, ...trend.map(d => Number(d.attempts || 0)));
            $('otp-chart').innerHTML = `<div class="otp-chart-grid">${trend.map(d => {
                const attempts = Number(d.attempts || 0), blocked = Number(d.blocked || 0);
                const h = Math.max(attempts ? 4 : 2, Math.round(attempts / max * 138));
                const hb = Math.max(blocked ? 4 : 0, Math.round(blocked / max * 138));
                return `<div class="otp-day" title="${esc(d.date)} — ${attempts} محاولة، ${blocked} محظورة"><div class="otp-bars"><span class="otp-bar" style="height:${h}px;opacity:${attempts ? 1 : .18}"></span>${blocked ? `<span class="otp-bar blocked" style="height:${hb}px"></span>` : ''}</div><span class="otp-day-label">${esc(String(d.date).slice(5))}</span></div>`;
            }).join('')}</div>`;
        }

        const providers = Array.isArray(meta.providers) ? meta.providers : [];
        testableChannels = [...new Set(providers.filter(p => p.testable).map(p => p.channel))];
        $('otp-providers').innerHTML = providers.length ? providers.map(p => `<div class="provider-row"><div><div class="provider-name">${esc(p.key)}</div><div class="provider-meta">${esc(p.channel_label || p.channel)} · الأولوية ${esc(p.priority)}</div></div><div class="text-nowrap">${p.enabled ? '<span class="badge badge-soft-success">مفعّل</span>' : '<span class="badge badge-soft-secondary">معطّل</span>'}${p.testable ? ' <span class="badge badge-soft-warning">قابل للفحص</span>' : ''}</div></div>`).join('') : '<div class="otp-empty">لم يتم اكتشاف أي مزود إرسال.</div>';

        const select = $('md-test-channel');
        select.innerHTML = testableChannels.length
            ? testableChannels.map(ch => `<option value="${esc(ch)}">${ch === 'whatsapp' ? 'واتساب' : 'رسائل SMS'}</option>`).join('')
            : '<option value="">لا توجد قناة قابلة للفحص</option>';
        $('otp-test').disabled = !testableChannels.length;

        $('md-close-ready').innerHTML = meta.delivery_ready
            ? '<div class="alert alert-success small py-2">توجد قناة إرسال مفعّلة. اختبرها قبل الإقفال النهائي.</div>'
            : '<div class="alert alert-danger small py-2"><strong>لا توجد قناة إرسال جاهزة.</strong> إقفال باب العرض الآن سيمنع التسجيل الحقيقي حتى تجهّز قناة الإرسال.</div>';
    }

    async function loadStats() {
        try {
            const data = await request('/stats');
            renderStats(data.meta || {});
        } catch (error) {
            $('otp-door').className = 'otp-status is-loading mb-3';
            $('otp-door').innerHTML = `<div><strong>تعذرت قراءة حالة التحقق</strong><small>${esc(error.message)}</small></div><button type="button" class="btn btn-light btn-sm" id="otp-status-retry">إعادة المحاولة</button>`;
            $('otp-providers').innerHTML = `<div class="otp-empty text-danger">${esc(error.message)}</div>`;
            $('otp-chart').innerHTML = `<div class="otp-empty text-danger">تعذر تحميل الرسم.</div>`;
            setTimeout(() => { const b=$('otp-status-retry'); if (b) b.onclick=loadStats; }, 0);
        }
    }

    function rowHtml(row) {
        const active = Boolean(row.is_active);
        return `<tr><td dir="ltr" class="text-end"><code>${esc(row.phone)}</code></td><td>${esc(row.label || '—')}</td><td>${active ? '<span class="badge badge-soft-warning">مفعّل</span>' : '<span class="badge badge-soft-secondary">معطّل</span>'}</td><td>${esc(row.use_count ?? 0)}</td><td class="small text-muted">${esc(row.last_used_at || 'لم يُستخدم')}</td><td><button type="button" class="btn btn-sm ${active ? 'btn-outline-danger' : 'btn-outline-success'}" data-action="toggle" data-testid="otp-toggle-${Number(row.id)}" data-id="${Number(row.id)}" data-phone="${esc(row.phone)}" data-active="${active ? 1 : 0}">${active ? 'تعطيل' : 'تفعيل'}</button></td></tr>`;
    }

    function mobileRowHtml(row) {
        const active = Boolean(row.is_active);
        return `<div class="otp-mobile-item"><div class="d-flex justify-content-between gap-2"><div><code dir="ltr">${esc(row.phone)}</code><div class="small text-muted mt-1">${esc(row.label || 'بلا وصف')}</div></div>${active ? '<span class="badge badge-soft-warning align-self-start">مفعّل</span>' : '<span class="badge badge-soft-secondary align-self-start">معطّل</span>'}</div><div class="d-flex justify-content-between align-items-end gap-2 mt-3"><div class="small text-muted">الاستخدام: ${esc(row.use_count ?? 0)}<br>آخر استعمال: ${esc(row.last_used_at || 'لم يُستخدم')}</div><button type="button" class="btn btn-sm ${active ? 'btn-outline-danger' : 'btn-outline-success'}" data-action="toggle" data-testid="otp-toggle-${Number(row.id)}" data-id="${Number(row.id)}" data-phone="${esc(row.phone)}" data-active="${active ? 1 : 0}">${active ? 'تعطيل' : 'تفعيل'}</button></div></div>`;
    }

    async function loadRows() {
        $('otp-rows').innerHTML = '<tr><td colspan="6" class="otp-empty">جارٍ تحميل الأرقام…</td></tr>';
        $('otp-mobile-rows').innerHTML = '<div class="otp-empty">جارٍ تحميل الأرقام…</div>';
        const params = new URLSearchParams({search:$('otp-search').value,state:$('otp-state').value,per_page:$('otp-per').value,page:String(page),sort,dir});
        try {
            const data = await request('/numbers?' + params.toString());
            const meta = data.meta || {};
            const rows = Array.isArray(meta.rows) ? meta.rows : [];
            $('otp-rows').innerHTML = rows.length ? rows.map(rowHtml).join('') : '<tr><td colspan="6" class="otp-empty">لا توجد أرقام مطابقة.</td></tr>';
            $('otp-mobile-rows').innerHTML = rows.length ? rows.map(mobileRowHtml).join('') : '<div class="otp-empty">لا توجد أرقام مطابقة.</div>';
            $('otp-count').textContent = `${meta.total ?? 0} رقم · الصفحة ${meta.page ?? 1} من ${meta.pages ?? 1}`;
            $('otp-prev').disabled = Number(meta.page || 1) <= 1;
            $('otp-next').disabled = Number(meta.page || 1) >= Number(meta.pages || 1);
            const env = $('otp-env');
            if (meta.from_env) {
                env.classList.remove('d-none');
                env.innerHTML = `الجدول لم يُعتمد بعد كمصدر للأرقام؛ المنصة تقرأ بذرة البيئة الحالية (${Number((meta.env_numbers || []).length)} رقم). أول تعديل من هذه الشاشة يجعل الجدول هو المصدر.`;
            } else env.classList.add('d-none');
        } catch (error) {
            const retry = `<button type="button" class="btn btn-outline-danger btn-sm mt-2" id="otp-rows-retry">إعادة المحاولة</button>`;
            $('otp-rows').innerHTML = `<tr><td colspan="6" class="otp-empty text-danger">${esc(error.message)}<br>${retry}</td></tr>`;
            $('otp-mobile-rows').innerHTML = `<div class="otp-empty text-danger">${esc(error.message)}<br>${retry.replace('id="otp-rows-retry"','id="otp-rows-retry-mobile"')}</div>`;
            setTimeout(() => { const a=$('otp-rows-retry'), b=$('otp-rows-retry-mobile'); if(a)a.onclick=loadRows;if(b)b.onclick=loadRows; }, 0);
        }
    }

    function openToggle(button) {
        toggleTarget = {id:button.dataset.id, phone:button.dataset.phone, active:button.dataset.active === '1'};
        $('md-toggle-text').innerHTML = toggleTarget.active
            ? `سيتم <strong>تعطيل</strong> الرقم <code dir="ltr">${esc(toggleTarget.phone)}</code> ولن يقبل رمز العرض بعد ذلك.`
            : `سيتم <strong>تفعيل</strong> الرقم <code dir="ltr">${esc(toggleTarget.phone)}</code> وسيقبل رمز العرض الثابت.`;
        $('md-toggle-go').className = toggleTarget.active ? 'btn btn-danger' : 'btn btn-success';
        $('md-toggle-go').textContent = toggleTarget.active ? 'تعطيل الرقم' : 'تفعيل الرقم';
        modal('mdToggle').show();
    }

    async function runToggle() {
        if (!toggleTarget) return;
        const button = $('md-toggle-go');
        setBusy(button,true,'جارٍ الحفظ');
        try {
            await request(`/numbers/${encodeURIComponent(toggleTarget.id)}/toggle`, {method:'POST',body:{}});
            modal('mdToggle').hide();
            banner('success','تم تحديث حالة الرقم وتسجيل الإجراء.');
            await Promise.all([loadStats(),loadRows()]);
        } catch(error) { banner('danger',error.message); }
        finally { setBusy(button,false); }
    }

    document.addEventListener('click', event => {
        const button = event.target.closest('[data-action="toggle"]');
        if (button) openToggle(button);
    });

    $('otp-refresh').onclick = async function () {
        setBusy(this,true,'جارٍ التحديث');
        await Promise.all([loadStats(),loadRows()]);
        setBusy(this,false);
    };
    $('otp-help').onclick = () => modal('mdHelp').show();
    $('otp-add').onclick = () => { $('md-phone').value='';$('md-label').value='';fieldError('md-phone-error','');modal('mdAdd').show(); };
    $('otp-close').onclick = () => { $('md-confirm').value='';fieldError('md-confirm-error','');modal('mdClose').show(); };
    $('otp-test').onclick = () => { $('md-test-out').innerHTML='';$('md-test-phone').value='';fieldError('md-test-phone-error','');fieldError('md-test-channel-error','');modal('mdTest').show(); };
    $('md-toggle-go').onclick = runToggle;

    $('otp-search').oninput = () => { clearTimeout(searchTimer); searchTimer=setTimeout(() => {page=1;loadRows();},300); };
    $('otp-state').onchange = () => {page=1;loadRows();};
    $('otp-per').onchange = () => {page=1;loadRows();};
    $('otp-prev').onclick = () => {if(page>1){page--;loadRows();}};
    $('otp-next').onclick = () => {page++;loadRows();};
    document.querySelectorAll('.otp-sort').forEach(th => th.onclick = () => { const next=th.dataset.sort; dir = sort===next && dir==='desc'?'asc':'desc'; sort=next; page=1; loadRows(); });

    $('md-save').onclick = async function () {
        fieldError('md-phone-error','');
        if ($('md-phone').value.replace(/\D/g,'').length < 7) { fieldError('md-phone-error','أدخل رقم هاتف صالحاً.'); return; }
        setBusy(this,true,'جارٍ الحفظ');
        try {
            await request('/numbers',{method:'POST',body:{phone:$('md-phone').value,label:$('md-label').value}});
            modal('mdAdd').hide(); banner('success','تمت إضافة رقم العرض بنجاح.'); await Promise.all([loadStats(),loadRows()]);
        } catch(error) { fieldError('md-phone-error',error.message); }
        finally { setBusy(this,false); }
    };

    $('md-close-go').onclick = async function () {
        fieldError('md-confirm-error','');
        if ($('md-confirm').value.trim() !== 'إقفال') { fieldError('md-confirm-error','اكتب كلمة إقفال كما هي.'); return; }
        setBusy(this,true,'جارٍ الإقفال');
        try {
            const data=await request('/close-door',{method:'POST',body:{confirm:'إقفال'}});
            modal('mdClose').hide();
            const suffix = data.meta && data.meta.delivery_ready ? '' : ' تنبيه: لا توجد قناة إرسال جاهزة حالياً.';
            banner(data.meta && data.meta.delivery_ready ? 'success' : 'warning',`تم إقفال باب العرض وتعطيل ${data.meta?.disabled ?? 0} رقم.${suffix}`);
            await Promise.all([loadStats(),loadRows()]);
        } catch(error) { banner('danger',error.message); }
        finally { setBusy(this,false); }
    };

    $('md-test-go').onclick = async function () {
        fieldError('md-test-phone-error','');fieldError('md-test-channel-error','');$('md-test-out').innerHTML='';
        const channel=$('md-test-channel').value, phone=$('md-test-phone').value;
        if(!channel){fieldError('md-test-channel-error','لا توجد قناة قابلة للفحص أو لم تختر قناة.');return;}
        if(phone.replace(/\D/g,'').length<7){fieldError('md-test-phone-error','أدخل رقم هاتف صالحاً.');return;}
        setBusy(this,true,'جارٍ الإرسال');
        try {
            const data=await request('/test-send',{method:'POST',body:{channel,phone}});
            $('md-test-out').innerHTML=`<div class="alert alert-success small py-2 mb-0">تم إرسال رسالة الفحص عبر ${channel==='whatsapp'?'واتساب':'SMS'} بنجاح.</div>`;
        } catch(error) { $('md-test-out').innerHTML=`<div class="alert alert-danger small py-2 mb-0">${esc(error.message)}</div>`; }
        finally { setBusy(this,false); }
    };

    Promise.all([loadStats(),loadRows()]).catch(() => {});
})();
</script>
@endpush
