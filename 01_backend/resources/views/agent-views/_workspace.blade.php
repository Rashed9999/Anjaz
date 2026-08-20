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
    <div class="row g-2 mb-3 {{ $role === 'teller' ? '' : 'd-none' }}" id="ws-kpis"></div>

    <div class="row g-3 mb-3 {{ $role === 'teller' ? '' : 'd-none' }}">
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
                <div id="ws-day" class="small mb-2"></div>

                {{-- AMIAL-WORKTIME-001 — الاستراحة والكشف.

                     ولولا زرّ الاستراحة لما كان أمام الموظّف إلّا إغلاق
                     ورديّته لأجل نصف ساعة غداء — وذلك جردُ درجٍ كامل. --}}
                <div class="d-flex gap-2 mb-3">
                    <button class="btn btn-sm btn-outline-warning flex-grow-1" id="ws-break">
                        ☕ استراحة
                    </button>
                    <button class="btn btn-sm btn-outline-primary flex-grow-1" id="ws-sheet">
                        ⏱️ كشف ساعاتي
                    </button>
                </div>

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

    @if($role !== 'teller')
    <div class="card p-3 mb-3" id="ws-emergency-card">
        <div class="d-flex align-items-center gap-2 mb-2">
            <h6 class="mb-0 text-danger">🚨 بلاغات الطوارئ</h6>
            <span class="badge bg-danger" id="ws-emergency-count">0</span>
            <button class="btn btn-sm btn-outline-secondary ms-auto" id="ws-emergency-refresh">تحديث</button>
        </div>
        <div id="ws-emergencies"><div class="text-muted small">جارٍ التحميل…</div></div>
    </div>
    @endif

    {{-- ١٠) العملاء الأخيرون --}}
    <div class="card p-3 mb-3 {{ $role === 'teller' ? '' : 'd-none' }}" id="ws-recent-card" style="display:none">
        <h6 class="mb-2">👥 آخر من خدمتَهم</h6>
        <div class="d-flex flex-wrap gap-1" id="ws-recent"></div>
        <div class="form-text">اضغط الاسم ليُملأ رقمُه في خانة البحث.</div>
    </div>
</div>

@if($role !== 'teller')
<div class="modal fade" id="ws-emergency-modal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">معالجة بلاغ الطوارئ</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div>
        <div class="modal-body">
            <div class="alert alert-danger py-2 small" id="ws-emergency-summary"></div>
            <label class="form-label">الإجراء</label>
            <select class="form-select mb-3" id="ws-emergency-status">
                <option value="acknowledged">استلمت البلاغ وأتابعه</option>
                <option value="resolved">تمت المعالجة</option>
                <option value="false_alarm">إنذار خاطئ موثق</option>
            </select>
            <label class="form-label">نتيجة المعالجة</label>
            <textarea class="form-control" rows="4" id="ws-emergency-note" placeholder="إلزامية عند إغلاق البلاغ — عشرة أحرف فأكثر"></textarea>
            <div class="text-danger small mt-2" id="ws-emergency-err"></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-light" data-bs-dismiss="modal">إلغاء</button>
            <button class="btn btn-danger" id="ws-emergency-save">حفظ الإجراء</button>
        </div>
    </div></div>
</div>
@endif

{{-- كشفُ ساعات الدوام (AMIAL-WORKTIME-001) --}}
<div class="modal fade" id="ws-sheet-modal" tabindex="-1">
    <div class="modal-dialog modal-xl"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">⏱️ كشف ساعاتي</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="d-flex gap-2 mb-3 flex-wrap align-items-end">
                <div><label class="form-label small mb-0">من</label>
                    <input type="date" class="form-control form-control-sm" id="ws-sheet-from"></div>
                <div><label class="form-label small mb-0">إلى</label>
                    <input type="date" class="form-control form-control-sm" id="ws-sheet-to"></div>
                <button class="btn btn-sm btn-primary" id="ws-sheet-load">عرض</button>
                <button class="btn btn-sm btn-outline-warning ms-auto" id="ws-ot-ask">
                    ✋ اطلب موافقة على وقتٍ إضافيّ اليوم
                </button>
            </div>
            <div id="ws-sheet-body"><div class="text-muted">جارٍ التحميل…</div></div>
        </div>
    </div></div>
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

