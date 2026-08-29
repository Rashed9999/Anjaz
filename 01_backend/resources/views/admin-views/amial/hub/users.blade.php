@extends('layouts.admin.app')

{{-- AMIAL-ADMIN-HUB-001 — لوحة مركزية موحّدة (عملاء / وكلاء / تجّار).
     تعمل بالكامل على JSON endpoints في AdminHubController. --}}

@section('title', $hubTitle)

@section('content')
<div class="content container-fluid" id="hub-root"
     data-slug="{{ $hubSlug }}" data-type="{{ $hubType }}">

    <div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
        <h2 class="page-header-title mb-0">{{ $hubTitle }}</h2>
        @if($hubSlug === 'agents')
            {{-- الرابط الذي كان ناقصاً: البوّابة مبنيّة، ولم يكن إليها بابٌ من هنا. --}}
            <a href="{{ route('agent.login') }}" target="_blank" rel="noopener"
               class="btn btn-sm btn-dark">🏦 فتح بوّابة الوكيل (شركات الصرافة)</a>
        @endif
        <span class="badge badge-soft-info ms-auto">AMIAL-HUB-001</span>
    </div>

    {{-- إحصاءات --}}
    <div class="row g-3 mb-4">
        <div class="col-md-3 col-6"><div class="card stat-card p-3">
            <small class="text-muted">الإجمالي</small>
            <div class="fs-3 fw-bold">{{ number_format($stats['total']) }}</div>
        </div></div>
        <div class="col-md-3 col-6"><div class="card stat-card p-3">
            <small class="text-muted">نشِط</small>
            <div class="fs-3 fw-bold text-success">{{ number_format($stats['active']) }}</div>
        </div></div>
        <div class="col-md-3 col-6"><div class="card stat-card p-3">
            <small class="text-muted">مجمَّد</small>
            <div class="fs-3 fw-bold text-danger">{{ number_format($stats['frozen']) }}</div>
        </div></div>
        <div class="col-md-3 col-6"><div class="card stat-card p-3">
            <small class="text-muted">وثائق بانتظار الاعتماد</small>
            <div class="fs-3 fw-bold text-warning">{{ number_format($stats['kyc_pending']) }}</div>
        </div></div>
    </div>
    <div class="card stat-card p-3 mb-4 d-flex flex-row justify-content-between align-items-center">
        <span class="text-muted">إجمالي أرصدة المحافظ لهذه الفئة</span>
        <span class="fs-4 fw-bold">{{ number_format((float) $stats['balance_sum'], 2) }} ر.ي</span>
    </div>

    @if($hubSlug === 'agents')
        @include('admin-views.amial.hub._agent_network_cards')
    @endif

    {{-- تبويبات --}}
    <ul class="nav nav-tabs mb-3">
        <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tab-list">القائمة</a></li>
        @if($hubSlug === 'agents')
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-branches" id="branches-tab-link">الفروع والخزائن</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-cash" id="cash-tab-link">حركة النقد</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-daily" id="daily-tab-link">🌙 إقفال اليوم</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-settlement" id="settlement-tab-link">⚖️ التوازن والتسوية</a></li>
        @endif
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-kyc" id="kyc-tab-link">طلبات التوثيق</a></li>
    </ul>

    <div class="tab-content">
        {{-- ===== تبويب القائمة ===== --}}
        <div class="tab-pane fade show active" id="tab-list">
            <div class="card stat-card">
                <div class="card-header d-flex gap-2 flex-wrap align-items-center">
                    <input type="text" id="search-box" class="form-control" style="max-width:280px"
                           placeholder="بحث بالاسم أو الهاتف أو الرقم…">
                    <button class="btn btn-primary ms-auto" data-bs-toggle="modal" data-bs-target="#modal-add">
                        + إضافة {{ $hubType == 1 ? 'وكيل' : ($hubType == 3 ? 'تاجر' : 'عميل') }}
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light"><tr>
                            <th>#</th><th>الاسم</th><th>الهاتف</th><th>الرصيد الإلكترونيّ</th>
                            @if($hubSlug === 'agents')
                            <th>الفروع</th><th>النقد في الدرج</th>
                            @endif
                            <th>التوثيق</th><th>الحالة</th><th>إجراءات</th>
                        </tr></thead>
                        <tbody id="users-tbody">
                            <tr><td colspan="9" class="text-center text-muted py-4">جارٍ التحميل…</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer d-flex justify-content-between align-items-center">
                    <span class="text-muted small" id="page-info"></span>
                    <div class="btn-group">
                        <button class="btn btn-sm btn-outline-secondary" id="prev-page">السابق</button>
                        <button class="btn btn-sm btn-outline-secondary" id="next-page">التالي</button>
                    </div>
                </div>
            </div>
        </div>

        @if($hubSlug === 'agents')
            @include('admin-views.amial.hub._agent_tabs')
        @endif

        {{-- ===== تبويب التوثيق ===== --}}
        <div class="tab-pane fade" id="tab-kyc">
            <div id="kyc-cards" class="row g-3">
                <div class="col-12 text-muted">جارٍ التحميل…</div>
            </div>
        </div>
    </div>
