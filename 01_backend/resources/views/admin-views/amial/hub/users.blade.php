@extends('layouts.admin.app')

{{-- AMIAL-ADMIN-HUB-001 — لوحة مركزية موحّدة (عملاء / وكلاء / تجّار).
     تعمل بالكامل على JSON endpoints في AdminHubController. --}}

@section('title', $hubTitle)

@section('content')
<div class="content container-fluid" id="hub-root"
     data-slug="{{ $hubSlug }}" data-type="{{ $hubType }}">

    <div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
        <h2 class="page-header-title mb-0">{{ $hubTitle }}</h2>
        @if($hubSlug === 'agents')
            {{-- الرابط الذي كان ناقصاً: البوّابة مبنيّة، ولم يكن إليها بابٌ من هنا. --}}
            <a href="{{ route('agent.login') }}" target="_blank" rel="noopener"
               class="btn btn-sm btn-dark">🏦 فتح بوّابة الوكيل (شركات الصرافة)</a>
        @endif
        <span class="badge badge-soft-info ms-auto">AMIAL-HUB-001</span>
    </div>

    {{-- إحصاءات --}}
    <div class="row g-3 mb-4">
        <div class="col-md-3 col-6"><div class="card stat-card p-3">
            <small class="text-muted">الإجمالي</small>
            <div class="fs-3 fw-bold">{{ number_format($stats['total']) }}</div>
        </div></div>
        <div class="col-md-3 col-6"><div class="card stat-card p-3">
            <small class="text-muted">نشِط</small>
            <div class="fs-3 fw-bold text-success">{{ number_format($stats['active']) }}</div>
        </div></div>
        <div class="col-md-3 col-6"><div class="card stat-card p-3">
            <small class="text-muted">مجمَّد</small>
            <div class="fs-3 fw-bold text-danger">{{ number_format($stats['frozen']) }}</div>
        </div></div>
        <div class="col-md-3 col-6"><div class="card stat-card p-3">
            <small class="text-muted">وثائق بانتظار الاعتماد</small>
            <div class="fs-3 fw-bold text-warning">{{ number_format($stats['kyc_pending']) }}</div>
        </div></div>
    </div>
    <div class="card stat-card p-3 mb-4 d-flex flex-row justify-content-between align-items-center">
        <span class="text-muted">إجمالي أرصدة المحافظ لهذه الفئة</span>
        <span class="fs-4 fw-bold">{{ number_format((float) $stats['balance_sum'], 2) }} ر.ي</span>
    </div>

    @if($hubSlug === 'agents')
        @include('admin-views.amial.hub._agent_network_cards')
    @endif

    {{-- تبويبات --}}
    <ul class="nav nav-tabs mb-3">
        <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tab-list">القائمة</a></li>
        @if($hubSlug === 'agents')
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-branches" id="branches-tab-link">الفروع والخزائن</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-cash" id="cash-tab-link">حركة النقد</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-daily" id="daily-tab-link">🌙 إقفال اليوم</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-settlement" id="settlement-tab-link">⚖️ التوازن والتسوية</a></li>
        @endif
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-kyc" id="kyc-tab-link">طلبات التوثيق</a></li>
    </ul>

    <div class="tab-content">
        {{-- ===== تبويب القائمة ===== --}}
        <div class="tab-pane fade show active" id="tab-list">
            <div class="card stat-card">
                <div class="card-header d-flex gap-2 flex-wrap align-items-center">
                    <input type="text" id="search-box" class="form-control" style="max-width:280px"
                           placeholder="بحث بالاسم أو الهاتف أو الرقم…">
                    <button class="btn btn-primary ms-auto" data-bs-toggle="modal" data-bs-target="#modal-add">
                        + إضافة {{ $hubType == 1 ? 'وكيل' : ($hubType == 3 ? 'تاجر' : 'عميل') }}
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light"><tr>
                            <th>#</th><th>الاسم</th><th>الهاتف</th><th>الرصيد الإلكترونيّ</th>
                            @if($hubSlug === 'agents')
                            <th>الفروع</th><th>النقد في الدرج</th>
                            @endif
                            <th>التوثيق</th><th>الحالة</th><th>إجراءات</th>
                        </tr></thead>
                        <tbody id="users-tbody">
                            <tr><td colspan="9" class="text-center text-muted py-4">جارٍ التحميل…</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer d-flex justify-content-between align-items-center">
                    <span class="text-muted small" id="page-info"></span>
                    <div class="btn-group">
                        <button class="btn btn-sm btn-outline-secondary" id="prev-page">السابق</button>
                        <button class="btn btn-sm btn-outline-secondary" id="next-page">التالي</button>
                    </div>
                </div>
            </div>
        </div>

        @if($hubSlug === 'agents')
            @include('admin-views.amial.hub._agent_tabs')
        @endif

        {{-- ===== تبويب التوثيق ===== --}}
        <div class="tab-pane fade" id="tab-kyc">
            <div id="kyc-cards" class="row g-3">
                <div class="col-12 text-muted">جارٍ التحميل…</div>
            </div>
        </div>
    </div>
