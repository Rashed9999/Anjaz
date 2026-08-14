@extends('layouts.admin.app')

{{-- AMIAL-ADMIN-HUB-001 — صفحة تفاصيل الحساب الكاملة.
     بيانات شخصية + وثائق + محفظة + حالة المخاطر (AML: سليم/مشبوه/خطر/خطر جداً)
     + سجل عمليات، وإضافات حسب الدور (تاجر: موظفون/فروع/مبيعات؛ وكيل: تسويات). --}}

@section('title', $roleLabel . ' — تفاصيل الحساب')

@section('content')
<div class="content container-fluid" id="acc-root" data-id="{{ $accountId }}">
    <div class="d-flex align-items-center gap-2 mb-3">
        <a href="javascript:history.back()" class="btn btn-sm btn-outline-secondary">→ رجوع</a>
        <h3 class="mb-0" id="acc-title">تفاصيل {{ $roleLabel }}</h3>
        <span class="badge badge-soft-info ms-auto">AMIAL-HUB-001</span>
    </div>
    <div id="acc-body"><div class="text-muted">جارٍ التحميل…</div></div>
</div>
@endsection

@push('script')
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(function () {
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const base = '{{ url('admin/amial/hub') }}';
    const id = document.getElementById('acc-root').dataset.id;
    const fmt = (n) => Number(n || 0).toLocaleString('en-US', {maximumFractionDigits: 2});
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    const riskColor = {'سليم':'success','مشبوه':'warning','خطر':'danger','خطر جداً':'danger'};

    async function post(url, body) {
        const r = await fetch(url, {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json'}, body: JSON.stringify(body || {})});
        const j = await r.json().catch(() => ({}));
        if (!r.ok) throw new Error(j.message || ('خطأ ' + r.status));
        return j;
    }

    function row(label, value) {
        if (value === null || value === undefined || value === '') return '';
        return `<div class="d-flex justify-content-between border-bottom py-1"><span class="text-muted small">${esc(label)}</span><span class="fw-500">${esc(value)}</span></div>`;
    }

    // AMIAL-MERCHANT-360-001 — صفٌّ بقيمةٍ **مبنيّةٍ عندنا** لا واردةٍ من
    // مستخدم. و`row` تُهرّب قيمتَها دائماً — وهو الصواب لبيانات الحساب —
    // فلو مُرّرت إليها «<span>غير متاح</span>» لظهر الوسمُ نصّاً على الشاشة.
    //
    // **ولا تُنادى هذه بقيمةٍ من الخادم**: مدخلاتُها من `money()`/`num()`
    // وحدهما، وكلتاهما تُهرّب ما يأتي من البيانات قبل أن تلفّه.
    function rowHtml(label, valueHtml) {
        if (valueHtml === null || valueHtml === undefined || valueHtml === '') return '';
        return `<div class="d-flex justify-content-between border-bottom py-1"><span class="text-muted small">${esc(label)}</span><span class="fw-500">${valueHtml}</span></div>`;
    }

    function card(title, inner) {
        return `<div class="card stat-card mb-3"><div class="card-header fw-bold">${esc(title)}</div><div class="card-body">${inner}</div></div>`;
    }

    async function load() {
        const r = await fetch(`${base}/users/${id}/detail.json`, {headers: {'Accept': 'application/json'}});
        if (!r.ok) { document.getElementById('acc-body').innerHTML = '<div class="alert alert-danger">تعذّر تحميل الحساب</div>'; return; }
        const u = await r.json();
        document.getElementById('acc-title').textContent = `${u.role_label}: ${u.name}`;

        const risk = u.risk || {};
        const rc = riskColor[risk.label] || 'secondary';

        // ===== رأس: الحالة + المخاطر + المحفظة =====
        let html = `<div class="row g-3 mb-1">
            <div class="col-md-4"><div class="card stat-card p-3 text-center">
                <small class="text-muted">حالة الحساب</small>
                <div class="fs-5 fw-bold ${u.is_active ? 'text-success' : 'text-danger'}">${u.is_active ? 'نشِط' : 'مجمَّد'}</div>
                <button class="btn btn-sm mt-2 ${u.is_active ? 'btn-outline-danger' : 'btn-outline-success'}" id="btn-freeze"
                        data-frozen="${u.is_active ? '0' : '1'}">
                    ${u.is_active ? 'تجميد الحساب' : 'فكّ التجميد'}</button>
            </div></div>
            <div class="col-md-4"><div class="card stat-card p-3 text-center border-${rc}" style="border-width:2px">
                <small class="text-muted">حالة المخاطر (AML)</small>
                <div class="fs-4 fw-bold text-${rc}">${esc(risk.label)}</div>
                <div class="small text-muted">درجة الخطورة: ${fmt(risk.score)}</div>
                ${risk.is_dangerous && u.is_active ? '<button class="btn btn-sm btn-danger mt-2" id="btn-freeze-risk">إيقاف فوري (خطر)</button>' : ''}
            </div></div>
            <div class="col-md-4"><div class="card stat-card p-3 text-center">
                <small class="text-muted">الرصيد المتاح</small>
                <div class="fs-4 fw-bold text-primary">${fmt(u.wallet.current)} ر.ي</div>
                <div class="small text-muted">محجوز: ${fmt(u.wallet.held)} · معلّق: ${fmt(u.wallet.pending)}</div>
            </div></div>
        </div>`;

        // ===== البيانات الشخصية =====
        html += card('البيانات الشخصية',
            row('الهاتف', u.phone) + row('البريد', u.email) + row('الجنس', u.gender === 'male' ? 'ذكر' : (u.gender === 'female' ? 'أنثى' : u.gender)) +
            row('المهنة', u.occupation) + row('العنوان', u.address) + row('رقم الهوية', u.national_id) +
            row('نوع الهوية', u.id_type) + row('رقم الوكيل', u.agent_number) + row('قريب', u.kin) +
            row('التوثيق', ({0:'قيد التحقق',1:'موثّق',2:'مرفوض'})[u.kyc] || '—') + row('تاريخ التسجيل', u.created_at));

        // ===== الوثائق =====
        const docs = (u.documents || []).map(d => `<a href="${esc(d)}" target="_blank"><img src="${esc(d)}" style="height:90px;border-radius:8px;border:1px solid #ddd;margin:4px"></a>`).join('');
        html += card('وثائق الهوية', docs || '<span class="text-muted small">لا وثائق مرفوعة</span>');

        // ===== إضافات التاجر =====
        if (u.merchant) {
            const m = u.merchant;
            html += card('بيانات المتجر',
                row('اسم المتجر', m.store_name) + row('رقم التاجر', m.merchant_number) + row('النشاط', m.business_type) +
                row('الباقة', m.plan) + row('التوثيق', m.verification) + row('انتهاء الاشتراك', m.subscription_expires) +
                row('إجمالي المبيعات', fmt(m.sales_total) + ' ر.ي') + row('عدد المبيعات', m.sales_count));

            // ══════════════════════════════════════════════════════════
            //  AMIAL-MERCHANT-360-001 — الماليّ ثمّ التشغيليّ ثمّ الحدود.
            //
            //  **والفراغُ يُقال ولا يُعرض صفراً** (القاعدة السابعة):
            //  جدولٌ غيرُ موجودٍ في المخطّط يُرجع `null` من الخدمة، وهو
            //  «غير متاح» — لا «صفر ريال».
            // ══════════════════════════════════════════════════════════
            const t = u.merchant360 || {};

            const money = (v) => v === null || v === undefined
                ? '<span class="text-muted">غير متاح</span>' : fmt(v) + ' ر.ي';
            const num = (v) => v === null || v === undefined
                ? '<span class="text-muted">غير متاح</span>' : esc(v);

            if (t.financial) {
                const f = t.financial;
                html += card('الأداء المالي',
                    rowHtml('إجمالي المبيعات', money(f.sales_total)) +
                    rowHtml('مبيعات ٣٠ يوماً', money(f.sales_30d)) +
                    rowHtml('عدد العمليات', num(f.sales_count)) +
                    rowHtml('متوسّط العملية', money(f.sales_avg)) +
                    rowHtml('رسومٌ دفعها للمنصّة', money(f.fees_paid)) +
                    rowHtml('رسوم ٣٠ يوماً', money(f.fees_paid_30d)) +
                    rowHtml('مرتجعات', money(f.refunds_total)) +
                    rowHtml('فواتير مفتوحة', num(f.invoices_open)) +
                    rowHtml('مستحقٌّ على الفواتير', money(f.invoices_due)) +
                    rowHtml('تسويات معلّقة', num(f.settlements_pending)));
            }

            if (t.operations) {
                const o = t.operations;
                let opsBody;

                if (o.missing) {
                    // **حسابُ محطّةٍ بلا محطّة** — يُقال صراحةً مع طريق الخروج.
                    opsBody = `<div class="alert alert-warning mb-0 small">${esc(o.missing)} —
                               يُبنى تلقائياً عند أوّل فتحٍ للوحة القطاع في التطبيق.</div>`;
                } else if (!(o.metrics || []).length) {
                    opsBody = '<span class="text-muted small">لا مؤشّرات قطاعيّة لهذا النشاط</span>';
                } else {
                    opsBody = '<div class="row g-2">' + o.metrics.map(x =>
                        `<div class="col-6 col-md-4"><div class="border rounded p-2 text-center">
                           <div class="fs-5 fw-bold">${num(x.value)}</div>
                           <div class="small text-muted">${esc(x.label)}</div>
                           ${x.hint ? `<div class="small text-warning">${esc(x.hint)}</div>` : ''}
                         </div></div>`).join('') + '</div>';
                }

                html += card('تفاصيل العمل — ' + esc(o.label || o.vertical || '—'), opsBody);
            }

            if (t.limits) {
                const l = t.limits;
                html += card('حدود الاستقبال',
                    rowHtml('الحدّ اليوميّ', money(l.daily_receive)) +
                    rowHtml('حدّ العمليّة الواحدة', money(l.single_receive)) +
                    rowHtml('الحدّ الشهريّ', money(l.monthly_receive)) +
                    rowHtml('يسمح بالتحويل للخارج', l.can_transfer_out === null
                        ? '<span class="text-muted">غير معروف</span>'
                        : (l.can_transfer_out ? 'نعم' : 'لا')));
            }

            const devs = (t.devices || []).map(d =>
                `<tr><td>${esc(d.device)}</td><td class="text-monospace small">${esc(d.device_id_tail ?? '—')}</td>` +
                `<td class="text-monospace small">${esc(d.ip ?? '—')}</td>` +
                `<td>${d.is_active ? '<span class="badge bg-success">نشِط</span>' : '<span class="badge bg-secondary">منتهٍ</span>'}</td>` +
                `<td class="small text-muted">${esc(d.last_seen)}</td></tr>`).join('');
            html += card('الأجهزة (آخر ١٠)', devs
                ? `<div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>الجهاز</th><th>المعرّف</th><th>IP</th><th>الحالة</th><th>آخر ظهور</th></tr></thead><tbody>${devs}</tbody></table></div>`
                : '<span class="text-muted small">لا أجهزة مسجّلة</span>');

            const staff = (m.staff || []).map(s => `<tr><td>${esc(s.display_name ?? '—')}</td><td>${esc(s.pos_number ?? '—')}</td><td>${esc(s.branch ?? '—')}</td><td>${s.is_active ? '<span class="badge bg-success">نشِط</span>' : '<span class="badge bg-danger">معطَّل</span>'}</td></tr>`).join('');
            html += card('الموظفون (نقاط البيع)', staff ? `<div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>الموظف</th><th>رقم النقطة</th><th>الفرع</th><th>الحالة</th></tr></thead><tbody>${staff}</tbody></table></div>` : '<span class="text-muted small">لا موظفين</span>');

            const branches = (m.branches || []).map(b => `<tr><td>${esc(b.name ?? '—')}</td><td>${esc(b.code ?? '—')}</td><td>${esc(b.city ?? '—')}</td><td>${b.is_active ? '<span class="badge bg-success">نشِط</span>' : '<span class="badge bg-secondary">مغلق</span>'}</td></tr>`).join('');
            html += card('الفروع', branches ? `<div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>الفرع</th><th>الرمز</th><th>المدينة</th><th>الحالة</th></tr></thead><tbody>${branches}</tbody></table></div>` : '<span class="text-muted small">لا فروع</span>');

            // AMIAL-RETAIL-VERTICAL-001 · المرحلة ١ — آخر المبيعات **بأسطرها**.
            // وتكلفةٌ غير معروفة تُكتب «—» ولا تُعرض صفراً (القاعدة ٧).
            const sales = (m.recent_sales || []).map(s => {
                const lines = (s.lines || []).map(l =>
                    `<tr class="small"><td class="ps-4">${esc(l.name)}</td>` +
                    `<td>${esc(l.quantity)} × ${fmt(l.unit_price)}</td>` +
                    `<td>${fmt(l.line_total)}</td>` +
                    `<td>${l.unit_cost === null ? '<span class="text-warning">غير معروفة</span>' : fmt(l.unit_cost)}</td>` +
                    `<td class="text-muted">${esc(l.cost_source)}</td></tr>`).join('');
                const warn = s.unknown_cost_lines > 0
                    ? ` <span class="badge bg-warning text-dark">${s.unknown_cost_lines} سطراً بلا تكلفة</span>` : '';
                return `<tr class="table-light"><td class="font-monospace small">${esc(s.ulid.slice(-8))}</td>` +
                    `<td>${esc(s.method)}</td><td class="fw-bold">${fmt(s.total)} ر.ي</td>` +
                    `<td>تكلفة: ${fmt(s.known_cost)}${warn}</td><td class="small">${esc(s.created_at ?? '')}</td></tr>` +
                    (lines || '<tr class="small"><td colspan="5" class="ps-4 text-muted">بيعة بمبلغ حرّ — بلا أصناف</td></tr>');
            }).join('');
            html += card('آخر المبيعات بأسطرها (١٠)', sales
                ? `<div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>المرجع</th><th>الطريقة/الصنف</th><th>المبلغ</th><th>التكلفة</th><th>التاريخ</th></tr></thead><tbody>${sales}</tbody></table></div>`
                : '<span class="text-muted small">لا مبيعات مسجّلة</span>');
        }

        // ===== إضافات الوكيل =====
        if (u.agent) {
            const a = u.agent;
            html += card('بيانات الوكيل',
                row('المستوى', a.agent_level) + row('الحالة', a.status) + row('نسبة العمولة', a.commission_rate + '%') +
                row('حد الإيداع اليومي', fmt(a.daily_cash_in_limit) + ' ر.ي') + row('حد السحب اليومي', fmt(a.daily_cash_out_limit) + ' ر.ي') +
                row('عدد الوكلاء الفرعيين', a.sub_agents_count));

            const sett = (a.settlements || []).map(s => `<tr><td class="small font-monospace">${esc(s.ulid)}</td><td>${esc(s.type)}</td><td>${fmt(s.amount)} ر.ي</td><td>${esc(s.status)}</td><td class="small">${esc(s.created_at)}</td></tr>`).join('');
            html += card('آخر التسويات', sett ? `<div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>المرجع</th><th>النوع</th><th>المبلغ</th><th>الحالة</th><th>التاريخ</th></tr></thead><tbody>${sett}</tbody></table></div>` : '<span class="text-muted small">لا تسويات</span>');
        }

        // ===== سجل العمليات =====
        const tx = (u.transactions || []).map(t => `<tr><td class="small font-monospace">${esc(t.transaction_id)}</td><td>${esc(t.transaction_type)}</td><td class="text-danger">${fmt(t.debit)}</td><td class="text-success">${fmt(t.credit)}</td><td>${fmt(t.balance)}</td><td class="small">${esc(t.created_at ?? '')}</td></tr>`).join('');
        html += card('سجل العمليات (آخر 25)', tx ? `<div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>المرجع</th><th>النوع</th><th>مدين</th><th>دائن</th><th>الرصيد</th><th>التاريخ</th></tr></thead><tbody>${tx}</tbody></table></div>` : '<span class="text-muted small">لا عمليات</span>');

        document.getElementById('acc-body').innerHTML = html;

        // أزرار التجميد
        // AMIAL-UI-DIALOGS-002 — **نافذةُ المشروع لا نافذةُ المتصفّح.**
        //
        // كانت `prompt()` تفتح نافذةَ المتصفّح البيضاء («يعرض موقع
        // amialpay.com»)، ثمّ يظهر النجاحُ في نافذة المشروع المصمَّمة —
        // نافذتان مختلفتان في ضغطتين متتاليتين.
        //
        // **والإلغاءُ يُحترم**: `prompt()` كانت تُرجع `null` عند الإلغاء،
        // و`|| ''` تحوّله إلى نصٍّ فارغ — فيُجمَّد الحسابُ بسببٍ فارغ
        // **ولو ضغط المديرُ «إلغاء»**. وهو تجميدُ حسابٍ لم يُطلَب.
        const doFreeze = async () => {
            const frozen = document.getElementById('btn-freeze')?.dataset.frozen === '1';

            const reason = await amialDialog.request(
                'اكتب سببَ ' + (frozen ? 'فكّ التجميد' : 'التجميد') + ' — يُسجَّل في التدقيق:',
                { title: frozen ? 'فكّ تجميد الحساب' : 'تجميد الحساب',
                  okLabel: frozen ? 'فكّ التجميد' : 'جمّد الحساب',
                  danger: ! frozen });

            if (reason === null) return;   // أُلغي — ولا يُنفَّذ شيء

            try { const j = await post(`${base}/users/${id}/toggle-active`, {reason}); await amialDialog.show(j.message); load(); }
            catch (err) { await amialDialog.show(err.message); }
        };
        document.getElementById('btn-freeze')?.addEventListener('click', doFreeze);
        document.getElementById('btn-freeze-risk')?.addEventListener('click', doFreeze);
    }

    load();
})();
</script>
@endpush
