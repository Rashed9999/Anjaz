@extends('layouts.admin.app')

{{-- AMIAL-PLAN-CATALOG-001 — كتالوج الباقات والميزات.
     الباقة: اسم · سعر شهري · مدد بخصم · ميزات أميال أعمال (مربّعات) · حدود · ورتبتها لقدرات التطبيق.
     الحفظ يُبلَّغ لمشتركي الباقة في أميال أعمال فوراً (entitlements.changed). --}}

@section('title', 'كتالوج الباقات والميزات')

@section('content')
<div class="content container-fluid">
    <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
        <h2 class="page-header-title mb-0">كتالوج الباقات والميزات</h2>
        <a href="{{ route('admin.amial.hub.subscriptions') }}" class="btn btn-sm btn-outline-secondary ms-auto">💎 اشتراكات التجّار</a>
        <button class="btn btn-sm btn-primary" id="btn-new">+ باقة جديدة</button>
    </div>
    <p class="text-muted small mb-4">
        الأسعار بـ{{ $currencyLabel }}. «الرتبة» تحدّد قدرات تطبيق أميال باي للتاجر، و«ميزات أميال أعمال» ما تفتحه الباقة هناك.
        الباقات الثلاث النظاميّة لا تُوقف ولا يتغيّر رمزها أو رتبتها. والباقة الموقوفة لا تُباع لجديد، ومشتركوها باقون حتى نهاية مددهم.
    </p>

    <div class="row g-3" id="plans-grid">
        <div class="col-12 text-center text-muted py-5">جارٍ التحميل…</div>
    </div>
</div>

<div class="modal fade" id="modal-plan" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title" id="plan-modal-title">باقة</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div>
        <div class="modal-body">
            <form id="plan-form" novalidate>
                <div class="row g-3">
                    <div class="col-md-4"><label class="form-label" for="f-code">الرمز (لا يتغيّر بعد الإنشاء)</label>
                        <input class="form-control" id="f-code" dir="ltr" placeholder="pro_plus" pattern="[a-z][a-z0-9_]*" maxlength="32"></div>
                    <div class="col-md-4"><label class="form-label" for="f-name-ar">الاسم بالعربية</label>
                        <input class="form-control" id="f-name-ar" maxlength="64" required></div>
                    <div class="col-md-4"><label class="form-label" for="f-name-en">الاسم بالإنجليزية</label>
                        <input class="form-control" id="f-name-en" dir="ltr" maxlength="64" required></div>
                    <div class="col-12"><label class="form-label" for="f-desc">وصف قصير</label>
                        <input class="form-control" id="f-desc" maxlength="255"></div>
                    <div class="col-md-4"><label class="form-label" for="f-price">السعر الشهري ({{ $currencyLabel }})</label>
                        <input class="form-control" id="f-price" type="number" min="0" step="0.01" dir="ltr" required></div>
                    <div class="col-md-4"><label class="form-label" for="f-tier">الرتبة (قدرات التطبيق)</label>
                        <select class="form-select" id="f-tier">
                            @foreach($tiers as $t)<option value="{{ $t['code'] }}">{{ $t['label'] }}</option>@endforeach
                        </select></div>
                    <div class="col-md-4"><label class="form-label" for="f-sort">ترتيب العرض</label>
                        <input class="form-control" id="f-sort" type="number" min="0" max="999" value="10" dir="ltr"></div>
                    <div class="col-md-4"><label class="form-label" for="f-users">حدّ المستخدمين (فارغ = بلا حد)</label>
                        <input class="form-control" id="f-users" type="number" min="1" dir="ltr"></div>
                    <div class="col-md-4"><label class="form-label" for="f-products">حدّ المنتجات (فارغ = بلا حد)</label>
                        <input class="form-control" id="f-products" type="number" min="1" dir="ltr"></div>
                    <div class="col-md-4 d-flex align-items-end"><div class="form-check">
                        <input class="form-check-input" type="checkbox" id="f-public" checked>
                        <label class="form-check-label" for="f-public">معروضة للتجّار</label></div></div>

                    <div class="col-12">
                        <label class="form-label fw-bold">ميزات أميال أعمال</label>
                        <div class="row g-2" id="f-features">
                            @foreach($features as $f)
                                <div class="col-md-4 col-6"><div class="form-check">
                                    <input class="form-check-input feature-box" type="checkbox" value="{{ $f['code'] }}" id="feat-{{ $f['code'] }}">
                                    <label class="form-check-label" for="feat-{{ $f['code'] }}">{{ $f['name'] }}</label>
                                </div></div>
                            @endforeach
                        </div>
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-bold">المدد والخصم</label>
                        <div class="row g-2">
                            @foreach([1, 3, 6, 12, 24] as $m)
                                <div class="col-md-4 col-6"><div class="input-group input-group-sm">
                                    <span class="input-group-text"><input class="form-check-input mt-0 me-1 dur-box" type="checkbox" value="{{ $m }}" id="dur-{{ $m }}" aria-label="مدة {{ $m }}"> {{ $m }} {{ $m === 1 ? 'شهر' : 'أشهر' }}</span>
                                    <input type="number" class="form-control dur-discount" data-months="{{ $m }}" min="0" max="90" step="0.5" value="0" dir="ltr" aria-label="خصم {{ $m }}">
                                    <span class="input-group-text">%</span>
                                </div></div>
                            @endforeach
                        </div>
                        <div class="small text-muted mt-2" id="f-preview"></div>
                    </div>
                </div>
            </form>
            <div class="text-danger small mt-3" id="plan-error" role="alert"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
            <button type="button" class="btn btn-primary" id="plan-save">حفظ</button>
        </div>
    </div></div>
