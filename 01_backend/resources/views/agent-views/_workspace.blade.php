{{-- AMIAL-TELLER-WS-001 — رأسُ مساحة عمل الصرّاف.

     ══════════════════════════════════════════════════════════════════
     **ما يراه الصرّاف قبل أن يبدأ، لا بعد أن يُخطئ.**

     كانت الشاشة تقول له رقمين: كم في درجه، وكم أودع. وكلُّ ما عداه كان
     يُكتشف بالرفض أمام العميل: حدُّه، وصلاحيتُه، وحالةُ العميل، وأنّ
     مزوّد الرسائل متوقّف.

     والرفض أمام عميلٍ واقف ليس رسالةَ خطأ — هو موقفٌ يخسر فيه الفرع
     زبوناً. فما يُعرَض هنا كلُّه جوابٌ عن سؤالٍ يُسأل قبل الضغط. --}}

<div id="ws" data-boot="0">

    {{-- ١٢) تعاميم الإدارة — فوق كلّ شيء لأنّها قد تُلغي العمل نفسه --}}
    <div id="ws-announce"></div>

    {{-- ١٨) المؤشّرات الرئيسية --}}
    <div class="row g-2 mb-3" id="ws-kpis"></div>

    <div class="row g-3 mb-3">
        {{-- ٦) الصلاحيات + ٣) الحدود --}}
        <div class="col-lg-7">
            <div class="card p-3 h-100">
                <div class="d-flex align-items-center mb-2 flex-wrap gap-2">
                    <h6 class="mb-0">🔐 صلاحياتك وحدودك</h6>
                    <span class="text-muted small">تُعرَض قبل المحاولة — لا بعد الرفض</span>
                    <button class="btn btn-sm btn-outline-secondary ms-auto" id="ws-refresh">تحديث</button>
                </div>
                <div id="ws-limits" class="mb-2"></div>
                <div id="ws-perms" class="d-flex flex-wrap gap-1"></div>
            </div>
        </div>

        {{-- ٨) مراقبة الاتّصال + ١١) سجلّي اليوميّ + ١٣) الطوارئ --}}
        <div class="col-lg-5">
            <div class="card p-3 h-100">
                <h6 class="mb-2">🩺 حالة الأنظمة</h6>
                <div id="ws-systems" class="small mb-3"></div>

                <h6 class="mb-2">📋 يومك</h6>
                <div id="ws-day" class="small mb-3"></div>

                {{-- زرّ الطوارئ في الأسفل عمداً: زرٌّ أحمر في أعلى الشاشة
                     يُضغط بالخطأ، وبلاغُ سطوٍ كاذب يُفقد البلاغ الحقيقيّ
                     قيمته. --}}
                <button class="btn btn-danger w-100" id="ws-panic" data-testid="ws-panic">
                    🚨 بلاغ طوارئ
                </button>
                <div class="form-text mt-1" id="ws-panic-note"></div>
            </div>
        </div>
    </div>

    {{-- ١٧) الموافقات: ما ينتظر قراري (للمدير) وما ينتظر قرار مديري (للصرّاف) --}}
    <div class="card p-3 mb-3" id="ws-req-card" style="display:none">
        <h6 class="mb-2" id="ws-req-title">✋ طلبات الموافقة</h6>
        <div id="ws-requests"></div>
    </div>

    {{-- ١٠) العملاء الأخيرون --}}
    <div class="card p-3 mb-3" id="ws-recent-card" style="display:none">
        <h6 class="mb-2">👥 آخر من خدمتَهم</h6>
        <div class="d-flex flex-wrap gap-1" id="ws-recent"></div>
        <div class="form-text">اضغط الاسم ليُملأ رقمُه في خانة البحث.</div>
    </div>
</div>

