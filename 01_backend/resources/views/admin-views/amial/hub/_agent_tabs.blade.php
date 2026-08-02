{{-- AMIAL-AGENT-SUPERVISION-001 — تبويبا «الفروع والخزائن» و«حركة النقد».

     الإدارة تقرأ ولا تكتب هنا. تحريك النقد يقع من بوّابة الوكيل بيد موظّفه
     ويُسجَّل باسمه؛ ولو حرّكته الإدارة من هنا لصار في الدرج مالٌ لا يعرف
     الفرع من وضعه، وسقط الجرد. --}}

{{-- ===== الفروع والخزائن ===== --}}
<div class="tab-pane fade" id="tab-branches">
    <div class="card stat-card">
        <div class="card-header d-flex gap-2 flex-wrap align-items-center">
            <input type="text" id="branch-search" class="form-control" style="max-width:260px"
                   placeholder="بحث باسم الفرع أو رمزه أو مدينته…">
            <select id="branch-flag" class="form-select" style="max-width:220px">
                <option value="">كلّ الفروع</option>
                <option value="low_cash">نقدٌ منخفض</option>
                <option value="overloaded">فوق الحدّ الأعلى</option>
                <option value="not_counted">لم يُجرَد اليوم</option>
                <option value="inactive">مُعطَّل</option>
            </select>
            <span class="text-muted small ms-auto" id="branch-count"></span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light"><tr>
                    <th>الفرع</th><th>الوكيل الأمّ</th><th>المدينة</th>
                    <th>رصيد إلكترونيّ</th><th>خزنة الفرع</th><th>أدراج الصرّافين</th>
                    <th>حدود الخزنة</th><th>آخر جرد</th><th>الحالة</th><th></th>
                </tr></thead>
                <tbody id="branches-tbody">
                    <tr><td colspan="10" class="text-center text-muted py-4">جارٍ التحميل…</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

{{-- ===== حركة النقد ===== --}}
<div class="tab-pane fade" id="tab-cash">
    <div class="card stat-card">
        <div class="card-header d-flex gap-2 flex-wrap align-items-center">
            <input type="date" id="cash-date" class="form-control" style="max-width:190px">
            <select id="cash-reason" class="form-select" style="max-width:250px">
                <option value="">كلّ الأسباب</option>
                @foreach(\App\Models\Agent\AgentCashMovement::REASON_LABELS as $k => $label)
                    <option value="{{ $k }}">{{ $label }}</option>
                @endforeach
            </select>
            <button class="btn btn-sm btn-outline-secondary" id="cash-clear">مسح المرشّحات</button>
            <span class="text-muted small ms-auto" id="cash-count"></span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light"><tr>
                    <th>الوقت</th><th>الفرع</th><th>السبب</th><th>الاتجاه</th>
                    <th>المبلغ</th><th>قبل</th><th>بعد</th><th>الموظّف</th><th>المرجع</th>
                </tr></thead>
                <tbody id="cash-tbody">
                    <tr><td colspan="10" class="text-center text-muted py-4">جارٍ التحميل…</td></tr>
                </tbody>
            </table>
        </div>
        <div class="card-footer text-muted small">
            سجلٌّ يُلحَق ولا يُعدَّل — لا يوجد في هذه الشاشة زرُّ تعديلٍ لحركةِ نقدٍ وقعت.
        </div>
    </div>
</div>


{{-- ===== إقفال اليوم: التسويات المرفوعة من الوكلاء =====

     **هنا يتحوّل الريال الورقيّ إلى إلكترونيّ والعكس.** الوكيل يرفع كشف
     يومه في نافذةٍ ليليّة، وقبولُ أميال هو ما يُنفّذ التحويل فعلاً.

     ومن **لم يرفع** يتصدّر الجدول: قائمةٌ تعرض المرفوع وحده تُخفي بالضبط
     ما يجب أن يُرى. --}}