</div>
@endsection

@push('script')
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(function () {
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const base = '{{ url('admin/amial/hub/plans') }}';
    const cur = @json($currencyLabel);
    const featureNames = @json(collect($features)->pluck('name', 'code'));
    const tierNames = @json(collect($tiers)->pluck('label', 'code'));
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const $ = (id) => document.getElementById(id);
    let plans = [], editing = null;

    async function send(url, body) {
        const r = await fetch(url, {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json'}, body: JSON.stringify(body || {})});
        const j = await r.json().catch(() => ({}));
        if (!r.ok) throw new Error(j.errors ? Object.values(j.errors).flat().join(' · ') : (j.message || ('خطأ ' + r.status)));
        return j;
    }

    async function load() {
        const j = await (await fetch(`${base}/list.json`, {headers: {'Accept': 'application/json'}})).json();
        plans = j.data;
        $('plans-grid').innerHTML = plans.map(p => `
            <div class="col-xl-4 col-md-6"><div class="card stat-card h-100 ${p.is_active ? '' : 'opacity-50'}">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div><h5 class="mb-0">${esc(p.name_ar)}</h5><small class="text-muted" dir="ltr">${esc(p.code)}</small></div>
                        <div class="text-end">
                            ${p.is_system ? '<span class="badge bg-secondary">نظاميّة</span>' : ''}
                            ${p.is_active ? '' : '<span class="badge bg-danger">موقوفة</span>'}
                            ${p.is_public ? '' : '<span class="badge bg-warning text-dark">مخفيّة</span>'}
                        </div>
                    </div>
                    <div class="fs-4 fw-bold mt-2"><span dir="ltr">${esc(p.monthly_price)}</span> <small class="fs-6 fw-normal">${esc(cur)} / شهر</small></div>
                    <div class="small text-muted">الرتبة: ${esc(tierNames[p.base_tier] ?? p.base_tier)} · مشتركون: ${p.subscribers}</div>
                    <div class="small mt-2">${p.prices.map(x => `<span class="badge bg-light text-dark border me-1">${x.months} ش: <span dir="ltr">${esc(x.price)}</span></span>`).join('')}</div>
                    <div class="small mt-2">المستخدمون: ${p.business_limits.users ?? 'بلا حد'} · المنتجات: ${p.business_limits.products ?? 'بلا حد'}</div>
                    <ul class="small mt-2 mb-0 ps-3">${p.business_features.map(c => `<li>${esc(featureNames[c] ?? c)}</li>`).join('') || '<li class="text-muted">لا ميزات</li>'}</ul>
                </div>
                <div class="card-footer d-flex gap-2">
                    <button class="btn btn-sm btn-outline-primary" data-act="edit" data-id="${p.id}">تعديل</button>
                    ${p.is_system ? '' : `<button class="btn btn-sm btn-outline-${p.is_active ? 'danger' : 'success'}" data-act="toggle" data-id="${p.id}">${p.is_active ? 'إيقاف' : 'تفعيل'}</button>`}
                </div>
            </div></div>`).join('');
    }

    function preview() {
        const price = Number($('f-price').value || 0);
        const parts = [...document.querySelectorAll('.dur-box:checked')].map(b => {
            const m = Number(b.value), d = Number(document.querySelector(`.dur-discount[data-months="${m}"]`).value || 0);
            return `${m} ش = ${(price * m * (1 - d / 100)).toFixed(2)} ${cur}`;
        });
        $('f-preview').textContent = parts.length ? 'سيدفع التاجر: ' + parts.join(' · ') : 'اختر مدّةً واحدة على الأقل.';
    }

    function open(plan) {
        editing = plan;
        $('plan-error').textContent = '';
        $('plan-modal-title').textContent = plan ? `تعديل باقة «${plan.name_ar}»` : 'باقة جديدة';
        $('f-code').value = plan?.code ?? ''; $('f-code').disabled = !!plan;
        $('f-name-ar').value = plan?.name_ar ?? ''; $('f-name-en').value = plan?.name_en ?? '';
        $('f-desc').value = plan?.description_ar ?? ''; $('f-price').value = plan?.monthly_price ?? '';
        $('f-tier').value = plan?.base_tier ?? 'business'; $('f-tier').disabled = !!plan?.is_system;
        $('f-sort').value = plan?.sort_order ?? 10; $('f-public').checked = plan ? !!plan.is_public : true;
        $('f-users').value = plan?.business_limits?.users ?? ''; $('f-products').value = plan?.business_limits?.products ?? '';
        document.querySelectorAll('.feature-box').forEach(b => b.checked = (plan?.business_features ?? []).includes(b.value));
        document.querySelectorAll('.dur-box').forEach(b => {
            const d = (plan?.durations ?? [{months: 1, discount_percent: 0}]).find(x => Number(x.months) === Number(b.value));
            b.checked = !!d; document.querySelector(`.dur-discount[data-months="${b.value}"]`).value = d ? d.discount_percent : 0;
        });
        preview();
        bootstrap.Modal.getOrCreateInstance('#modal-plan').show();
    }

    $('plan-form').addEventListener('input', preview);
    $('btn-new').addEventListener('click', () => open(null));
    $('plans-grid').addEventListener('click', async (e) => {
        const btn = e.target.closest('button[data-act]');
        if (!btn) return;
        const plan = plans.find(p => p.id === Number(btn.dataset.id));
        if (btn.dataset.act === 'edit') return open(plan);
        // نافذة المشروع المصمَّمة (وعدٌ يُنتظر) لا نافذة المتصفّح — partials/_app_dialogs.
        const label = plan.is_active ? 'إيقاف' : 'تفعيل';
        if (!await amialDialog.ask(plan.is_active ? `إيقاف «${plan.name_ar}»؟ لن تُباع لتاجرٍ جديد، ومشتركوها باقون حتى نهاية مددهم.` : `تفعيل «${plan.name_ar}»؟`,
            {okLabel: label, danger: plan.is_active})) return;
        try { alert((await send(`${base}/${plan.id}/toggle`)).message); load(); } catch (err) { alert(err.message); }
    });

    $('plan-save').addEventListener('click', async () => {
        $('plan-error').textContent = '';
        const num = (id) => $(id).value === '' ? null : Number($(id).value);
        const body = {
            name_ar: $('f-name-ar').value.trim(), name_en: $('f-name-en').value.trim(), description_ar: $('f-desc').value.trim() || null,
            monthly_price: $('f-price').value, sort_order: Number($('f-sort').value || 0), is_public: $('f-public').checked,
            limits: {users: num('f-users'), products: num('f-products')},
            business_features: [...document.querySelectorAll('.feature-box:checked')].map(b => b.value),
            durations: [...document.querySelectorAll('.dur-box:checked')].map(b => ({months: Number(b.value), discount_percent: Number(document.querySelector(`.dur-discount[data-months="${b.value}"]`).value || 0)})),
        };
        if (!editing) { body.code = $('f-code').value.trim(); body.base_tier = $('f-tier').value; }
        else if (!editing.is_system) { body.base_tier = $('f-tier').value; }
        const btn = $('plan-save'); btn.disabled = true;
        try {
            const j = await send(editing ? `${base}/${editing.id}` : base, body);
            bootstrap.Modal.getInstance($('modal-plan')).hide(); alert(j.message); load();
        } catch (err) { $('plan-error').textContent = err.message; }
        finally { btn.disabled = false; }
    });

    load();
})();
</script>
@endpush