</div>

{{-- ===== Modal: إضافة مستخدم ===== --}}
<div class="modal fade" id="modal-add" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
        <div class="modal-header"><div><h5 class="modal-title mb-1">ملف فتح {{ $hubType == 3 ? 'منشأة' : ($hubType == 1 ? 'وكيل' : 'عميل') }}</h5>
            <small class="text-muted">نموذج واحد: تُحفظ البيانات في الحساب ونسخة إلكترونية قابلة للطباعة في الأرشيف.</small></div>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <form class="modal-body" id="opening-dossier-form" enctype="multipart/form-data">
            <div class="alert alert-warning small py-2">الحساب يُنشأ <strong>قيد مراجعة الامتثال</strong>، ولا تُعدّ الورقة الموقعة أو إدخال الموظف تحققاً من ملكية الهاتف. لا تُحفظ كلمة السر أو PIN في الأرشيف.</div>
            <div class="accordion" id="opening-dossier-sections">
                <div class="accordion-item"><h2 class="accordion-header"><button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#opening-owner">1. صاحب الحساب والاتصال</button></h2>
                    <div id="opening-owner" class="accordion-collapse collapse show"><div class="accordion-body"><div class="row g-3">
                        <div class="col-md-4"><label class="form-label">الاسم الأول *</label><input class="form-control" name="f_name" required></div>
                        <div class="col-md-4"><label class="form-label">اسم الأب</label><input class="form-control" name="father_name"></div>
                        <div class="col-md-4"><label class="form-label">الاسم الأخير *</label><input class="form-control" name="l_name" required></div>
                        <div class="col-md-6"><label class="form-label">الاسم بالإنجليزية</label><input class="form-control" name="name_en" dir="ltr"></div>
                        <div class="col-md-3"><label class="form-label">الجنس *</label><select class="form-select" name="gender" required><option value="">اختر</option><option value="male">ذكر</option><option value="female">أنثى</option><option value="other">آخر</option></select></div>
                        <div class="col-md-3"><label class="form-label">تاريخ الميلاد *</label><input class="form-control" name="date_of_birth" type="date" required></div>
                        <div class="col-md-3"><label class="form-label">مفتاح الدولة *</label><input class="form-control" name="dial_country_code" value="+967" dir="ltr" required></div>
                        <div class="col-md-4"><label class="form-label">رقم الجوال *</label><input class="form-control" name="phone" dir="ltr" placeholder="771234567" required></div>
                        <div class="col-md-5"><label class="form-label">البريد الإلكتروني</label><input class="form-control" name="email" type="email" dir="ltr"></div>
                    </div></div></div></div>
                <div class="accordion-item"><h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#opening-identity">2. الهوية والعنوان</button></h2>
                    <div id="opening-identity" class="accordion-collapse collapse"><div class="accordion-body"><div class="row g-3">
                        <div class="col-md-3"><label class="form-label">نوع الهوية *</label><select class="form-select" name="identification_type" required><option value="">اختر</option><option value="nid">بطاقة شخصية</option><option value="passport">جواز سفر</option><option value="driving_licence">رخصة قيادة</option><option value="trade_license">ترخيص تجاري</option></select></div>
                        <div class="col-md-3"><label class="form-label">رقم الهوية *</label><input class="form-control" name="identification_number" required></div>
                        <div class="col-md-3"><label class="form-label">تاريخ الإصدار</label><input class="form-control" name="identification_issue_date" type="date"></div>
                        <div class="col-md-3"><label class="form-label">تاريخ الانتهاء</label><input class="form-control" name="identification_expiry_date" type="date"></div>
                        <div class="col-md-4"><label class="form-label">مكان الإصدار</label><input class="form-control" name="id_place_of_issue"></div>
                        <div class="col-md-4"><label class="form-label">بلد الميلاد</label><input class="form-control" name="country_of_birth" value="اليمن"></div>
                        <div class="col-md-4"><label class="form-label">الحالة الاجتماعية</label><select class="form-select" name="marital_status"><option value="">غير محدد</option><option value="single">أعزب</option><option value="married">متزوج</option><option value="divorced">مطلق</option><option value="widowed">أرمل</option></select></div>
                        <div class="col-md-6"><label class="form-label">العنوان التفصيلي *</label><input class="form-control" name="address" required></div>
                        <div class="col-md-3"><label class="form-label">محافظة السكن</label><select class="form-select" name="residence_governorate"><option value="">اختر</option>@foreach(\App\Support\YemenGovernorates::all() as $governorate)<option value="{{ $governorate['code'] }}">{{ $governorate['name'] }}</option>@endforeach</select></div>
                        <div class="col-md-3"><label class="form-label">المديرية *</label><input class="form-control" name="residence_district" required></div>
                        <div class="col-md-6"><label class="form-label">المنطقة / الحي</label><input class="form-control" name="residence_area"></div>
                        <div class="col-md-6"><label class="form-label">علامة مميزة</label><input class="form-control" name="residence_landmark"></div>
                        <div class="col-md-3"><label class="form-label">وجه الهوية *</label><input class="form-control" name="identity_front" type="file" accept="image/*,application/pdf" required></div>
                        <div class="col-md-3"><label class="form-label">ظهر الهوية *</label><input class="form-control" name="identity_back" type="file" accept="image/*,application/pdf" required></div>
                        <div class="col-md-3"><label class="form-label">صورة شخصية *</label><input class="form-control" name="selfie" type="file" accept="image/*" required></div>
                        <div class="col-md-3"><label class="form-label">إثبات العنوان</label><input class="form-control" name="address_proof" type="file" accept="image/*,application/pdf"></div>
                    </div></div></div></div>
                <div class="accordion-item"><h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#opening-financial">3. العمل والامتثال والمراجع</button></h2>
                    <div id="opening-financial" class="accordion-collapse collapse"><div class="accordion-body"><div class="row g-3">
                        <div class="col-md-4"><label class="form-label">المهنة</label><input class="form-control" name="occupation"></div><div class="col-md-4"><label class="form-label">جهة العمل</label><input class="form-control" name="employer_name"></div><div class="col-md-4"><label class="form-label">المسمى الوظيفي</label><input class="form-control" name="job_title"></div>
                        <div class="col-md-6"><label class="form-label">عنوان العمل</label><input class="form-control" name="work_address"></div>
                        <div class="col-md-3"><label class="form-label">مصدر الدخل *</label><select class="form-select" name="income_source" required><option value="">اختر</option><option value="salary">راتب</option><option value="business">تجارة</option><option value="remittance">حوالات</option><option value="investment">استثمار</option><option value="rent">إيجار</option><option value="other">أخرى</option></select></div>
                        <div class="col-md-3"><label class="form-label">الغرض من الحساب *</label><select class="form-select" name="account_purpose" required><option value="">اختر</option><option value="payments">مدفوعات</option><option value="remittance">حوالات</option><option value="business">تجارة</option><option value="savings">ادخار</option><option value="salary">راتب</option><option value="other">أخرى</option></select></div>
                        <div class="col-md-4"><label class="form-label">الدخل الشهري</label><input class="form-control" name="monthly_income" type="number" min="0"></div><div class="col-md-2"><label class="form-label">العملة</label><input class="form-control" name="monthly_income_currency" value="YER" maxlength="3" dir="ltr"></div>
                        <div class="col-md-3"><label class="form-label">شخص سياسي بارز؟ *</label><select class="form-select" name="is_pep" required><option value="">اختر</option><option value="0">لا</option><option value="1">نعم</option></select></div><div class="col-md-9"><label class="form-label">المنصب أو الصلة (إن وجدت)</label><input class="form-control" name="pep_position"></div>
                        <div class="col-md-4"><label class="form-label">مرجع أول: الاسم</label><input class="form-control" name="kin_name"></div><div class="col-md-4"><label class="form-label">جوال المرجع</label><input class="form-control" name="kin_phone" dir="ltr"></div><div class="col-md-4"><label class="form-label">الصلة</label><input class="form-control" name="kin_relation"></div>
                        <div class="col-md-4"><label class="form-label">مرجع ثانٍ: الاسم</label><input class="form-control" name="kin2_name"></div><div class="col-md-4"><label class="form-label">جوال المرجع</label><input class="form-control" name="kin2_phone" dir="ltr"></div><div class="col-md-4"><label class="form-label">الصلة</label><input class="form-control" name="kin2_relation"></div>
                    </div></div></div></div>
                @if($hubType == 3)
                <div class="accordion-item"><h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#opening-business">4. هوية المنشأة</button></h2>
                    <div id="opening-business" class="accordion-collapse collapse"><div class="accordion-body"><div class="row g-3">
                        <div class="col-md-6"><label class="form-label">اسم المنشأة / المتجر *</label><input class="form-control" name="store_name" required></div>
                        <div class="col-md-3"><label class="form-label">نوع النشاط *</label>{{-- AMIAL-VERTICAL-OOP-004 — **القائمةُ تُولَّد من مصدر الأسماء.**

                                 كانت ستّةَ خياراتٍ مكتوبةً بيدها، وهجاؤها
                                 يخالف ما تعرضه اللوحاتُ الأخرى («محطة وقود»
                                 هنا و«محطّة وقود» في ٣٦٠). **فالتاجرُ يُنشَأ
                                 باسمٍ ويُعرَض بآخر.** والأخطرُ أنّ قطاعاً
                                 يُضاف في الشيفرة لا يظهر هنا أبداً: يُنشئه
                                 المديرُ فلا يجده في القائمة. --}}
                            <select class="form-select" name="business_type" required><option value="">اختر</option>@foreach(\App\Support\Access\AccessConstants::BUSINESS_TYPE_LABELS as $bizCode => $bizLabel)<option value="{{ $bizCode }}">{{ $bizLabel }}</option>@endforeach</select></div>
                        <div class="col-md-3"><label class="form-label">الباقة</label><select class="form-select" name="plan"><option value="free">مجاني — 0 ر.س</option><option value="business">الأعمال — 35 ر.س</option><option value="enterprise">مؤسسة — 99 ر.س</option></select></div>
                        <div class="col-md-4"><label class="form-label">رقم السجل / الترخيص *</label><input class="form-control" name="business_registration_number" required></div><div class="col-md-4"><label class="form-label">الشكل القانوني</label><input class="form-control" name="business_legal_form" placeholder="مؤسسة فردية، شركة…"></div><div class="col-md-4"><label class="form-label">فئة النشاط</label><input class="form-control" name="business_category"></div>
                        <div class="col-md-6"><label class="form-label">المفوض بالتوقيع *</label><input class="form-control" name="authorized_signatory_name" required></div><div class="col-md-6"><label class="form-label">هوية المفوض *</label><input class="form-control" name="authorized_signatory_id" required></div>
                    </div></div></div></div>
                @endif
                <div class="accordion-item"><h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#opening-consent">{{ $hubType == 3 ? '5' : '4' }}. الإقرار والأرشفة</button></h2>
                    <div id="opening-consent" class="accordion-collapse collapse"><div class="accordion-body"><div class="row g-3"><div class="col-md-6"><label class="form-label">نسخة النموذج الورقي الموقّع (اختياري)</label><input class="form-control" name="signed_paper_form" type="file" accept="application/pdf,image/jpeg,image/png"><small class="text-muted">PDF أو JPG/PNG حتى 8MB. تُشفّر النسخة وتُربط بهذا الملف.</small></div><div class="col-md-6"><label class="form-label">بيانات الدخول الأولية</label><input class="form-control mb-2" name="password" type="password" minlength="8" placeholder="كلمة سر مؤقتة (8 أحرف على الأقل)" required dir="ltr"><input class="form-control" name="pin" inputmode="numeric" maxlength="4" placeholder="PIN معاملات من 4 أرقام (اختياري)" dir="ltr"></div><div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="declaration_accepted" value="1" id="declaration-accepted" required><label class="form-check-label" for="declaration-accepted">أقرّ بأن البيانات قُدّمت من صاحبها أو مفوضه، وبأنه اطّلع على الموافقات المطلوبة. ستُراجع الهوية والهاتف قبل اعتماد الحساب.</label></div></div></div></div></div>
            </div>
            <div class="text-danger small" id="add-error"></div>
        </form>
        <div class="modal-footer">
            <button class="btn btn-primary" id="add-submit">حفظ ملف الفتح وإنشاء الحساب</button>
        </div>
    </div></div>