<div class="tab-pane fade" id="tab-daily">
    <div class="alert alert-secondary small">
        <strong>لماذا نافذةٌ زمنيّة؟</strong>
        عميلٌ أودع ألفاً في فرعٍ بالمكلا: سلّم ورقاً للوكيل، وخرج من رصيد الوكيل
        ألفٌ إلى محفظة العميل. فصار في الشبكة ألفٌ إلكترونيٌّ <strong>غطاؤه ورقةٌ في
        درج رجلٍ في المكلا</strong> — لا في خزينتنا. وذلك مقبولٌ ساعاتٍ لا أسابيع.
        فآخر اليوم يسلّم الورق ويستلم رصيداً، فيعود الغطاء إلى مكانه.
        <strong>ومن تأخّر لا يُغتفر بصمت</strong> — يحتاج فكّاً باسمٍ وسبب.
    </div>

    <div class="row g-3 mb-3" id="dly-totals"></div>

    <div class="card stat-card">
        <div class="card-header d-flex gap-2 align-items-center flex-wrap">
            <strong>يوم الشبكة</strong>
            <input type="date" id="dly-date" class="form-control form-control-sm" style="max-width:170px">
            <button class="btn btn-sm btn-outline-primary" id="dly-refresh">عرض</button>
            <span class="text-muted small ms-auto" id="dly-window"></span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light"><tr>
                    <th>الوكيل</th><th>الحالة</th><th>الرفع</th>
                    <th>إيداعات</th><th>سحوبات</th><th>عجز</th><th>فائض</th>
                    <th>التحويل المطلوب</th><th></th>
                </tr></thead>
                <tbody id="dly-tbody">
                    <tr><td colspan="9" class="text-center text-muted py-4">اضغط «عرض»</td></tr>
                </tbody>
            </table>
        </div>
    </div>
    <div id="dly-detail" class="mt-3"></div>
</div>

{{-- ===== التوازن والتسوية ===== --}}
<div class="tab-pane fade" id="tab-settlement">
    <div class="alert alert-secondary small">
        <strong>كيف يُقرأ هذا الجدول.</strong>
        «المتوقَّع» محسوبٌ من <strong>سطور الدفتر</strong> و<strong>سجلّ حركة النقد</strong>.
        و«الفعليّ» مقروءٌ من الأعمدة المخزَّنة. <strong>والفرق بينهما هو المنتَج</strong> —
        فلو حُسب المتوقَّع من العمود نفسه لخرج الفرق صفراً دائماً وبدا كلّ شيءٍ
        متوازناً حتى وهو منهار.
    </div>

    <div class="card stat-card">
        <div class="card-header d-flex gap-2 align-items-center flex-wrap">
            <strong>ميزان الوكلاء</strong>
            <span class="text-muted small" id="stl-count"></span>
            <button class="btn btn-sm btn-outline-primary ms-auto" id="stl-refresh">تحديث</button>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light"><tr>
                    <th>الوكيل</th><th>الحالة</th>
                    <th>الرصيد الإلكترونيّ<div class="small text-muted fw-normal">فعليّ · فرق</div></th>
                    <th>النقد الورقيّ<div class="small text-muted fw-normal">فعليّ · فرق</div></th>
                    <th>التزام المنصّة</th><th>صرفٌ معلَّق</th><th></th>
                </tr></thead>
                <tbody id="stl-tbody">
                    <tr><td colspan="7" class="text-center text-muted py-4">جارٍ التحميل…</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div id="stl-detail" class="mt-3"></div>
</div>