</div>

{{-- ===== Modal: إضافة مستخدم ===== --}}
<div class="modal fade" id="modal-add" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">إضافة حساب جديد</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="mb-2"><label class="form-label">الاسم الأول</label>
                <input class="form-control" id="add-fname"></div>
            <div class="mb-2"><label class="form-label">الاسم الأخير</label>
                <input class="form-control" id="add-lname"></div>
            <div class="mb-2"><label class="form-label">الهاتف</label>
                <input class="form-control" id="add-phone" dir="ltr" placeholder="9677xxxxxxxx"></div>
            <div class="mb-2"><label class="form-label">كلمة السر (8+ أحرف)</label>
                <input class="form-control" id="add-password" dir="ltr"></div>
            <div class="mb-2"><label class="form-label">رمز PIN للمعاملات (4 أرقام — الافتراضي 1234)</label>
                <input class="form-control" id="add-pin" dir="ltr" maxlength="4" placeholder="1234"></div>

            @if($hubType == 3)
            {{-- حقول التاجر: تفتح لوحة القطاع الصحيحة والباقة في التطبيق --}}
            <div class="mb-2"><label class="form-label">اسم المتجر</label>
                <input class="form-control" id="add-store"></div>
            <div class="mb-2"><label class="form-label">نوع النشاط</label>
                <select class="form-select" id="add-biz">
                    <option value="retail">بقالة / سوبرماركت</option>
                    <option value="quick_sale">بيع سريع (بسطة/خضار/أسماك)</option>
                    <option value="fuel">محطة وقود</option>
                    <option value="pharmacy">صيدلية</option>
                    <option value="wholesale">جملة</option>
                    <option value="restaurant">مطعم</option>
                </select></div>
            <div class="mb-2"><label class="form-label">الباقة</label>
                <select class="form-select" id="add-plan">
                    <option value="free">مجاني</option>
                    <option value="starter">البداية</option>
                    <option value="business">الأعمال</option>
                    <option value="merchant_pro">تاجر محترف</option>
                    <option value="enterprise">مؤسسة</option>
                </select></div>
            @endif

            <div class="alert alert-info small py-2">
                الحساب يُنشأ جاهزاً للدخول من التطبيق فوراً: الهاتف + كلمة السر أعلاه،
                وPIN المعاملات المدخل، والتوثيق معتمد تلقائياً.
            </div>
            <div class="text-danger small" id="add-error"></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-primary" id="add-submit">إنشاء الحساب</button>
        </div>
    </div></div>
</div>

{{-- ===== Modal: تحويل / إعادة مبلغ ===== --}}
<div class="modal fade" id="modal-transfer" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title" id="transfer-title">تحويل من محفظة الإدارة</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <p class="text-muted small mb-2" id="transfer-target"></p>
            <div class="mb-2"><label class="form-label">المبلغ (ر.ي)</label>
                <input class="form-control" id="transfer-amount" type="number" min="1" dir="ltr"></div>
            <div class="mb-2"><label class="form-label">السبب / المرجع (اختياري)</label>
                <input class="form-control" id="transfer-reason" placeholder="إعادة مبلغ عملية… / تمويل وكيل…"></div>
            <div class="text-danger small" id="transfer-error"></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-primary" id="transfer-submit">تنفيذ التحويل</button>
        </div>
    </div></div>
</div>

{{-- ===== Modal: سجل العمليات ===== --}}
<div class="modal fade" id="modal-tx" tabindex="-1">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title" id="tx-title">سجل العمليات</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body p-0">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light"><tr>
                        <th>المرجع</th><th>النوع</th><th>مدين</th><th>دائن</th><th>الرصيد</th><th>التاريخ</th>
                    </tr></thead>
                    <tbody id="tx-tbody"></tbody>
                </table>
            </div>
        </div>
    </div></div>
</div>

@endsection

