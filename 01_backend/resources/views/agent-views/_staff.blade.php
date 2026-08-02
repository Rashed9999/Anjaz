{{-- AMIAL-AGENT-STAFF-001 — الموظّفون والشبابيك.

     الشركة تُعيّن وتُعطّل بنفسها. ولو مرّ تعيينُ صرّافٍ بتذكرة دعمٍ عندنا
     لتعطّل فرعٌ في المكلا انتظاراً لموظّفٍ في صنعاء. --}}

<div class="tab-pane fade show active" id="ag-staff">
    <div class="card p-3">
        <div class="d-flex mb-3 align-items-center flex-wrap gap-2">
            <h6 class="mb-0">موظّفو الشركة</h6>
            <span class="text-muted small" id="st-count"></span>
            <button class="btn btn-sm btn-primary ms-auto" id="st-add" data-testid="st-add">+ تعيين موظّف</button>
        </div>

        {{-- **كيف يدخل الموظّف؟** سؤالٌ لم تكن الشاشة تجيبه.
             كنّا نُعطي الوكيل رمزاً ولا نقول له من أين يُستعمل، فيبقى الرمز
             في يده بلا باب. والعنوان يُبنى من عنوان الخادم نفسه فيصحّ في كلّ
             نشرٍ بلا ضبط. --}}
        <div class="alert alert-info py-2 small d-flex flex-wrap align-items-center gap-2">
            <span>🔑 <strong>الموظّف يدخل من:</strong></span>
            <code class="user-select-all" dir="ltr" id="st-portal-url">{{ route('agent.login') }}</code>
            <button class="btn btn-sm btn-outline-primary" id="st-copy-url">نسخ العنوان</button>
            <span class="text-muted">برمزه (مثل <code dir="ltr">MKL-001</code>) وكلمة السرّ التي أعطيتَه إيّاها.</span>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light"><tr>
                    <th>رمز الدخول</th><th>الاسم</th><th>الدور</th><th>الفرع</th>
                    <th>حدّ العملية</th><th>آخر دخول</th><th>الحالة</th><th></th>
                </tr></thead>
                <tbody id="st-tbody">
                    <tr><td colspan="8" class="text-center text-muted py-4">جارٍ التحميل…</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="tab-pane fade" id="ag-shifts">
    <div class="card p-3">
        <div class="d-flex gap-2 mb-3 flex-wrap align-items-center">
            <h6 class="mb-0">ورديّات الشبابيك</h6>
            <select id="sh-branch" class="form-select" style="max-width:260px"></select>
            <input type="date" id="sh-date" class="form-control" style="max-width:180px">
            <button class="btn btn-outline-primary btn-sm" id="sh-load">عرض</button>
        </div>

        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light"><tr>
                    <th>#</th><th>الصرّاف</th><th>العهدة</th><th>في الدرج</th>
                    <th>إيداعات</th><th>سحوبات</th><th>المتوقَّع</th><th>المعدود</th>
                    <th>الفرق</th><th>الحالة</th><th>ملفّ التسوية</th><th></th>
                </tr></thead>
                <tbody id="sh-tbody">
                    <tr><td colspan="12" class="text-center text-muted py-4">اختر فرعاً</td></tr>
                </tbody>
            </table>
        </div>
        <div class="text-muted small mt-2">
            الفرق يُسجَّل حركةَ نقدٍ بسببه ولا يُبتلَع — ولا يُعرَض المتوقَّع للصرّاف قبل أن يعدّ.
            <br>وإغلاقُ الورديّة إقرارٌ ماليّ يُرفع إلى الإدارة: ما كان بفرقٍ ينتظر قرارك،
            وما كان مطابقاً لا يحتاج قراراً — و«مطابقة» ليست «قُبلت».
        </div>
    </div>
</div>

{{-- ============ سجلّ العمليات ============
     كان الطريق الوحيد لمعرفة «ماذا جرى اليوم» فتحَ كلّ فرعٍ على حدة في
     «حركة النقد». والسجلّ الواحد باسم من نفّذ هو ما يُقرأ فعلاً. --}}