{{-- نافذة طلب الموافقة (١٧) --}}
<div class="modal fade" id="ws-req-modal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">طلب موافقة المدير</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="alert alert-info py-2 small">
                <strong>لا تقسّم العمليّة.</strong> تقسيمُ مبلغٍ إلى دفعاتٍ للتحايل على
                الحدّ نمطٌ تُدان به قواعد غسل الأموال — والطلب هنا هو الطريق الصحيح.
            </div>
            <div class="mb-2"><label class="form-label">المبلغ</label>
                <input type="number" class="form-control" id="ws-req-amount" min="1" dir="ltr"></div>
            <div class="mb-2"><label class="form-label">نوع العمليّة</label>
                <select class="form-select" id="ws-req-op">
                    <option value="deposit">إيداع</option>
                    <option value="withdraw">سحب</option>
                </select></div>
            <div class="mb-2"><label class="form-label">السبب (عشرة أحرف فأكثر)</label>
                <textarea class="form-control" id="ws-req-reason" rows="3"
                          placeholder="مثال: العميل تاجرٌ معروف يودع حصيلة يومه ومعه فاتورة"></textarea></div>
            <div class="text-danger small" id="ws-req-err"></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-primary" id="ws-req-send">إرسال الطلب</button>
        </div>
    </div></div>
</div>

{{-- نافذة الطوارئ (١٣) --}}
<div class="modal fade" id="ws-panic-modal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content border-danger">
        <div class="modal-header bg-danger text-white">
            <h5 class="modal-title">🚨 بلاغ طوارئ</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="alert alert-danger py-2 small mb-3">
                يصل البلاغ إلى إدارتك <strong>فوراً</strong> ومعه فرعُك ووقتُك واسمُك.
                <br><strong>ولا يُحذف بعد إرساله</strong> — ولا حتى بيدك. فبلاغٌ يُلغيه صاحبُه
                تحت التهديد لا يحمي أحداً.
            </div>
            <div class="mb-2"><label class="form-label">النوع</label>
                <select class="form-select" id="ws-panic-kind">
                    <option value="duress">إكراه — أُجبِرتُ على عمليّة</option>
                    <option value="robbery">سطو على الفرع</option>
                    <option value="threat">تهديد</option>
                    <option value="fraud">محاولة احتيال</option>
                </select></div>
            <div class="mb-2"><label class="form-label">ملاحظة (اختياريّة)</label>
                <input class="form-control" id="ws-panic-text" placeholder="اتركها فارغة إن لم تستطع"></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-danger btn-lg w-100" id="ws-panic-send">إرسال البلاغ الآن</button>
        </div>
    </div></div>
</div>

