@extends('layouts.admin.app')

@section('title', 'مركز حدود المستخدمين')

@section('content')
<div class="content container-fluid" id="limit-center">
    <div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
        <div><h2 class="page-header-title mb-1">مركز حدود المستخدمين</h2>
            <p class="text-muted mb-0">الحدود يقررها الخادم؛ هنا نعرضها ونعدلها بسببٍ موثّق.</p></div>
        <span class="badge badge-soft-info ms-auto">AMIAL-USER-LIMIT-CENTER-001</span>
    </div>
    <div class="row g-3 mb-4">
        <div class="col-md-3 col-6"><div class="card p-3 h-100"><small>العملاء</small><strong class="fs-3" id="stat-customers">…</strong><span class="small text-muted" id="stat-overrides"></span></div></div>
        <div class="col-md-3 col-6"><div class="card p-3 h-100"><small>التجار</small><strong class="fs-3" id="stat-merchants">…</strong><span class="small text-muted" id="stat-verified"></span></div></div>
        <div class="col-md-3 col-6"><div class="card p-3 h-100"><small>الوكلاء</small><strong class="fs-3" id="stat-agents">…</strong><span class="small text-muted" id="stat-active"></span></div></div>
        <div class="col-md-3 col-6"><div class="card p-3 h-100"><small>سقف شحن التسوية للطلب</small><strong class="fs-5" id="stat-settlement">…</strong><span class="small text-muted">يخضع للاعتماد والرصيد</span></div></div>
    </div>
    <div class="card">
        <div class="card-header d-flex gap-2 flex-wrap align-items-center">
            <div class="btn-group" role="group">
                <button class="btn btn-primary kind-btn" data-kind="customer">العملاء</button>
                <button class="btn btn-outline-primary kind-btn" data-kind="merchant">التجار</button>
                <button class="btn btn-outline-primary kind-btn" data-kind="agent">الوكلاء</button>
            </div>
            <input id="search" class="form-control ms-auto" style="max-width:280px" placeholder="بحث بالاسم أو الهاتف">
        </div>
        <div class="table-responsive"><table class="table table-hover align-middle mb-0">
            <thead class="table-light"><tr><th>الحساب</th><th>الرصيد</th><th>الحدود المطبقة</th><th>الحالة</th><th></th></tr></thead>
            <tbody id="rows"><tr><td colspan="5" class="text-center text-muted py-4">جارٍ التحميل…</td></tr></tbody>
        </table></div>
    </div>
</div>

<div class="modal fade" id="limit-modal" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><div class="modal-content">
    <form id="limit-form"><div class="modal-header"><h5 class="modal-title">تعديل الحدود</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><div id="limit-fields" class="row g-3"></div>
        <div class="mt-3"><label class="form-label">سبب التعديل</label><textarea class="form-control" id="reason" required minlength="10" maxlength="500" placeholder="سبب واضح يمكن مراجعته لاحقاً"></textarea></div>
        <div class="text-danger small mt-2" id="form-error"></div></div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">إلغاء</button><button class="btn btn-primary" type="submit">حفظ وتوثيق</button></div>
    </form></div></div></div>
@endsection