@push('script')
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(function () {
    const base = '{{ url('admin/amial/hub') }}';
    const fmt = (n) => Number(n || 0).toLocaleString('en-US', {maximumFractionDigits: 2});
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c =>
        ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const get = async (u) => (await fetch(u, {headers: {'Accept': 'application/json'}})).json();
    const $ = (id) => document.getElementById(id);

    // **كانتا مستعمَلتين وغير معرَّفتين.** شيفرةُ «إقفال اليوم» كانت تنادي
    // `$` و`post` ولا وجود لهما في هذا الملفّ، فيُلقى «$ is not defined»
    // عند التحميل — **فيموت باقي الملفّ معه**، ومعه تبويب التوازن كلّه.
    // ولم يكشفه فحصُ المعرّفات (يفحص وجود العناصر لا وجود الدوالّ)، ولا
    // فحصُ التركيب (الصياغة سليمة). كشفه المتصفّح وحده.
    const post = async (u, body) => {
        const r = await fetch(u, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json',
            },
            body: JSON.stringify(body || {}),
        });
        const j = await r.json().catch(() => ({}));
        if (!r.ok) throw new Error(j.message || ('خطأ ' + r.status));
        return j;
    };

    // ===== بطاقات الشبكة =====
    async function loadNetwork() {
        const n = await get(`${base}/agents/network.json`);
        const set = (k, v) => document.querySelectorAll(`[data-net="${k}"]`)
            .forEach(el => el.textContent = v);

        set('agents', fmt(n.agents));
        set('branches', fmt(n.branches));
        set('branches_active_txt', `${fmt(n.branches_active)} نشِط`);
        set('agents_without_branch_txt',
            n.agents_without_branch > 0 ? `${fmt(n.agents_without_branch)} بلا فرعٍ نشِط — لا يخدمون أحداً` : '');
        // مفصولان: التزامُ المنصّة أوّلاً، ثمّ مالُ شركات الصرافة.
        set('emoney_total', fmt(Number(n.emoney_parents) + Number(n.emoney_branches)) + ' ر.ي');
        set('cash_on_hand', fmt(n.cash_on_hand) + ' ر.ي');
        set('low_cash', fmt(n.flags.low_cash));
        set('overloaded', fmt(n.flags.overloaded));
        set('not_counted_today', fmt(n.flags.not_counted_today));
        set('movements_today', fmt(n.movements_today));
        set('open_shifts', fmt(n.open_shifts));
        set('cash_reserve', fmt(n.cash_reserve) + ' ر.ي');
    }

    // ===== الفروع =====
    async function loadBranches() {
        const tbody = document.getElementById('branches-tbody');
        tbody.innerHTML = '<tr><td colspan="10" class="text-center text-muted py-4">جارٍ التحميل…</td></tr>';
        const flag = document.getElementById('branch-flag').value;
        const search = document.getElementById('branch-search').value.trim();
        const j = await get(`${base}/agents/branches.json?flag=${flag}&search=${encodeURIComponent(search)}`);

        document.getElementById('branch-count').textContent = `${j.data.length} فرع`;

        if (!j.data.length) {
            tbody.innerHTML = '<tr><td colspan="10" class="text-center text-muted py-4">'
                + 'لا فروع مطابقة. الفروع تُنشأ من بوّابة الوكيل بيد شركة الصرافة نفسها.'
                + '</td></tr>';
            return;
        }

        tbody.innerHTML = j.data.map(b => {
            const cash = b.has_till
                ? `<span class="${b.low_cash ? 'text-danger fw-bold' : (b.overloaded ? 'text-warning fw-bold' : '')}">${fmt(b.cash_on_hand)} ر.ي</span>`
                : '<span class="badge bg-secondary">لا خزنة</span>';
            const limits = b.has_till
                ? `<small class="text-muted">أدنى ${fmt(b.min_cash_alert)} · أعلى ${fmt(b.max_cash_on_hand)}</small>`
                : '—';
            // «لم يُجرَد» ليس صفراً ولا خطأً — هو غيابُ شهادةِ إنسان.
            const counted = b.not_counted_today
                ? `<span class="badge bg-warning text-dark">لم يُجرَد اليوم</span>`
                  + (b.last_counted_at ? `<div class="small text-muted">${esc(b.last_counted_at)}</div>` : '')
                : `<span class="badge bg-success">جُرِد</span>`
                  + `<div class="small text-muted">${esc(b.last_counted_at)}</div>`;

            return `<tr>
                <td><strong>${esc(b.name)}</strong>
                    <div class="small text-muted font-monospace">${esc(b.code)} · ${esc(b.phone ?? '')}</div></td>
                <td>${esc(b.agent.name)}</td>
                <td>${esc(b.city ?? '—')}</td>
                <td class="text-primary">${fmt(b.emoney)} ر.ي</td>
                <td>${cash}</td>
                <td>${Number(b.drawers_cash) > 0 || b.open_shifts > 0
                        ? `${fmt(b.drawers_cash)} ر.ي<div class="small text-muted">${b.open_shifts} شبّاك مفتوح</div>`
                        : '<span class="text-muted small">لا شبّاك مفتوح</span>'}</td>
                <td>${limits}</td>
                <td>${counted}</td>
                <td>${b.is_active ? '<span class="badge bg-success">نشِط</span>'
                                  : '<span class="badge bg-danger">مُعطَّل</span>'}</td>
                <td><button class="btn btn-sm btn-outline-primary" data-branch-cash="${b.id}">حركة النقد</button></td>
            </tr>`;
        }).join('');
    }

    // ===== حركة النقد =====
    let branchFilter = '';
    async function loadCash() {
        const tbody = document.getElementById('cash-tbody');
        tbody.innerHTML = '<tr><td colspan="10" class="text-center text-muted py-4">جارٍ التحميل…</td></tr>';
        const qs = new URLSearchParams();
        if (branchFilter) qs.set('branch_id', branchFilter);
        const d = document.getElementById('cash-date').value;
        if (d) qs.set('date', d);
        const r = document.getElementById('cash-reason').value;
        if (r) qs.set('reason', r);

        const j = await get(`${base}/agents/movements.json?${qs.toString()}`);
        document.getElementById('cash-count').textContent =
            `${j.data.length} حركة${branchFilter ? ' — فرعٌ واحد' : ''}`;

        if (!j.data.length) {
            tbody.innerHTML = '<tr><td colspan="10" class="text-center text-muted py-4">لا حركات مطابقة</td></tr>';
            return;
        }

        tbody.innerHTML = j.data.map(m => `
            <tr>
                <td class="small text-nowrap">${esc(m.created_at)}</td>
                <td>${esc(m.branch)} <span class="small text-muted font-monospace">${esc(m.branch_code ?? '')}</span></td>
                <td>${esc(m.reason_label)}</td>
                <td>${m.direction === 'in' ? '<span class="badge bg-success">داخل</span>'
                                           : '<span class="badge bg-danger">خارج</span>'}</td>
                <td class="fw-bold">${fmt(m.amount)}</td>
                <td class="small text-muted">${fmt(m.balance_before)}</td>
                <td class="small">${fmt(m.balance_after)}</td>
                <td class="small">${esc(m.actor ?? '—')}</td>
                <td class="small font-monospace">${esc(m.reference ?? '')}</td>
            </tr>`).join('');
    }

    // ===== ربط =====
    document.getElementById('branches-tbody').addEventListener('click', (e) => {
        const btn = e.target.closest('[data-branch-cash]');
        if (!btn) return;
        branchFilter = btn.dataset.branchCash;
        bootstrap.Tab.getOrCreateInstance(document.getElementById('cash-tab-link')).show();
        loadCash();
    });

    // زرّ الشحن يُرجع المدير إلى القائمة حيث زرّ «تحويل رصيد» في كلّ صفّ.
    const creditBtn = document.getElementById('agent-credit-open');
    if (creditBtn) {
        creditBtn.addEventListener('click', () => {
            document.querySelector('a[href="#tab-list"]')?.click();
            const s = document.getElementById('search-box');
            if (s) { s.focus(); s.scrollIntoView({behavior: 'smooth', block: 'center'}); }
        });
    }

    document.getElementById('agent-network-flags').addEventListener('click', (e) => {
        const btn = e.target.closest('[data-flag]');
        if (!btn) return;
        document.getElementById('branch-flag').value = btn.dataset.flag;
        bootstrap.Tab.getOrCreateInstance(document.getElementById('branches-tab-link')).show();
        loadBranches();
    });

    let bt;
    document.getElementById('branch-search').addEventListener('input', () => {
        clearTimeout(bt); bt = setTimeout(loadBranches, 350);
    });
    document.getElementById('branch-flag').addEventListener('change', loadBranches);
    document.getElementById('cash-date').addEventListener('change', loadCash);
    document.getElementById('cash-reason').addEventListener('change', loadCash);
    document.getElementById('cash-clear').addEventListener('click', () => {
        branchFilter = '';
        document.getElementById('cash-date').value = '';
        document.getElementById('cash-reason').value = '';
        loadCash();
    });

    // التبويبات تُحمَّل عند فتحها — لا عند فتح الصفحة.
    document.getElementById('branches-tab-link').addEventListener('shown.bs.tab', loadBranches);
    document.getElementById('cash-tab-link').addEventListener('shown.bs.tab', loadCash);


    // ═══ التوازن والتسوية ═══
    const stlBadge = (s) => ({
        balanced: '<span class="badge bg-success">متوازن</span>',
        short: '<span class="badge bg-danger">عجز</span>',
        over: '<span class="badge bg-warning text-dark">فائض</span>',
        no_data: '<span class="badge bg-secondary">لا حركة بعد</span>',
    }[s] || esc(s));

    const diffCell = (actual, diff) => {
        const zero = Math.abs(Number(diff)) < 0.0001;
        return `${fmt(actual)}<div class="small ${zero ? 'text-muted' : 'text-danger fw-bold'}">`
             + `${zero ? 'مطابق' : (Number(diff) > 0 ? '+' : '') + fmt(diff)}</div>`;
    };

    async function loadSettlement() {
        const tbody = document.getElementById('stl-tbody');
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">جارٍ التحميل…</td></tr>';
        const j = await get(`${base}/agents/settlement.json`);

        document.getElementById('stl-count').textContent = `${j.data.length} وكيل`;

        tbody.innerHTML = j.data.length ? j.data.map(r => `
            <tr>
                <td><strong>${esc(r.agent.name)}</strong>
                    <div class="small text-muted">${r.agent.branches} فرع</div></td>
                <td>${stlBadge(r.status)}</td>
                <td>${diffCell(r.float_actual, r.float_difference)}</td>
                <td>${diffCell(r.cash_actual, r.cash_difference)}</td>
                <td class="text-primary">${fmt(r.company_liability)}</td>
                <td>${Number(r.pending_payout) > 0
                        ? `<span class="badge bg-info text-dark">${fmt(r.pending_payout)}</span>` : '—'}</td>
                <td><button class="btn btn-sm btn-outline-primary" data-stl="${r.agent.id}">التفاصيل</button></td>
            </tr>`).join('')
            : '<tr><td colspan="7" class="text-center text-muted py-4">لا وكلاء</td></tr>';
    }

    document.getElementById('stl-tbody').addEventListener('click', async (e) => {
        const b = e.target.closest('[data-stl]');
        if (!b) return;
        const box = document.getElementById('stl-detail');
        box.innerHTML = '<div class="text-muted small">جارٍ التحميل…</div>';

        const j = await get(`${base}/agents/${b.dataset.stl}/settlement.json`);
        const p = j.position, d = j.daily;
        const fb = p.float.breakdown, cb = p.cash.breakdown;

        box.innerHTML = `
            <div class="card stat-card p-3">
                <h6 class="mb-3">${esc(p.agent.name)} — ${stlBadge(p.status)}</h6>
                <div class="row g-3">
                    <div class="col-md-6"><div class="border rounded p-3 h-100">
                        <div class="fw-bold text-primary mb-2">الرصيد الإلكترونيّ</div>
                        <table class="table table-sm mb-0">
                            <tr><td>اشترى (شحن معتمَد)</td><td class="text-end">${fmt(fb.bought)}</td></tr>
                            <tr><td>أعاد (صرف معتمَد)</td><td class="text-end">−${fmt(fb.returned)}</td></tr>
                            <tr><td>دخل من سحوبات العملاء</td><td class="text-end">+${fmt(fb.from_withdrawals)}</td></tr>
                            <tr><td>خرج في إيداعاتهم</td><td class="text-end">−${fmt(fb.to_deposits)}</td></tr>
                            <tr class="table-light"><td><strong>المتوقَّع</strong></td>
                                <td class="text-end"><strong>${fmt(p.float.expected)}</strong></td></tr>
                            <tr><td>الفعليّ</td><td class="text-end">${fmt(p.float.actual)}</td></tr>
                            <tr><td><strong>الفرق</strong></td>
                                <td class="text-end ${Math.abs(Number(p.float.difference))<0.0001?'':'text-danger fw-bold'}">
                                ${fmt(p.float.difference)}</td></tr>
                            <tr><td class="text-muted">محجوز</td><td class="text-end text-muted">${fmt(p.float.held)}</td></tr>
                        </table>
                    </div></div>
                    <div class="col-md-6"><div class="border rounded p-3 h-100">
                        <div class="fw-bold text-success mb-2">النقد الورقيّ</div>
                        <table class="table table-sm mb-0">
                            <tr><td>نقد إيداعات العملاء</td><td class="text-end">+${fmt(cb.deposits_in ?? 0)}</td></tr>
                            <tr><td>نقد سحوباتهم</td><td class="text-end">−${fmt(cb.withdrawals_out ?? 0)}</td></tr>
                            <tr><td>توريد إلى الفروع</td><td class="text-end">+${fmt(cb.treasury_in ?? 0)}</td></tr>
                            <tr><td>توريد منها</td><td class="text-end">−${fmt(cb.treasury_out ?? 0)}</td></tr>
                            <tr><td>تسويات جرد</td><td class="text-end">${fmt(cb.adjustments ?? 0)}</td></tr>
                            <tr class="table-light"><td><strong>المتوقَّع</strong></td>
                                <td class="text-end"><strong>${fmt(p.cash.expected)}</strong></td></tr>
                            <tr><td>الفعليّ (خزائن ${fmt(p.cash.in_safes)} + أدراج ${fmt(p.cash.in_drawers)})</td>
                                <td class="text-end">${fmt(p.cash.actual)}</td></tr>
                            <tr><td><strong>الفرق</strong></td>
                                <td class="text-end ${Math.abs(Number(p.cash.difference))<0.0001?'':'text-danger fw-bold'}">
                                ${fmt(p.cash.difference)}</td></tr>
                        </table>
                    </div></div>
                </div>

                <div class="row g-3 mt-1">
                    <div class="col-md-6"><div class="alert alert-primary mb-0 py-2 small">
                        <strong>التزام المنصّة:</strong> ${fmt(p.net.company_liability)} ر.ي
                        — مالٌ نَدين به للوكيل ويستطيع صرفه.
                        ${Number(p.net.agent_liability) > 0
                            ? `<div class="text-danger fw-bold mt-1">⚠️ رصيد سالب: ${fmt(p.net.agent_liability)}</div>` : ''}
                    </div></div>
                    <div class="col-md-6"><div class="alert alert-light border mb-0 py-2 small">
                        <strong>تسوية اليوم (${esc(d.date)}):</strong>
                        ${d.shifts_closed}/${d.shifts_total} ورديّة مُغلقة ·
                        <span class="text-danger">عجز ${d.shortage_count} (${fmt(d.shortage_total)})</span> ·
                        <span class="text-warning">فائض ${d.overage_count} (${fmt(d.overage_total)})</span>
                        ${d.unclosed_drawers ? `<div class="mt-1">⚠️ ${d.unclosed_drawers} درج لم يُغلق</div>` : ''}
                        <div class="text-muted mt-1">العجز والفائض لا يُقاصّان — كلٌّ حادثةٌ تستحقّ سؤالاً.</div>
                    </div></div>
                </div>
            </div>`;
    });

    document.getElementById('stl-refresh').addEventListener('click', loadSettlement);
    document.getElementById('settlement-tab-link').addEventListener('shown.bs.tab', loadSettlement);

    // ══════════════════════════════════════════════════════════════════
    // إقفال اليوم
    // ══════════════════════════════════════════════════════════════════
    const DLY_BADGE = {
        not_submitted: 'bg-danger',
        submitted: 'bg-warning text-dark',
        accepted: 'bg-success',
        rejected: 'bg-secondary',
    };

    async function loadDaily() {
        const d = $('dly-date').value || new Date().toISOString().slice(0, 10);
        const tb = $('dly-tbody');
        tb.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-3">جارٍ التحميل…</td></tr>';

        const j = await (await fetch(`${base}/agents/daily.json?date=${d}`,
            {headers: {'Accept': 'application/json'}})).json();

        const t = j.totals || {};
        $('dly-window').textContent = (j.window && j.window.message) || '';

        const card = (label, value, cls) => `
            <div class="col-md-2 col-4"><div class="card stat-card p-3">
                <small class="text-muted">${label}</small>
                <div class="fs-4 fw-bold ${cls || ''}">${value}</div></div></div>`;

        // «لم يرفع» أوّل بطاقة: هي السؤال الذي يُفتح لأجله هذا التبويب.
        $('dly-totals').innerHTML =
            card('لم يرفعوا', t.not_submitted ?? 0, (t.not_submitted ? 'text-danger' : '')) +
            card('بانتظار قرارك', t.awaiting ?? 0, (t.awaiting ? 'text-warning' : '')) +
            card('قُبلت', t.accepted ?? 0, 'text-success') +
            card('رُفعت متأخّرة', t.late ?? 0, (t.late ? 'text-danger' : '')) +
            card('إشاراتٌ تستحقّ نظرة', t.suspicious ?? 0, (t.suspicious ? 'text-danger' : '')) +
            card('وكلاء', t.agents ?? 0);

        const rows = j.rows || [];
        tb.innerHTML = rows.length ? rows.map(r => `
            <tr>
                <td><strong>${esc(r.agent)}</strong><div class="small text-muted" dir="ltr">${esc(r.phone ?? '')}</div></td>
                <td><span class="badge ${DLY_BADGE[r.status] || 'bg-secondary'}">${esc(r.status_label)}</span>
                    ${r.suspicious_count ? `<span class="badge bg-danger">${r.suspicious_count} إشارة</span>` : ''}</td>
                <td class="small">${r.window_label
                        ? `<span class="badge ${r.window_state === 'on_time' ? 'bg-success' : 'bg-danger'}">${esc(r.window_label)}</span>`
                        : '—'}<div class="text-muted">${esc(r.submitted_at ?? '')}</div></td>
                <td class="text-success">${fmt(r.deposits_total)}</td>
                <td class="text-primary">${fmt(r.withdrawals_total)}</td>
                <td class="${Number(r.shortage_total) > 0 ? 'text-danger fw-bold' : 'text-muted'}">${fmt(r.shortage_total)}</td>
                <td class="${Number(r.overage_total) > 0 ? 'text-warning fw-bold' : 'text-muted'}">${fmt(r.overage_total)}</td>
                <td>${r.conversion && r.conversion !== 'none'
                        ? `<span class="badge ${r.conversion === 'topup' ? 'bg-success' : 'bg-warning text-dark'}">${esc(r.conversion_label)}</span>
                           <div class="fw-bold">${fmt(r.conversion_amount)}</div>` : '<span class="text-muted">—</span>'}</td>
                <td class="text-nowrap">
                    ${r.status === 'submitted'
                        ? `<button class="btn btn-sm btn-success" data-dly-ok="${r.ulid}">قبول وتنفيذ</button>
                           <button class="btn btn-sm btn-outline-danger" data-dly-no="${r.ulid}">رفض</button>` : ''}
                    ${r.status === 'not_submitted'
                        ? `<button class="btn btn-sm btn-outline-warning" data-dly-unlock="${r.agent_user_id}">فكّ اليوم</button>` : ''}
                </td>
            </tr>`).join('')
            : '<tr><td colspan="9" class="text-center text-muted py-4">لا وكلاء</td></tr>';
    }

    $('dly-refresh').addEventListener('click', loadDaily);

    $('dly-tbody').addEventListener('click', async (e) => {
        const b = e.target.closest('button[data-dly-ok], button[data-dly-no], button[data-dly-unlock]');
        if (!b) return;
        const d = $('dly-date').value || new Date().toISOString().slice(0, 10);

        try {
            if (b.hasAttribute('data-dly-ok')) {
                if (!confirm('سيُنفَّذ التحويل بين النقد والرصيد فوراً. متابعة؟')) return;
                const j = await post(`${base}/agents/daily/${b.dataset.dlyOk}/accept`, {});
                alert(j.message);
            } else if (b.hasAttribute('data-dly-no')) {
                const note = prompt('سبب الرفض (عشرة أحرف فأكثر — يصل إلى الوكيل):');
                if (!note || note.trim().length < 10) { alert('السبب إلزاميّ'); return; }
                const j = await post(`${base}/agents/daily/${b.dataset.dlyNo}/reject`, {note});
                alert(j.message);
            } else {
                // تدخّلُ إدارة المشروع: يُفتح الباب بقرارٍ باسمٍ وسبب، ولا
                // يُمحى أنّ اليوم تأخّر.
                const reason = prompt('سبب فكّ اليوم المتأخّر (عشرة أحرف فأكثر — يُسجَّل باسمك):');
                if (!reason || reason.trim().length < 10) { alert('السبب إلزاميّ'); return; }
                const j = await post(`${base}/agents/daily/unlock`,
                    {agent_user_id: Number(b.dataset.dlyUnlock), date: d, reason});
                alert(j.message);
            }
            loadDaily();
        } catch (err) { alert(err.message); }
    });

    document.getElementById('daily-tab-link').addEventListener('shown.bs.tab', loadDaily);

    loadNetwork();
})();
</script>
@endpush
