@extends('layouts.admin.app')
{{-- AMIAL-ZONE-PANEL-001: سياسة المناطق كانت كلها غير مرئية — جداول لا يفتحها أحد --}}
@section('title', translate('لوحة المناطق'))
@section('content')
<div class="content container-fluid" dir="rtl">

    <div class="d-flex justify-content-between align-items-center flex-wrap mb-1">
        <h4 class="fw-bold mb-0" style="color:var(--amial-primary)">🗺️ {{ translate('لوحة المناطق') }}</h4>
        <span class="badge" id="modeBadge" style="background:var(--amial-text-secondary)">…</span>
    </div>
    <p class="text-muted small mb-3">
        اليمن فيه بنكان مركزيان وعملتان بسعرين. الضبط يقع عند <strong>عبور النقد</strong>
        (وكيل أو تاجر) لا عند موقع العميل: التحويل والاستقبال يبقيان عاملَين لأن القيمة
        لا تغادر دفتراً واحداً.
    </p>

    {{-- ===== الأرقام الحيّة ===== --}}
    <div class="row g-3 mb-3" id="statCards">
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100" style="border-radius:14px">
                <div class="card-body">
                    <div class="text-muted small">ضمن نطاق التشغيل</div>
                    <div class="fw-bold" style="font-size:26px;color:#0F9D58" id="cSouth">—</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100" style="border-radius:14px">
                <div class="card-body">
                    <div class="text-muted small">خارج النطاق</div>
                    <div class="fw-bold" style="font-size:26px;color:#CFA300" id="cOutside">—</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100" style="border-radius:14px">
                <div class="card-body">
                    <div class="text-muted small">معتمد بلا منطقة <span class="text-danger">(عالق)</span></div>
                    <div class="fw-bold" style="font-size:26px;color:var(--amial-danger)" id="cStranded">—</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100" style="border-radius:14px">
                <div class="card-body">
                    <div class="text-muted small">مخالفات موقع الوكلاء (30 يوماً)</div>
                    <div class="fw-bold" style="font-size:26px;color:var(--amial-danger)" id="cViolations">—</div>
                </div>
            </div>
        </div>
    </div>

    {{-- ===== الحسابات العالقة ===== --}}
    <div class="card border-0 shadow-sm mb-3" style="border-radius:16px" id="strandedCard" hidden>
        <div class="card-body">
            <h6 class="fw-bold text-danger">⚠️ حسابات معتمدة بلا منطقة تشغيل</h6>
            <p class="text-muted small">
                معتمدة ولا تستطيع تنفيذ عملية واحدة. سببها أن الاعتماد كان لا يُسند
                المنطقة (أُصلح) — وهذه بقايا اعتُمدت قبل الإصلاح. الإسناد يقرأ محافظة
                السكن المسجّلة.
            </p>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead><tr>
                        <th>#</th><th>الاسم</th><th>الهاتف</th><th>الدور</th>
                        <th>محافظة السكن</th><th></th>
                    </tr></thead>
                    <tbody id="strandedBody"></tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- ===== جدول المحافظات ===== --}}
    <div class="card border-0 shadow-sm mb-3" style="border-radius:16px">
        <div class="card-body">
            <h6 class="fw-bold mb-1">نطاق التشغيل والتغطية</h6>
            <p class="text-muted small mb-3">
                نطاق التشغيل يُضبط بمتغيّر البيئة <code>AMIAL_OPERATIONAL_GOVERNORATES</code> —
                خريطة السيطرة تتغيّر، وتغييرها إعداد لا إصدار برمجي.
                <br>
                <strong>التغطية الصفرية ليست حجباً:</strong> العميل هناك يستقبل ويحوّل،
                وما يتوقّف هو السحب والدفع لانعدام الوكيل والتاجر.
            </p>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead><tr>
                        <th>المحافظة</th><th>النطاق</th>
                        <th>عملاء</th><th>وكلاء</th><th>تجار</th><th>التغطية</th>
                    </tr></thead>
                    <tbody>
                    @foreach($operational as $g)
                        <tr @if($g['operational']) style="background:#F4FBF6" @endif>
                            <td class="fw-bold">{{ $g['name'] }}</td>
                            <td>
                                @if($g['operational'])
                                    <span class="badge" style="background:#0F9D58">ضمن النطاق</span>
                                @else
                                    <span class="badge bg-secondary">خارجه</span>
                                @endif
                            </td>
                            <td>{{ number_format($g['customers']) }}</td>
                            <td>{{ number_format($g['agents']) }}</td>
                            <td>{{ number_format($g['merchants']) }}</td>
                            <td>
                                @if($g['agents'] === 0 && $g['merchants'] === 0)
                                    <span class="text-muted small">لا وكلاء ولا تجار</span>
                                @elseif($g['agents'] === 0)
                                    <span class="small" style="color:#CFA300">بلا وكلاء — لا سحب</span>
                                @elseif($g['merchants'] === 0)
                                    <span class="small" style="color:#CFA300">بلا تجار — لا دفع</span>
                                @else
                                    <span class="small" style="color:#0F9D58">كاملة</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- ===== السجلّات ===== --}}
    <div class="card border-0 shadow-sm" style="border-radius:16px">
        <div class="card-body">
            <ul class="nav nav-pills mb-3" id="evTabs">
                <li class="nav-item"><a class="nav-link active" href="#" data-t="blocked">عمليات مرفوضة بالمنطقة</a></li>
                <li class="nav-item"><a class="nav-link" href="#" data-t="violations">مخالفات موقع الوكلاء</a></li>
                <li class="nav-item"><a class="nav-link" href="#" data-t="assignments">إسناد المناطق</a></li>
                <li class="nav-item"><a class="nav-link" href="#" data-t="sink">حسابات تستقبل ولا تُخرج</a></li>
            </ul>
            <div id="evNote" class="text-muted small mb-2"></div>
            <div class="table-responsive"><table class="table table-sm align-middle">
                <thead id="evHead"></thead><tbody id="evBody"></tbody>
            </table></div>
        </div>
    </div>
