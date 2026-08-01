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
                    <th>رصيد إلكترونيّ</th><th>نقد في الدرج</th>
                    <th>حدود الخزنة</th><th>آخر جرد</th><th>الحالة</th><th></th>
                </tr></thead>
                <tbody id="branches-tbody">
                    <tr><td colspan="9" class="text-center text-muted py-4">جارٍ التحميل…</td></tr>
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
                    <tr><td colspan="9" class="text-center text-muted py-4">جارٍ التحميل…</td></tr>
                </tbody>
            </table>
        </div>
        <div class="card-footer text-muted small">
            سجلٌّ يُلحَق ولا يُعدَّل — لا يوجد في هذه الشاشة زرُّ تعديلٍ لحركةِ نقدٍ وقعت.
        </div>
    </div>
</div>

@push('script')
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(function () {
    const base = '{{ url('admin/amial/hub') }}';
    const fmt = (n) => Number(n || 0).toLocaleString('en-US', {maximumFractionDigits: 2});
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c =>
        ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const get = async (u) => (await fetch(u, {headers: {'Accept': 'application/json'}})).json();

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
    }

    // ===== الفروع =====
    async function loadBranches() {
        const tbody = document.getElementById('branches-tbody');
        tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-4">جارٍ التحميل…</td></tr>';
        const flag = document.getElementById('branch-flag').value;
        const search = document.getElementById('branch-search').value.trim();
        const j = await get(`${base}/agents/branches.json?flag=${flag}&search=${encodeURIComponent(search)}`);

        document.getElementById('branch-count').textContent = `${j.data.length} فرع`;

        if (!j.data.length) {
            tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-4">'
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
        tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-4">جارٍ التحميل…</td></tr>';
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
            tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-4">لا حركات مطابقة</td></tr>';
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

    loadNetwork();
})();
</script>
@endpush