@push('script')
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(function () {
    const root = document.getElementById('hub-root');
    const slug = root.dataset.slug;
    const isAgents = slug === 'agents';
    // AMIAL-MERCHANT-CENTER-001 — زرُّ «فتح المركز» للتجّار وحدهم:
    // مركزُ التاجر يعرض تسوياتٍ ومخاطرَ واشتراكاً، ولا معنى لها لعميل.
    const isMerchants = slug === 'merchants';
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const base = '{{ url('admin/amial/hub') }}';

    let page = 1, lastPage = 1, search = '';
    let transferUserId = null;

    const fmt = (n) => Number(n || 0).toLocaleString('en-US', {maximumFractionDigits: 2});
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    async function post(url, body) {
        const r = await fetch(url, {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json'},
            body: JSON.stringify(body || {}),
        });
        const j = await r.json().catch(() => ({}));
        if (!r.ok) throw new Error(j.message || ('خطأ ' + r.status));
        return j;
    }

    // ===== قائمة المستخدمين =====
    const COLS = isAgents ? 9 : 7;

    // عمودا الوكيل: الفروع، والنقد في الدرج. وكيلٌ بلا فرعٍ لا يخدم أحداً مهما
    // كان رصيده؛ وفرعٌ بلا نقدٍ يقبل الإيداع ويعجز عن السحب.
    function agentCells(u) {
        if (!isAgents) return '';
        const a = u.agent;
        if (!a) return '<td colspan="2" class="text-muted small">—</td>';

        if (a.is_branch_account) {
            return `<td><span class="badge bg-info text-dark">فرع</span>
                        <div class="small text-muted">تابع لـ ${esc(a.parent ? a.parent.name : '—')}</div></td>
                    <td class="text-muted small">يُدار من صفّ الوكيل الأمّ</td>`;
        }

        const labels = {low_cash: 'نقدٌ منخفض', overloaded: 'فوق الحدّ', not_counted: 'لم يُجرَد', no_till: 'خزنة ناقصة'};
        const badges = (a.flags || []).map(f =>
            `<span class="badge bg-warning text-dark">${labels[f] || f}</span>`).join(' ');

        return `<td>${a.no_branches
                    ? '<span class="badge bg-danger">بلا فروع</span>'
                    : `<strong>${a.branches}</strong> <span class="small text-muted">(${a.branches_active} نشِط)</span>`}</td>
                <td>${a.no_branches ? '<span class="text-muted small">—</span>'
                                    : `${fmt(a.cash_on_hand)} ر.ي<div>${badges}</div>`}</td>`;
    }

    // زرّ التمويل — ولا يظهر على صفّ فرع.
    //
    //      المنصّة ──► الوكيل الأمّ ──► الفرع
    //
    // فالمنصّة طرفُ الوكيل وحده، والوكيل يوزّع على فروعه من بوّابته.
    // والخدمة ترفض تمويل الفرع على كلّ حال (AgentNetworkService)، وهذا
    // هنا ليقرأ المدير **السبب** بدل أن يضغط ويُصدَّ.
    function transferControl(u) {
        const a = isAgents ? u.agent : null;
        if (a && a.is_branch_account) {
            const parent = esc(a.parent ? a.parent.name : 'الوكيل الأمّ');
            return `<span class="badge bg-light text-dark border fw-normal"
                          title="التمويل يمرّ بالوكيل الأمّ ليبقى مسؤولاً عن فرعه، ولتبقى التسوية مع طرفٍ واحد">
                        يُموَّل من ${parent}</span>`;
        }
        return `<button class="btn btn-sm btn-outline-primary" data-act="transfer" data-id="${u.id}" data-name="${esc(u.name)}">
                    ${isAgents ? 'تحويل رصيد' : 'إعادة مبلغ'}</button>`;
    }

    async function loadUsers() {
        const tbody = document.getElementById('users-tbody');
        tbody.innerHTML = `<tr><td colspan="${COLS}" class="text-center text-muted py-4">جارٍ التحميل…</td></tr>`;
        const r = await fetch(`${base}/${slug}/users.json?page=${page}&search=${encodeURIComponent(search)}`,
            {headers: {'Accept': 'application/json'}});
        const j = await r.json();
        lastPage = j.last_page;
        document.getElementById('page-info').textContent = `صفحة ${j.current_page} من ${j.last_page} — ${j.total} حساب`;

        if (!j.data.length) {
            tbody.innerHTML = `<tr><td colspan="${COLS}" class="text-center text-muted py-4">لا نتائج</td></tr>`;
            return;
        }
        tbody.innerHTML = j.data.map(u => `
            <tr style="cursor:pointer" data-act="open" data-id="${u.id}">
                <td>${u.id}</td>
                <td class="text-primary fw-bold">${esc(u.name)}</td>
                <td dir="ltr">${esc(u.phone)}</td>
                <td>${fmt(u.balance)} ر.ي</td>
                ${agentCells(u)}
                <td>${u.kyc === 1 ? '<span class="badge bg-success">موثّق</span>'
                    : (u.kyc === 2 ? '<span class="badge bg-danger">مرفوض</span>'
                    : (u.has_docs ? '<span class="badge bg-warning text-dark">بانتظار الاعتماد</span>'
                    : '<span class="badge bg-secondary">بلا وثائق</span>'))}</td>
                <td>${u.is_active ? '<span class="badge bg-success">نشِط</span>' : '<span class="badge bg-danger">مجمَّد</span>'}</td>
                <td class="text-nowrap">
                    <button class="btn btn-sm btn-primary" data-act="open" data-id="${u.id}">التفاصيل</button>
                    ${isMerchants ? `<button class="btn btn-sm btn-dark" data-act="center" data-id="${u.id}"
                            title="الملف · المال · التسويات · العمليات · المخاطر · الأجهزة · التدقيق"
                            data-testid="hub-open-center">فتح المركز</button>` : ''}
                    ${transferControl(u)}
                    <button class="btn btn-sm ${u.is_active ? 'btn-outline-danger' : 'btn-outline-success'}"
                            data-act="toggle" data-id="${u.id}">${u.is_active ? 'تجميد' : 'فكّ التجميد'}</button>
                </td>
            </tr>`).join('');
    }

    document.getElementById('users-tbody').addEventListener('click', async (e) => {
        // الزرّ يُحسم أوّلاً. الصفّ نفسه يحمل data-act="open"، فلو بُحث عن
        // الصفّ قبل الزرّ لابتلع الصفُّ كلَّ ضغطةٍ على أزراره — وهو ما جعل
        // «تحويل رصيد» ينقل إلى صفحة الوكيل بدل فتح النافذة.
        const btn = e.target.closest('button[data-act]');
        // فتح صفحة التفاصيل: زر «التفاصيل»، أو الضغط على الصفّ خارج الأزرار
        const row = btn ? null : e.target.closest('tr[data-act="open"]');
        // AMIAL-MERCHANT-CENTER-001 — **«فتح المركز» غيرُ «التفاصيل»**:
        // التفاصيل ملفُّ حسابٍ لأيّ دور، والمركزُ لوحةُ تاجرٍ كاملةً بأقسامها
        // الإحدى عشرة وأفعالها. ويُحسم قبل الصفّ لئلّا يبتلع الصفُّ الضغطة.
        if (btn && btn.dataset.act === 'center') {
            window.location.href = `{{ url('admin/amial/merchant-center') }}/${btn.dataset.id}`;
            return;
        }
        if ((btn && btn.dataset.act === 'open') || row) {
            window.location.href = `${base}/account/${(btn || row).dataset.id}`;
            return;
        }
        if (!btn) return;
        const id = btn.dataset.id;

        if (btn.dataset.act === 'toggle') {
            const reason = prompt('سبب التجميد/فكّ التجميد (يُسجَّل في التدقيق):') || '';
            try {
                const j = await post(`${base}/users/${id}/toggle-active`, {reason});
                alert(j.message); loadUsers();
            } catch (err) { alert(err.message); }

        } else if (btn.dataset.act === 'transfer') {
            transferUserId = id;
            document.getElementById('transfer-target').textContent = `إلى: ${btn.dataset.name} (#${id})`;
            document.getElementById('transfer-title').textContent = isAgents ? 'تحويل رصيد للوكيل' : 'إعادة مبلغ / تحويل للحساب';
            document.getElementById('transfer-error').textContent = '';
            document.getElementById('transfer-amount').value = '';
            new bootstrap.Modal('#modal-transfer').show();

        } else if (btn.dataset.act === 'tx') {
            document.getElementById('tx-title').textContent = `سجل عمليات ${btn.dataset.name}`;
            const tbody = document.getElementById('tx-tbody');
            tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3">جارٍ التحميل…</td></tr>';
            new bootstrap.Modal('#modal-tx').show();
            const r = await fetch(`${base}/users/${id}/transactions.json`, {headers: {'Accept': 'application/json'}});
            const j = await r.json();
            tbody.innerHTML = j.data.length ? j.data.map(t => `
                <tr>
                    <td class="small font-monospace">${esc(t.transaction_id)}</td>
                    <td>${esc(t.transaction_type)}</td>
                    <td class="text-danger">${fmt(t.debit)}</td>
                    <td class="text-success">${fmt(t.credit)}</td>
                    <td>${fmt(t.balance)}</td>
                    <td class="small">${esc(t.created_at ?? '')}</td>
                </tr>`).join('')
                : '<tr><td colspan="6" class="text-center text-muted py-3">لا عمليات</td></tr>';
        }
    });

    document.getElementById('transfer-submit').addEventListener('click', async () => {
        const errEl = document.getElementById('transfer-error');
        errEl.textContent = '';
        try {
            const amount = document.getElementById('transfer-amount').value;
            const reason = document.getElementById('transfer-reason').value;
            const j = isAgents
                ? await post(`${base}/agents/${transferUserId}/credit`, {amount, reference: reason})
                : await post(`${base}/transfer`, {to_user_id: transferUserId, amount, reason});
            bootstrap.Modal.getInstance(document.getElementById('modal-transfer')).hide();
            alert(j.message); loadUsers();
        } catch (err) { errEl.textContent = err.message; }
    });

    document.getElementById('add-submit').addEventListener('click', async () => {
        const errEl = document.getElementById('add-error');
        errEl.textContent = '';
        try {
            const payload = {
                f_name: document.getElementById('add-fname').value,
                l_name: document.getElementById('add-lname').value,
                phone: document.getElementById('add-phone').value,
                password: document.getElementById('add-password').value,
                pin: document.getElementById('add-pin').value || undefined,
            };
            if (slug === 'merchants') {
                payload.store_name = document.getElementById('add-store').value;
                payload.business_type = document.getElementById('add-biz').value;
                payload.plan = document.getElementById('add-plan').value;
            }
            const j = await post(`${base}/${slug}/users`, payload);
            bootstrap.Modal.getInstance(document.getElementById('modal-add')).hide();
            alert(j.message); loadUsers();
        } catch (err) { errEl.textContent = err.message; }
    });

    // بحث + ترقيم
    let searchTimer;
    document.getElementById('search-box').addEventListener('input', (e) => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => { search = e.target.value.trim(); page = 1; loadUsers(); }, 350);
    });
    document.getElementById('prev-page').addEventListener('click', () => { if (page > 1) { page--; loadUsers(); } });
    document.getElementById('next-page').addEventListener('click', () => { if (page < lastPage) { page++; loadUsers(); } });

    // ===== تبويب التوثيق =====
    async function loadKyc() {
        const wrap = document.getElementById('kyc-cards');
        wrap.innerHTML = '<div class="col-12 text-muted">جارٍ التحميل…</div>';
        const r = await fetch(`${base}/${slug}/kyc.json`, {headers: {'Accept': 'application/json'}});
        const j = await r.json();
        if (!j.data.length) {
            wrap.innerHTML = '<div class="col-12 text-muted">لا طلبات توثيق معلّقة 🎉</div>';
            return;
        }
        wrap.innerHTML = j.data.map(u => `
            <div class="col-md-6 col-lg-4"><div class="card stat-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <strong>${esc(u.name)}</strong>
                        <span class="text-muted small" dir="ltr">${esc(u.phone)}</span>
                    </div>
                    <div class="small text-muted mb-2">${esc(u.id_type ?? '')} ${esc(u.id_number ?? '')}</div>
                    <div class="d-flex gap-2 flex-wrap mb-3">
                        ${(u.documents || []).map(d =>
                            `<a href="${esc(d)}" target="_blank"><img src="${esc(d)}" style="height:70px;border-radius:6px;border:1px solid #ddd"></a>`
                        ).join('') || '<span class="text-muted small">لا صور</span>'}
                    </div>
                    <div class="d-flex gap-2">
                        <button class="btn btn-sm btn-success flex-fill" data-kyc="1" data-id="${u.id}">اعتماد</button>
                        <button class="btn btn-sm btn-outline-danger flex-fill" data-kyc="2" data-id="${u.id}">رفض</button>
                    </div>
                </div>
            </div></div>`).join('');
    }

    document.getElementById('kyc-cards').addEventListener('click', async (e) => {
        const btn = e.target.closest('button[data-kyc]');
        if (!btn) return;
        try {
            const j = await post(`${base}/users/${btn.dataset.id}/kyc`, {status: Number(btn.dataset.kyc)});
            alert(j.message); loadKyc(); loadUsers();
        } catch (err) { alert(err.message); }
    });

    document.getElementById('kyc-tab-link').addEventListener('shown.bs.tab', loadKyc);
    document.getElementById('kyc-tab-link').addEventListener('click', () => setTimeout(loadKyc, 200));

    loadUsers();
})();
</script>
@endpush
