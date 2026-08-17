@extends('layouts.admin.app')

{{--
    AMIAL-LEDGER-CENTER-001 — مركز الدفتر (الفصل ١٧).

    أُصلح الدفتر في هذا المشروع بالكامل — القيد الافتتاحيّ، والترحيل
    الإلزاميّ، وتغطية كلّ مسارات المال، وحرّاسٌ تمنع تراجعه — **ولا شاشة
    تقرؤه**. الموجود بثٌّ حيّ لآخر ثلاثين قيداً، وهو عرضٌ لا دفتر.

    وما يميّز هذه الشاشة عن ذلك البثّ أنّ كلّ رقمٍ فيها **يُحسب من سطور
    القيود** لا من الأرصدة المخزَّنة. والفرق ليس أداءً: الأرصدة المخزَّنة هي
    ما نتحقّق منه، وميزانُ مراجعةٍ يُبنى منها يُثبت أنّ العمود يساوي نفسه.

    ولذلك يظهر هنا عمودٌ لا يوجد في أيّ لوحة أخرى: **«الانحراف»** — الفرق
    بين ما تقوله الحركة وما يقوله العمود المخزَّن.
--}}

@section('title', 'مركز الدفتر')

@section('content')
<div class="content container-fluid" id="ledger-center" data-testid="ledger-center">

    <div class="d-flex align-items-center gap-3 mb-3">
        <i class="tio-book text-primary" style="font-size:24px"></i>
        <h2 class="page-header-title mb-0">مركز الدفتر</h2>
        <span class="badge badge-soft-secondary ms-auto">القيد المزدوج</span>
    </div>

    <div id="ledger-banner"></div>

    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#lg-trial" data-testid="lg-tab-trial">⚖️ ميزان المراجعة</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#lg-recon" data-testid="lg-tab-recon">🔗 مطابقة المحافظ <span class="badge bg-danger" id="lg-recon-count">0</span></button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#lg-accounts" data-testid="lg-tab-accounts">📒 دليل الحسابات</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#lg-entries" data-testid="lg-tab-entries">🔍 بحث القيود</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#lg-runs" data-testid="lg-tab-runs">🌙 المصالحات الليليّة <span class="badge bg-secondary" id="lg-runs-count">—</span></button></li>
    </ul>

    <div class="tab-content">

        {{-- ============ ميزان المراجعة ============ --}}
        <div class="tab-pane fade show active" id="lg-trial">
            <div class="card p-3">
                <div class="alert alert-secondary py-2 small">
                    كلّ رقمٍ هنا <strong>محسوبٌ من سطور القيود</strong> لا مقروءٌ من الأرصدة المخزَّنة —
                    وإلّا لأثبت الميزان أنّ العمود يساوي نفسه.
                    وعمود <strong>«الانحراف»</strong> هو الفرق بين ما تقوله الحركة وما يقوله العمود.
                </div>
                <div class="d-flex gap-2 mb-3 flex-wrap align-items-end">
                    <div><label class="form-label small mb-1">من</label>
                        <input type="date" id="lg-from" class="form-control form-control-sm"></div>
                    <div><label class="form-label small mb-1">إلى</label>
                        <input type="date" id="lg-to" class="form-control form-control-sm"></div>
                    <button class="btn btn-primary btn-sm" id="lg-btn-trial" data-testid="lg-btn-trial">احسب</button>
                </div>
                <div id="lg-trial-summary" class="row g-3 mb-3"></div>
                <div id="lg-trial-list"></div>
            </div>
        </div>


        {{-- ============ AMIAL-RECON-NIGHTLY-001 — المصالحات الليليّة ============

             القاعدة ١٢: بُني `reconciliation_runs` ولم يكن يُقرأ من أيّ
             مكان. ومصالحةٌ لا يقرؤها أحدٌ هي «مبنيٌّ ولا يُوصَل إليه».

             والسلسلةُ هي المقصود لا الليلةُ الأخيرة: رقمٌ واحدٌ لا يقول إن
             كان الانحراف بدأ اليوم أم يكبر منذ أسبوع. --}}
        <div class="tab-pane fade" id="lg-runs">
            <div class="card p-3">
                <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                    <div>
                        <strong>🌙 المصالحة الليليّة</strong>
                        <div class="small text-muted">تعمل تلقائياً ٢:٠٠ صباحاً — المحافظ والدفتر وخزائن النقد</div>
                    </div>
                    <button class="btn btn-sm btn-outline-secondary" id="lg-runs-refresh" data-testid="lg-runs-refresh">⟳ تحديث</button>
                </div>

                {{-- **صمتُ الإنذار لا يعني السلامة.** ليلةٌ بلا صفٍّ تعني أنّ
                     المهمّة لم تعمل — لا أنّ الحساب سليم. (القاعدة السابعة.) --}}
                <div id="lg-runs-stale"></div>

                <div id="lg-runs-blind" class="alert alert-warning py-2 small d-none">
                    <strong>🔍 خارج التغطية:</strong>
                    <span id="lg-runs-blind-list"></span>
                    <div class="mt-1 text-muted">دَينٌ معلوم — ما ينقص فيها ليس ضياعَ مال.</div>
                </div>

                <div id="lg-runs-list" data-testid="lg-runs-list">
                    <div class="text-muted py-4 text-center">…جارٍ التحميل</div>
                </div>
            </div>
        </div>

        {{-- ============ مطابقة المحافظ ============ --}}
        <div class="tab-pane fade" id="lg-recon">
            <div class="card p-3">
                <div class="alert alert-secondary py-2 small">
                    الدفتر قد يكون <strong>متوازناً تماماً ويختلف مع ذلك عن المحافظ</strong>:
                    التوازن الداخليّ لا يعني المطابقة الخارجية.
                    ومصدر الحقيقة للرصيد هو المحفظة — فالانحراف يعني <strong>قيداً ناقصاً في الدفتر</strong>
                    لا خطأً في رصيد العميل.
                </div>
                <div class="d-flex mb-2"><button class="btn btn-outline-primary btn-sm ms-auto" id="lg-btn-recon">تحديث</button></div>
                <div id="lg-recon-summary" class="row g-3 mb-3"></div>
                <div id="lg-recon-list"></div>
            </div>
        </div>

        {{-- ============ دليل الحسابات ============ --}}
        <div class="tab-pane fade" id="lg-accounts">
            <div class="row g-3">
                <div class="col-lg-5">
                    <div class="card">
                        <div class="card-header d-flex gap-2 align-items-center">
                            <h5 class="card-header-title mb-0">الحسابات</h5>
                            <select id="lg-acc-type" class="form-select form-select-sm ms-auto" style="max-width:150px">
                                <option value="">الكل</option>
                                <option value="asset">أصول</option>
                                <option value="liability">التزامات</option>
                                <option value="equity">حقوق ملكية</option>
                                <option value="revenue">إيرادات</option>
                                <option value="expense">مصروفات</option>
                            </select>
                        </div>
                        <div id="lg-accounts-list" class="list-group list-group-flush"
                             style="max-height:600px;overflow:auto" data-testid="lg-accounts-list"></div>
                    </div>
                </div>
                <div class="col-lg-7">
                    <div class="card"><div class="card-body" id="lg-statement" data-testid="lg-statement">
                        <div class="text-center text-muted py-5">
                            <i class="tio-book-outlined" style="font-size:48px;opacity:.3"></i>
                            <div class="mt-2">اختر حساباً لعرض كشفه</div>
                        </div>
                    </div></div>
                </div>
            </div>
        </div>

        {{-- ============ بحث القيود ============ --}}
        <div class="tab-pane fade" id="lg-entries">
            <div class="card p-3">
                <div class="d-flex gap-2 mb-3 flex-wrap align-items-end">
                    <div><label class="form-label small mb-1">المرجع</label>
                        <input type="text" id="lg-e-ulid" class="form-control form-control-sm" placeholder="ULID"></div>
                    <div><label class="form-label small mb-1">المصدر</label>
                        <select id="lg-e-source" class="form-select form-select-sm"><option value="">الكل</option></select></div>
                    <div><label class="form-label small mb-1">من</label>
                        <input type="date" id="lg-e-from" class="form-control form-control-sm"></div>
                    <div><label class="form-label small mb-1">إلى</label>
                        <input type="date" id="lg-e-to" class="form-control form-control-sm"></div>
                    <div><label class="form-label small mb-1">أقلّ مبلغ</label>
                        <input type="number" id="lg-e-min" class="form-control form-control-sm" style="max-width:120px"></div>
                    <button class="btn btn-primary btn-sm" id="lg-btn-entries">بحث</button>
                </div>
                <div id="lg-entries-list"></div>
            </div>
        </div>
    </div>