</div>

<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(function () {
    const base = '{{ url('admin/amial/hub/zones') }}';
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c =>
        ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const num = (n) => Number(n || 0).toLocaleString('en-US');

    const MODES = {
        soft:   ['موقع الوكلاء: متساهل',  '#CFA300'],
        strict: ['موقع الوكلاء: صارم',   '#0F9D58'],
        off:    ['موقع الوكلاء: معطّل',  '#DC0A0B'],
    };

    async function loadSummary() {
        const r = await fetch(`${base}/summary.json`, {headers: {'Accept': 'application/json'}});
        const j = await r.json();

        document.getElementById('cSouth').textContent = num(j.zones.SOUTH);
        document.getElementById('cOutside').textContent =
            num(j.zones.NORTH + j.zones.MIDDLE + j.zones.OTHER);
        document.getElementById('cStranded').textContent = num(j.stranded.count);
        document.getElementById('cViolations').textContent = num(j.agent_violations_30d);

        const [label, color] = MODES[j.agent_location_mode] || MODES.soft;
        const badge = document.getElementById('modeBadge');
        badge.textContent = label;
        badge.style.background = color;

        if (j.stranded.count > 0) {
            document.getElementById('strandedCard').hidden = false;
            document.getElementById('strandedBody').innerHTML = j.stranded.sample.map(u => `
                <tr>
                    <td>${u.id}</td><td>${esc(u.name)}</td>
                    <td dir="ltr">${esc(u.phone)}</td><td>${esc(u.role)}</td>
                    <td>${u.governorate ? esc(u.governorate) : '<span class="text-danger">غير مسجّلة</span>'}</td>
                    <td>${u.fixable
                        ? `<button class="btn btn-sm btn-success" data-fix="${u.id}">إسناد المنطقة</button>`
                        : '<span class="text-muted small">سجّل محافظة السكن أولاً</span>'}</td>
                </tr>`).join('');
        }
    }

    const HEADS = {
        blocked:     ['المستخدم', 'الهاتف', 'السبب', 'المنطقة', 'التاريخ'],
        violations:  ['الوكيل', 'الهاتف', 'المحافظة', 'الخطورة', 'التاريخ'],
        assignments: ['المستخدم', 'الهاتف', 'المنطقة', 'الطريقة', 'التاريخ'],
        sink:        ['المستخدم', 'التنبيه', 'التفاصيل', 'الحالة', 'التاريخ'],
    };
    const NOTES = {
        blocked: 'عمليات رفضتها سياسة المناطق. الرفض هنا يخصّ العمليات النقدية وحدها — التحويل والاستقبال لا يُحجبان.',
        violations: 'وكيل حاول تنفيذ عملية نقدية من خارج نطاق التشغيل. هذه أخطر ما في اللوحة: تعني صرفاً بسعر خاطئ.',
        assignments: 'تاريخ إسناد المناطق — يبيّن مصدر كل قرار (اعتماد KYC، قرار أدمن، تسجيل).',
        sink: 'حسابات تستقبل باستمرار ولا تسحب ولا تدفع. مشروعة تماماً، لكنها تحفّز التسوية خارج التطبيق — للمراجعة لا للحجب.',
    };

    async function loadEvents(type) {
        document.getElementById('evNote').textContent = NOTES[type] || '';
        document.getElementById('evHead').innerHTML =
            '<tr>' + HEADS[type].map(h => `<th>${h}</th>`).join('') + '</tr>';

        const r = await fetch(`${base}/events.json?type=${type}`, {headers: {'Accept': 'application/json'}});
        const rows = (await r.json()).data || [];

        if (!rows.length) {
            document.getElementById('evBody').innerHTML =
                `<tr><td colspan="5" class="text-muted text-center py-4">لا سجلّات</td></tr>`;
            return;
        }

        document.getElementById('evBody').innerHTML = rows.map(x => {
            if (type === 'sink') return `<tr>
                <td>${x.user_id}</td><td>${esc(x.title)}</td>
                <td class="small">${esc(x.message)}</td>
                <td><span class="badge bg-warning text-dark">${esc(x.status)}</span></td>
                <td class="small">${esc(x.at)}</td></tr>`;
            if (type === 'assignments') return `<tr>
                <td>${x.user_id}</td><td dir="ltr">${esc(x.phone)}</td>
                <td>${esc(x.zone)}</td><td class="small">${esc(x.method)}</td>
                <td class="small">${esc(x.at)}</td></tr>`;
            if (type === 'violations') return `<tr>
                <td>${x.user_id ?? '—'}</td><td dir="ltr">${esc(x.phone)}</td>
                <td>${x.governorate ? esc(x.governorate) : '—'}</td>
                <td><span class="badge bg-danger">${esc(x.severity)}</span></td>
                <td class="small">${esc(x.at)}</td></tr>`;
            return `<tr>
                <td>${x.user_id ?? '—'}</td><td dir="ltr">${esc(x.phone)}</td>
                <td class="small">${esc(x.reason ?? x.code)}</td>
                <td>${esc(x.zone ?? '—')}</td>
                <td class="small">${esc(x.at)}</td></tr>`;
        }).join('');
    }

    document.getElementById('evTabs').addEventListener('click', (e) => {
        const a = e.target.closest('[data-t]');
        if (!a) return;
        e.preventDefault();
        document.querySelectorAll('#evTabs .nav-link').forEach(n => n.classList.remove('active'));
        a.classList.add('active');
        loadEvents(a.dataset.t);
    });

    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-fix]');
        if (!btn) return;
        btn.disabled = true;
        try {
            const r = await fetch(`${base}/users/${btn.dataset.fix}/reassign`, {
                method: 'POST',
                headers: {'X-CSRF-TOKEN': csrf, 'Accept': 'application/json',
                          'Content-Type': 'application/json'},
                body: '{}',
            });
            const j = await r.json();
            if (!r.ok) throw new Error(j.message || 'خطأ');
            btn.outerHTML = `<span class="text-success small">${esc(j.message)}</span>`;
            loadSummary();
        } catch (err) {
            btn.disabled = false;
            alert(err.message);
        }
    });

    loadSummary();
    loadEvents('blocked');
})();
</script>
@endsection