<div class="tab-pane fade" id="ag-ops">
    <div class="card p-3">
        <div class="d-flex gap-2 mb-3 flex-wrap align-items-center">
            <h6 class="mb-0">سجلّ العمليات</h6>
            <select id="op-branch" class="form-select" style="max-width:220px"></select>
            <select id="op-reason" class="form-select" style="max-width:180px">
                <option value="">إيداع وسحب</option>
                <option value="customer_deposit">إيداع فقط</option>
                <option value="customer_withdraw">سحب فقط</option>
            </select>
            <input type="date" id="op-from" class="form-control" style="max-width:160px">
            <input type="date" id="op-to" class="form-control" style="max-width:160px">
            <button class="btn btn-outline-primary btn-sm" id="op-load">عرض</button>
        </div>

        <div class="row g-3 mb-3" id="op-totals"></div>

        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light"><tr>
                    <th>الوقت</th><th>النوع</th><th>المبلغ</th><th>العميل</th>
                    <th>الموظّف</th><th>الفرع</th><th>المرجع</th>
                </tr></thead>
                <tbody id="op-tbody">
                    <tr><td colspan="7" class="text-center text-muted py-4">اضغط «عرض»</td></tr>
                </tbody>
            </table>
        </div>
        <div class="text-muted small mt-2" id="op-limit-note"></div>
    </div>
</div>

{{-- ============ ملفّ الموظّف ٣٦٠ ============ --}}
<div class="modal fade" id="pr-modal" tabindex="-1">
    <div class="modal-dialog modal-xl"><div class="modal-content">
        <div class="modal-header">
            <h5 class="modal-title" id="pr-title">ملفّ الموظّف</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body" id="pr-body">
            <div class="text-muted">جارٍ التحميل…</div>
        </div>
    </div></div>
</div>

{{-- نافذة تعيين موظّف --}}
<div class="modal fade" id="st-modal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">تعيين موظّف</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="mb-2"><label class="form-label">الاسم</label>
                <input class="form-control" id="st-name"></div>
            <div class="mb-2"><label class="form-label">الدور</label>
                <select class="form-select" id="st-role">
                    <option value="teller">صرّاف (شبّاك)</option>
                    <option value="branch_manager">مدير فرع</option>
                </select></div>
            <div class="mb-2" id="st-branch-wrap"><label class="form-label">الفرع</label>
                <select class="form-select" id="st-branch"></select></div>
            <div class="alert alert-warning py-2 small" id="st-no-branch" style="display:none">
                لا فروع لشركتك بعد، والموظّف يعمل في فرع.
                أغلق هذه النافذة وافتح تبويب <strong>«🏬 الفروع»</strong> ← «+ فرع جديد» أوّلاً.
            </div>
            <div class="mb-2"><label class="form-label">الهاتف (اختياري)</label>
                <input class="form-control" id="st-phone" dir="ltr"></div>
            <div class="mb-2"><label class="form-label">كلمة السرّ (٦ أحرف فأكثر)</label>
                <input class="form-control" id="st-pass" dir="ltr"></div>
            <div class="mb-2"><label class="form-label">حدّ العملية الواحدة (٠ = بلا حدّ خاصّ)</label>
                <input class="form-control" id="st-limit" type="number" min="0" value="0" dir="ltr">
                <div class="form-text">صرّافٌ جديد يُعطى حدّاً منخفضاً حتى يُوثَق به.</div></div>
            <div class="text-danger small" id="st-err"></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-primary" id="st-save">تعيين</button>
        </div>
    </div></div>
</div>