</div>

{{-- ===== Modal: تحويل / إعادة مبلغ ===== --}}
<div class="modal fade" id="modal-transfer" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title" id="transfer-title">تحويل من محفظة الإدارة</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <p class="text-muted small mb-2" id="transfer-target"></p>
            <div class="mb-2"><label class="form-label">المبلغ (ر.ي)</label>
                <input class="form-control" id="transfer-amount" type="number" min="1" dir="ltr"></div>
            <div class="mb-2"><label class="form-label">السبب / المرجع (اختياري)</label>
                <input class="form-control" id="transfer-reason" placeholder="إعادة مبلغ عملية… / تمويل وكيل…"></div>
            <div class="text-danger small" id="transfer-error"></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-primary" id="transfer-submit">تنفيذ التحويل</button>
        </div>
    </div></div>
</div>

{{-- ===== Modal: سجل العمليات ===== --}}
<div class="modal fade" id="modal-tx" tabindex="-1">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title" id="tx-title">سجل العمليات</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body p-0">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light"><tr>
                        <th>المرجع</th><th>النوع</th><th>مدين</th><th>دائن</th><th>الرصيد</th><th>التاريخ</th>
                    </tr></thead>
                    <tbody id="tx-tbody"></tbody>
                </table>
            </div>
        </div>
    </div></div>