@push('script')
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(() => {
 const base='{{ url('admin/amial/hub/limits') }}', csrf=document.querySelector('meta[name="csrf-token"]').content;
 const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','>':'&gt;','<':'&lt;','"':'&quot;',"'":'&#39;'}[c]));
 const money=n=>Number(n??0).toLocaleString('en-US',{maximumFractionDigits:2})+' ر.ي';
 let kind='customer', selected=null, modal;
 const limits = u => { const p=u.profile||{}; if(kind==='customer'){const l=(u.override&&Object.keys(u.override).length?u.override:u.kyc?.limits)||{}; return `عملية: ${money(l.max_single_transaction)}<br>يومي: ${money(l.max_daily_total)}<br>شهري: ${money(l.max_monthly_total)}`}; if(kind==='merchant') return `عملية: ${money(p.single_receive_limit)}<br>يومي: ${money(p.daily_receive_limit)}<br>شهري: ${money(p.monthly_receive_limit)}`; return `عملية: ${money(p.single_transaction_limit)}<br>إيداع: ${money(p.daily_cash_in_limit)}<br>سحب: ${money(p.daily_cash_out_limit)}`};
 const state = u => kind==='customer' ? (u.kyc?.tier_name||'—') : (kind==='merchant' ? (u.profile?.verification_status||'بلا ملف') : (u.profile?.status||'بلا ملف'));
 async function load(){const q=encodeURIComponent(document.getElementById('search').value); const r=await fetch(`${base}/users.json?kind=${kind}&search=${q}`); const j=await r.json(); document.getElementById('rows').innerHTML=(j.users||[]).map(u=>`<tr><td><strong>${esc(u.name||'—')}</strong><br><small class="text-muted">${esc(u.phone)}</small></td><td>${money(u.balance)}</td><td class="small">${limits(u)}</td><td>${esc(state(u))}</td><td><button class="btn btn-sm btn-outline-primary edit" data-id="${u.id}">تعديل</button></td></tr>`).join('')||'<tr><td colspan="5" class="text-center py-4 text-muted">لا توجد حسابات</td></tr>'; window.centerUsers=j.users||[];}
 async function overview(){const r=await fetch(`${base}/overview.json`), j=await r.json(); document.getElementById('stat-customers').textContent=j.customers.total; document.getElementById('stat-overrides').textContent=`${j.customers.overrides} استثناءات فردية`; document.getElementById('stat-merchants').textContent=j.merchants.total; document.getElementById('stat-verified').textContent=`${j.merchants.verified} موثّق`; document.getElementById('stat-agents').textContent=j.agents.total; document.getElementById('stat-active').textContent=`${j.agents.active} نشط`; document.getElementById('stat-settlement').textContent=money(j.settlement.max_topup_per_request);}
 function field(name,label,value){return `<div class="col-12"><label class="form-label">${label}</label><input class="form-control limit-input" type="number" min="0" step="0.0001" name="${name}" value="${esc(value??0)}" required></div>`}
 document.querySelectorAll('.kind-btn').forEach(b=>b.onclick=()=>{kind=b.dataset.kind; document.querySelectorAll('.kind-btn').forEach(x=>x.className='btn btn-outline-primary kind-btn');b.className='btn btn-primary kind-btn';load();});
 document.getElementById('search').addEventListener('input',()=>{clearTimeout(window.st);window.st=setTimeout(load,250)});
 document.getElementById('rows').onclick=e=>{const b=e.target.closest('.edit');if(!b)return;selected=(window.centerUsers||[]).find(u=>u.id==b.dataset.id);if(!selected)return;let p=selected.profile||{},l=(selected.override&&Object.keys(selected.override).length?selected.override:selected.kyc?.limits)||{};document.getElementById('limit-fields').innerHTML=kind==='customer'?field('max_balance','الحد الأقصى للرصيد',l.max_balance)+field('max_single_transaction','حد العملية الواحدة',l.max_single_transaction)+field('max_daily_total','الحد اليومي',l.max_daily_total)+field('max_monthly_total','الحد الشهري',l.max_monthly_total):kind==='merchant'?field('single_receive_limit','حد الاستلام للعملية',p.single_receive_limit)+field('daily_receive_limit','حد الاستلام اليومي',p.daily_receive_limit)+field('monthly_receive_limit','حد الاستلام الشهري',p.monthly_receive_limit):field('single_transaction_limit','حد العملية الواحدة',p.single_transaction_limit)+field('daily_cash_in_limit','حد الإيداع النقدي اليومي',p.daily_cash_in_limit)+field('daily_cash_out_limit','حد السحب النقدي اليومي',p.daily_cash_out_limit);document.getElementById('reason').value='';document.getElementById('form-error').textContent='';modal.show();};
 document.getElementById('limit-form').onsubmit=async e=>{e.preventDefault(); const data=new FormData(e.target);data.append('reason',document.getElementById('reason').value);const r=await fetch(`${base}/users/${selected.id}`,{method:'POST',headers:{'X-CSRF-TOKEN':csrf,'Accept':'application/json','X-Idempotency-Key':`limit-${selected.id}-${Date.now()}`},body:data});const j=await r.json();if(!r.ok){document.getElementById('form-error').textContent=j.message||'تعذر الحفظ';return;}modal.hide();load();};
 modal=new bootstrap.Modal(document.getElementById('limit-modal')); overview();load();
})();
</script>
@endpush
