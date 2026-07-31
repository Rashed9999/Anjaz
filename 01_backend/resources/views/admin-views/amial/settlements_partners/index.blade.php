@extends('layouts.admin.app')

{{--
    AMIAL-SETTLEMENT-PANEL-001 — تسويات الشركاء.

    غير «لوحة التسويات» الموجودة: تلك تسويات الوكلاء (`AgentSettlement`).
    هذه تسويات الشركاء (`Settlement`) — وعليها بُنيت **الموافقة المزدوجة**
    وفصلُ المهام، وكان سطحها الوحيد الـAPI.

    والأثر لم يكن نقصَ شاشةٍ فحسب: تسويةٌ فوق الحدّ يوافق عليها موظّف فتبقى
    «بانتظار الاعتماد» بلا ما يقول إنّ توقيعاً أوّل سُجّل وإنّ ثانياً مطلوب.
    فمن لا يعرف يظنّ الاعتماد فشل ويعيد الضغط — والنظام يرفضه لأنه هو نفسه،
    فيبدو الضابط عطلاً.

    ولذلك أوّل ما يُقرأ في كلّ صفٍّ هنا هو **حالة التوقيعين**، لا المبلغ.
--}}

@section('title', 'تسويات الشركاء')

@section('content')
<div class="content container-fluid" id="ps-panel" data-testid="ps-panel">

    <div class="d-flex align-items-center gap-3 mb-4">
        <i class="tio-receipt text-primary" style="font-size:24px"></i>
        <h2 class="page-header-title mb-0">تسويات الشركاء</h2>
        <span class="badge badge-soft-secondary ms-auto">Settlement</span>
    </div>

    <div class="alert alert-secondary py-2 small">
        <strong>ضابطان يعملان هنا:</strong>
        من أنشأ التسوية أو قدّمها لا يعتمدها،
        والتسوية فوق الحدّ تحتاج <strong>توقيعين من شخصين مختلفين</strong> —
        الأوّل يُسجَّل وتبقى الحالة «بانتظار الاعتماد» حتى يأتي الثاني.
    </div>

    <div class="card p-3 mb-3">
        <div class="d-flex gap-2 flex-wrap align-items-center">
            <select id="ps-status" class="form-select" style="max-width:220px">
                <option value="pending_approval">بانتظار الاعتماد</option>
                <option value="approved">معتمدة</option>
                <option value="processing">قيد التنفيذ</option>
                <option value="completed">مكتملة</option>
                <option value="rejected">مرفوضة</option>
                <option value="draft">مسودّات</option>
                <option value="">الكل</option>
            </select>
            <button class="btn btn-outline-primary" id="ps-refresh" data-testid="ps-refresh">تحديث</button>
            <span class="ms-auto" id="ps-awaiting-badge"></span>
        </div>
    </div>

    <div class="card">
        <div id="ps-list" class="table-responsive" data-testid="ps-list"></div>
    </div>
</div>