{{-- قرار المدير في نافذة من المشروع، لا نافذة المتصفّح الخام. --}}
<div class="modal fade" id="ws-decision-modal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title" id="ws-decision-title">قرار الطلب</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div>
        <div class="modal-body">
            <input type="hidden" id="ws-decision-id"><input type="hidden" id="ws-decision-approve">
            <div class="alert alert-light border small" id="ws-decision-help"></div>
            <label class="form-label">ملاحظة القرار</label>
            <textarea class="form-control" id="ws-decision-note" rows="3"></textarea>
            <div class="text-danger small mt-2" id="ws-decision-err"></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-light" data-bs-dismiss="modal">إلغاء</button>
            <button class="btn btn-primary" id="ws-decision-save">حفظ القرار</button>
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
                    <button class="btn btn-sm btn-success" data-ws-request="${r.id}" data-ws-approve="1">موافقة</button>
                    <button class="btn btn-sm btn-outline-danger" data-ws-request="${r.id}" data-ws-approve="0">رفض</button>
                </div>
            </div>`).join('')
            : '<div class="text-muted small">لا طلبات تنتظر قرارك.</div>';
    }

    let EMERGENCY_ID = null;
    let EMERGENCY_ROWS = [];

    function renderEmergencies(rows) {
        const host = $('ws-emergencies');
        if (!host) return;
        EMERGENCY_ROWS = rows;
        $('ws-emergency-count').textContent = String(rows.length);
        host.innerHTML = rows.length ? rows.map(a => `
            <div class="border border-danger-subtle rounded-3 p-3 mb-2 bg-danger-subtle bg-opacity-25">
                <div class="d-flex gap-2 flex-wrap align-items-center">
                    <strong class="text-danger">${esc(a.kind_label)}</strong>
                    <span class="badge ${a.status === 'open' ? 'bg-danger' : 'bg-warning text-dark'}">${esc(a.status_label)}</span>
                    <span class="ms-auto small text-muted" dir="ltr">${esc(a.number)} · ${esc(a.at)}</span>
                </div>
                <div class="small mt-2"><strong>${esc(a.staff)}</strong> · ${esc(a.branch)}
                    ${a.staff_username ? `· <span dir="ltr">${esc(a.staff_username)}</span>` : ''}</div>
                ${a.note ? `<div class="small mt-1">ملاحظة الموظف: ${esc(a.note)}</div>` : ''}
                <div class="small text-muted mt-1">${esc(a.geo_label)}</div>
                <button class="btn btn-sm btn-danger mt-2" data-ws-emergency="${a.id}">استلام ومعالجة</button>
            </div>`).join('')
            : '<div class="alert alert-success py-2 mb-0">لا بلاغات طوارئ مفتوحة.</div>';
    }

    async function loadEmergencies() {
        if (!$('ws-emergencies')) return;
        try { renderEmergencies(((await get('/teller/emergencies')).meta || {}).rows || []); }
        catch (e) { $('ws-emergencies').innerHTML = `<div class="alert alert-danger py-2">${esc(e.message)}</div>`; }
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
            loadEmergencies();
        }

        onBreak = (m.day_log || {}).on_break === true;
        renderBreak();

        $('ws').dataset.boot = '1';
        if (ROLE === 'teller') window.wsLogEvent('counter_opened');
    }

    $('ws-refresh').addEventListener('click', load);

    if ($('ws-emergency-refresh')) $('ws-emergency-refresh').addEventListener('click', loadEmergencies);
    if ($('ws-emergencies')) $('ws-emergencies').addEventListener('click', (e) => {
        const b = e.target.closest('[data-ws-emergency]');
        if (!b) return;
        const row = EMERGENCY_ROWS.find(x => Number(x.id) === Number(b.dataset.wsEmergency));
        if (!row) return;
        EMERGENCY_ID = row.id;
        $('ws-emergency-summary').textContent = `${row.kind_label} — ${row.staff} — ${row.branch}`;
        $('ws-emergency-status').value = row.status === 'open' ? 'acknowledged' : 'resolved';
        $('ws-emergency-note').value = '';
        $('ws-emergency-err').textContent = '';
        bootstrap.Modal.getOrCreateInstance($('ws-emergency-modal')).show();
    });
    if ($('ws-emergency-save')) $('ws-emergency-save').addEventListener('click', async () => {
        if (!EMERGENCY_ID) return;
        $('ws-emergency-err').textContent = '';
        try {
            await post(`/teller/emergencies/${EMERGENCY_ID}/decide`, {
                status: $('ws-emergency-status').value,
                note: $('ws-emergency-note').value,
            });
            bootstrap.Modal.getInstance($('ws-emergency-modal'))?.hide();
            await loadEmergencies();
            if (typeof loadOverview === 'function') loadOverview();
        } catch (e) { $('ws-emergency-err').textContent = e.message; }
    });

    // العميل الأخير يملأ خانة البحث في الشبّاك — إن كانت موجودة.
    $('ws-recent').addEventListener('click', (e) => {
        const b = e.target.closest('button[data-ws-phone]');
        if (!b) return;
        const inp = $('ct-phone');
        if (inp) { inp.value = b.dataset.wsPhone; inp.focus(); }
        const find = $('ct-find');
        if (find) find.click();
    });

    $('ws-requests').addEventListener('click', (e) => {
        const b = e.target.closest('button[data-ws-request]');
        if (!b) return;
        const approve = b.dataset.wsApprove === '1';
        $('ws-decision-id').value = b.dataset.wsRequest;
        $('ws-decision-approve').value = b.dataset.wsApprove;
        $('ws-decision-note').value = '';
        $('ws-decision-err').textContent = '';
        $('ws-decision-title').textContent = approve ? 'تأكيد الموافقة' : 'رفض الطلب';
        $('ws-decision-help').textContent = approve
            ? 'راجع المبلغ والسبب قبل تسجيل موافقتك.'
            : 'سبب الرفض إلزامي — عشرة أحرف فأكثر.';
        bootstrap.Modal.getOrCreateInstance($('ws-decision-modal')).show();
    });

    $('ws-decision-save').addEventListener('click', async () => {
        const id = $('ws-decision-id').value;
        const approve = $('ws-decision-approve').value === '1';
        $('ws-decision-err').textContent = '';
        try {
            await post(`/teller/requests/${id}/decide`, {
                approve: approve, note: $('ws-decision-note').value,
            });
            bootstrap.Modal.getInstance($('ws-decision-modal'))?.hide();
            load();
        } catch (err) { $('ws-decision-err').textContent = err.message; }
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

    // ── ساعاتُ الدوام (AMIAL-WORKTIME-001) ───────────────────────────
    let onBreak = false;

    function renderBreak() {
        const b = $('ws-break');
        b.className = onBreak
            ? 'btn btn-sm btn-warning flex-grow-1'
            : 'btn btn-sm btn-outline-warning flex-grow-1';
        b.textContent = onBreak ? '▶️ أنهِ الاستراحة' : '☕ استراحة';
    }

    $('ws-break').addEventListener('click', async () => {
        try {
            const j = await post('/teller/break', {action: onBreak ? 'end' : 'start'});
            onBreak = !onBreak;
            renderBreak();
            alert(j.message);
        } catch (e) { alert(e.message); }
    });

    $('ws-sheet').addEventListener('click', () => {
        const t = new Date();
        // الشهرُ الجاري افتراضاً: هو المدى الذي يُسأل عنه فعلاً.
        $('ws-sheet-to').value = t.toISOString().slice(0, 10);
        $('ws-sheet-from').value = new Date(t.getFullYear(), t.getMonth(), 1)
            .toISOString().slice(0, 10);
        new bootstrap.Modal($('ws-sheet-modal')).show();
        loadSheet();
    });

    $('ws-sheet-load').addEventListener('click', loadSheet);

    async function loadSheet() {
        const box = $('ws-sheet-body');
        box.innerHTML = '<div class="text-muted">جارٍ التحميل…</div>';

        let m;
        try {
            m = (await get(`/teller/timesheet?from=${$('ws-sheet-from').value}`
                + `&to=${$('ws-sheet-to').value}`)).meta || {};
        } catch (e) {
            box.innerHTML = `<div class="alert alert-danger py-2 mb-0">${esc(e.message)}</div>`;
            return;
        }

        const t = m.totals || {};
        const ot = m.overtime || {};
        const integ = m.integrity || {};

        // **إنذارُ السلسلة فوق الأرقام لا تحتها.** رقمٌ من سجلٍّ مكسور
        // لا يُقرأ قبل أن يُعرف أنّه مكسور.
        const chain = integ.ok === false
            ? `<div class="alert alert-danger py-2">
                 <strong>⛔ سجلّ الدوام غير سليم — لا تُبنَ على هذه الأرقام قرارات.</strong>
                 <div class="small">${esc(integ.reason || '')} (الحدث #${esc(integ.broken_at)})</div>
                 <div class="small">أبلِغ إدارة أميال: عُدّل السجلّ خارج النظام.</div></div>`
            : `<div class="alert alert-success py-2 small mb-2">
                 ✅ سلسلةُ السجلّ سليمة — فُحص ${esc(integ.checked)} حدثاً</div>`;

        const kpi = (label, val, sub, tone) => `<div class="col-6 col-md-3">
            <div class="card p-2 border-${tone || 'light'}">
                <div class="small text-muted">${esc(label)}</div>
                <div class="fs-5 fw-bold">${esc(val ?? '—')}</div>
                ${sub ? `<div class="small text-muted">${esc(sub)}</div>` : ''}
            </div></div>`;

        const rows = (m.daily || []).map(d => `<tr>
            <td>${esc(d.date)}</td>
            <td class="fw-bold">${esc(d.worked)}</td>
            <td>${esc(d.break)}</td>
            <td>${esc(d.expected ?? '—')}</td>
            <td>${d.overtime && d.overtime !== '0د'
                    ? `<span class="badge bg-info text-dark">${esc(d.overtime)}</span>` : '—'}</td>
            <td>${d.unverified && d.unverified !== '0د'
                    ? `<span class="badge bg-danger">${esc(d.unverified)}</span>` : '—'}</td>
            <td>${esc(d.idle)}</td>
            <td>${esc(d.sessions)}</td>
        </tr>`).join('');

        const period = (title, list) => `
            <h6 class="mt-3">${title}</h6>
            <div class="d-flex flex-wrap gap-2">
                ${(list || []).map(w => `<span class="badge bg-secondary">
                    ${esc(w.key)}: ${esc(w.worked)}</span>`).join('') || '<span class="text-muted">—</span>'}
            </div>`;

        box.innerHTML = chain
            + '<div class="row g-2 mb-3">'
            + kpi('إجمالي العمل', t.worked, `${esc(t.days_worked)} يوم`, 'success')
            + kpi('الاستراحات', t.break)
            + kpi('وقتٌ إضافيّ', t.overtime ?? '—',
                  t.overtime ? `مُقَرّ ${esc(ot.payable || '0د')}` : 'لا دوامَ مضبوط')
            + kpi('غير مؤكَّد', t.unverified, 'فوق سقف الورديّة',
                  (t.unverified_minutes || 0) > 0 ? 'danger' : null)
            + '</div>'
            + (ot.note ? `<div class="alert alert-info py-2 small">${esc(ot.note)}</div>` : '')
            // **حالةُ كلّ يومٍ إضافيّ تُعرَض بيومها.** ومجموعٌ وحده يقول
            // «لك ساعتان معلّقتان» ولا يقول أيّ يومٍ ينتظر موافقة — فلا
            // يعرف الموظّف عمّا يسأل مديره.
            + ((ot.rows || []).length
                ? `<div class="mb-2 d-flex flex-wrap gap-1">`
                  + ot.rows.map(r => `<span class="badge bg-${r.approved ? 'success' : 'warning text-dark'}">
                        ${esc(r.date)} · ${esc(r.overtime)} · ${esc(r.label)}</span>`).join('')
                  + `</div>`
                : '')
            + `<div class="table-responsive"><table class="table table-sm table-hover">
                 <thead class="table-light"><tr>
                   <th>اليوم</th><th>عمل</th><th>استراحة</th><th>المتوقَّع</th>
                   <th>إضافيّ</th><th>غير مؤكَّد</th><th>خمول</th><th>جلسات</th>
                 </tr></thead><tbody>${rows || '<tr><td colspan="8" class="text-center text-muted py-3">لا أيّام في هذا المدى</td></tr>'}</tbody>
               </table></div>`
            + period('📅 أسبوعيّاً', m.weekly)
            + period('🗓️ شهريّاً', m.monthly)
            + '<div class="form-text mt-3">«غير مؤكَّد» ساعاتٌ تجاوزت سقف الورديّة — '
            + 'تُعرَض ولا تُحذف، ويقرّرها مديرك. و«الخمول» فتراتٌ بلا عمليّات، '
            + 'تُعرَض ولا تُخصَم.</div>';
    }

    $('ws-ot-ask').addEventListener('click', async () => {
        const reason = prompt('سببُ الوقت الإضافيّ اليوم (عشرة أحرف فأكثر):');
        if (!reason) return;
        try {
            const j = await post('/teller/request', {kind: 'overtime', amount: 0, reason: reason});
            alert(j.message);
            loadSheet();
        } catch (e) { alert(e.message); }
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
