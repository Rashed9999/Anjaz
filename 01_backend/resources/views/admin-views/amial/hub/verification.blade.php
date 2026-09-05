@extends('layouts.admin.app')

{{-- AMIAL-VERIFY-HUB — لوحة التحقق.
     كل حساب يسجَّل ذاتياً من التطبيق (عميل/تاجر/وكيل) يصل هنا «قيد التحقق»
     بوثائقه وبياناته، والأدمن يعتمد (يوثّق الحساب وملف التاجر) أو يرفض أو يحظر.
     القرارات كلها حقيقية: تفتح/تغلق ميزات التطبيق فوراً. --}}

@section('title', 'لوحة التحقق')

@section('content')
<div class="content container-fluid">

    <div class="d-flex align-items-center gap-3 mb-4">
        <h2 class="page-header-title mb-0">لوحة التحقق</h2>
        <span class="badge bg-warning text-dark">{{ number_format($pendingCount) }} بانتظار المراجعة</span>
        <span class="badge badge-soft-info ms-auto">AMIAL-VERIFY-HUB</span>
    </div>

    <div class="d-flex gap-2 mb-3">
        <select id="filter" class="form-select" style="max-width:240px">
            <option value="pending">قيد التحقق (الجديدة)</option>
            <option value="rejected">المرفوضة</option>
            <option value="all">الكل</option>
        </select>
        <span class="text-muted small ms-auto align-self-center" id="page-info"></span>
        <div class="btn-group">
            <button class="btn btn-sm btn-outline-secondary" id="prev-page">السابق</button>
            <button class="btn btn-sm btn-outline-secondary" id="next-page">التالي</button>
        </div>
    </div>

    {{-- ══════════════════════════════════════════════════════════════
         AMIAL-VERIFY-GOV-001 — **لافتةٌ تبقى، لا نافذةُ متصفّحٍ تذهب.**

         قِيس بمتصفّحٍ حقيقيّ: الضغطةُ تخرج طلباً، والخادمُ يردّ ٤٢٢
         برسالةٍ صحيحة، **والرسالةُ تُسلَّم بـ`alert()` وحدَها**. ومن
         أشّر يوماً على «امنع هذه الصفحة من إنشاء مربّعات حوار إضافية»
         — وهي خانةٌ يعرضها المتصفّحُ نفسُه بعد أوّل تنبيه — صار كلُّ
         رفضٍ **صامتاً**، ويُقرأ «الزرّ لا يعمل».

         فصار الأثرُ في الصفحة أوّلاً ودائماً، والنافذةُ زائدة.
    ══════════════════════════════════════════════════════════════ --}}
    <div id="verify-banner" class="mb-3"></div>

    <div class="row g-3" id="cards">
        <div class="col-12 text-muted">جارٍ التحميل…</div>
    </div>
</div>
@endsection