</div>

@endsection

@push('script')
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(function () {
    const root = document.getElementById('hub-root');
    const slug = root.dataset.slug;
    const isAgents = slug === 'agents';
    // AMIAL-MERCHANT-CENTER-001 — زرُّ «فتح المركز» للتجّار وحدهم:
    // مركزُ التاجر يعرض تسوياتٍ ومخاطرَ واشتراكاً، ولا معنى لها لعميل.
    const isMerchants = slug === 'merchants';
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const base = '{{ url('admin/amial/hub') }}';

    let page = 1, lastPage = 1, search = '';
    let transferUserId = null;

    const fmt = (n) => Number(n || 0).toLocaleString('en-US', {maximumFractionDigits: 2});
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    // AMIAL-AGENT-NETWORK-DOOR-001 — الطريقةُ صارت مُعامِلاً: مسارُ حدود
    // الوكيل `PUT`، ونداؤه بـ`POST` يردّ ٤٠٥ بلا رسالةٍ تدلّ على السبب.
    async function post(url, body, method) {
        const r = await fetch(url, {
            method: method || 'POST',
            headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json'},
            body: JSON.stringify(body || {}),
        });
        const j = await r.json().catch(() => ({}));
        if (!r.ok) throw new Error(j.message || ('خطأ ' + r.status));
        return j;
    }

    // ===== قائمة المستخدمين =====
    const COLS = isAgents ? 9 : 7;

    // عمودا الوكيل: الفروع، والنقد في الدرج. وكيلٌ بلا فرعٍ لا يخدم أحداً مهما
    // كان رصيده؛ وفرعٌ بلا نقدٍ يقبل الإيداع ويعجز عن السحب.
    function agentCells(u) {
        if (!isAgents) return '';
        const a = u.agent;
        if (!a) return '<td colspan="2" class="text-muted small">—</td>';

        if (a.is_branch_account) {
            return `<td><span class="badge bg-info text-dark">فرع</span>
                        <div class="small text-muted">تابع لـ ${esc(a.parent ? a.parent.name : '—')}</div></td>
                    <td class="text-muted small">يُدار من صفّ الوكيل الأمّ</td>`;
        }

        const labels = {low_cash: 'نقدٌ منخفض', overloaded: 'فوق الحدّ', not_counted: 'لم يُجرَد', no_till: 'خزنة ناقصة'};
        const badges = (a.flags || []).map(f =>
            `<span class="badge bg-warning text-dark">${labels[f] || f}</span>`).join(' ');

        return `<td>${a.no_branches
                    ? '<span class="badge bg-danger">بلا فروع</span>'
                    : `<strong>${a.branches}</strong> <span class="small text-muted">(${a.branches_active} نشِط)</span>`}</td>
                <td>${a.no_branches ? '<span class="text-muted small">—</span>'
                                    : `${fmt(a.cash_on_hand)} ر.ي<div>${badges}</div>`}</td>`;
    }

    // زرّ التمويل — ولا يظهر على صفّ فرع.
    //
    //      المنصّة ──► الوكيل الأمّ ──► الفرع
    //
    // فالمنصّة طرفُ الوكيل وحده، والوكيل يوزّع على فروعه من بوّابته.
    // والخدمة ترفض تمويل الفرع على كلّ حال (AgentNetworkService)، وهذا
    // هنا ليقرأ المدير **السبب** بدل أن يضغط ويُصدَّ.
    function transferControl(u) {
        const a = isAgents ? u.agent : null;
        if (a && a.is_branch_account) {
            const parent = esc(a.parent ? a.parent.name : 'الوكيل الأمّ');
            return `<span class="badge bg-light text-dark border fw-normal"
                          title="التمويل يمرّ بالوكيل الأمّ ليبقى مسؤولاً عن فرعه، ولتبقى التسوية مع طرفٍ واحد">
                        يُموَّل من ${parent}</span>`;
        }
        return `<button class="btn btn-sm btn-outline-primary" data-act="transfer" data-id="${u.id}" data-name="${esc(u.name)}">
                    ${isAgents ? 'تحويل رصيد' : 'إعادة مبلغ'}</button>`;
    }

    // ══════════════════════════════════════════════════════════════════
    //  زرّ المركز + قائمته — «فتح المركز ⋮»
    //
    //  والقائمةُ ليست زينة: **كلّ بندٍ يفتح المركز على تبويبه مباشرة**
    //  (`#tab=money`)، فمن يفتح الملفّ من أجل التسويات لا يبدأ من الملفّ
    //  الأساسيّ ثمّ يبحث عن التبويب. وما لا يملكه دورُه لن يظهر له داخل
    //  المركز أصلاً — فالقائمةُ تعرض، والخادمُ يقرّر.
    // ══════════════════════════════════════════════════════════════════

    const CENTER_MENU = [
        ['profile',      '👤 الملف الأساسي'],
        ['money',        '💰 المركز المالي وكشف الحساب'],
        ['settlements',  '🔄 التسويات'],
        ['operations',   '📊 العمليات'],
        ['risk',         '🚨 المخاطر'],
        ['staff',        '👨‍💼 الموظفون (عرض فقط)'],
        ['devices',      '📱 الأجهزة والجلسات'],
        ['compliance',   '🛡️ التوثيق والامتثال'],
        ['support',      '🎫 التذاكر'],
        ['subscription', '⚙️ الاشتراك'],
        ['audit',        '📝 سجل التدقيق'],
    ];

    function centerMenu(u) {
        return `<div class="btn-group">
            <button class="btn btn-sm btn-dark" data-act="center" data-id="${u.id}"
                    data-testid="hub-open-center">فتح المركز</button>
            <button class="btn btn-sm btn-dark dropdown-toggle dropdown-toggle-split"
                    data-bs-toggle="dropdown" data-testid="hub-center-menu"
                    aria-expanded="false"><span class="visually-hidden">أقسام المركز</span></button>
            <ul class="dropdown-menu dropdown-menu-end">
                ${CENTER_MENU.map(([sec, label]) =>
                    `<li><a class="dropdown-item" href="#" data-act="center-sec"
                            data-id="${u.id}" data-sec="${sec}">${label}</a></li>`).join('')}
            </ul>
        </div>`;
    }

    // ══════════════════════════════════════════════════════════════════
    //  AMIAL-AGENT-NETWORK-DOOR-001 — **شبكةُ الوكلاء: مسارٌ بلا زرّ.**
    //
    //  `AdminAgentNetworkController` يُقدّم اعتمادَ تسجيلٍ وإيقافاً
    //  وتعديلَ حدود — خمسةُ مساراتٍ مسجَّلةٌ منذ v2.4 **ولا شاشةَ
    //  تناديها**. فالميزةُ مبنيّةٌ ولا يُوصل إليها، والاعتمادُ يقع بتحرير
    //  القاعدة يدويّاً — حيث لا صلاحيّةَ ولا سجلّ.
    //
    //  والفرعُ لا يُعتمد ولا يُوقف من هنا: الأمُّ تُدير فروعَها
    //  (القاعدة العاشرة) — ويُقال ذلك بدل إخفاء الزرّ صامتاً.
    // ══════════════════════════════════════════════════════════════════

    function networkMenu(u) {
        const a = u.agent;
        if (!a) return '';

        if (a.is_branch_account) {
            return `<span class="btn btn-sm btn-light disabled" title="الأمّ تدير فروعها">
                        فرع — يُدار من الأمّ</span>`;
        }

        const st = a.reg_status || 'unknown';
        const pending = st !== 'active';

        return `<div class="btn-group">
            <button class="btn btn-sm ${pending ? 'btn-success' : 'btn-outline-secondary'} dropdown-toggle"
                    data-bs-toggle="dropdown" aria-expanded="false"
                    data-testid="hub-network-menu">
                ${pending ? 'بانتظار الاعتماد' : 'الشبكة'}
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                ${pending
                    ? `<li><a class="dropdown-item text-success" href="#"
                             data-act="net-approve" data-id="${u.id}">✅ اعتماد التسجيل</a></li>`
                    : `<li><a class="dropdown-item text-danger" href="#"
                             data-act="net-suspend" data-id="${u.id}">⛔ إيقاف الوكيل</a></li>`}
                <li><a class="dropdown-item" href="#" data-act="net-limits"
                       data-id="${u.id}"
                       data-daily="${a.daily_cash_in_limit || ''}">📊 حدود الشبّاك</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><span class="dropdown-item-text small text-muted">
                    الحالة: ${esc({active:'نشِط', suspended:'موقوف',
                                   pending_approval:'بانتظار الاعتماد'}[st] || st)}</span></li>
            </ul>
        </div>`;
    }

    async function loadUsers() {
        const tbody = document.getElementById('users-tbody');
        tbody.innerHTML = `<tr><td colspan="${COLS}" class="text-center text-muted py-4">جارٍ التحميل…</td></tr>`;
        const r = await fetch(`${base}/${slug}/users.json?page=${page}&search=${encodeURIComponent(search)}`,
            {headers: {'Accept': 'application/json'}});
        const j = await r.json();
        lastPage = j.last_page;
        document.getElementById('page-info').textContent = `صفحة ${j.current_page} من ${j.last_page} — ${j.total} حساب`;

        if (!j.data.length) {
            tbody.innerHTML = `<tr><td colspan="${COLS}" class="text-center text-muted py-4">لا نتائج</td></tr>`;
            return;
        }
        tbody.innerHTML = j.data.map(u => `
            <tr style="cursor:pointer" data-act="open" data-id="${u.id}">
                <td>${u.id}</td>
                <td class="text-primary fw-bold">${esc(u.name)}</td>
                <td dir="ltr">${esc(u.phone)}</td>
                <td>${fmt(u.balance)} ر.ي</td>
                ${agentCells(u)}
                <td>${u.kyc === 1 ? '<span class="badge bg-success">موثّق</span>'
                    : (u.kyc === 2 ? '<span class="badge bg-danger">مرفوض</span>'
                    : (u.has_docs ? '<span class="badge bg-warning text-dark">بانتظار الاعتماد</span>'
                    : '<span class="badge bg-secondary">بلا وثائق</span>'))}</td>
                <td>${u.is_active ? '<span class="badge bg-success">نشِط</span>' : '<span class="badge bg-danger">مجمَّد</span>'}</td>
                <td class="text-nowrap">
                    <button class="btn btn-sm btn-primary" data-act="open" data-id="${u.id}">التفاصيل</button>
                    ${isMerchants ? centerMenu(u) : ''}
                    ${isAgents ? networkMenu(u) : ''}
                    ${transferControl(u)}
                    <button class="btn btn-sm ${u.is_active ? 'btn-outline-danger' : 'btn-outline-success'}"
                            data-act="toggle" data-id="${u.id}">${u.is_active ? 'تجميد' : 'فكّ التجميد'}</button>
                </td>
            </tr>`).join('');
    }

    document.getElementById('users-tbody').addEventListener('click', async (e) => {
        // الزرّ يُحسم أوّلاً. الصفّ نفسه يحمل data-act="open"، فلو بُحث عن
        // الصفّ قبل الزرّ لابتلع الصفُّ كلَّ ضغطةٍ على أزراره — وهو ما جعل
        // «تحويل رصيد» ينقل إلى صفحة الوكيل بدل فتح النافذة.
        // بندُ القائمة `<a>` لا `<button>` — ويُحسم قبل كلّ شيء، وإلّا
        // ابتلعه الصفُّ ونقل إلى صفحة الحساب بدل تبويب المركز.
        // AMIAL-AGENT-NETWORK-DOOR-001 — أفعالُ الشبكة، وكلٌّ بتأكيدٍ يقول
        // أثرَه. واعتمادُ وكيلٍ يفتح له الشبّاك، وإيقافُه يُغلقه فوراً.
        const netItem = e.target.closest('a[data-act^="net-"]');
        if (netItem) {
            e.preventDefault();
            const id = netItem.dataset.id;
            const act = netItem.dataset.act;

            try {
                if (act === 'net-approve') {
                    if (!confirm('اعتمادُ التسجيل يفتح الشبّاك لهذا الوكيل فوراً. متابعة؟')) return;
                    const j = await post(`{{ url('admin/amial/agents') }}/${id}/approve`, {});
                    alert(j.message || 'اعتُمد الوكيل'); loadUsers(); return;
                }

                if (act === 'net-suspend') {
                    const reason = prompt('سبب الإيقاف (يُسجَّل):') || '';
                    if (!reason.trim()) { alert('السببُ مطلوب — إيقافٌ بلا سبب لا يُراجَع'); return; }
                    const j = await post(`{{ url('admin/amial/agents') }}/${id}/suspend`, {reason});
                    alert(j.message || 'أُوقف الوكيل'); loadUsers(); return;
                }

                if (act === 'net-limits') {
                    const cur = netItem.dataset.daily || '';
                    const v = prompt('الحدّ اليوميّ للإيداع النقديّ (ريال):', cur);
                    if (v === null) return;
                    if (!/^\d+(\.\d+)?$/.test(v.trim())) { alert('أدخل رقماً صحيحاً'); return; }

                    const j = await post(`{{ url('admin/amial/agents') }}/${id}/limits`,
                        {daily_cash_in_limit: v.trim()}, 'PUT');
                    alert(j.message || 'حُدّثت الحدود'); loadUsers(); return;
                }
            } catch (err) { alert(err.message); }
            return;
        }

        const menuItem = e.target.closest('a[data-act="center-sec"]');
        if (menuItem) {
            e.preventDefault();
            window.location.href = `{{ url('admin/amial/merchant-center') }}/`
                + `${menuItem.dataset.id}#tab=${menuItem.dataset.sec}`;
            return;
        }

        const btn = e.target.closest('button[data-act]');
        // فتح صفحة التفاصيل: زر «التفاصيل»، أو الضغط على الصفّ خارج الأزرار
        const row = btn ? null : e.target.closest('tr[data-act="open"]');
        // AMIAL-MERCHANT-CENTER-001 — **«فتح المركز» غيرُ «التفاصيل»**:
        // التفاصيل ملفُّ حسابٍ لأيّ دور، والمركزُ لوحةُ تاجرٍ كاملةً بأقسامها
        // الإحدى عشرة وأفعالها. ويُحسم قبل الصفّ لئلّا يبتلع الصفُّ الضغطة.
        if (btn && btn.dataset.act === 'center') {
            window.location.href = `{{ url('admin/amial/merchant-center') }}/${btn.dataset.id}`;
            return;
        }
        if ((btn && btn.dataset.act === 'open') || row) {
            window.location.href = `${base}/account/${(btn || row).dataset.id}`;
            return;
        }
        if (!btn) return;
        const id = btn.dataset.id;

        if (btn.dataset.act === 'toggle') {
            const reason = prompt('سبب التجميد/فكّ التجميد (يُسجَّل في التدقيق):') || '';
            try {
                const j = await post(`${base}/users/${id}/toggle-active`, {reason});
                alert(j.message); loadUsers();
            } catch (err) { alert(err.message); }

        } else if (btn.dataset.act === 'transfer') {
            transferUserId = id;
            document.getElementById('transfer-target').textContent = `إلى: ${btn.dataset.name} (#${id})`;
            document.getElementById('transfer-title').textContent = isAgents ? 'تحويل رصيد للوكيل' : 'إعادة مبلغ / تحويل للحساب';
            document.getElementById('transfer-error').textContent = '';
            document.getElementById('transfer-amount').value = '';
            new bootstrap.Modal('#modal-transfer').show();

        } else if (btn.dataset.act === 'tx') {
            document.getElementById('tx-title').textContent = `سجل عمليات ${btn.dataset.name}`;
            const tbody = document.getElementById('tx-tbody');
            tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3">جارٍ التحميل…</td></tr>';
            new bootstrap.Modal('#modal-tx').show();
            const r = await fetch(`${base}/users/${id}/transactions.json`, {headers: {'Accept': 'application/json'}});
            const j = await r.json();
            tbody.innerHTML = j.data.length ? j.data.map(t => `
                <tr>
                    <td class="small font-monospace">${esc(t.transaction_id)}</td>
                    <td>${esc(t.transaction_type)}</td>
                    <td class="text-danger">${fmt(t.debit)}</td>
                    <td class="text-success">${fmt(t.credit)}</td>
                    <td>${fmt(t.balance)}</td>
                    <td class="small">${esc(t.created_at ?? '')}</td>
                </tr>`).join('')
                : '<tr><td colspan="6" class="text-center text-muted py-3">لا عمليات</td></tr>';
        }
    });

    document.getElementById('transfer-submit').addEventListener('click', async () => {
        const errEl = document.getElementById('transfer-error');
        errEl.textContent = '';
        try {
            const amount = document.getElementById('transfer-amount').value;
            const reason = document.getElementById('transfer-reason').value;
            const j = isAgents
                ? await post(`${base}/agents/${transferUserId}/credit`, {amount, reference: reason})
                : await post(`${base}/transfer`, {to_user_id: transferUserId, amount, reason});
            bootstrap.Modal.getInstance(document.getElementById('modal-transfer')).hide();
            alert(j.message); loadUsers();
        } catch (err) { errEl.textContent = err.message; }
    });

    document.getElementById('add-submit').addEventListener('click', async () => {
        const errEl = document.getElementById('add-error');
        errEl.textContent = '';
        try {
            const form = document.getElementById('opening-dossier-form');
            if (!form.reportValidity()) return;
            const r = await fetch(`${base}/${slug}/users`, {
                method: 'POST', headers: {'X-CSRF-TOKEN': csrf, 'Accept': 'application/json'},
                body: new FormData(form),
            });
            const j = await r.json().catch(() => ({}));
            if (!r.ok) throw new Error(j.message || ('خطأ ' + r.status));
            bootstrap.Modal.getInstance(document.getElementById('modal-add')).hide();
            alert(j.message); loadUsers();
        } catch (err) { errEl.textContent = err.message; }
    });

    // بحث + ترقيم
    let searchTimer;
    document.getElementById('search-box').addEventListener('input', (e) => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => { search = e.target.value.trim(); page = 1; loadUsers(); }, 350);
    });
    document.getElementById('prev-page').addEventListener('click', () => { if (page > 1) { page--; loadUsers(); } });
    document.getElementById('next-page').addEventListener('click', () => { if (page < lastPage) { page++; loadUsers(); } });

    // ===== تبويب التوثيق =====
    async function loadKyc() {
        const wrap = document.getElementById('kyc-cards');
        wrap.innerHTML = '<div class="col-12 text-muted">جارٍ التحميل…</div>';
        const r = await fetch(`${base}/${slug}/kyc.json`, {headers: {'Accept': 'application/json'}});
        const j = await r.json();
        if (!j.data.length) {
            wrap.innerHTML = '<div class="col-12 text-muted">لا طلبات توثيق معلّقة 🎉</div>';
            return;
        }
        wrap.innerHTML = j.data.map(u => `
            <div class="col-md-6 col-lg-4"><div class="card stat-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <strong>${esc(u.name)}</strong>
                        <span class="text-muted small" dir="ltr">${esc(u.phone)}</span>
                    </div>
                    <div class="small text-muted mb-2">${esc(u.id_type ?? '')} ${esc(u.id_number ?? '')}</div>
                    <div class="d-flex gap-2 flex-wrap mb-3">
                        ${(u.documents || []).map(d =>
                            `<a href="${esc(d)}" target="_blank"><img src="${esc(d)}" style="height:70px;border-radius:6px;border:1px solid #ddd"></a>`
                        ).join('') || '<span class="text-muted small">لا صور</span>'}
                    </div>
                    <div class="d-flex gap-2">
                        <button class="btn btn-sm btn-success flex-fill" data-kyc="1" data-id="${u.id}">اعتماد</button>
                        <button class="btn btn-sm btn-outline-danger flex-fill" data-kyc="2" data-id="${u.id}">رفض</button>
                    </div>
                </div>
            </div></div>`).join('');
    }

    document.getElementById('kyc-cards').addEventListener('click', async (e) => {
        const btn = e.target.closest('button[data-kyc]');
        if (!btn) return;
        try {
            const status = Number(btn.dataset.kyc);
            const reason = status === 2
                ? prompt('سبب رفض الوثائق (سيظهر للعميل ويُسجّل في التدقيق):')
                : null;
            if (status === 2 && (!reason || reason.trim().length < 5)) {
                alert('سبب رفض واضح مطلوب (5 أحرف على الأقل)'); return;
            }
            const j = await post(`${base}/users/${btn.dataset.id}/kyc`, {status, reason});
            alert(j.message); loadKyc(); loadUsers();
        } catch (err) { alert(err.message); }
    });

    document.getElementById('kyc-tab-link').addEventListener('shown.bs.tab', loadKyc);
    document.getElementById('kyc-tab-link').addEventListener('click', () => setTimeout(loadKyc, 200));

    loadUsers();
})();
</script>
@endpush