@once
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
document.addEventListener('DOMContentLoaded', function () {
    const root = '{{ url('agent') }}';
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const $ = (id) => document.getElementById(id);
    const fmt = (n) => Number(n || 0).toLocaleString('en-US', {maximumFractionDigits: 2});
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c =>
        ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    if (!$('st-tbody')) return;   // صرّافٌ — لا شاشة موظّفين في صفحته

    async function post(url, body) {
        const r = await fetch(root + url, {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json'},
            body: JSON.stringify(body || {}),
        });
        const j = await r.json().catch(() => ({}));
        if (!r.ok || j.success === false) throw new Error(j.message || ('خطأ ' + r.status));
        return j;
    }
    const get = async (u) => (await fetch(root + u, {headers: {'Accept': 'application/json'}})).json();

    let branches = [];
    const PORTAL_URL = $('st-portal-url') ? $('st-portal-url').textContent.trim() : '';

    if ($('st-copy-url')) {
        $('st-copy-url').addEventListener('click', async () => {
            try {
                await navigator.clipboard.writeText(PORTAL_URL);
                $('st-copy-url').textContent = 'نُسخ ✓';
                setTimeout(() => { $('st-copy-url').textContent = 'نسخ العنوان'; }, 1800);
            } catch (e) {
                // المتصفّح قد يمنع النسخ على اتّصالٍ غير مشفَّر — يُقال ذلك
                // بدل أن يبدو الزرّ معطّلاً بلا سبب.
                alert('انسخ العنوان يدويّاً:\n' + PORTAL_URL);
            }
        });
    }

    async function loadBranches() {
        // الغلاف `ok()` يضع الحمولة في `meta` — لا `data` ولا الجذر.
        // وقراءةُ المفتاح الخطأ لا تُخطئ بصوتٍ عالٍ: تُعيد `[]` بهدوء،
        // فتُخفى قائمة الفروع، ثمّ يرفض الخادم التعيين بـ«اختر الفرع» —
        // فيقف المستعمل أمام رسالةٍ تطلب حقلاً لا يراه.
        const j = await get('/overview');
        branches = (j.meta && j.meta.branches) || [];

        const opts = branches.map(b =>
            `<option value="${b.id}">${esc(b.name)} (${esc(b.code)})</option>`).join('');

        ['sh-branch', 'st-branch'].forEach(id => { if ($(id)) $(id).innerHTML = opts; });
        // سجلّ العمليات يقبل «كلّ الفروع»: السؤال الأوّل «ماذا جرى اليوم»
        // لا «ماذا جرى في فرعٍ بعينه».
        if ($('op-branch')) $('op-branch').innerHTML = '<option value="">كلّ الفروع</option>' + opts;
        return branches;
    }

    async function loadStaff() {
        const j = await get('/staff');
        const rows = j.data || [];
        $('st-count').textContent = `${rows.length} موظّف`;

        $('st-tbody').innerHTML = rows.length ? rows.map(s => `
            <tr>
                <td><span class="badge bg-dark font-monospace" dir="ltr">${esc(s.username)}</span></td>
                <td>${esc(s.name)}<div class="small text-muted" dir="ltr">${esc(s.phone ?? '')}</div></td>
                <td>${esc(s.role_label)}</td>
                <td>${esc(s.branch ?? '—')}</td>
                <td>${Number(s.max_txn_amount) > 0 ? fmt(s.max_txn_amount) : '<span class="text-muted">بلا حدّ خاصّ</span>'}</td>
                <td class="small">${esc(s.last_login_at ?? 'لم يدخل بعد')}</td>
                <td>
                    ${s.is_active ? '<span class="badge bg-success">نشِط</span>' : '<span class="badge bg-danger">معطَّل</span>'}
                    ${s.has_open_shift ? '<span class="badge bg-warning text-dark">ورديّة مفتوحة</span>' : ''}
                </td>
                <td class="text-nowrap">
                    <button class="btn btn-sm btn-primary" data-do="profile" data-id="${s.id}" data-name="${esc(s.name)}">الملفّ</button>
                    <button class="btn btn-sm btn-outline-secondary" data-do="pass" data-id="${s.id}">كلمة سرّ</button>
                    <button class="btn btn-sm ${s.is_active ? 'btn-outline-danger' : 'btn-outline-success'}"
                            data-do="toggle" data-id="${s.id}" data-active="${s.is_active ? 0 : 1}">
                        ${s.is_active ? 'تعطيل' : 'تفعيل'}</button>
                </td>
            </tr>`).join('')
            : '<tr><td colspan="8" class="text-center text-muted py-4">لا موظّفين بعد — عيّن أوّل صرّافٍ ليعمل الشبّاك</td></tr>';
    }

    $('st-tbody').addEventListener('click', async (e) => {
        const b = e.target.closest('button[data-do]');
        if (!b) return;
        try {
            if (b.dataset.do === 'profile') {
                openProfile(b.dataset.id, b.dataset.name);
                return;
            }
            if (b.dataset.do === 'toggle') {
                const j = await post(`/staff/${b.dataset.id}/active`, {active: b.dataset.active === '1'});
                alert(j.message);
            } else {
                const p = prompt('كلمة السرّ الجديدة (٦ أحرف فأكثر):');
                if (!p) return;
                const j = await post(`/staff/${b.dataset.id}/password`, {password: p});
                alert(j.message);
            }
            loadStaff();
        } catch (err) { alert(err.message); }
    });

    // النافذة تجلب الفروع **عند كلّ فتح**، لا عند تحميل الصفحة.
    //
    // **العطل الذي أدخل هذا.** كانت `loadBranches()` تُستدعى مرّةً واحدة عند
    // التحميل. فمن يفتح اللوحة وشركتُه بلا فروع، ثمّ يُنشئ فرعاً من تبويب
    // «الفروع»، ثمّ يفتح نافذة التعيين — يرى «لا فروع لشركتك بعد» بينما
    // البطاقة فوقه تقول «١ فرع». الشاشة نفسها تناقض نفسها.
    //
    // والسبب أنّ الصفحة فيها سكربتان مستقلّان لكلٍّ منهما نسختُه من قائمة
    // الفروع، فيُحدَّث أحدهما ويبقى الآخر على ما حُمّل. وجلبُ القائمة عند
    // الفتح يجعل التقادم مستحيلاً مهما فعل السكربت الآخر.
    $('st-add').addEventListener('click', async () => {
        $('st-err').textContent = '';
        applyBranchState(null);                  // «جارٍ التحميل» لا حكمٌ مبكّر
        new bootstrap.Modal($('st-modal')).show();
        applyBranchState(await loadBranches());
    });

    /**
     * حالة حقل الفرع: null = يُجلب الآن، [] = لا فروع، وإلّا جاهز.
     *
     * والحالات الثلاث مفصولة عمداً: عرضُ «لا فروع» أثناء الجلب يجعل من
     * ينشئ فرعه للتوّ يظنّ أنّه لم يُحفظ.
     */
    function applyBranchState(list) {
        const wrap = $('st-branch-wrap');
        const empty = $('st-no-branch');
        const save = $('st-save');

        if (list === null) {
            if (wrap) wrap.style.display = 'none';
            if (empty) empty.style.display = 'none';
            if (save) save.disabled = true;
            return;
        }

        const has = list.length > 0;
        if (wrap) wrap.style.display = has ? '' : 'none';
        if (empty) empty.style.display = has ? 'none' : '';
        if (save) save.disabled = !has;
    }

    $('st-save').addEventListener('click', async () => {
        $('st-err').textContent = '';
        try {
            const j = await post('/staff', {
                name: $('st-name').value,
                role: $('st-role').value,
                branch_id: $('st-branch').value || null,
                phone: $('st-phone').value || null,
                password: $('st-pass').value,
                max_txn_amount: $('st-limit').value || 0,
            });
            bootstrap.Modal.getInstance($('st-modal')).hide();
            // الرمز والعنوان وكلمة السرّ معاً — وهي الثلاثة التي يحتاجها
            // الموظّف. وإعطاءُ الرمز وحده يترك سؤالاً بلا جواب.
            alert(j.message
                + '\n\nالعنوان: ' + PORTAL_URL
                + '\nالرمز: ' + j.username
                + '\nكلمة السرّ: التي أدخلتَها الآن');
            $('st-name').value = $('st-pass').value = $('st-phone').value = '';
            loadStaff();
        } catch (e) { $('st-err').textContent = e.message; }
    });

    // ── ملفّ الموظّف ٣٦٠ ────────────────────────────────────────────────
    //
    // الرقم وحده لا يُتَّخذ عليه قرار: مديرٌ يقرأ «٧٢» لا يعرف أيوقف الموظّف
    // أم يدرّبه أم يراجع خزنته. فتُعرَض الإشارات كلّها بأوزانها.
    async function openProfile(id, name) {
        $('pr-title').textContent = 'ملفّ الموظّف — ' + (name || '');
        $('pr-body').innerHTML = '<div class="text-muted">جارٍ التحميل…</div>';
        new bootstrap.Modal($('pr-modal')).show();

        const j = await get(`/staff/${id}/profile`);
        if (!j.success) {
            $('pr-body').innerHTML = `<div class="alert alert-danger mb-0">${esc(j.message)}</div>`;
            return;
        }
        renderProfile(j.meta);
    }

    const RISK_CLASS = {low: 'success', medium: 'warning', high: 'danger', unrated: 'secondary'};

    function renderProfile(m) {
        const s = m.staff, o = m.operations, sh = m.shifts, r = m.risk;

        const stat = (title, value, sub, cls) => `
            <div class="col-md-3 col-6"><div class="card p-3 h-100">
                <small class="text-muted">${title}</small>
                <div class="fs-4 fw-bold ${cls || ''}">${value}</div>
                ${sub ? `<div class="small text-muted">${sub}</div>` : ''}
            </div></div>`;

        // درجةٌ غير محسوبة تُقال بسببها ولا تُعرَض صفراً: من لم يُختبَر ليس
        // منخفض المخاطر، وإظهارُه أخضرَ يجعل المدير يثق بمن لم يُجرَّب.
        const riskBox = r.score === null
            ? `<div class="alert alert-secondary mb-0">
                   <strong>درجة المخاطرة: غير محسوبة</strong>
                   <div class="small mt-1">${esc(r.reason)}</div>
               </div>`
            : `<div class="alert alert-${RISK_CLASS[r.level]} mb-0">
                   <div class="d-flex align-items-center gap-3 flex-wrap">
                       <div class="fs-2 fw-bold">${r.score}</div>
                       <div><strong>خطر ${esc(r.level_label)}</strong>
                            <div class="small">من ١٠٠ — محسوبةٌ من الإشارات أدناه</div></div>
                   </div>
                   <table class="table table-sm mt-3 mb-0 bg-white">
                       <thead><tr><th>الإشارة</th><th>القياس</th><th>النقاط</th></tr></thead>
                       <tbody>${r.signals.map(g => `
                           <tr><td>${esc(g.label)}</td><td class="small">${esc(g.value)}</td>
                               <td><strong>${g.points}</strong> <span class="text-muted small">/ ${g.max}</span></td></tr>`).join('')}
                       </tbody>
                   </table>
               </div>`;

        $('pr-body').innerHTML = `
            <div class="mb-3">
                <span class="badge bg-dark font-monospace" dir="ltr">${esc(s.username)}</span>
                <span class="badge bg-secondary">${esc(s.role_label)}</span>
                ${s.branch ? `<span class="badge bg-info text-dark">${esc(s.branch)}</span>` : ''}
                ${s.is_active ? '<span class="badge bg-success">نشِط</span>' : '<span class="badge bg-danger">معطَّل</span>'}
                <span class="text-muted small ms-2">آخر دخول: ${esc(s.last_login_at ?? 'لم يدخل')}</span>
                <span class="text-muted small ms-2">الفترة: ${esc(m.period.from)} → ${esc(m.period.to)}</span>
            </div>

            <div class="row g-3 mb-3">
                ${stat('إيداعات', fmt(o.deposits_total), `${o.deposits_count} عملية`, 'text-success')}
                ${stat('سحوبات', fmt(o.withdrawals_total), `${o.withdrawals_count} عملية`, 'text-primary')}
                ${stat('حجم العمل', fmt(o.total_volume), `${o.total_count} عملية · ${o.distinct_customers} عميلاً`)}
                ${stat('الدقّة',
                       sh.accuracy_pct === null ? '<span class="fs-6 text-muted">لا إغلاق بعد</span>' : sh.accuracy_pct + '٪',
                       `${sh.exact_count} من ${sh.closed} ورديّة مطابقة`)}
            </div>

            <div class="row g-3 mb-3">
                ${stat('عجز', fmt(sh.shortage_total), `${sh.shortage_count} ورديّة`, 'text-danger')}
                ${stat('فائض', fmt(sh.overage_total), `${sh.overage_count} ورديّة`, 'text-warning')}
                ${stat('ورديّات', sh.closed, `${sh.opened} فُتحت · ${sh.still_open} ما زالت مفتوحة`)}
                ${stat('فروقٌ لم تُراجَع', sh.pending_review, 'تنتظر قرارك', sh.pending_review > 0 ? 'text-danger' : '')}
            </div>

            <div class="alert alert-light border small py-2">
                <strong>العجز والفائض لا يُقاصّان.</strong> من نقص عنده خمسةٌ يوماً وزاد خمسةٌ
                يوماً آخر ليس «صفراً» — هما حادثتان تستحقّان سؤالين.
            </div>

            ${riskBox}

            <h6 class="mt-4">آخر الورديّات</h6>
            <div class="table-responsive"><table class="table table-sm align-middle">
                <thead class="table-light"><tr>
                    <th>#</th><th>فُتحت</th><th>إيداعات</th><th>سحوبات</th>
                    <th>المعدود</th><th>الفرق</th><th>ملفّ التسوية</th></tr></thead>
                <tbody>${sh.closed + sh.still_open === 0
                    ? '<tr><td colspan="7" class="text-center text-muted py-3">لا ورديّات في الفترة</td></tr>'
                    : m.recent_shifts.map(x => `
                        <tr>
                            <td>${x.id}</td>
                            <td class="small">${esc(x.opened_at ?? '')}</td>
                            <td class="money">${fmt(x.deposits_total)}</td>
                            <td class="money">${fmt(x.withdrawals_total)}</td>
                            <td class="money">${x.counted_cash === null ? '<span class="text-muted">لم يُعدّ</span>' : fmt(x.counted_cash)}</td>
                            <td class="money ${x.variance && Number(x.variance) !== 0 ? 'text-danger fw-bold' : ''}">
                                ${x.variance === null ? '—' : fmt(x.variance)}</td>
                            <td class="small">${esc(x.review_label)}</td>
                        </tr>`).join('')}</tbody>
            </table></div>

            <h6 class="mt-4">آخر العمليات</h6>
            <div class="table-responsive"><table class="table table-sm align-middle">
                <thead class="table-light"><tr>
                    <th>الوقت</th><th>النوع</th><th>المبلغ</th><th>العميل</th><th>المرجع</th></tr></thead>
                <tbody>${m.recent_operations.length ? m.recent_operations.map(x => `
                    <tr>
                        <td class="small">${esc(x.at)}</td>
                        <td>${esc(x.label)}</td>
                        <td class="money fw-bold">${fmt(x.amount)}</td>
                        <td>${esc(x.customer ?? '—')}<div class="small text-muted" dir="ltr">${esc(x.customer_phone ?? '')}</div></td>
                        <td class="small font-monospace" dir="ltr">${esc(x.reference ?? '')}</td>
                    </tr>`).join('')
                    : '<tr><td colspan="5" class="text-center text-muted py-3">لا عمليات في الفترة</td></tr>'}</tbody>
            </table></div>`;
    }

    // ── سجلّ العمليات ───────────────────────────────────────────────────
    async function loadOps() {
        const tb = $('op-tbody');
        tb.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-3">جارٍ التحميل…</td></tr>';

        const q = new URLSearchParams();
        if ($('op-branch').value) q.set('branch_id', $('op-branch').value);
        if ($('op-reason').value) q.set('reason', $('op-reason').value);
        if ($('op-from').value) q.set('from', $('op-from').value);
        if ($('op-to').value) q.set('to', $('op-to').value);

        const j = await get('/operations?' + q.toString());
        const m = j.meta || {};
        const rows = m.rows || [];
        const t = m.totals || {};

        $('op-totals').innerHTML = `
            <div class="col-md-3 col-6"><div class="card p-3">
                <small class="text-muted">إيداعات</small>
                <div class="fs-4 fw-bold text-success">${fmt(t.deposits_total)}</div>
                <div class="small text-muted">${t.deposits_count} عملية</div></div></div>
            <div class="col-md-3 col-6"><div class="card p-3">
                <small class="text-muted">سحوبات</small>
                <div class="fs-4 fw-bold text-primary">${fmt(t.withdrawals_total)}</div>
                <div class="small text-muted">${t.withdrawals_count} عملية</div></div></div>`;

        // الإجماليّات على الفترة كلّها والصفوف محدودة — ويُقال ذلك صراحةً،
        // فقارئٌ يجمع الصفوف بنفسه فلا يطابق الإجماليّ يظنّ الرقم خاطئاً.
        $('op-limit-note').textContent = t.row_limit_reached
            ? 'يُعرَض أحدث ٣٠٠ عمليّة — والإجماليّات أعلاه على الفترة كلّها. ضيّق التاريخ لرؤية الباقي.'
            : '';

        tb.innerHTML = rows.length ? rows.map(x => `
            <tr>
                <td class="small">${esc(x.at)}</td>
                <td>${x.reason === 'customer_deposit'
                    ? '<span class="badge bg-success">إيداع</span>'
                    : '<span class="badge bg-primary">سحب</span>'}</td>
                <td class="money fw-bold">${fmt(x.amount)}</td>
                <td>${esc(x.customer ?? '—')}<div class="small text-muted" dir="ltr">${esc(x.customer_phone ?? '')}</div></td>
                <td>${esc(x.staff)}<div class="small text-muted font-monospace" dir="ltr">${esc(x.staff_code ?? '')}</div></td>
                <td>${esc(x.branch)}</td>
                <td class="small font-monospace" dir="ltr">${esc(x.reference ?? '')}</td>
            </tr>`).join('')
            : '<tr><td colspan="7" class="text-center text-muted py-4">لا عمليات في هذه الفترة</td></tr>';
    }

    $('op-load').addEventListener('click', loadOps);

    async function loadShifts() {
        const bid = $('sh-branch').value;
        if (!bid) return;
        const d = $('sh-date').value;
        const j = await get(`/staff/shifts?branch_id=${bid}` + (d ? `&date=${d}` : ''));
        const rows = j.data || [];

        $('sh-tbody').innerHTML = rows.length ? rows.map(s => `
            <tr>
                <td>${s.id}</td>
                <td>${esc(s.staff ?? '—')}<div class="small text-muted font-monospace" dir="ltr">${esc(s.username ?? '')}</div></td>
                <td class="money">${fmt(s.opening_float)}</td>
                <td class="money cash">${fmt(s.cash_on_hand)}</td>
                <td class="money">${fmt(s.deposits_total)} <span class="text-muted small">(${s.deposits_count})</span></td>
                <td class="money">${fmt(s.withdrawals_total)} <span class="text-muted small">(${s.withdrawals_count})</span></td>
                <td class="money">${fmt(s.expected_cash)}</td>
                <td class="money">${s.counted_cash === null ? '<span class="text-muted">لم يُعدّ</span>' : fmt(s.counted_cash)}</td>
                <td class="money ${s.variance && Number(s.variance) !== 0 ? 'text-danger fw-bold' : ''}">
                    ${s.variance === null ? '—' : fmt(s.variance)}</td>
                <td>${s.status === 'open' ? '<span class="badge bg-success">مفتوحة</span>'
                                          : '<span class="badge bg-secondary">مغلقة</span>'}</td>
                <td>${reviewCell(s)}</td>
                <td class="text-nowrap">${s.status === 'open'
                        ? `<button class="btn btn-sm btn-outline-danger" data-close="${s.id}">إغلاق بالنيابة</button>`
                        : reviewButtons(s)}</td>
            </tr>`).join('')
            : '<tr><td colspan="12" class="text-center text-muted py-4">لا ورديّات</td></tr>';
    }

    // حالةُ ملفّ التسوية. و«مطابقة» ليست «قُبلت»: الأولى لا تحتاج قراراً،
    // والثانية تعني أنّ إنساناً نظر — وخلطُهما يجعل عجزاً بخمسة آلاف يبدو
    // كأنّ أحداً راجعه وهو لم يُقرأ.
    const REVIEW_BADGE = {
        balanced: 'bg-light text-dark border',
        pending: 'bg-danger',
        accepted: 'bg-success',
        investigating: 'bg-warning text-dark',
        resolved: 'bg-secondary',
    };

    function reviewCell(s) {
        if (s.status === 'open') return '<span class="text-muted small">لم تُغلق</span>';
        return `<span class="badge ${REVIEW_BADGE[s.review_status] || 'bg-secondary'}">${esc(s.review_label ?? '')}</span>`
            + (s.review_note ? `<div class="small text-muted">${esc(s.review_note)}</div>` : '')
            + (s.close_note ? `<div class="small text-muted">قال الصرّاف: ${esc(s.close_note)}</div>` : '');
    }

    function reviewButtons(s) {
        if (s.review_status === 'balanced') return '';
        if (s.review_status === 'pending' || s.review_status === 'investigating') {
            return `<button class="btn btn-sm btn-outline-success" data-review="${s.id}" data-decision="accepted">قبول الفرق</button>
                    <button class="btn btn-sm btn-outline-warning" data-review="${s.id}" data-decision="investigating">تحقيق</button>
                    <button class="btn btn-sm btn-outline-secondary" data-review="${s.id}" data-decision="resolved">إغلاق التحقيق</button>`;
        }
        return '';
    }

    $('sh-tbody').addEventListener('click', async (e) => {
        // الزرّ يُحسم أوّلاً — وأزرار المراجعة قبل زرّ الإغلاق بالنيابة.
        const rev = e.target.closest('button[data-review]');
        if (rev) {
            const note = prompt('سببُ القرار (عشرة أحرف فأكثر — يُسجَّل باسمك):');
            if (!note || note.trim().length < 10) { alert('السبب إلزاميّ — عشرة أحرف فأكثر'); return; }
            try {
                const j = await post(`/staff/shifts/${rev.dataset.review}/review`,
                    {decision: rev.dataset.decision, note});
                alert(j.message); loadShifts();
            } catch (err) { alert(err.message); }
            return;
        }

        const b = e.target.closest('button[data-close]');
        if (!b) return;
        // صرّافٌ غادر ولم يُغلق يترك درجاً مقفلاً على النظام لا على الواقع.
        const counted = prompt('اعدّ ما في درج الصرّاف واكتب المبلغ:');
        if (counted === null) return;
        const note = prompt('سبب الإغلاق بالنيابة (عشرة أحرف فأكثر إن وُجد فرق):', '') || '';
        try {
            const j = await post(`/staff/shifts/${b.dataset.close}/close`, {counted_cash: counted, note});
            alert(j.message); loadShifts();
        } catch (err) { alert(err.message); }
    });

    $('sh-load').addEventListener('click', loadShifts);
    $('sh-branch').addEventListener('change', loadShifts);

    // ملاحظة على ما كان هنا: كانت قائمة الفرع **تُخفى** حين يكون للشركة
    // فرعٌ واحدٌ أو لا فرع. وهذا خطأ في الحالتين — وأسوأه الثانية: شركةٌ بلا
    // فروع تفتح النافذة فلا ترى حقل الفرع، وتضغط «تعيين»، فيردّ الخادم
    // «اختر الفرع الذي يعمل فيه الموظّف». أي أنّ الواجهة تُخفي الحقل الذي
    // يطلبه الخادم.
    //
    // فصارت القائمة تُعرَض دائماً، وحالةُ «لا فروع» تُقال صراحةً مع ما يجب
    // فعله — لأنّ الترتيب الصحيح: الفرع أوّلاً ثمّ موظّفوه.
    // عند التحميل تُجلب القائمة لتبويب الورديّات فقط — وحالةُ النافذة
    // تُحسب عند فتحها لا هنا.
    loadBranches().then(() => loadShifts());

    loadStaff();
});
</script>
@endonce