@push('script')
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(function () {
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const base = '{{ url('admin/amial/hub') }}';
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    let page = 1, lastPage = 1, filter = 'pending';

    const roleBadge = (r, t) => ({1: 'bg-info', 3: 'bg-primary'}[t] || 'bg-secondary');

    async function post(url, body) {
        const r = await fetch(url, {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json'},
            body: JSON.stringify(body || {}),
        });
        const j = await r.json().catch(() => ({}));
        if (!r.ok) throw new Error(j.message || ('خطأ ' + r.status));
        return j;
    }

    function card(u) {
        const info = [
            u.id_type ? `الهوية: ${esc(u.id_type)} ${esc(u.id_number ?? '')}` : null,
            u.address ? `العنوان: ${esc(u.address)}` : null,
            u.kin ? `قريب: ${esc(u.kin)}` : null,
            u.store_name ? `المتجر: ${esc(u.store_name)} (${esc(u.business_type ?? '')})` : null,
            u.merchant_number ? `رقم التاجر: ${esc(u.merchant_number)}` : null,
            u.agent_number ? `رقم الوكيل: ${esc(u.agent_number)}` : null,
            `سُجّل: ${esc(u.registered_at ?? '')}`,
        ].filter(Boolean).map(l => `<div class="small text-muted">${l}</div>`).join('');

        // ══════════════════════════════════════════════════════════
        // AMIAL-KYC-EVIDENCE-001 — **الدليلُ المعروضُ هو الدليلُ المحكومُ
        // به.**
        //
        // كان يُعرَض `u.documents` — صورُ العمود القديم — **والقرارُ
        // يُحسب من `kyc_documents`**. فيرى المراجعُ صوراً بائدةً فيعتمد،
        // أو يقرأ «لا وثائق مرفوعة» ووثائقُ العميل مرفوعةٌ في السجلّ
        // الحديث لم تُعرَض له إطلاقاً.
        //
        // **والقديمُ لا يُطوى بل يُوسَم**: طيُّه يُخفي ما قد ينفع، وخلطُه
        // يجعله يُقرأ وثيقةً معتمَدة. (القاعدة السابعة.)
        // ══════════════════════════════════════════════════════════
        const ev = u.evidence || {};

        const docs = (ev.documents || []).map(d => {
            const cls = d.status === 'approved'
                ? (d.counts ? 'bg-success' : 'bg-warning text-dark')
                : (d.status === 'rejected' ? 'bg-danger' : 'bg-secondary');
            const why = d.rejection_reason ? ` — ${esc(d.rejection_reason)}` : '';
            return `<div class="small"><span class="badge ${cls}">${esc(d.status_label)}</span>
                    ${esc(d.type_label)}<span class="text-muted">${why}</span></div>`;
        }).join('') || '<div class="text-muted small">لا وثائق في سجلّ المستندات</div>';

        // **والناقصُ يُسمّى بعينه** — «الملفّ ناقص» تُرسل المراجعَ يبحث.
        const missing = (ev.missing || []).length
            ? `<div class="small text-danger mt-1">ينقص: ${esc((ev.missing || []).join('، '))}</div>`
            : '';

        const legacy = (ev.legacy_images || []).length
            ? `<div class="mt-2">
                 <div class="small text-muted">صورٌ قديمةٌ من حقل الهويّة — <b>لا تُحتسَب في الاكتمال</b>:</div>
                 <div class="d-flex gap-2 flex-wrap mt-1">` +
               (ev.legacy_images || []).map(d =>
                 `<a href="${esc(d)}" target="_blank"><img src="${esc(d)}" style="height:56px;border-radius:6px;border:1px dashed #bbb;opacity:.75"></a>`
               ).join('') + `</div></div>`
            : '';

        // **وما يمنع الاعتمادَ يُقال قبل الضغط لا بعد الرفض** — وزرٌّ
        // يُضغط فيُردّ يُعلّم المراجعَ أن يجرّب ويرى.
        const blockers = (ev.blockers || []);
        const blocked = blockers.length > 0;
        const blockNote = blocked
            ? `<div class="alert alert-warning py-1 px-2 small mt-2 mb-0">
                 <b>لا يتمّ الاعتماد الآن:</b><ul class="mb-0 ps-3">` +
               blockers.map(b => `<li>${esc(b)}</li>`).join('') + `</ul></div>`
            : '';

        // **والمحافظةُ تُقال قبل الضغط لا بعد الرفض.**
        const govBlock = u.governorate
            ? `<div class="small text-muted">محافظة السكن: ${esc(u.governorate_name || u.governorate)}</div>`
            : `<div class="mt-2">
                 <div class="small text-danger fw-bold mb-1">⚠ محافظة السكن غير محدَّدة — الاعتماد لا يتمّ بدونها</div>
                 <select class="form-select form-select-sm" data-gov-for="${u.id}">
                   <option value="">اختر المحافظة…</option>
                   {{-- **والقائمةُ تُقرأ بشكلها لا بما يُفترَض**: `all()`
                        تُرجع صفوفاً (`code`/`name`) لا `code => name`.
                        وأوّلُ صياغةٍ هنا افترضت الثانية فأخرجت ٥٠٠
                        (‏`htmlspecialchars(): array given`) — والبطاقاتُ
                        كلُّها اختفت. --}}
                   @foreach(\App\Support\YemenGovernorates::all() as $g)
                   <option value="{{ $g['code'] }}">{{ $g['name'] }}</option>
                   @endforeach
                 </select>
               </div>`;

        const state = u.kyc === 1 ? '<span class="badge bg-success">موثّق</span>'
            : (u.kyc === 2 ? '<span class="badge bg-danger">مرفوض</span>'
            : '<span class="badge bg-warning text-dark">قيد التحقق</span>');

        return `<div class="col-md-6 col-lg-4"><div class="card stat-card h-100">
            <div class="card-body d-flex flex-column">
                <div class="d-flex justify-content-between align-items-start mb-1">
                    <div>
                        <span class="badge ${roleBadge(u.role, u.type)}">${esc(u.role)}</span>
                        <strong class="ms-1">${esc(u.name)}</strong>
                    </div>
                    ${state}
                </div>
                <div class="small text-muted mb-1" dir="ltr">${esc(u.phone)}</div>
                ${info}
                ${govBlock}
                <div class="my-2">${docs}${missing}</div>
                ${legacy}
                ${blockNote}
                <div class="mt-auto d-flex gap-2">
                    ${u.kyc !== 1 ? `<button class="btn btn-sm btn-success flex-fill" data-act="approve" data-id="${u.id}"
                        ${blocked && u.governorate ? 'disabled' : ''}
                        title="${blocked ? esc(blockers.join(' · ')) : 'اعتماد الحساب'}">اعتماد</button>` : ''}
                    ${u.kyc !== 2 ? `<button class="btn btn-sm btn-outline-danger flex-fill" data-act="reject" data-id="${u.id}">رفض</button>` : ''}
                    <button class="btn btn-sm ${u.is_active ? 'btn-outline-dark' : 'btn-dark'}" data-act="block" data-id="${u.id}">
                        ${u.is_active ? 'حظر' : 'فكّ الحظر'}</button>
                </div>
            </div>
        </div></div>`;
    }

    async function load() {
        const wrap = document.getElementById('cards');
        wrap.innerHTML = '<div class="col-12 text-muted">جارٍ التحميل…</div>';
        const r = await fetch(`${base}/verification/list.json?filter=${filter}&page=${page}`,
            {headers: {'Accept': 'application/json'}});
        const j = await r.json();
        lastPage = j.last_page;
        document.getElementById('page-info').textContent = `${j.total} حساب — صفحة ${j.current_page} من ${j.last_page}`;
        wrap.innerHTML = j.data.length ? j.data.map(card).join('')
            : '<div class="col-12 text-muted py-4 text-center">لا حسابات في هذا التصنيف 🎉</div>';
    }

    // **الأثرُ في الصفحة أوّلاً ودائماً** — ونافذةُ المتصفّح تُحذف:
    // خانةُ «امنع مربّعات الحوار» تُطفئها إلى الأبد بلا أثر.
    function banner(msg, kind) {
        const box = document.getElementById('verify-banner');
        box.innerHTML = `<div class="alert alert-${kind} d-flex align-items-start gap-2 mb-0">
            <span>${kind === 'danger' ? '⛔' : '✓'}</span><div>${esc(msg)}</div></div>`;
        box.scrollIntoView({behavior: 'smooth', block: 'nearest'});
        if (kind !== 'danger') setTimeout(() => { box.innerHTML = ''; }, 6000);
    }

    document.getElementById('cards').addEventListener('click', async (e) => {
        const btn = e.target.closest('button[data-act]');
        if (!btn) return;
        const id = btn.dataset.id;
        try {
            if (btn.dataset.act === 'approve') {
                // **والمحافظةُ تُرسَل من البطاقة نفسِها.**
                //
                // النقطةُ تقبل `governorate` منذ بُنيت، **ولا مرسِلَ لها**:
                // فيُردّ المراجعُ ٤٢٢ ولا يجد باباً يُصلحه منه. وكانت
                // الرسالةُ تحيله إلى «طابور مراجعة الهوية» بلا رابط.
                const sel = document.querySelector(`select[data-gov-for="${id}"]`);
                const gov = sel ? sel.value : '';

                if (sel && !gov) {
                    banner('اختر محافظة السكن من البطاقة أوّلاً — الاعتماد لا يتمّ بدونها.', 'danger');
                    sel.focus();
                    return;
                }

                const j = await post(`${base}/users/${id}/kyc`,
                    gov ? {status: 1, governorate: gov} : {status: 1});
                banner(j.message || 'اعتُمد الحساب', 'success');
            } else if (btn.dataset.act === 'reject') {
                const reason = prompt('سبب رفض الوثائق (سيظهر للعميل ويُسجّل في التدقيق):');
                if (reason === null) return;               // أُلغيت الرسالة
                if (reason.trim().length < 5) {
                    banner('سبب رفض واضح مطلوب (5 أحرف على الأقل).', 'danger'); return;
                }
                const j = await post(`${base}/users/${id}/kyc`, {status: 2, reason});
                banner(j.message || 'رُفضت الوثائق', 'success');
            } else if (btn.dataset.act === 'block') {
                const reason = prompt('سبب الحظر/فكّ الحظر (يُسجَّل في التدقيق):');
                if (reason === null) return;
                const j = await post(`${base}/users/${id}/toggle-active`, {reason});
                banner(j.message || 'تمّ', 'success');
            }
            load();
        } catch (err) { banner(err.message, 'danger'); }
    });

    document.getElementById('filter').addEventListener('change', (e) => { filter = e.target.value; page = 1; load(); });
    document.getElementById('prev-page').addEventListener('click', () => { if (page > 1) { page--; load(); } });
    document.getElementById('next-page').addEventListener('click', () => { if (page < lastPage) { page++; load(); } });

    load();
})();
</script>
@endpush