<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(function () {
    const BASE = '{{ url('admin/amial/partner-settlements') }}';
    const CSRF = '{{ csrf_token() }}';
    const esc = s => String(s ?? '—').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const nm = u => u ? esc(((u.f_name || '') + ' ' + (u.l_name || '')).trim() || ('#' + u.id)) : '—';

    async function get(path) {
        const r = await fetch(BASE + path, {headers: {'Accept': 'application/json'}});
        return r.json();
    }
    async function post(path, body) {
        const r = await fetch(BASE + path, {
            method: 'POST',
            headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF},
            body: JSON.stringify(body || {}),
        });
        return r.json();
    }

    const statusLabel = {
        draft: 'مسودّة', pending_approval: 'بانتظار الاعتماد', approved: 'معتمدة',
        processing: 'قيد التنفيذ', completed: 'مكتملة', rejected: 'مرفوضة', cancelled: 'ملغاة',
    };
    const statusColor = {
        draft: 'secondary', pending_approval: 'warning text-dark', approved: 'primary',
        processing: 'info', completed: 'success', rejected: 'dark', cancelled: 'dark',
    };

    /**
     * حالة التوقيعين — العمود الذي كان غائباً تماماً.
     *
     * ثلاث حالات لا حالتان: «توقيع واحد يكفي»، و«وقّع الأوّل وينتظر ثانياً»،
     * و«اكتمل التوقيعان». وخلطُ الثانية بالأولى هو ما جعل الضابط يبدو عطلاً.
     */
    function approvalCell(s) {
        const req = parseInt(s.approvals_required || 1, 10);
        if (req < 2) {
            return s.approved_by
                ? `<span class="badge bg-success">وقّع ${nm(s.approver)}</span>`
                : '<span class="text-muted small">توقيع واحد يكفي</span>';
        }
        if (!s.approved_by) {
            return '<span class="badge bg-warning text-dark">يحتاج توقيعين — لم يوقّع أحد</span>';
        }
        if (!s.second_approved_by) {
            return `<div>
                <span class="badge bg-warning text-dark">بانتظار التوقيع الثاني</span>
                <div class="small text-muted mt-1">وقّع أوّلاً: ${nm(s.approver)}</div>
                <div class="small text-danger">لا يوقّع الثانية هو نفسه</div>
            </div>`;
        }
        return `<div>
            <span class="badge bg-success">توقيعان مكتملان</span>
            <div class="small text-muted mt-1">${nm(s.approver)} + ${nm(s.secondApprover || s.second_approver)}</div>
        </div>`;
    }

    document.getElementById('ps-refresh').onclick = load;
    document.getElementById('ps-status').onchange = load;

    async function load() {
        const st = document.getElementById('ps-status').value;
        const box = document.getElementById('ps-list');
        box.innerHTML = '<div class="p-4 text-muted">جارٍ التحميل…</div>';
        const j = await get('/list' + (st ? '?status=' + encodeURIComponent(st) : ''));
        if (!j.success) { box.innerHTML = `<div class="alert alert-warning m-3">${esc(j.message)}</div>`; return; }

        const items = (j.meta && j.meta.settlements) || [];

        // ما ينتظر توقيعاً ثانياً يُعدّ ويُقال في الأعلى: هو أخطر ما في
        // الطابور — مالٌ اعتُمد نصفَ اعتماد وتوقّف، وقد يُنسى.
        const awaiting = items.filter(s =>
            parseInt(s.approvals_required || 1, 10) >= 2 && s.approved_by && !s.second_approved_by);
        document.getElementById('ps-awaiting-badge').innerHTML = awaiting.length
            ? `<span class="badge bg-warning text-dark" data-testid="ps-awaiting">${awaiting.length} تنتظر توقيعاً ثانياً</span>`
            : '';

        const rows = items.map(s => `
            <tr class="${(parseInt(s.approvals_required || 1, 10) >= 2 && s.approved_by && !s.second_approved_by) ? 'table-warning' : ''}">
                <td class="font-monospace small">#${s.id}</td>
                <td>${esc(s.partner ? s.partner.name_ar : '—')}</td>
                <td class="fw-bold">${esc(s.amount)} <span class="small text-muted">${esc(s.currency || 'YER')}</span></td>
                <td><span class="badge bg-${statusColor[s.status] || 'secondary'}">${esc(statusLabel[s.status] || s.status)}</span></td>
                <td>${approvalCell(s)}</td>
                <td class="small">${nm(s.creator)}</td>
                <td class="small">${esc((s.created_at || '').toString().slice(0, 16).replace('T', ' '))}</td>
                <td class="text-nowrap">${actions(s)}</td>
            </tr>`).join('');

        box.innerHTML = `<table class="table table-sm table-hover align-middle mb-0">
            <thead class="thead-light"><tr>
                <th>#</th><th>الشريك</th><th>المبلغ</th><th>الحالة</th>
                <th>التوقيعات</th><th>المُنشئ</th><th>التاريخ</th><th></th>
            </tr></thead>
            <tbody>${rows || '<tr><td colspan="8" class="text-muted text-center py-4">لا تسويات في هذه الحالة</td></tr>'}</tbody>
        </table>`;
    }

    function actions(s) {
        const b = (act, label, cls) =>
            `<button class="btn btn-sm btn-${cls} js-ps" data-do="${act}" data-id="${s.id}" data-amount="${esc(s.amount)}">${label}</button>`;
        switch (s.status) {
            case 'draft':            return b('submit', 'تقديم للاعتماد', 'outline-primary');
            case 'pending_approval': return b('approve', 'اعتماد', 'success') + ' ' + b('reject', 'رفض', 'outline-danger');
            case 'approved':         return b('process', 'بدء التنفيذ', 'outline-info');
            case 'processing':       return b('complete', 'تأكيد التحويل', 'outline-success');
            default:                 return '';
        }
    }

    document.addEventListener('click', async function (e) {
        const b = e.target.closest('.js-ps');
        if (!b) return;
        const id = b.dataset.id, act = b.dataset.do;
        let body = {};

        if (act === 'reject') {
            const reason = prompt('سبب الرفض (إلزامي — 10 أحرف على الأقل):');
            if (!reason || reason.trim().length < 10) { alert('سبب الرفض إلزامي (10 أحرف على الأقل)'); return; }
            body = {reason: reason.trim()};
        } else if (act === 'complete') {
            // مرجع خارجيّ إلزاميّ: تسويةٌ «مكتملة» بلا مرجعٍ بنكيّ لا تُطابَق
            // مع كشف الشريك، فتصير إتماماً على الورق وحده.
            const ref = prompt('المرجع الخارجي للتحويل (إلزامي — رقم الحوالة/الإشعار البنكي):');
            if (!ref || ref.trim().length < 3) { alert('المرجع الخارجي إلزامي'); return; }
            body = {external_reference: ref.trim(), external_bank_ref: prompt('مرجع بنكي إضافي (اختياري):') || null};
        } else if (act === 'approve') {
            if (!confirm(`اعتماد تسوية بمبلغ ${b.dataset.amount}؟\n\nإن كانت فوق حدّ الموافقة المزدوجة، فهذا توقيعٌ واحد ولن تُعتمد حتى يوقّع موظّف آخر.`)) return;
            body = {notes: prompt('ملاحظة الاعتماد (اختياري):') || null};
        }

        const j = await post(`/${id}/${act}`, body);
        alert(j.message || (j.success ? 'تم' : 'فشل'));
        load();
    });

    load();
})();
</script>
@endsection
