{{-- AMIAL-SHIFT-STATEMENT-001 — كشف تسوية الورديّة: مستندٌ واحدٌ لطرفين.

     **يقرؤه الصرّاف الذي رفعه، وتقرؤه الإدارة التي تقرّر فيه — بنفس
     الشيفرة حرفيّاً.** ولو رُسم لكلٍّ منهما كشفٌ على حدة لاختلف الرقمان
     يوماً، فصار الخلاف على أيّهما صحيح بدل الخلاف على الفرق نفسه. --}}

<div class="modal fade" id="stm-modal" tabindex="-1">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <div class="modal-header">
            <h5 class="modal-title" id="stm-title">كشف تسوية الورديّة</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body" id="stm-body"><div class="text-muted">جارٍ التحميل…</div></div>
    </div></div>
</div>

@once
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(function () {
    const n = (v) => Number(v || 0).toLocaleString('en-US', {maximumFractionDigits: 2});
    const e = (s) => String(s ?? '').replace(/[&<>"']/g, c =>
        ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    const REVIEW_BADGE = {
        balanced: 'bg-light text-dark border', pending: 'bg-danger',
        accepted: 'bg-success', investigating: 'bg-warning text-dark', resolved: 'bg-secondary',
    };

    // العجز والفائض يُسمَّيان ولا يُدمجان في كلمة «فرق»: من يقرأ «−٥٠٠٠»
    // يحتاج أن يعرف أنّ مالاً **نقص**، لا أن يفكّ إشارةً.
    const VARIANCE = {
        balanced: ['success', 'مطابِق — لا فرق'],
        shortage: ['danger', 'عجز — نقصَ من الدرج'],
        overage: ['warning', 'فائض — زادَ عن المتوقَّع'],
    };

    window.renderStatement = function (m) {
        const c = m.cash;
        const v = c.variance_kind ? VARIANCE[c.variance_kind] : null;

        // السلسلة: كلّ سطرٍ ومقداره والرصيد الجاري بعده. فرقمٌ نهائيٌّ بلا
        // ما يفسّره لا يُبنى عليه اتّهامٌ ولا براءة.
        const ladder = c.lines.map(l => `
            <tr>
                <td>${e(l.label)}${l.count ? ` <span class="text-muted small">(${l.count} عملية)</span>` : ''}</td>
                <td class="money ${l.direction === 'in' ? 'text-success' : 'text-danger'}">
                    ${l.direction === 'in' ? '+' : '−'} ${n(l.amount)}</td>
                <td class="money text-muted">${n(l.running)}</td>
            </tr>`).join('');

        const integrity = c.integrity.checkable
            ? (c.integrity.matches
                ? '<div class="small text-success mt-2">✓ فحص السلامة: الرصيد الجاري في النظام يطابق المحسوب من سجلّ الحركة.</div>'
                : `<div class="alert alert-danger py-2 small mt-2 mb-0">
                     ⚠️ <strong>فحص السلامة سقط:</strong> النظام يقول ${n(c.integrity.stored)}
                     وسجلّ الحركة يقول ${n(c.integrity.computed)}. الخلل هنا في النظام لا في الصرّاف
                     — أبلغ الإدارة قبل أيّ قرار.</div>`)
            : '<div class="small text-muted mt-2">فحص السلامة لا ينطبق بعد الإغلاق — الدرج سُلّم وصُفّر.</div>';

        return `
        <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
            <span class="badge bg-dark font-monospace" dir="ltr">${e(m.statement_no)}</span>
            <span class="badge bg-secondary">${e(m.staff.name)}</span>
            ${m.staff.username ? `<span class="badge bg-light text-dark border font-monospace" dir="ltr">${e(m.staff.username)}</span>` : ''}
            <span class="badge bg-info text-dark">${e(m.branch.name)}</span>
            <span class="badge ${REVIEW_BADGE[m.review.status] || 'bg-secondary'}">${e(m.review.label)}</span>
        </div>

        <div class="row g-2 small text-muted mb-3">
            <div class="col-md-4">فُتحت: ${e(m.opened_at ?? '—')}</div>
            <div class="col-md-4">أُغلقت: ${e(m.closed_at ?? 'ما زالت مفتوحة')}</div>
            <div class="col-md-4">${m.duration_minutes !== null ? 'المدّة: ' + m.duration_minutes + ' دقيقة' : ''}</div>
        </div>

        ${m.closed_at && !m.closed_by_self
            ? `<div class="alert alert-warning py-2 small">أُغلقت بالنيابة عن الصرّاف بيد ${e(m.closed_by ?? '—')}.</div>`
            : ''}

        <h6>أوّلاً — النقد الورقيّ في الدرج</h6>
        <div class="table-responsive"><table class="table table-sm align-middle">
            <thead class="table-light"><tr><th>البند</th><th>المبلغ</th><th>الرصيد الجاري</th></tr></thead>
            <tbody>${ladder}</tbody>
            <tfoot>
                <tr class="table-light fw-bold">
                    <td>المتوقَّع في الدرج</td><td class="money">${n(c.expected)}</td><td></td></tr>
                <tr class="fw-bold">
                    <td>المعدود فعلاً (شهادة الصرّاف)</td>
                    <td class="money">${c.counted === null ? '<span class="text-muted">لم يُعدّ بعد</span>' : n(c.counted)}</td>
                    <td></td></tr>
                ${v ? `<tr class="table-${v[0]} fw-bold">
                    <td>${e(v[1])}</td><td class="money">${n(c.variance)}</td><td></td></tr>` : ''}
            </tfoot>
        </table></div>
        ${integrity}

        <h6 class="mt-4">ثانياً — الرصيد الإلكترونيّ والرسوم</h6>
        <div class="table-responsive"><table class="table table-sm align-middle">
            <tbody>
                <tr><td>خرج من محفظة الفرع في الإيداعات</td>
                    <td class="money text-danger">${n(m.emoney.out_on_deposits)}</td></tr>
                <tr><td>دخل محفظة الفرع من السحوبات</td>
                    <td class="money text-success">${n(m.emoney.in_on_withdrawals)}</td></tr>
                <tr><td>الرسوم المحصّلة من العملاء</td>
                    <td class="money">${m.fees.known ? n(m.fees.collected) : '<span class="text-muted">غير معروفة</span>'}</td></tr>
                <tr><td>منها عمولة شركتك</td>
                    <td class="money text-success">${m.fees.known ? n(m.fees.agent_commission) : '<span class="text-muted">—</span>'}</td></tr>
            </tbody>
        </table></div>
        <div class="small text-muted">الرسم إلكترونيّ ولا يخرج من الدرج — فلا يظهر في سلسلة النقد أعلاه.</div>

        ${m.unknown_side.count > 0
            ? `<div class="alert alert-warning py-2 small mt-3">${e(m.unknown_side.note)}</div>` : ''}

        <h6 class="mt-4">ثالثاً — العمليات (${m.operations.length})</h6>
        <div class="table-responsive"><table class="table table-sm align-middle">
            <thead class="table-light"><tr>
                <th>الوقت</th><th>النوع</th><th>المبلغ</th><th>العميل</th>
                <th>الدرج بعدها</th><th>المرجع</th></tr></thead>
            <tbody>${m.operations.length ? m.operations.map(o => `
                <tr>
                    <td class="small">${e(o.at)}</td>
                    <td>${o.reason === 'customer_deposit'
                        ? '<span class="badge bg-success">إيداع</span>'
                        : '<span class="badge bg-primary">سحب</span>'}</td>
                    <td class="money fw-bold">${n(o.amount)}</td>
                    <td>${e(o.customer ?? '—')}<div class="small text-muted" dir="ltr">${e(o.customer_phone ?? '')}</div></td>
                    <td class="money text-muted">${n(o.drawer_after)}</td>
                    <td class="small font-monospace" dir="ltr">${e(o.reference ?? '')}</td>
                </tr>`).join('')
                : '<tr><td colspan="6" class="text-center text-muted py-3">لا عمليات في هذه الورديّة</td></tr>'}</tbody>
        </table></div>

        <h6 class="mt-4">رابعاً — الإقرار والقرار</h6>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0">
            <tbody>
                <tr><td style="width:30%">ما كتبه الصرّاف عند الإغلاق</td>
                    <td>${e(m.review.teller_note ?? '—')}</td></tr>
                <tr><td>حالة المراجعة</td>
                    <td><span class="badge ${REVIEW_BADGE[m.review.status] || 'bg-secondary'}">${e(m.review.label)}</span></td></tr>
                <tr><td>قرار الإدارة</td>
                    <td>${m.review.note ? e(m.review.note) : '<span class="text-muted">لم يُتّخذ قرارٌ بعد</span>'}</td></tr>
                <tr><td>من قرّر ومتى</td>
                    <td>${m.review.reviewed_by
                        ? e(m.review.reviewed_by) + ' — ' + e(m.review.reviewed_at ?? '')
                        : '<span class="text-muted">—</span>'}</td></tr>
            </tbody>
        </table></div>`;
    };
})();
</script>
@endonce