</div>

<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(function () {
    const BASE = '{{ url('admin/amial/ledger') }}';
    const esc = s => String(s ?? '—').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const num = n => Number(n || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});

    async function get(path) {
        const r = await fetch(BASE + path, {headers: {'Accept': 'application/json'}});
        return r.json();
    }

    const tile = (label, value, sub, cls) => `
        <div class="col-lg-3 col-md-4 col-6"><div class="card p-3 h-100 ${cls || ''}">
            <div class="small text-muted">${label}</div><div class="fs-5 fw-bold">${value}</div>
            ${sub ? `<div class="small text-muted">${sub}</div>` : ''}</div></div>`;


    // ---------- AMIAL-RECON-NIGHTLY-001 — المصالحات الليليّة ----------
    //
    // القاعدة ١٢: الجدول بُني ولم يكن يُقرأ. والسلسلةُ هي المقصود لا
    // الليلةُ الأخيرة — رقمٌ واحدٌ لا يقول إن كان الانحراف بدأ اليوم.
    document.getElementById('lg-runs-refresh').onclick = loadRuns;

    async function loadRuns() {
        const box = document.getElementById('lg-runs-list');
        box.innerHTML = '<div class="text-muted py-3 text-center">…جارٍ التحميل</div>';

        const j = await get('/reconciliation-runs');
        if (!j.success) {
            box.innerHTML = '<div class="alert alert-warning">تعذّر جلب سجلّ المصالحات</div>';
            return;
        }

        const m = j.meta, rows = m.rows || [];
        document.getElementById('lg-runs-count').textContent = rows.length;

        // **الغيابُ يُقال.** ليلةٌ بلا صفٍّ تعني أنّ المهمّة لم تعمل —
        // لا أنّ الحساب سليم. (القاعدة السابعة.)
        document.getElementById('lg-runs-stale').innerHTML = m.stale
            ? `<div class="alert alert-danger py-2 small" data-testid="lg-runs-stale-warn">
                 ⚠️ <strong>لم تجرِ مصالحةٌ منذ أكثر من ٣٠ ساعة.</strong>
                 ${m.last_run_at ? 'آخرها: ' + esc(m.last_run_at) : 'ولا مصالحةَ مسجّلةٌ إطلاقاً.'}
                 وصمتُ الإنذار هنا لا يعني السلامة — قد يعني أنّ المهمّة توقّفت.
               </div>`
            : `<div class="alert alert-success py-2 small">✅ آخرُ مصالحة: ${esc(m.last_run_at)}</div>`;

        const blind = m.blind_spots || [];
        const blindBox = document.getElementById('lg-runs-blind');
        if (blind.length) {
            blindBox.classList.remove('d-none');
            document.getElementById('lg-runs-blind-list').innerHTML =
                blind.map(b => esc(b.service)).join(' · ');
        } else {
            blindBox.classList.add('d-none');
        }

        if (!rows.length) {
            box.innerHTML = '<div class="text-muted py-4 text-center">لا مصالحاتٍ مسجّلة بعد.</div>';
            return;
        }

        const badge = st => st === 'clean'
            ? '<span class="badge bg-success">لا فرق</span>'
            : (st === 'failed'
                ? '<span class="badge bg-dark">لم تكتمل</span>'
                : '<span class="badge bg-danger">وُجد فرق</span>');

        box.innerHTML = `
            <div class="table-responsive"><table class="table table-sm align-middle">
            <thead><tr>
                <th>الليلة</th><th>الحالة</th>
                <th class="text-end">محافظ</th><th class="text-end">فرقُ المحافظ</th>
                <th class="text-end">صافي الدفتر</th>
                <th class="text-end">خزائن</th><th class="text-end">فرقُ الخزائن</th>
                <th class="text-end">المدّة</th>
            </tr></thead><tbody>` +
            rows.map(r => `
                <tr class="${r.status === 'clean' ? '' : 'table-danger'}">
                    <td>${esc(r.ran_at)}</td>
                    <td>${badge(r.status)}</td>
                    <td class="text-end">${r.wallets_checked}<span class="text-muted small"> / ${r.wallets_diverged}</span></td>
                    <td class="text-end ${Number(r.wallets_gap) ? 'fw-bold text-danger' : 'text-muted'}">${num(r.wallets_gap)}</td>
                    <td class="text-end ${Number(r.ledger_net) ? 'fw-bold text-danger' : 'text-muted'}">${num(r.ledger_net)}</td>
                    <td class="text-end">${r.tills_checked}<span class="text-muted small"> / ${r.tills_diverged}</span></td>
                    <td class="text-end ${Number(r.tills_gap) ? 'fw-bold text-danger' : 'text-muted'}">${num(r.tills_gap)}</td>
                    <td class="text-end text-muted">${r.duration_ms}ms</td>
                </tr>`).join('') +
            '</tbody></table></div>';
    }

    // يُحمَّل عند فتح التبويب — لا عند فتح الصفحة، فالسجلّ قد يطول.
    document.querySelector('[data-testid="lg-tab-runs"]')
        .addEventListener('shown.bs.tab', loadRuns, {once: false});

    // ---------- ميزان المراجعة ----------
    document.getElementById('lg-btn-trial').onclick = loadTrial;

    async function loadTrial() {
        const from = document.getElementById('lg-from').value;
        const to = document.getElementById('lg-to').value;
        const box = document.getElementById('lg-trial-list');
        box.innerHTML = '<div class="text-muted">جارٍ الحساب…</div>';

        const qs = [];
        if (from) qs.push('from=' + from);
        if (to) qs.push('to=' + to);
        const j = await get('/trial-balance' + (qs.length ? '?' + qs.join('&') : ''));
        if (!j.success) { box.innerHTML = '<div class="alert alert-warning">تعذّر الحساب</div>'; return; }
        const m = j.meta;

        // المعادلة الأساسية في القيد المزدوج. اختلالها ليس تحذيراً بل إنذار:
        // مالٌ ظهر أو اختفى من العدم.
        let banner = '';
        if (!m.balanced) {
            banner += `<div class="alert alert-danger" data-testid="lg-unbalanced">
                <strong>⚠️ الدفتر غير متوازن.</strong>
                مجموع المدين ${num(m.total_debit)} ومجموع الدائن ${num(m.total_credit)} —
                الفرق <strong>${num(m.difference)}</strong>.
                هذا يعني قيداً غير متوازن: مالٌ ظهر أو اختفى من العدم.
                ${m.unbalanced_entries.length ? `<div class="mt-2 small">القيود المختلّة:
                    ${m.unbalanced_entries.slice(0, 5).map(e => `<span class="badge bg-dark">${esc(e.ulid)} (${num(e.difference)})</span>`).join(' ')}</div>` : ''}
            </div>`;
        }
        if (m.drifted_accounts > 0) {
            banner += `<div class="alert alert-warning">
                <strong>${m.drifted_accounts} حساباً</strong> رصيدُه المخزَّن يختلف عن مجموع حركته.
                الدفتر قد يكون متوازناً ومع ذلك يكون العمود المخزَّن منحرفاً.
            </div>`;
        }
        document.getElementById('ledger-banner').innerHTML = banner;

        document.getElementById('lg-trial-summary').innerHTML =
            tile('مجموع المدين', num(m.total_debit)) +
            tile('مجموع الدائن', num(m.total_credit)) +
            tile('الفرق', num(m.difference), m.balanced ? 'متوازن ✓' : 'غير متوازن',
                 m.balanced ? 'border-success' : 'border-danger') +
            tile('حسابات منحرفة', m.drifted_accounts, 'المخزَّن ≠ المحسوب',
                 m.drifted_accounts > 0 ? 'border-warning' : 'border-success');

        const rows = (m.accounts || []).map(a => `
            <tr class="${a.has_drift ? 'table-warning' : ''}">
                <td class="font-monospace small">${esc(a.account_code)}</td>
                <td>${esc(a.name)}<div class="small text-muted">${esc(a.account_type)}</div></td>
                <td class="text-end">${num(a.debit_total)}</td>
                <td class="text-end">${num(a.credit_total)}</td>
                <td class="text-end fw-bold">${num(a.computed_balance)}</td>
                <td class="text-end text-muted">${num(a.stored_balance)}</td>
                <td class="text-end ${a.has_drift ? 'text-danger fw-bold' : 'text-muted'}">${num(a.drift)}</td>
            </tr>`).join('');

        box.innerHTML = `<div class="table-responsive"><table class="table table-sm" data-testid="lg-trial-table">
            <thead class="thead-light"><tr>
                <th>الرمز</th><th>الحساب</th>
                <th class="text-end">مدين</th><th class="text-end">دائن</th>
                <th class="text-end">الرصيد (محسوب)</th><th class="text-end">المخزَّن</th><th class="text-end">الانحراف</th>
            </tr></thead>
            <tbody>${rows || '<tr><td colspan="7" class="text-muted text-center py-3">لا حركة في هذه الفترة</td></tr>'}</tbody>
            <tfoot class="table-light fw-bold"><tr>
                <td colspan="2">الإجمالي</td>
                <td class="text-end">${num(m.total_debit)}</td>
                <td class="text-end">${num(m.total_credit)}</td>
                <td colspan="3" class="text-end ${m.balanced ? 'text-success' : 'text-danger'}">
                    ${m.balanced ? 'متوازن ✓' : 'الفرق ' + num(m.difference)}</td>
            </tr></tfoot>
        </table></div>`;
    }

    // ---------- مطابقة المحافظ ----------
    document.getElementById('lg-btn-recon').onclick = loadRecon;

    async function loadRecon() {
        const box = document.getElementById('lg-recon-list');
        box.innerHTML = '<div class="text-muted">جارٍ المطابقة…</div>';
        const j = await get('/reconciliation');
        if (!j.success) { box.innerHTML = '<div class="alert alert-warning">تعذّرت المطابقة</div>'; return; }
        const m = j.meta;

        document.getElementById('lg-recon-count').textContent = m.divergent;
        document.getElementById('lg-recon-summary').innerHTML =
            tile('محافظ فُحصت', m.checked) +
            tile('منحرفة', m.divergent, '', m.divergent > 0 ? 'border-danger' : 'border-success') +
            tile('مجموع الفروق', num(m.total_gap), 'محفظة − دفتر',
                 m.divergent > 0 ? 'border-danger' : '');

        const rows = (m.rows || []).map(r => `
            <tr class="${r.diverged ? 'table-danger' : ''}">
                <td>${esc(r.name)}<div class="small text-muted">#${r.user_id} • ${esc(r.phone)}</div></td>
                <td class="text-end">${num(r.wallet_balance)}</td>
                <td class="text-end">${num(r.ledger_balance)}</td>
                <td class="text-end ${r.diverged ? 'fw-bold' : 'text-muted'}">${num(r.gap)}</td>
                <td>${r.diverged
                    ? '<span class="badge bg-danger">قيد ناقص في الدفتر</span>'
                    : '<span class="badge bg-success">مطابق</span>'}</td>
            </tr>`).join('');

        box.innerHTML = `<div class="table-responsive"><table class="table table-sm" data-testid="lg-recon-table">
            <thead class="thead-light"><tr><th>العميل</th><th class="text-end">المحفظة</th>
                <th class="text-end">الدفتر</th><th class="text-end">الفرق</th><th></th></tr></thead>
            <tbody>${rows || '<tr><td colspan="5" class="text-muted text-center py-3">لا محافظ</td></tr>'}</tbody></table></div>`;
    }

    // ---------- دليل الحسابات ----------
    document.getElementById('lg-acc-type').onchange = loadAccounts;

    async function loadAccounts() {
        const t = document.getElementById('lg-acc-type').value;
        const box = document.getElementById('lg-accounts-list');
        box.innerHTML = '<div class="list-group-item text-muted">جارٍ التحميل…</div>';
        const j = await get('/accounts' + (t ? '?type=' + t : ''));
        if (!j.success) return;

        box.innerHTML = (j.meta.items || []).map(a => `
            <button class="list-group-item list-group-item-action js-lg-acc" data-id="${a.id}">
                <div class="d-flex justify-content-between">
                    <div>
                        <div class="font-monospace small">${esc(a.account_code)}</div>
                        <div class="fw-bold">${esc(a.name)}</div>
                        <div class="small text-muted">${esc(a.account_type)} • ${esc(a.normal_balance)}</div>
                    </div>
                    <div class="text-end fw-bold">${num(a.current_balance)}</div>
                </div>
            </button>`).join('') || '<div class="list-group-item text-muted">لا حسابات</div>';
    }

    document.addEventListener('click', async function (e) {
        const b = e.target.closest('.js-lg-acc');
        if (!b) return;
        const j = await get(`/accounts/${b.dataset.id}/statement`);
        if (!j.success || !j.meta.account) return;
        const m = j.meta, a = m.account;

        // الرصيد المتدرّج محسوبٌ من الصفر تصاعديّاً، ولا يُقرأ من
        // `balance_after` المخزَّن — فذاك ما نراجعه. وعمود «المخزَّن» يُعرَض
        // بجانبه ليظهر الاختلاف إن وقع.
        document.getElementById('lg-statement').innerHTML = `
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <div class="font-monospace text-muted">${esc(a.account_code)}</div>
                    <h5 class="mb-0">${esc(a.name)}</h5>
                </div>
                <div class="text-end">
                    <div class="small text-muted">الرصيد المحسوب</div>
                    <div class="fs-5 fw-bold">${num(m.computed_balance)}</div>
                    <div class="small text-muted">المخزَّن ${num(a.stored_balance)}</div>
                </div>
            </div>
            ${m.mismatched_lines > 0 ? `<div class="alert alert-warning py-2 small">
                ${m.mismatched_lines} سطراً رصيدُه المخزَّن يخالف التسلسل المحسوب.</div>` : ''}
            <div class="table-responsive" style="max-height:460px;overflow:auto">
            <table class="table table-sm">
                <thead class="thead-light"><tr><th>التاريخ</th><th>البيان</th>
                    <th class="text-end">مدين</th><th class="text-end">دائن</th><th class="text-end">الرصيد</th></tr></thead>
                <tbody>${m.lines.map(l => `
                    <tr class="${l.mismatch ? 'table-warning' : ''}">
                        <td class="small">${esc((l.posted_at || '').slice(0, 16))}</td>
                        <td class="small">${esc(l.description)}<div class="text-muted font-monospace">${esc(l.source_type)}</div></td>
                        <td class="text-end">${l.direction === 'debit' ? num(l.amount) : ''}</td>
                        <td class="text-end">${l.direction === 'credit' ? num(l.amount) : ''}</td>
                        <td class="text-end fw-bold">${num(l.running_balance)}</td>
                    </tr>`).join('') || '<tr><td colspan="5" class="text-muted text-center py-3">لا حركة</td></tr>'}</tbody>
            </table></div>`;
    });

    // ---------- بحث القيود ----------
    document.getElementById('lg-btn-entries').onclick = loadEntries;

    // AMIAL-FEE-TRUTH-019 — **وصلةُ التنقّل من تقرير الأرباح.**
    //
    // يفتح مركزُ الرسوم هذه الصفحةَ بـ`?idempotency_key=…` ليعرض قيدَ
    // حركةٍ بعينها. وبلا قراءةِ المُعامِل هنا يصل الزائرُ إلى صفحةٍ عامّةٍ
    // **تتجاهل ما طلبه** — رابطٌ يعمل ويذهب إلى غير وجهته.
    const lgPinnedKey = new URLSearchParams(location.search).get('idempotency_key') || '';

    async function loadEntries() {
        const qs = [];
        const v = id => document.getElementById(id).value;
        if (lgPinnedKey) qs.push('idempotency_key=' + encodeURIComponent(lgPinnedKey));
        if (v('lg-e-ulid')) qs.push('ulid=' + encodeURIComponent(v('lg-e-ulid')));
        if (v('lg-e-source')) qs.push('source_type=' + encodeURIComponent(v('lg-e-source')));
        if (v('lg-e-from')) qs.push('from=' + v('lg-e-from'));
        if (v('lg-e-to')) qs.push('to=' + v('lg-e-to'));
        if (v('lg-e-min')) qs.push('min_amount=' + v('lg-e-min'));

        const box = document.getElementById('lg-entries-list');
        box.innerHTML = '<div class="text-muted">جارٍ البحث…</div>';
        const j = await get('/entries' + (qs.length ? '?' + qs.join('&') : ''));
        if (!j.success) return;

        const sel = document.getElementById('lg-e-source');
        if (sel.options.length <= 1) {
            (j.meta.source_types || []).forEach(t => {
                const o = document.createElement('option');
                o.value = t; o.textContent = t; sel.appendChild(o);
            });
        }

        const rows = (j.meta.items || []).map(e => `
            <tr>
                <td class="font-monospace small">${esc(e.ulid)}</td>
                <td class="small">${esc(e.source_type)}<div class="text-muted">${esc(e.description)}</div></td>
                <td class="text-end fw-bold">${num(e.amount)}</td>
                <td class="small">${e.debits.map(d => `${esc(d.account)} <span class="text-muted">${num(d.amount)}</span>`).join('<br>')}</td>
                <td class="small">${e.credits.map(c => `${esc(c.account)} <span class="text-muted">${num(c.amount)}</span>`).join('<br>')}</td>
                <td class="small text-muted">${esc(e.posted_at)}</td>
            </tr>`).join('');

        const pinned = lgPinnedKey
            ? `<div class="alert alert-info py-2 small d-flex align-items-center gap-2">
                   <span>مقصورٌ على قيود الحركة <code>${esc(lgPinnedKey)}</code></span>
                   <a class="ms-auto" href="${location.pathname}">إزالةُ القصر</a>
               </div>` : '';

        box.innerHTML = pinned + `<div class="table-responsive"><table class="table table-sm" data-testid="lg-entries-table">
            <thead class="thead-light"><tr><th>المرجع</th><th>المصدر</th><th class="text-end">المبلغ</th>
                <th>من (مدين)</th><th>إلى (دائن)</th><th>التاريخ</th></tr></thead>
            <tbody>${rows || '<tr><td colspan="6" class="text-muted text-center py-3">لا قيود</td></tr>'}</tbody></table></div>`;
    }

    // أوّل ما يُفتح: الميزان والمطابقة — هما ما يُسأل عنه.
    loadTrial();
    loadRecon();
    loadAccounts();
    loadEntries();

    // وإن جاء الزائرُ من تقرير الأرباح إلى قيدٍ بعينه، يُفتح تبويبُ القيود
    // — لا يُترك على الميزان فيظنّ أنّ الرابطَ لم يفعل شيئاً.
    if (lgPinnedKey) {
        const tab = document.querySelector('[data-bs-target="#lg-entries"]');
        if (tab && window.bootstrap) new bootstrap.Tab(tab).show();
    }
})();
</script>
@endsection
