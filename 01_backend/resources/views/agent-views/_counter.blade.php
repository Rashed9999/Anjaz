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

    <div class="row g-3" id="ct-work" style="display:none">
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

                <div class="d-flex gap-2">
                    <button class="btn btn-success btn-lg flex-fill" id="ct-deposit" data-testid="ct-deposit">
                        ⬇️ إيداع — العميل يسلّمك نقداً
                    </button>
                    <button class="btn btn-warning btn-lg flex-fill" id="ct-withdraw" data-testid="ct-withdraw">
                        ⬆️ سحب — تسلّم العميل نقداً
                    </button>
                </div>

                <div id="ct-result" class="mt-3"></div>
            </div>
        </div>
    </div>
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
        $('ct-work').style.display = j.can_operate ? '' : 'none';

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

        const c = j.data ? j.data.customer : j.customer;
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
        $('ct-deposit').disabled = $('ct-withdraw').disabled = true;
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
                </div>`;
            $('ct-amount').value = '';
            $('ct-note').value = '';
            render(j.shift ? {shift: j.shift, can_operate: true, staff: {role: 'teller'}} : {});
        } catch (e) {
            $('ct-result').innerHTML = `<div class="alert alert-danger mb-0">${esc(e.message)}</div>`;
        } finally {
            busy = false;
            $('ct-deposit').disabled = $('ct-withdraw').disabled = false;
        }
    }

    $('ct-deposit').addEventListener('click', () => operate('deposit'));
    $('ct-withdraw').addEventListener('click', () => operate('withdraw'));

    loadState();
});
</script>
@endonce
