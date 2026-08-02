{{-- AMIAL-AGENT-STAFF-001 — شبّاك الصرّاف.

     **لا قائمة فروعٍ في هذه الشاشة.** فرعُ الصرّاف هو فرعُه، يُعرَض في
     الترويسة ولا يُختار. وكان اختيارُه قبل كلّ عملية عيباً في الصلاحيات قبل
     أن يكون عيباً في الواجهة: من يستطيع أن يختار فرعاً يستطيع أن يختار
     فرعاً ليس فرعه.

     **والدرج قبل العميل.** لا يُفتح حقلُ مبلغٍ قبل فتح ورديّة: صرّافٌ يُدخل
     عمليةً ثمّ يُقال له «افتح ورديّتك» يكون قد أخذ نقد العميل بيده. --}}

<div class="tab-pane fade show active" id="ag-counter">

    {{-- حالة الورديّة — الشريط الذي يقرؤه الصرّاف قبل كلّ شيء --}}
    <div class="card mb-3" id="ct-shift-card">
        <div class="card-body">
            <div class="d-flex align-items-center gap-3 flex-wrap">
                <div>
                    <div class="text-muted small">حالة الشبّاك</div>
                    <div class="fs-5 fw-bold" id="ct-shift-status">جارٍ التحميل…</div>
                </div>

                <div class="ms-auto d-flex gap-4 flex-wrap text-center" id="ct-shift-nums" style="display:none">
                    <div>
                        <div class="text-muted small">النقد في درجك</div>
                        <div class="fs-4 fw-bold cash money" id="ct-drawer">—</div>
                    </div>
                    <div>
                        <div class="text-muted small">إيداعات</div>
                        <div class="fs-5 money" id="ct-deps">—</div>
                    </div>
                    <div>
                        <div class="text-muted small">سحوبات</div>
                        <div class="fs-5 money" id="ct-wdrs">—</div>
                    </div>
                </div>

                <div class="d-flex gap-2">
                    <button class="btn btn-success" id="ct-open" data-testid="ct-open" style="display:none">
                        ▶️ افتح ورديّتي
                    </button>
                    <button class="btn btn-outline-secondary" id="ct-refill" style="display:none">
                        🔁 توريد من/إلى الخزنة
                    </button>
                    <button class="btn btn-outline-danger" id="ct-close" data-testid="ct-close" style="display:none">
                        ⏹️ إغلاق الورديّة وجرد الدرج
                    </button>
                </div>
            </div>

            <div class="alert alert-warning mt-3 mb-0 py-2 small" id="ct-why" style="display:none"></div>
        </div>
    </div>

    {{-- **إيداعٌ وسحبٌ لا يتشابهان، فلا يُجمعان في نموذجٍ واحد.**

         الإيداع يبدأ من الصرّاف: العميل يسلّم ورقاً، فيكفي رقم هاتفه.
         والسحب يبدأ من **العميل**: يطلبه من تطبيقه فيُحجز المبلغ ويصدر
         رمزٌ مؤقّت. وموافقتُه مثبتةٌ لأنّه أنشأ الطلب بنفسه.

         وجمعُهما في شاشةٍ واحدة هو ما جعلني أبني سحباً برقم الهاتف —
         أي خصماً من محفظة عميلٍ لم يوافق. --}}
    <ul class="nav nav-pills mb-3" id="ct-modes" style="display:none">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="pill"
            data-bs-target="#ct-dep-pane" data-testid="ct-mode-deposit">⬇️ إيداع</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="pill"
            data-bs-target="#ct-wdr-pane" data-testid="ct-mode-withdraw">⬆️ سحب برمز العميل</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="pill"
            data-bs-target="#ct-stm-pane" data-testid="ct-mode-statements">🧾 كشوفاتي</button></li>
    </ul>

    <div class="tab-content" id="ct-panes" style="display:none">

    {{-- ============ كشوفاتي ============
         **الإقرار الماليّ يبقى عند صاحبه.** كان الصرّاف يُغلق درجه فيختفي
         ما رفعه من أمامه: لا يعرف أقُبل الفرق أم ما زال معلَّقاً، ولا
         يستطيع أن يُري أحداً ما سلّمه. --}}
    <div class="tab-pane fade" id="ct-stm-pane">
        <div class="card p-3">
            <div class="d-flex align-items-center mb-3">
                <h6 class="mb-0">كشوفُ ورديّاتي المرفوعة إلى إدارة الشركة</h6>
                <button class="btn btn-sm btn-outline-primary ms-auto" id="ct-stm-load">تحديث</button>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light"><tr>
                        <th>الكشف</th><th>فُتحت</th><th>إيداعات</th><th>سحوبات</th>
                        <th>المعدود</th><th>الفرق</th><th>قرار الإدارة</th><th></th>
                    </tr></thead>
                    <tbody id="ct-stm-tbody">
                        <tr><td colspan="8" class="text-center text-muted py-4">اضغط «تحديث»</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    {{-- ═══ سحب: رمزٌ واحد، لا رقم هاتفٍ ولا مبلغٍ يُدخله الصرّاف ═══ --}}
    <div class="tab-pane fade" id="ct-wdr-pane">
        <div class="row g-3">
            <div class="col-lg-5">
                <div class="card p-3 h-100">
                    <h6>رمز العملية</h6>
                    <p class="small text-muted">يطلب العميل السحب من تطبيقه فيظهر له رمزٌ مؤقّت.</p>
                    <div class="input-group input-group-lg mb-2">
                        <input type="text" id="wd-code" class="form-control text-uppercase" dir="ltr"
                               placeholder="رمز العملية" data-testid="wd-code">
                        <button class="btn btn-primary" id="wd-find" data-testid="wd-find">تحقّق</button>
                    </div>
                    <div id="wd-info"></div>
                </div>
            </div>
            <div class="col-lg-7">
                <div class="card p-3 h-100" id="wd-op" style="display:none">
                    <h6>تسليم النقد</h6>
                    <div class="alert alert-secondary py-2 small mb-3" id="wd-summary"></div>
                    <div class="mb-3">
                        <label class="form-label">تأكيد هويّة العميل (اختياري)</label>
                        <input type="text" id="wd-ident" class="form-control" dir="ltr"
                               placeholder="رقم الهاتف أو رقم الهوية">
                        <div class="form-text">الرمز هو المُصرّح؛ وهذا تأكيدٌ إضافيّ إن طلبتَه.</div>
                    </div>
                    {{-- المبلغ لا يُدخله الصرّاف: هو ما طلبه العميل، فلا يُعدَّل. --}}
                    <button class="btn btn-warning btn-lg w-100" id="wd-pay" data-testid="wd-pay">
                        ⬆️ ادفع للعميل نقداً
                    </button>
                    <div id="wd-result" class="mt-3"></div>
                </div>
            </div>
        </div>
    </div>

    {{-- ═══ إيداع ═══ --}}
    <div class="tab-pane fade show active" id="ct-dep-pane">
    <div class="row g-3" id="ct-work-inner">
        {{-- العميل --}}
        <div class="col-lg-5">
            <div class="card p-3 h-100">
                <h6>١) العميل</h6>
                <div class="input-group input-group-lg mb-2">
                    <input type="text" id="ct-phone" class="form-control" dir="ltr"
                           placeholder="رقم هاتف العميل" data-testid="ct-phone">
                    <button class="btn btn-primary" id="ct-find" data-testid="ct-find">بحث</button>
                </div>
                <div id="ct-customer"></div>
            </div>
        </div>

        {{-- العملية --}}
        <div class="col-lg-7">
            <div class="card p-3 h-100" id="ct-op" style="display:none">
                <h6>٢) العملية</h6>

                <div class="mb-2">
                    <label class="form-label">المبلغ (ر.ي)</label>
                    <input type="number" id="ct-amount" class="form-control form-control-lg money"
                           min="1" step="1" data-testid="ct-amount">
                </div>
                <div class="mb-3">
                    <label class="form-label">ملاحظة (اختياري)</label>
                    <input type="text" id="ct-note" class="form-control">
                </div>

                {{-- زرّ السحب أُزيل من هنا: السحب له تبويبه ورمزه. --}}
                {{-- **اختياريّ.** بعض العملاء يريد ورقةً وبعضهم لا، وطابعةٌ
                     تعمل في كلّ عمليّةٍ تُهدر ورقاً وتُبطئ الطابور. --}}
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="ct-autoprint" checked>
                    <label class="form-check-label" for="ct-autoprint">
                        اطبع الإيصال تلقائياً بعد العملية
                    </label>
                </div>

                <button class="btn btn-success btn-lg w-100" id="ct-deposit" data-testid="ct-deposit">
                    ⬇️ إيداع — العميل يسلّمك نقداً
                </button>

                <div id="ct-result" class="mt-3"></div>
            </div>
        </div>
    </div>
    </div>{{-- ct-dep-pane --}}
    </div>{{-- ct-panes --}}
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

    if (!$('ct-shift-status')) return;   // ليس صرّافاً — لا شبّاك في صفحته

    let customerId = null;
    let busy = false;

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

    async function loadState() {
        const r = await fetch(root + '/counter/state', {headers: {'Accept': 'application/json'}});
        const j = await r.json();
        render(j);
    }

    function render(j) {
        const s = j.shift;
        const open = s && s.status === 'open';

        $('ct-shift-status').textContent = open ? 'ورديّة مفتوحة' : 'لا ورديّة مفتوحة';
        $('ct-shift-status').className = 'fs-5 fw-bold ' + (open ? 'text-success' : 'text-muted');

        $('ct-shift-nums').style.display = open ? 'flex' : 'none';
        $('ct-open').style.display = (!open && j.staff && j.staff.role === 'teller') ? '' : 'none';
        $('ct-close').style.display = open ? '' : 'none';
        $('ct-refill').style.display = open ? '' : 'none';
        ['ct-modes', 'ct-panes'].forEach(id => {
            if ($(id)) $(id).style.display = j.can_operate ? '' : 'none';
        });

        if (open) {
            $('ct-drawer').textContent = fmt(s.cash_on_hand) + ' ر.ي';
            $('ct-deps').textContent = fmt(s.deposits_total) + ` (${s.deposits_count})`;
            $('ct-wdrs').textContent = fmt(s.withdrawals_total) + ` (${s.withdrawals_count})`;
        }

        // سببُ التعطيل يُكتب بدل أن يُترك الزرّ ميّتاً: زرٌّ لا يعمل بلا سببٍ
        // مكتوب يُنتج مكالمةَ دعمٍ في كلّ فرع.
        $('ct-why').style.display = j.why_not ? '' : 'none';
        $('ct-why').textContent = j.why_not || '';
    }

    $('ct-open').addEventListener('click', async () => {
        const v = prompt('العهدة الافتتاحيّة — النقد الذي تستلمه من خزنة الفرع الآن (اكتب 0 إن بدأت بلا نقد):', '0');
        if (v === null) return;
        try { const j = await post('/counter/shift/open', {opening_float: v}); alert(j.message); render(j.shift ? {shift: j.shift, can_operate: true, staff: {role: 'teller'}} : {}); loadState(); }
        catch (e) { alert(e.message); }
    });

    $('ct-close').addEventListener('click', async () => {
        // لا يُعرَض المتوقَّع قبل السؤال: صرّافٌ يرى الرقم يكتبه بدل أن يعدّ.
        const counted = prompt('اعدّ ما في درجك الآن واكتب المبلغ:');
        if (counted === null) return;
        const note = prompt('ملاحظة (إلزاميّة إن وُجد فرق — عشرة أحرف فأكثر):', '') || '';
        try { const j = await post('/counter/shift/close', {counted_cash: counted, note}); alert(j.message); loadState(); }
        catch (e) { alert(e.message); }
    });

    $('ct-refill').addEventListener('click', async () => {
        const dir = confirm('موافق = استلام نقدٍ من خزنة الفرع إلى درجك.\\nإلغاء = تسليم نقدٍ من درجك إلى الخزنة.')
            ? 'to_drawer' : 'to_safe';
        const amount = prompt('المبلغ:');
        if (!amount) return;
        try { const j = await post('/counter/shift/refill', {amount, direction: dir}); alert(j.message); loadState(); }
        catch (e) { alert(e.message); }
    });

    $('ct-find').addEventListener('click', async () => {
        const phone = $('ct-phone').value.trim();
        const box = $('ct-customer');
        box.innerHTML = '<div class="text-muted small">جارٍ البحث…</div>';
        customerId = null;
        $('ct-op').style.display = 'none';

        const r = await fetch(root + '/counter/customer?phone=' + encodeURIComponent(phone),
            {headers: {'Accept': 'application/json'}});
        const j = await r.json();

        if (!r.ok || j.success === false) {
            box.innerHTML = `<div class="alert alert-danger py-2 mb-0 small">${esc(j.message || 'تعذّر البحث')}</div>`;
            return;
        }

        // الغلاف `ok()` يضع الحمولة في `meta` — لا `data`. وقراءةُ المفتاح
        // الخطأ تُعيد `undefined` بهدوء فتسقط الشاشة على `c.name` بلا رسالةٍ
        // مفهومة للصرّاف الواقف أمام عميل.
        const c = (j.meta && j.meta.customer) || j.customer;
        box.innerHTML = `
            <div class="border rounded p-3">
                <div class="fw-bold fs-5">${esc(c.name)}</div>
                <div class="text-muted" dir="ltr">${esc(c.phone)}</div>
                <div class="mt-2"><span class="badge bg-${c.can_transact ? 'success' : 'danger'}">${esc(c.status_label)}</span></div>
            </div>`;

        if (c.can_transact) {
            customerId = c.id;
            $('ct-op').style.display = '';
        }
    });

    $('ct-phone').addEventListener('keydown', (e) => { if (e.key === 'Enter') $('ct-find').click(); });

    async function operate(op) {
        if (busy) return;                       // نقرٌ مزدوجٌ = عمليّتان
        if (!customerId) { alert('ابحث عن العميل أوّلاً'); return; }
        const amount = $('ct-amount').value;
        if (!amount || Number(amount) <= 0) { alert('أدخل مبلغاً'); return; }

        busy = true;
        // كان هنا `$('ct-withdraw').disabled` — وزرُّ السحب هذا أُزيل حين
        // صار السحب برمز العميل. فصار السطر يقرأ `null.disabled` **خارج
        // `try`**، فيُلقي قبل أيّ طلبٍ ويموت المعالج بصمت: الزرّ يُضغط ولا
        // يحدث شيء، ولا رسالة، ولا سطر في أيّ سجلّ.
        $('ct-deposit').disabled = true;
        try {
            const j = await post('/counter/' + op, {
                customer_id: customerId, amount, note: $('ct-note').value || null,
            });
            const r = j.result;
            $('ct-result').innerHTML = `
                <div class="alert alert-success mb-0">
                    <div class="fw-bold">${esc(j.message)}</div>
                    <div class="small mt-1">المرجع: <span dir="ltr">${esc(r.reference)}</span></div>
                    <div class="small">الرسم: ${fmt(r.fee)} · عمولتك: ${fmt(r.commission)}</div>
                    ${receiptButton(r.receipt_number)}
                </div>`;
            openReceipt(r.receipt_number);
            $('ct-amount').value = '';
            $('ct-note').value = '';
            render(j.shift ? {shift: j.shift, can_operate: true, staff: {role: 'teller'}} : {});
        } catch (e) {
            $('ct-result').innerHTML = `<div class="alert alert-danger mb-0">${esc(e.message)}</div>`;
        } finally {
            busy = false;
            $('ct-deposit').disabled = false;
        }
    }

    $('ct-deposit').addEventListener('click', () => operate('deposit'));

    // ── إيصال العملية ────────────────────────────────────────────
    //
    // **إيصالٌ لا يُطبَع إلّا إن طُلب.** وزرُّه يبقى ظاهراً بعد العملية
    // حتى لو أُغلقت النافذة أو رفض العميل الورقة ثمّ عاد يطلبها.
    function receiptUrl(n) { return root + '/receipt/' + encodeURIComponent(n); }

    function receiptButton(n) {
        if (!n) {
            // الإيصال قد لا يصدر (عطلٌ في إصداره لا يُسقط عمليّةً تمّت).
            // ويُقال ذلك بدل تركِ زرٍّ لا يفتح شيئاً.
            return '<div class="small text-muted mt-2">لم يصدر إيصالٌ لهذه العملية — راجع الإدارة.</div>';
        }
        return `<div class="mt-2">
            <a class="btn btn-sm btn-outline-dark" target="_blank" rel="noopener"
               href="${receiptUrl(n)}">🖨️ طباعة الإيصال</a>
            <span class="small text-muted ms-2">رقم الإيصال: <span dir="ltr">${esc(n)}</span></span>
        </div>`;
    }

    function openReceipt(n) {
        if (!n || !$('ct-autoprint') || !$('ct-autoprint').checked) return;
        // نافذةٌ منفصلة: الطباعة من الشبّاك نفسه تطبع الشبّاك.
        window.open(receiptUrl(n) + '?auto=1', '_blank', 'noopener');
    }

    // ── كشوفاتي: ما رفعتُه إلى إدارة الشركة ──────────────────────
    const REVIEW_BADGE = {
        balanced: 'bg-light text-dark border', pending: 'bg-danger',
        accepted: 'bg-success', investigating: 'bg-warning text-dark', resolved: 'bg-secondary',
    };

    async function loadMyStatements() {
        const tb = $('ct-stm-tbody');
        tb.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-3">جارٍ التحميل…</td></tr>';
        const r = await fetch(root + '/my-statements', {headers: {'Accept': 'application/json'}});
        const j = await r.json();
        const rows = (j.meta && j.meta.rows) || [];

        tb.innerHTML = rows.length ? rows.map(x => `
            <tr>
                <td class="font-monospace" dir="ltr">${esc(x.statement_no)}</td>
                <td class="small">${esc(x.opened_at ?? '')}</td>
                <td class="money">${fmt(x.deposits_total)} <span class="text-muted small">(${x.deposits_count})</span></td>
                <td class="money">${fmt(x.withdrawals_total)} <span class="text-muted small">(${x.withdrawals_count})</span></td>
                <td class="money">${x.counted_cash === null ? '<span class="text-muted">لم تُغلق</span>' : fmt(x.counted_cash)}</td>
                <td class="money ${x.variance && Number(x.variance) !== 0 ? 'text-danger fw-bold' : ''}">
                    ${x.variance === null ? '—' : fmt(x.variance)}</td>
                <td><span class="badge ${REVIEW_BADGE[x.review_status] || 'bg-secondary'}">${esc(x.review_label ?? '')}</span>
                    ${x.review_note ? `<div class="small text-muted">${esc(x.review_note)}</div>` : ''}</td>
                <td><button class="btn btn-sm btn-primary" data-stm="${x.id}">الكشف</button></td>
            </tr>`).join('')
            : '<tr><td colspan="8" class="text-center text-muted py-4">لا ورديّات بعد</td></tr>';
    }

    $('ct-stm-load').addEventListener('click', loadMyStatements);

    $('ct-stm-tbody').addEventListener('click', async (e) => {
        const b = e.target.closest('button[data-stm]');
        if (!b) return;
        $('stm-body').innerHTML = '<div class="text-muted">جارٍ التحميل…</div>';
        new bootstrap.Modal($('stm-modal')).show();
        const r = await fetch(root + '/my-statements/' + b.dataset.stm,
            {headers: {'Accept': 'application/json'}});
        const j = await r.json();
        if (!j.success) { $('stm-body').innerHTML = `<div class="alert alert-danger mb-0">${esc(j.message)}</div>`; return; }
        $('stm-title').textContent = 'كشف تسوية — ' + j.meta.statement_no;
        $('stm-body').innerHTML = renderStatement(j.meta);
    });

    // ── السحب برمز العميل ────────────────────────────────────────
    let wdCode = null;

    $('wd-find').addEventListener('click', async () => {
        const code = $('wd-code').value.trim().toUpperCase();
        const box = $('wd-info');
        wdCode = null;
        $('wd-op').style.display = 'none';
        box.innerHTML = '<div class="text-muted small">جارٍ التحقّق…</div>';

        const r = await fetch(root + '/counter/withdrawal?op_code=' + encodeURIComponent(code),
            {headers: {'Accept': 'application/json'}});
        const j = await r.json();

        if (!r.ok || j.success === false) {
            box.innerHTML = `<div class="alert alert-danger py-2 mb-0 small">${esc(j.message)}</div>`;
            return;
        }

        const q = j.request;
        box.innerHTML = `
            <div class="border rounded p-3">
                <div class="fw-bold fs-5">${esc(q.customer.name)}</div>
                <div class="text-muted" dir="ltr">${esc(q.customer.phone ?? '')}</div>
                <div class="small text-muted mt-2">ينتهي: ${esc(q.expires_at ?? '—')}</div>
            </div>`;

        // سببُ التعذّر يُقال قبل أن يعدّ الصرّاف الأوراق لا بعد.
        if (!j.can_pay) {
            box.innerHTML += `<div class="alert alert-warning py-2 mt-2 mb-0 small">${esc(j.why_not)}</div>`;
            return;
        }

        wdCode = q.op_code;
        $('wd-summary').innerHTML =
            `<div class="fs-4 fw-bold">ادفع نقداً: ${fmt(q.amount)} ر.ي</div>` +
            `<div class="small text-muted">رسم العملية ${fmt(q.fee)} — مخصومٌ من رصيده، لا من النقد.</div>`;
        $('wd-op').style.display = '';
    });

    $('wd-code').addEventListener('keydown', (e) => { if (e.key === 'Enter') $('wd-find').click(); });

    $('wd-pay').addEventListener('click', async () => {
        if (busy || !wdCode) return;
        busy = true;
        $('wd-pay').disabled = true;
        try {
            const j = await post('/counter/withdrawal', {
                op_code: wdCode, identifier: $('wd-ident').value.trim() || null,
            });
            const rn = (j.result && j.result.receipt_number) || j.receipt_number || null;
            $('wd-result').innerHTML =
                `<div class="alert alert-success mb-0">
                    <div class="fw-bold">${esc(j.message)}</div>
                    ${receiptButton(rn)}
                 </div>`;
            openReceipt(rn);
            $('wd-code').value = ''; $('wd-ident').value = '';
            $('wd-info').innerHTML = ''; $('wd-op').style.display = 'none';
            wdCode = null;
            render(j.shift ? {shift: j.shift, can_operate: true, staff: {role: 'teller'}} : {});
        } catch (e) {
            $('wd-result').innerHTML = `<div class="alert alert-danger mb-0">${esc(e.message)}</div>`;
        } finally {
            busy = false;
            $('wd-pay').disabled = false;
        }
    });

    loadState();
});
</script>
@endonce