@once
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
document.addEventListener('DOMContentLoaded', function () {
    const BASE = '{{ url('agent') }}';
    const $ = (id) => document.getElementById(id);
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c =>
        ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const fmt = (n) => Number(n || 0).toLocaleString('en-US', {maximumFractionDigits: 0});
    const csrf = () => (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    async function get(p) {
        const r = await fetch(BASE + p, {headers: {'Accept': 'application/json'}});
        const j = await r.json().catch(() => ({}));
        if (!r.ok) throw new Error(j.message || 'تعذّر الاتّصال');
        return j;
    }

    async function post(p, body) {
        const r = await fetch(BASE + p, {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'Accept': 'application/json',
                      'X-CSRF-TOKEN': csrf()},
            body: JSON.stringify(body || {}),
        });
        const j = await r.json().catch(() => ({}));
        if (!r.ok) throw new Error(j.message || 'تعذّر التنفيذ');
        return j;
    }

    // يُتاح لبقيّة الشاشة: الشبّاك ينادي `wsLogEvent` و`wsAssess`.
    window.wsLogEvent = function (event, extra) {
        // **وفشلُ التسجيل لا يُوقف الصرّاف.** سجلُّ تدقيقٍ يمنع العمل حين
        // يتعطّل يجعل تعطيلَه هدفاً لمن يريد أن يعمل بلا أثر.
        post('/teller/event', Object.assign({event: event}, extra || {})).catch(() => {});
    };

    window.wsAssess = async function (customerId, amount, operation) {
        const q = `/teller/assess?customer_id=${encodeURIComponent(customerId)}`
            + `&amount=${encodeURIComponent(amount)}&operation=${encodeURIComponent(operation || 'deposit')}`;
        const j = await get(q);
        return (j.meta || {});
    };

    // ── ١٨) بطاقات المؤشّرات ────────────────────────────────────────
    //
    // **و«غير معروف» يُكتب «—» لا صفراً** (القاعدة السابعة): صرّافٌ بلا
    // ورديّة يقرأ «٠ في درجك» فيظنّ درجه فارغاً وهو لم يفتحه بعد.
    function card(icon, label, value, sub, tone) {
        const v = (value === null || value === undefined)
            ? '<span class="text-muted">—</span>' : value;
        return `<div class="col-6 col-md-4 col-xl-3">
            <div class="card p-2 h-100 border-${tone || 'light'}">
                <div class="small text-muted">${icon} ${esc(label)}</div>
                <div class="fs-5 fw-bold">${v}</div>
                ${sub ? `<div class="small text-muted">${sub}</div>` : ''}
            </div></div>`;
    }

    function renderKpis(k) {
        $('ws-kpis').innerHTML = [
            card('💰', 'النقد في درجك',
                 k.drawer_known ? fmt(k.drawer_cash) : null,
                 k.drawer_known ? 'ر.ي' : 'لا ورديّة مفتوحة', 'success'),
            card('📲', 'رصيد الفرع الإلكترونيّ',
                 k.branch_emoney === null ? null : fmt(k.branch_emoney), 'يحدّ الإيداع'),
            card('🏦', 'خزنة الفرع',
                 k.safe_known ? fmt(k.branch_safe) : null,
                 k.safe_known ? 'تحدّ السحب' : 'لا خزنة مضبوطة'),
            card('👥', 'عملاء خدمتَهم اليوم', fmt(k.customers_served)),
            card('💵', 'إيداعات اليوم', fmt(k.deposits_total), `${k.deposits_count} عمليّة`),
            card('💸', 'سحوبات اليوم', fmt(k.withdrawals_total), `${k.withdrawals_count} عمليّة`),
            card('⚠️', 'طلبات معلّقة', fmt(k.pending_requests), 'بانتظار مديرك',
                 Number(k.pending_requests) > 0 ? 'warning' : null),
            card('🚨', 'فروقٌ لم تُراجَع', fmt(k.risk_alerts), 'من ورديّاتك',
                 Number(k.risk_alerts) > 0 ? 'danger' : null),
        ].join('');
    }

    // ── ٣) الحدود ────────────────────────────────────────────────────
    function renderLimits(l) {
        const row = (label, val, extra) =>
            `<div class="d-flex justify-content-between border-bottom py-1">
                <span>${esc(label)}</span>
                <span class="fw-bold">${val}${extra ? ` <span class="text-muted small">${extra}</span>` : ''}</span>
            </div>`;

        // **null يعني «بلا حدّ خاصّ» لا «صفر».** وقلبُهما يجعل الصرّاف
        // يظنّ نفسه ممنوعاً من كلّ شيء.
        const none = '<span class="text-muted">بلا حدّ خاصّ</span>';

        let bar = '';
        if (l.daily !== null) {
            const pct = Math.min(100, Math.round((Number(l.daily_used) / Number(l.daily)) * 100));
            const tone = pct >= 90 ? 'bg-danger' : (pct >= 70 ? 'bg-warning' : 'bg-success');
            bar = `<div class="progress mt-2" style="height:8px">
                     <div class="progress-bar ${tone}" style="width:${pct}%"></div></div>
                   <div class="small text-muted">استهلكتَ ${pct}% من حدّك اليوميّ</div>`;
        }

        $('ws-limits').innerHTML =
            row('حدّ العمليّة الواحدة', l.per_operation === null ? none : fmt(l.per_operation) + ' ر.ي')
            + row('الحدّ اليوميّ', l.daily === null ? none : fmt(l.daily) + ' ر.ي')
            + row('المتبقّي اليوم', l.daily_remaining === null ? none : fmt(l.daily_remaining) + ' ر.ي')
            + row('عدد العمليّات', l.count_limit === null
                  ? `${l.count_used} <span class="text-muted small">(بلا حدّ)</span>`
                  : `${l.count_used} / ${l.count_limit}`)
            + (Number(l.rejected_today) > 0
               ? row('طلبات رُفضت اليوم', `<span class="text-danger">${l.rejected_today}</span>`) : '')
            + bar;
    }

    // ── ٦) الصلاحيات ─────────────────────────────────────────────────
    function renderPerms(rows) {
        $('ws-perms').innerHTML = rows.map(p =>
            `<span class="badge ${p.allowed ? 'bg-success' : 'bg-secondary'}">
                ${p.allowed ? '✅' : '❌'} ${esc(p.label)}</span>`).join('');
    }

    // ── ٨) حالة الأنظمة ──────────────────────────────────────────────
    //
    // **و«غير مربوط» لونٌ ثالث لا أخضر ولا أحمر.** أخضرُ أمام طابعةٍ لم
    // نسألها كذبةٌ يبني عليها الصرّاف وعداً للعميل.
    function renderSystems(rows) {
        const dot = {ok: '🟢', down: '🔴', off: '🟠', not_integrated: '⚪'};
        $('ws-systems').innerHTML = rows.map(s =>
            `<div class="d-flex justify-content-between py-1">
                <span>${dot[s.state] || '⚪'} ${esc(s.label)}</span>
                <span class="text-muted">${esc(s.note || '')}</span>
             </div>`).join('');
    }

    function renderDay(d) {
        const line = (k, v) => `<div class="d-flex justify-content-between py-1">
            <span>${esc(k)}</span><span class="text-muted">${esc(v ?? '—')}</span></div>`;
        $('ws-day').innerHTML =
            line('الورديّة', d.shift_open ? ('مفتوحة #' + d.shift_id) : 'غير مفتوحة')
            + line('فُتحت', d.opened_at)
            + line('مضى على الورديّة', d.minutes_on_shift === null
                   ? '—' : Math.floor(d.minutes_on_shift / 60) + 'س ' + (d.minutes_on_shift % 60) + 'د')
            + line('آخر نشاط', d.last_activity_at);
    }

    function renderAnnounce(rows) {
        $('ws-announce').innerHTML = rows.map(a => {
            const tone = a.severity === 'critical' ? 'danger'
                       : (a.severity === 'warning' ? 'warning' : 'info');
            return `<div class="alert alert-${tone} py-2 mb-2">
                <strong>${a.icon} ${esc(a.title)}</strong>
                <div class="small">${esc(a.body)}</div></div>`;
        }).join('');
    }

    function renderRecent(rows) {
        $('ws-recent-card').style.display = rows.length ? '' : 'none';
        $('ws-recent').innerHTML = rows.map(c =>
            `<button class="btn btn-sm btn-outline-primary" data-ws-phone="${esc(c.phone)}">
                ${esc(c.name)} <span class="text-muted small" dir="ltr">${esc(c.phone)}</span>
             </button>`).join('');
    }

    // ── ١٧) الموافقات ────────────────────────────────────────────────
    function renderMyRequests(rows) {
        if (!rows.length) { $('ws-req-card').style.display = 'none'; return; }
        $('ws-req-card').style.display = '';
        $('ws-req-title').textContent = '✋ طلباتي';
        $('ws-requests').innerHTML = rows.map(r => {
            const tone = r.status === 'approved' ? 'success'
                       : (r.status === 'rejected' ? 'danger'
                       : (r.status === 'pending' ? 'warning' : 'secondary'));
            return `<div class="d-flex flex-wrap gap-2 align-items-center border-bottom py-2">
                <span class="badge bg-${tone}">${esc(r.status_label)}</span>
                <span class="fw-bold">${fmt(r.amount)} ر.ي</span>
                <span class="text-muted small">${esc(r.kind_label)} · ${esc(r.operation)}</span>
                ${r.note ? `<span class="small text-muted">📝 ${esc(r.note)}</span>` : ''}
                <span class="ms-auto small text-muted" dir="ltr">${esc(r.number)}</span>
            </div>`;
        }).join('');
    }

    function renderPending(rows) {
        $('ws-req-card').style.display = '';
        $('ws-req-title').textContent = `✋ طلباتٌ تنتظر قرارك (${rows.length})`;
        $('ws-requests').innerHTML = rows.length ? rows.map(r =>
            `<div class="border rounded p-2 mb-2">
                <div class="d-flex flex-wrap gap-2 align-items-center">
                    <strong>${esc(r.staff)}</strong>
                    <span class="badge bg-dark font-monospace" dir="ltr">${esc(r.staff_username)}</span>
                    <span class="badge bg-warning text-dark">${esc(r.kind_label)}</span>
                    <span class="fs-5 fw-bold">${fmt(r.amount)} ر.ي</span>
                    <span class="text-muted small">${esc(r.operation)}</span>
                    <span class="ms-auto small text-muted">تنتهي ${esc(r.expires_at || '')}</span>
                </div>
                <div class="small mt-1">📝 ${esc(r.reason)}</div>
                <div class="mt-2 d-flex gap-2">
                    <button class="btn btn-sm btn-success" data-ws-approve="${r.id}">موافقة</button>
                    <button class="btn btn-sm btn-outline-danger" data-ws-reject="${r.id}">رفض</button>
                </div>
            </div>`).join('')
            : '<div class="text-muted small">لا طلبات تنتظر قرارك.</div>';
    }

    // ── التحميل ──────────────────────────────────────────────────────
    let ROLE = 'teller';

    async function load() {
        let j;
        try { j = await get('/teller/workspace'); }
        catch (e) {
            $('ws-kpis').innerHTML =
                `<div class="col-12"><div class="alert alert-danger py-2 mb-0">
                    تعذّر تحميل مساحة العمل: ${esc(e.message)}</div></div>`;
            return;
        }

        const m = j.meta || {};
        ROLE = (m.staff || {}).role || 'teller';

        renderKpis(m.kpis || {});
        renderLimits(m.limits || {});
        renderPerms(m.permissions || []);
        renderSystems(m.systems || []);
        renderDay(m.day_log || {});
        renderAnnounce(m.announcements || []);
        renderRecent(m.recent_customers || []);

        const panic = m.panic || {};
        $('ws-panic-note').textContent = panic.geo_possible
            ? 'يُرسَل موقعك مع البلاغ إن أذنتَ للمتصفّح.'
            : 'الاتّصال غير مشفَّر — المتصفّح يمنع إرسال الموقع. يُرسَل الفرع والوقت فقط.';

        if (panic.has_open) {
            $('ws-panic').className = 'btn btn-outline-danger w-100';
            $('ws-panic').textContent = '🚨 لديك بلاغ مفتوح — ' + (panic.status_label || '');
        }

        // المدير يرى ما ينتظر قراره، والصرّاف يرى طلباته هو.
        if (ROLE === 'teller') {
            renderMyRequests(m.requests || []);
        } else {
            try { renderPending(((await get('/teller/requests')).meta || {}).rows || []); }
            catch (e) { /* لا تُسقط الشاشة كلّها لأجل قائمة */ }
        }

        $('ws').dataset.boot = '1';
        window.wsLogEvent('counter_opened');
    }

    $('ws-refresh').addEventListener('click', load);

    // العميل الأخير يملأ خانة البحث في الشبّاك — إن كانت موجودة.
    $('ws-recent').addEventListener('click', (e) => {
        const b = e.target.closest('button[data-ws-phone]');
        if (!b) return;
        const inp = $('ct-phone');
        if (inp) { inp.value = b.dataset.wsPhone; inp.focus(); }
        const find = $('ct-find');
        if (find) find.click();
    });

    $('ws-requests').addEventListener('click', async (e) => {
        const ok = e.target.closest('button[data-ws-approve]');
        const no = e.target.closest('button[data-ws-reject]');
        if (!ok && !no) return;
        try {
            if (no) {
                const note = prompt('سبب الرفض (عشرة أحرف فأكثر):');
                if (!note) return;
                await post(`/teller/requests/${no.dataset.wsReject}/decide`, {approve: false, note: note});
            } else {
                const note = prompt('ملاحظة الموافقة (اختياريّة):') || '';
                await post(`/teller/requests/${ok.dataset.wsApprove}/decide`, {approve: true, note: note});
            }
            load();
        } catch (err) { alert(err.message); }
    });

    // ── طلب الموافقة ─────────────────────────────────────────────────
    window.wsOpenRequest = function (amount, operation) {
        $('ws-req-err').textContent = '';
        if (amount) $('ws-req-amount').value = amount;
        if (operation) $('ws-req-op').value = operation;
        new bootstrap.Modal($('ws-req-modal')).show();
    };

    $('ws-req-send').addEventListener('click', async () => {
        $('ws-req-err').textContent = '';
        try {
            const j = await post('/teller/request', {
                amount: $('ws-req-amount').value,
                operation: $('ws-req-op').value,
                reason: $('ws-req-reason').value,
            });
            bootstrap.Modal.getInstance($('ws-req-modal')).hide();
            alert(j.message);
            $('ws-req-reason').value = '';
            load();
        } catch (e) { $('ws-req-err').textContent = e.message; }
    });

    // ── ١٣) الطوارئ ──────────────────────────────────────────────────
    $('ws-panic').addEventListener('click', () => {
        new bootstrap.Modal($('ws-panic-modal')).show();
    });

    $('ws-panic-send').addEventListener('click', async () => {
        const btn = $('ws-panic-send');
        btn.disabled = true;
        btn.textContent = 'يُرسَل…';

        const body = {
            kind: $('ws-panic-kind').value,
            note: $('ws-panic-text').value || null,
            geo_state: 'unavailable',
        };

        // **الموقع لا يُنتظر أكثر من ثلاث ثوانٍ.** موظّفٌ تحت تهديدٍ لا
        // ينتظر GPS — البلاغ يُرسَل بما توفّر، ويُقال ما لم يتوفّر.
        try {
            if (!window.isSecureContext) {
                body.geo_state = 'insecure';
            } else if (navigator.geolocation) {
                const pos = await new Promise((res) => {
                    let done = false;
                    const t = setTimeout(() => { if (!done) { done = true; res(null); } }, 3000);
                    navigator.geolocation.getCurrentPosition(
                        (p) => { if (!done) { done = true; clearTimeout(t); res(p); } },
                        () => { if (!done) { done = true; clearTimeout(t); res(null); } },
                        {timeout: 3000});
                });
                if (pos) {
                    body.geo_state = 'ok';
                    body.lat = pos.coords.latitude;
                    body.lng = pos.coords.longitude;
                } else {
                    body.geo_state = 'denied';
                }
            }
        } catch (e) { body.geo_state = 'unavailable'; }

        try {
            const j = await post('/teller/panic', body);
            bootstrap.Modal.getInstance($('ws-panic-modal')).hide();
            alert(j.message);
            load();
        } catch (e) {
            alert('تعذّر إرسال البلاغ: ' + e.message + '\nاتّصل بإدارتك هاتفياً فوراً.');
        } finally {
            btn.disabled = false;
            btn.textContent = 'إرسال البلاغ الآن';
        }
    });

    load();
});
</script>
@endonce
