@extends('layouts.admin.app')

{{--
    AMIAL-AML-PANEL-001 — لوحة مكافحة غسل الأموال.

    ما كان ينقص ليس المنطق بل المُشغِّل: `AdminAmlController` فيه اثنتا عشرة
    نقطة نهاية، وكلّها مسجَّلة في المسارات، ولا واحدة منها تُفتح من متصفّح.
    فكان النظام يرصد ويعلّق ويُنبّه، ولا أحد يعتمد أو يرفض — والمعلَّق يبقى
    معلّقاً بلا أجل.

    ثلاثة أشياء تُعرَض هنا عمداً ولا تُخفى خلف إعداد:

    • **وضع الظل** يُكتب على كلّ قاعدة بوضوح. قاعدةٌ في الظلّ تُحصي ولا تمنع،
      ومن يظنّها تمنع يبني قراره على حمايةٍ غير موجودة.

    • **زمن الانتظار** على كلّ عملية معلّقة. العدد وحده لا يقول شيئاً: عشر
      عمليات عمرها ساعة غير عشرٍ عمرها أسبوع.

    • **سبب التعليق** بنصّه (`triggered_rules`)، لا رمزُ خطر مجرَّد. من يعتمد
      يحتاج أن يعرف أيّ قاعدةٍ أمسكت العملية.
--}}

@section('title', 'مكافحة غسل الأموال')

@section('content')
<div class="content container-fluid" id="aml-panel" data-testid="aml-panel">

    <div class="d-flex align-items-center gap-3 mb-4">
        <i class="tio-shield-outlined text-warning" style="font-size:24px"></i>
        <h2 class="page-header-title mb-0">مكافحة غسل الأموال</h2>
        <span class="badge badge-soft-secondary ms-auto">AML</span>
    </div>

    <div id="aml-shadow-banner"></div>

    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#aml-tab-dash" data-testid="aml-tab-dash">📊 المؤشّرات</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#aml-tab-flagged" data-testid="aml-tab-flagged">🚩 عمليات معلّقة <span class="badge bg-danger" id="aml-flag-count">0</span></button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#aml-tab-large" data-testid="aml-tab-large">💰 العمليات الكبيرة</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#aml-tab-struct" data-testid="aml-tab-struct">🧩 تقسيم العمليات</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#aml-tab-sanctions" data-testid="aml-tab-sanctions">🚫 العقوبات <span class="badge bg-danger" id="aml-sanction-count">0</span></button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#aml-tab-alerts" data-testid="aml-tab-alerts">🔔 التنبيهات</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#aml-tab-cases" data-testid="aml-tab-cases">🗂️ التحقيقات <span class="badge bg-warning text-dark" id="aml-case-count">0</span></button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#aml-tab-reports" data-testid="aml-tab-reports">📄 البلاغات التنظيمية <span class="badge bg-danger" id="aml-report-count">0</span></button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#aml-tab-rules" data-testid="aml-tab-rules">⚖️ القواعد</button></li>
    </ul>

    <div class="tab-content">

        {{-- ============ المؤشّرات (الفصل ١٠ — Dashboard) ============ --}}
        <div class="tab-pane fade show active" id="aml-tab-dash">
            <div id="aml-dash" class="row g-3" data-testid="aml-dash"></div>
        </div>

        {{-- ============ العمليات الكبيرة (التبويب ٢) ============ --}}
        <div class="tab-pane fade" id="aml-tab-large">
            <div class="card p-3">
                <div class="alert alert-secondary py-2 small">
                    العمود الذي يجعل هذه الصفحة أداةَ امتثال لا قائمةَ عمليات هو
                    <strong>«بلاغ العملة»</strong>: عمليةٌ فوق الحدّ بلا بلاغ مخالفةٌ صريحة.
                </div>
                <div class="d-flex mb-2"><button class="btn btn-outline-primary btn-sm ms-auto" id="aml-btn-large">تحديث</button></div>
                <div id="aml-large-list"></div>
            </div>
        </div>

        {{-- ============ تقسيم العمليات (التبويب ٣) ============ --}}
        <div class="tab-pane fade" id="aml-tab-struct">
            <div class="card p-3">
                <div class="alert alert-secondary py-2 small">
                    التقسيم <strong>نمطٌ لا حادثة</strong> — ولذلك يُجمَّع على العميل لا على العملية.
                    وصفٌّ بلا قضيّة مفتوحة هو الرصد الذي يقف عنده النظام.
                </div>
                <div class="d-flex mb-2"><button class="btn btn-outline-primary btn-sm ms-auto" id="aml-btn-struct">تحديث</button></div>
                <div id="aml-struct-list"></div>
            </div>
        </div>

        {{-- ============ العقوبات (التبويب ٦) ============ --}}
        <div class="tab-pane fade" id="aml-tab-sanctions">
            <div class="card p-3">
                <div class="alert alert-secondary py-2 small">
                    <strong>المطابقة المحتملة لا تحسم شيئاً بنفسها</strong> — تبقى «لم تُراجَع» حتى يبتّ فيها موظّف.
                    والاستبعاد يحتاج سبباً أطول من التأكيد: التأكيد يوقف الحساب وأثره ظاهر،
                    أمّا الاستبعاد فيُعيد العميل إلى العمل بلا أثر — وهو القرار الذي يُسأل عنه في التفتيش.
                </div>
                <div class="d-flex gap-2 mb-3 flex-wrap">
                    <select id="aml-sanction-status" class="form-select" style="max-width:220px">
                        <option value="pending">لم تُراجَع</option>
                        <option value="confirmed">مؤكَّدة</option>
                        <option value="dismissed">مستبعَدة</option>
                        <option value="">الكل</option>
                    </select>
                    <button class="btn btn-outline-primary" id="aml-btn-sanctions">تحديث</button>
                </div>
                <div id="aml-sanctions-list"></div>
            </div>
        </div>

        {{-- ============ العمليات المعلّقة ============ --}}
        <div class="tab-pane fade" id="aml-tab-flagged">
            <div class="card p-3">
                <div class="d-flex gap-2 mb-3 flex-wrap align-items-center">
                    <select id="aml-flag-status" class="form-select" style="max-width:220px">
                        <option value="pending_review">بانتظار المراجعة</option>
                        <option value="approved_by_admin">معتمَدة</option>
                        <option value="rejected_by_admin">مرفوضة</option>
                        <option value="">الكل</option>
                    </select>
                    <button class="btn btn-outline-primary" id="aml-btn-flagged" data-testid="aml-btn-flagged">تحديث</button>
                    <span class="text-muted small ms-auto">الاعتماد هنا قرارٌ توثيقيّ: العملية رُفضت وقت وقوعها، والاعتماد يفتح لصاحبها إعادة الإرسال.</span>
                </div>
                <div id="aml-flagged-list"></div>
            </div>
        </div>

        {{-- ============ التنبيهات ============ --}}
        <div class="tab-pane fade" id="aml-tab-alerts">
            <div class="card p-3">
                <div class="d-flex gap-2 mb-3 flex-wrap">
                    <select id="aml-alert-status" class="form-select" style="max-width:180px">
                        <option value="open">مفتوحة</option>
                        <option value="resolved">محلولة</option>
                    </select>
                    <select id="aml-alert-severity" class="form-select" style="max-width:180px">
                        <option value="">كل الدرجات</option>
                        <option value="critical">حرجة</option>
                        <option value="high">عالية</option>
                        <option value="medium">متوسطة</option>
                        <option value="low">منخفضة</option>
                    </select>
                    <button class="btn btn-outline-primary" id="aml-btn-alerts" data-testid="aml-btn-alerts">تحديث</button>
                </div>
                <div id="aml-alerts-list"></div>
            </div>
        </div>

        {{-- ============ التحقيقات (التبويب ٧) ============ --}}
        <div class="tab-pane fade" id="aml-tab-cases">
            <div class="row g-3">
                <div class="col-lg-5">
                    <div class="card">
                        <div class="card-header d-flex align-items-center gap-2">
                            <h5 class="card-header-title mb-0">القضايا</h5>
                            <select id="aml-case-status" class="form-select form-select-sm ms-auto" style="max-width:150px">
                                <option value="open">المفتوحة</option>
                                <option value="closed">المغلقة</option>
                                <option value="">الكل</option>
                            </select>
                        </div>
                        <div id="aml-cases" class="list-group list-group-flush" data-testid="aml-cases"></div>
                    </div>
                </div>
                <div class="col-lg-7">
                    <div class="card"><div class="card-body" id="aml-case-detail" data-testid="aml-case-detail">
                        <div class="text-center text-muted py-5">
                            <i class="tio-folder-outlined" style="font-size:48px;opacity:.3"></i>
                            <div class="mt-2">اختر قضيّة لعرض ملفّها</div>
                        </div>
                    </div></div>
                </div>
            </div>
        </div>

        {{-- ============ البلاغات التنظيمية (التبويب ٨) ============ --}}
        <div class="tab-pane fade" id="aml-tab-reports">
            <div id="aml-report-summary" class="row g-3 mb-3"></div>
            <div class="card p-3">
                <div class="d-flex gap-2 mb-3 flex-wrap align-items-center">
                    <select id="aml-report-type" class="form-select" style="max-width:200px">
                        <option value="">كل الأنواع</option>
                        <option value="STR">بلاغ اشتباه (STR)</option>
                        <option value="CTR">بلاغ عملة (CTR)</option>
                    </select>
                    <select id="aml-report-status" class="form-select" style="max-width:200px">
                        <option value="">كل الحالات</option>
                        <option value="draft">مسودّة</option>
                        <option value="pending_submission">بانتظار الإرسال</option>
                        <option value="submitted">أُرسل</option>
                    </select>
                    <button class="btn btn-outline-primary" id="aml-btn-reports" data-testid="aml-btn-reports">تحديث</button>
                    <button class="btn btn-outline-danger ms-auto" id="aml-btn-new-ctr">+ بلاغ عملة يدويّ</button>
                </div>
                <div class="alert alert-secondary py-2 small mb-3">
                    <strong>الفرق بين البلاغين:</strong>
                    بلاغ الاشتباه (STR) <em>تقديريّ</em> — يُرفع بعد تحقيق ويحتاج سرداً يشرح سبب الاشتباه.
                    وبلاغ العملة (CTR) <em>غير تقديريّ</em> — كلّ عملية فوق الحدّ تُبلَّغ، مشبوهةً كانت أو لا،
                    <strong>ولا يملك أحد إلغاءه</strong>.
                    ومن ولّد بلاغاً لا يؤكّد إرساله بنفسه.
                </div>
                <div id="aml-reports-list"></div>
            </div>
        </div>

        {{-- ============ القواعد ============ --}}
        <div class="tab-pane fade" id="aml-tab-rules">
            <div class="card p-3">
                <div class="d-flex gap-2 mb-3 align-items-center">
                    <span class="text-muted small">
                        <strong>وضع الظل</strong>: القاعدة تُحصي المخالفات ولا تمنعها. يُستعمل لقياس أثر
                        قاعدة قبل تفعيلها — لا لتأجيل حدٍّ قرَّرته السياسة.
                    </span>
                    <button class="btn btn-outline-primary btn-sm ms-auto" id="aml-btn-rules" data-testid="aml-btn-rules">تحديث</button>
                </div>
                <div id="aml-rules-list"></div>
            </div>
        </div>
    </div>

    {{-- ملفّ خطر العميل — الشاشة التي كانت نقطتا نهايتها مسجَّلتين بلا مستدعٍ --}}
    <div class="modal fade" id="aml-profile" tabindex="-1" data-testid="aml-profile">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">ملفّ خطر العميل <span id="aml-profile-user" class="font-monospace text-muted"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="aml-profile-body"></div>
                <div class="modal-footer justify-content-start gap-2">
                    <button class="btn btn-sm btn-outline-success js-aml-override" data-override="whitelist">قائمة بيضاء</button>
                    <button class="btn btn-sm btn-outline-danger js-aml-override" data-override="blacklist">قائمة سوداء</button>
                    <button class="btn btn-sm btn-outline-secondary js-aml-override" data-override="none">إزالة الاستثناء</button>
                    <span class="small text-muted ms-auto">القائمة البيضاء تُعطّل الرقابة عن هذا العميل — بسببٍ مكتوب يبقى في التدقيق.</span>
                </div>
            </div>
        </div>
    </div>
</div>

<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(function () {
    const BASE = '{{ url('admin/amial/aml') }}';
    const CSRF = '{{ csrf_token() }}';
    const esc = s => String(s ?? '—').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    async function get(path) {
        const r = await fetch(BASE + path, {headers: {'Accept': 'application/json'}});
        return r.json();
    }
    async function send(path, body, method) {
        const r = await fetch(BASE + path, {
            method: method || 'POST',
            headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF},
            body: JSON.stringify(body || {}),
        });
        return r.json();
    }

    // الانتظار هو ما يُشتكى منه — يُعرض بالساعات لا بالتاريخ وحده.
    function waited(iso) {
        if (!iso) return '—';
        const h = Math.floor((Date.now() - new Date(iso).getTime()) / 3600000);
        if (h < 1) return 'أقل من ساعة';
        if (h < 48) return h + ' ساعة';
        return Math.floor(h / 24) + ' يوم';
    }
    function waitClass(iso) {
        if (!iso) return '';
        const h = (Date.now() - new Date(iso).getTime()) / 3600000;
        return h > 72 ? 'text-danger fw-bold' : (h > 24 ? 'text-warning fw-bold' : 'text-muted');
    }

    // ---------- المؤشّرات ----------
    //
    // القرار الأهمّ هنا ليس حسابياً: ماذا يُعرَض عن ضابطٍ غير موجود؟
    //
    // «٠» في لوحة امتثال تُقرأ «فحصنا فلم نجد»، لا «لم نفحص». والفرق بينهما
    // هو الفرق بين منصّةٍ نظيفة وأخرى عمياء — ومن يقرأ اللوحة يبني على ما
    // قرأ. فما لم يُبنَ يُعرَض «غير مُفعَّل» بلا رقم.
    function tile(label, value, sub, cls) {
        return `<div class="col-lg-3 col-md-4 col-6"><div class="card p-3 h-100 ${cls || ''}">
            <div class="small text-muted">${label}</div>
            <div class="fs-4 fw-bold">${value}</div>
            ${sub ? `<div class="small text-muted">${sub}</div>` : ''}</div></div>`;
    }

    function notConfigured(label, why) {
        return `<div class="col-lg-3 col-md-4 col-6"><div class="card p-3 h-100 border-secondary bg-light">
            <div class="small text-muted">${label}</div>
            <div class="fs-6 fw-bold text-secondary">غير مُفعَّل</div>
            <div class="small text-muted">${esc(why)}</div></div></div>`;
    }

    async function loadDash() {
        const box = document.getElementById('aml-dash');
        box.innerHTML = '<div class="col-12 text-muted">جارٍ التحميل…</div>';
        const j = await get('/dashboard');
        if (!j.success) { box.innerHTML = '<div class="col-12 alert alert-warning">تعذّر التحميل</div>'; return; }
        const m = j.meta;

        let html = '';

        const health = m.health || {};
        const banner = document.getElementById('aml-shadow-banner');
        if (health.status === 'critical') {
            banner.innerHTML = `<div class="alert alert-danger" data-testid="aml-critical-health">
                <strong>CRITICAL — ${esc(health.message)}</strong>
                لا تعني الأصفار أن الفحص تم؛ يجب معالجة سبب غياب القواعد قبل اعتبار الرقابة عاملة.
            </div>`;
        } else if (health.status === 'warning') {
            banner.innerHTML = `<div class="alert alert-warning" data-testid="aml-shadow-health">
                <strong>تنبيه AML:</strong> ${esc(health.message)}
            </div>`;
        } else {
            banner.innerHTML = '';
        }
        html += tile('قواعد AML الفعالة', health.active_rules ?? 'غير متاح',
            'الإجمالي ' + (health.rules_total ?? '—') + ' • ظل ' + (health.shadow_rules ?? '—'),
            health.status === 'healthy' ? 'border-success' : 'border-danger');

        const hr = m.high_risk;
        if (hr.configured) {
            html += tile('عملاء عالو الخطر', hr.customers, '', hr.customers > 0 ? 'border-warning' : '')
                 +  tile('تجّار عالو الخطر', hr.merchants)
                 // وكيلٌ عالي الخطر مشكلةٌ من نوعٍ آخر: نقطةُ دخولٍ للنقد إلى
                 // المنصّة كلّها لا حسابٌ واحد.
                 +  tile('وكلاء عالو الخطر', hr.agents, 'نقاط دخول نقد', hr.agents > 0 ? 'border-danger' : '')
                 +  tile('القائمة السوداء / البيضاء', hr.blacklisted + ' / ' + hr.whitelisted,
                         'المستثنون من الرقابة', hr.whitelisted > 0 ? 'border-warning' : '');
        } else { html += notConfigured('ملفّات الخطر', hr.why); }

        const lt = m.large_transactions;
        html += tile('عمليات كبيرة (٣٠ يوماً)', lt.flagged_30d, 'الحدّ ' + esc(lt.threshold))
             +  tile('بلاغات عملة وُلِّدت', lt.ctr_generated_30d, '',
                     lt.flagged_30d > lt.ctr_generated_30d ? 'border-danger' : 'border-success');

        const st = m.structuring;
        html += st.configured
            ? tile('تقسيم عمليات (٣٠ يوماً)', st.matched_30d,
                   st.distinct_customers_30d + ' عميلاً', st.matched_30d > 0 ? 'border-warning' : '')
            : notConfigured('تقسيم العمليات', st.why);

        const sc = m.sanctions;
        html += sc.configured
            ? tile('مطابقات عقوبات لم تُراجَع', sc.potential_pending,
                   'مؤكَّدة ' + sc.confirmed + ' • موقوفون ' + sc.blocked_users,
                   sc.potential_pending > 0 ? 'border-danger' : '')
            : notConfigured('فحص العقوبات', sc.why);

        html += notConfigured('قوائم المراقبة', m.watchlist.why)
             +  notConfigured('الأشخاص المعرَّضون سياسيّاً', m.pep.why);

        const iv = m.investigations;
        html += tile('تحقيقات مفتوحة', iv.open,
                     iv.unassigned > 0 ? iv.unassigned + ' بلا ضابط مُسنَد' : '',
                     iv.critical_open > 0 ? 'border-danger' : '')
             // قضيّةٌ مفتوحة منذ ستّة أشهر ليست تحقيقاً بل إهمالاً.
             +  tile('أقدم قضيّة مفتوحة', iv.oldest_open_hours + ' ساعة',
                     esc(iv.oldest_open_case || '—'), iv.oldest_open_hours > 720 ? 'border-danger' : '')
             +  tile('تحقيقات مغلقة', iv.closed, 'متوسّط الإغلاق ' + iv.avg_close_hours + ' ساعة');

        const rp = m.reports;
        html += tile('بلاغات بانتظار الإرسال', rp.pending_total,
                     rp.oldest_pending_number ? 'أقدمها منذ ' + rp.oldest_pending_hours + ' ساعة' : '',
                     rp.pending_total > 0 ? 'border-danger' : 'border-success');

        box.innerHTML = html;
        document.getElementById('aml-sanction-count').textContent =
            (sc.configured ? sc.potential_pending : 0);
    }

    // ---------- العمليات الكبيرة (التبويب ٢) ----------
    document.getElementById('aml-btn-large').onclick = loadLarge;

    async function loadLarge() {
        const box = document.getElementById('aml-large-list');
        box.innerHTML = '<div class="text-muted">جارٍ التحميل…</div>';
        const j = await get('/large-transactions');
        if (!j.success) { box.innerHTML = '<div class="alert alert-warning">تعذّر التحميل</div>'; return; }

        const rows = (j.meta.items || []).map(t => `
            <tr class="${t.ctr_report ? '' : 'table-danger'}">
                <td class="font-monospace small">${esc(t.transaction_ulid || t.flag_ulid)}</td>
                <td>${esc(t.source)}<div class="small text-muted">${esc(t.source_phone)}</div></td>
                <td>${esc(t.destination)}</td>
                <td class="fw-bold">${esc(t.amount)}</td>
                <td>${esc(t.transaction_type)}</td>
                <td>${esc(t.status)}</td>
                <td>${t.ctr_report
                    ? `<span class="badge bg-success">${esc(t.ctr_report)}</span>`
                    : `<button class="btn btn-sm btn-danger js-quick-ctr" data-user="${t.actor_user_id}" data-amount="${esc(t.amount)}" data-ulid="${esc(t.transaction_ulid || '')}">لم يُبلَّغ — بلّغ الآن</button>`}</td>
            </tr>`).join('');

        box.innerHTML = `<div class="table-responsive"><table class="table table-sm" data-testid="aml-large-table">
            <thead><tr><th>المرجع</th><th>المصدر</th><th>الوجهة</th><th>المبلغ</th><th>النوع</th><th>الحالة</th><th>بلاغ العملة</th></tr></thead>
            <tbody>${rows || '<tr><td colspan="7" class="text-muted text-center py-3">لا عمليات فوق الحدّ</td></tr>'}</tbody></table></div>`;
    }

    document.addEventListener('click', async function (e) {
        const b = e.target.closest('.js-quick-ctr');
        if (!b) return;
        if (!confirm('توليد بلاغ عملة عن هذه العملية؟\n\nبلاغ العملة غير تقديريّ — كلّ عملية فوق الحدّ تُبلَّغ.')) return;
        const j = await send('/reports/ctr', {
            user_id: parseInt(b.dataset.user, 10), amount: b.dataset.amount,
            transaction_ulid: b.dataset.ulid || null,
        });
        alert(j.message || (j.success ? 'تم' : 'فشل'));
        loadLarge(); loadDash();
    });

    // ---------- تقسيم العمليات (التبويب ٣) ----------
    document.getElementById('aml-btn-struct').onclick = loadStruct;

    async function loadStruct() {
        const box = document.getElementById('aml-struct-list');
        box.innerHTML = '<div class="text-muted">جارٍ التحميل…</div>';
        const j = await get('/structuring');
        if (!j.success) { box.innerHTML = '<div class="alert alert-warning">تعذّر التحميل</div>'; return; }

        const rows = (j.meta.items || []).map(s => `
            <tr class="${s.investigation ? '' : 'table-warning'}">
                <td>${esc(s.customer)}<div class="small text-muted">${esc(s.phone)}</div></td>
                <td class="fw-bold">${s.transactions}</td>
                <td>${esc(s.total_amount)}</td>
                <td>${s.window_hours} ساعة</td>
                <td><span class="badge bg-${s.risk_score >= 70 ? 'danger' : 'warning text-dark'}">${esc(s.risk_score)}</span></td>
                <td>${s.investigation
                    ? `<span class="badge bg-info">${esc(s.investigation)}</span>`
                    : `<button class="btn btn-sm btn-dark js-struct-case" data-user="${s.user_id}">افتح قضية</button>`}</td>
            </tr>`).join('');

        box.innerHTML = `<div class="table-responsive"><table class="table table-sm" data-testid="aml-struct-table">
            <thead><tr><th>العميل</th><th>عدد العمليات</th><th>المجموع</th><th>النافذة الزمنية</th><th>الخطر</th><th>التحقيق</th></tr></thead>
            <tbody>${rows || '<tr><td colspan="6" class="text-muted text-center py-3">لا أنماط تقسيم مرصودة</td></tr>'}</tbody></table></div>`;
    }

    document.addEventListener('click', async function (e) {
        const b = e.target.closest('.js-struct-case');
        if (!b) return;
        const j = await send('/investigations', {user_id: parseInt(b.dataset.user, 10), priority: 'high'});
        alert(j.message || (j.success ? 'تم' : 'فشل'));
        loadStruct(); loadCases(); loadDash();
    });

    // ---------- العقوبات (التبويب ٦) ----------
    document.getElementById('aml-btn-sanctions').onclick = loadSanctions;
    document.getElementById('aml-sanction-status').onchange = loadSanctions;

    async function loadSanctions() {
        const st = document.getElementById('aml-sanction-status').value;
        const box = document.getElementById('aml-sanctions-list');
        box.innerHTML = '<div class="text-muted">جارٍ التحميل…</div>';
        const j = await get('/sanctions' + (st ? '?review_status=' + st : ''));
        if (!j.success) { box.innerHTML = '<div class="alert alert-warning">تعذّر التحميل</div>'; return; }

        const rev = {pending: ['warning text-dark', 'لم تُراجَع'],
                     confirmed: ['danger', 'مؤكَّدة'], dismissed: ['secondary', 'مستبعَدة']};

        const rows = (j.meta.items || []).map(s => {
            const r = rev[s.review_status] || rev.pending;
            return `
            <tr class="${s.review_status === 'pending' && s.age_hours > 48 ? 'table-danger' : ''}">
                <td>${esc(s.customer)}<div class="small text-muted">${esc(s.phone)}</div></td>
                <td class="small">${esc(s.matched_name)}<div class="text-muted">${esc(s.program)}</div></td>
                <td><span class="badge bg-secondary">${esc(s.list_source)}</span></td>
                <td>${esc(s.nationality)}</td>
                <td><span class="badge bg-${s.match_score >= 95 ? 'danger' : 'warning text-dark'}">${esc(s.match_score)}٪</span></td>
                <td><span class="badge bg-${r[0]}">${r[1]}</span>
                    ${s.review_note ? `<div class="small text-muted">${esc(s.review_note)}</div>` : ''}</td>
                <td class="small ${s.review_status === 'pending' && s.age_hours > 48 ? 'text-danger fw-bold' : 'text-muted'}">${s.age_hours} ساعة</td>
                <td class="text-nowrap">${s.review_status === 'pending'
                    ? `<button class="btn btn-sm btn-danger js-sanction" data-do="confirmed" data-id="${s.id}">تأكيد</button>
                       <button class="btn btn-sm btn-outline-secondary js-sanction" data-do="dismissed" data-id="${s.id}">استبعاد</button>`
                    : ''}</td>
            </tr>`;
        }).join('');

        box.innerHTML = `<div class="table-responsive"><table class="table table-sm" data-testid="aml-sanctions-table">
            <thead><tr><th>العميل</th><th>الاسم المطابق</th><th>القائمة</th><th>الجنسية</th><th>الدرجة</th><th>المراجعة</th><th>العمر</th><th></th></tr></thead>
            <tbody>${rows || '<tr><td colspan="8" class="text-muted text-center py-3">لا مطابقات</td></tr>'}</tbody></table></div>`;
    }

    document.addEventListener('click', async function (e) {
        const b = e.target.closest('.js-sanction');
        if (!b) return;
        const confirm_ = b.dataset.do === 'confirmed';
        const note = prompt(confirm_
            ? 'سبب التأكيد (١٠ أحرف على الأقل) — سيُوقَف الحساب:'
            : 'سبب الاستبعاد (٢٠ حرفاً على الأقل).\n\nاذكر ما فحصتَه وكيف تبيّن الاختلاف — هذا ما يُسأل عنه في التفتيش.');
        const min = confirm_ ? 10 : 20;
        if (!note || note.trim().length < min) { alert(`السبب إلزامي (${min} حرفاً على الأقل)`); return; }
        const j = await send(`/sanctions/${b.dataset.id}/review`, {decision: b.dataset.do, note: note.trim()});
        alert(j.message || (j.success ? 'تم' : 'فشل'));
        loadSanctions(); loadDash();
    });

    // ---------- العمليات المعلّقة ----------
    document.getElementById('aml-btn-flagged').onclick = loadFlagged;
    document.getElementById('aml-flag-status').onchange = loadFlagged;

    async function loadFlagged() {
        const st = document.getElementById('aml-flag-status').value;
        const box = document.getElementById('aml-flagged-list');
        box.innerHTML = '<div class="text-muted">جارٍ التحميل…</div>';
        const j = await get('/flagged' + (st ? '?status=' + st : ''));
        if (!j.success) { box.innerHTML = `<div class="alert alert-warning">${esc(j.message)}</div>`; return; }

        const items = j.meta.items || [];
        if (st === 'pending_review') document.getElementById('aml-flag-count').textContent = j.meta.pagination.total;

        const rows = items.map(f => {
            const pending = f.current_status === 'pending_review';
            // سبب التعليق بنصّه: من يعتمد يحتاج أن يعرف أيّ قاعدةٍ أمسكت العملية.
            let matched = f.triggered_rules;
            if (typeof matched === 'string') { try { matched = JSON.parse(matched); } catch (e) { matched = null; } }
            const reasons = Array.isArray(matched)
                ? matched.map(m => esc(m.name_ar || m.code || m)).join('، ')
                : '—';
            return `
            <tr>
                <td class="font-monospace small">${esc(f.flag_ulid)}</td>
                <td>${esc(f.actor ? (f.actor.f_name || '') + ' ' + (f.actor.phone_masked || '') : f.actor_user_id)}</td>
                <td class="fw-bold">${esc(f.amount)}</td>
                <td><span class="badge bg-${f.total_risk_score >= 70 ? 'danger' : (f.total_risk_score >= 40 ? 'warning text-dark' : 'secondary')}">${esc(f.total_risk_score)}</span></td>
                <td class="small">${reasons}</td>
                <td class="small ${waitClass(f.created_at)}">${waited(f.created_at)}</td>
                <td class="text-nowrap">
                    <button class="btn btn-sm btn-outline-secondary js-aml-profile" data-user="${f.actor_user_id}">ملفّ الخطر</button>
                    <button class="btn btn-sm btn-outline-dark js-aml-open-case" data-ulid="${esc(f.flag_ulid)}">فتح قضية</button>
                </td>
                <td>${pending
                    ? `<button class="btn btn-sm btn-success js-aml-flag" data-do="approve" data-ulid="${esc(f.flag_ulid)}">اعتماد</button>
                       <button class="btn btn-sm btn-outline-danger js-aml-flag" data-do="reject" data-ulid="${esc(f.flag_ulid)}">رفض</button>`
                    : `<span class="badge bg-${f.current_status === 'approved_by_admin' ? 'success' : 'dark'}">${f.current_status === 'approved_by_admin' ? 'معتمَدة' : 'مرفوضة'}</span>`}</td>
            </tr>`;
        }).join('');

        box.innerHTML = `<div class="table-responsive"><table class="table table-sm table-hover" data-testid="aml-flagged-table">
            <thead><tr><th>المرجع</th><th>العميل</th><th>المبلغ</th><th>الخطر</th><th>سبب التعليق</th><th>الانتظار</th><th>الملفّ</th><th></th></tr></thead>
            <tbody>${rows || '<tr><td colspan="8" class="text-muted text-center py-3">لا عمليات في هذه الحالة</td></tr>'}</tbody></table></div>`;
    }

    document.addEventListener('click', async function (e) {
        const b = e.target.closest('.js-aml-flag');
        if (!b) return;
        const isApprove = b.dataset.do === 'approve';
        // السبب إلزاميّ في الطرفين: القرار يُراجَع لاحقاً من مدقّق لا يعرف
        // ما دار في الغرفة يوم اتُّخذ.
        const note = prompt((isApprove ? 'سبب الاعتماد' : 'سبب الرفض') + ' (إلزامي — 10 أحرف على الأقل، يُسجَّل في التدقيق):');
        if (!note || note.trim().length < 10) { alert('السبب إلزامي (10 أحرف على الأقل)'); return; }
        const j = await send(`/flagged/${b.dataset.ulid}/${b.dataset.do}`, {note: note.trim()});
        alert(j.message || (j.success ? 'تم' : 'فشل'));
        loadFlagged();
    });

    // ---------- ملفّ خطر العميل والقائمتان ----------
    //
    // `users/{id}/profile` و`users/{id}/override` كانتا مسجَّلتين ولا تُستدعيان
    // من أيّ مكان. والقائمة البيضاء/السوداء ضابطُ سياسةٍ لا صيانة: عميلٌ
    // موثوق يُرهقه التعليق المتكرّر يُدرَج بيضاء بسببٍ مكتوب، وآخر ثبت عليه
    // شيء يُدرَج سوداء — وكلاهما كان يتطلّب الدخول إلى قاعدة البيانات.
    document.addEventListener('click', async function (e) {
        const b = e.target.closest('.js-aml-profile');
        if (!b) return;
        const uid = b.dataset.user;

        const j = await get(`/users/${uid}/profile`);
        const p = (j.success && j.meta.profile) || null;
        const evals = (j.success && j.meta.recent_evaluations) || [];

        const lvl = {low: 'success', medium: 'info', high: 'warning text-dark', very_high: 'danger', critical: 'danger'};
        const ovr = {none: 'بلا استثناء', whitelist: 'قائمة بيضاء', blacklist: 'قائمة سوداء'};

        document.getElementById('aml-profile-body').innerHTML = p ? `
            <div class="row g-3 mb-3">
                <div class="col-6"><div class="border rounded p-2">
                    <div class="small text-muted">درجة الخطر</div>
                    <div class="fs-4 fw-bold">${esc(p.current_risk_score)}</div></div></div>
                <div class="col-6"><div class="border rounded p-2">
                    <div class="small text-muted">المستوى</div>
                    <div><span class="badge bg-${lvl[p.risk_level] || 'secondary'}">${esc(p.risk_level)}</span></div></div></div>
                <div class="col-12"><div class="border rounded p-2">
                    <div class="small text-muted">الاستثناء اليدويّ</div>
                    <div>${esc(ovr[p.manual_override] || p.manual_override)}</div>
                    ${p.override_reason ? `<div class="small text-muted mt-1">${esc(p.override_reason)}</div>` : ''}</div></div>
            </div>
            <h6 class="small">آخر التقييمات</h6>
            <div class="table-responsive" style="max-height:220px;overflow:auto">
              <table class="table table-sm"><tbody>${
                evals.map(ev => `<tr><td class="small">${esc(ev.rule_code || ev.rule_id)}</td>
                    <td><span class="badge bg-${ev.matched ? 'warning text-dark' : 'light text-dark'}">${ev.matched ? 'طابقت' : 'لم تطابق'}</span></td>
                    <td class="small text-muted">${esc((ev.created_at || '').toString().slice(0, 16))}</td></tr>`).join('')
                || '<tr><td class="text-muted">لا تقييمات</td></tr>'}</tbody></table>
            </div>`
            : '<div class="alert alert-secondary">لا ملفّ خطر لهذا العميل بعد — يُنشأ عند أوّل استثناء يُطبَّق.</div>';

        document.getElementById('aml-profile-user').textContent = '#' + uid;
        document.getElementById('aml-profile').dataset.user = uid;
        new bootstrap.Modal(document.getElementById('aml-profile')).show();
    });

    document.addEventListener('click', async function (e) {
        const b = e.target.closest('.js-aml-override');
        if (!b) return;
        const uid = document.getElementById('aml-profile').dataset.user;
        const target = b.dataset.override;

        // السبب إلزاميّ: إدراجٌ في قائمةٍ بيضاء يُعطّل الرقابة عن شخصٍ بعينه،
        // ومن يراجع القرار بعد سنة لن يجد في يده غير ما كُتب هنا.
        const reason = prompt(`سبب وضع العميل في «${b.textContent.trim()}» (إلزامي — ١٠ أحرف على الأقل):`);
        if (!reason || reason.trim().length < 10) { alert('السبب إلزامي (١٠ أحرف على الأقل)'); return; }

        const j = await send(`/users/${uid}/override`, {override: target, reason: reason.trim()});
        alert(j.message || (j.success ? 'تم' : 'فشل'));
    });

    // ---------- التنبيهات ----------
    document.getElementById('aml-btn-alerts').onclick = loadAlerts;

    async function loadAlerts() {
        const st = document.getElementById('aml-alert-status').value;
        const sev = document.getElementById('aml-alert-severity').value;
        const box = document.getElementById('aml-alerts-list');
        box.innerHTML = '<div class="text-muted">جارٍ التحميل…</div>';
        const j = await get('/alerts?status=' + encodeURIComponent(st) + (sev ? '&severity=' + sev : ''));
        if (!j.success) { box.innerHTML = `<div class="alert alert-warning">${esc(j.message)}</div>`; return; }

        const sevBadge = {critical: 'danger', high: 'warning text-dark', medium: 'info', low: 'secondary'};
        const rows = (j.meta.items || []).map(a => `
            <tr>
                <td><span class="badge bg-${sevBadge[a.severity] || 'secondary'}">${esc(a.severity)}</span></td>
                <td>${esc(a.alert_type)}</td>
                <td class="small">${esc(a.description_ar || a.description || '—')}</td>
                <td class="small ${waitClass(a.created_at)}">${waited(a.created_at)}</td>
                <td>${a.status === 'open'
                    ? `<button class="btn btn-sm btn-outline-secondary js-aml-alert" data-ulid="${esc(a.alert_ulid)}">حلّ التنبيه</button>`
                    : '<span class="badge bg-success">محلول</span>'}</td>
            </tr>`).join('');

        box.innerHTML = `<div class="table-responsive"><table class="table table-sm table-hover" data-testid="aml-alerts-table">
            <thead><tr><th>الدرجة</th><th>النوع</th><th>الوصف</th><th>العمر</th><th></th></tr></thead>
            <tbody>${rows || '<tr><td colspan="5" class="text-muted text-center py-3">لا تنبيهات</td></tr>'}</tbody></table></div>`;
    }

    document.addEventListener('click', async function (e) {
        const b = e.target.closest('.js-aml-alert');
        if (!b) return;
        const note = prompt('ماذا فُعل بشأن هذا التنبيه؟ (إلزامي — 5 أحرف على الأقل):');
        if (!note || note.trim().length < 5) { alert('الملاحظة إلزامية (5 أحرف على الأقل)'); return; }
        const j = await send(`/alerts/${b.dataset.ulid}/resolve`, {note: note.trim()});
        alert(j.message || (j.success ? 'تم' : 'فشل'));
        loadAlerts();
    });

    // ---------- التحقيقات ----------
    //
    // الجسر الذي كان مفقوداً: النظام يرصد ويعلّق، ثمّ لا شيء يجمع عشرين
    // تنبيهاً على عميلٍ واحد في ملفٍّ له رقمٌ وضابطٌ وقرار. والمنظّم لا يسأل
    // «هل رأيتم؟» بل «أروني ملفّ القضية».
    document.addEventListener('click', async function (e) {
        const b = e.target.closest('.js-aml-open-case');
        if (!b) return;
        if (!confirm('فتح قضية تحقيق على هذه العملية؟')) return;
        const j = await send('/investigations', {flag_ulid: b.dataset.ulid, priority: 'high'});
        alert(j.message || (j.success ? 'تم' : 'فشل'));
        if (j.success) loadCases();
    });

    document.getElementById('aml-case-status').onchange = loadCases;

    const prioBadge = {critical: 'danger', high: 'warning text-dark', medium: 'info', low: 'secondary'};
    const prioLabel = {critical: 'حرجة', high: 'عالية', medium: 'متوسطة', low: 'منخفضة'};
    const caseStatus = {open: 'مفتوحة', investigating: 'قيد التحقيق',
                       pending_decision: 'تنتظر قراراً', closed: 'مغلقة', reopened: 'أُعيد فتحها'};

    async function loadCases() {
        const st = document.getElementById('aml-case-status').value;
        const box = document.getElementById('aml-cases');
        box.innerHTML = '<div class="list-group-item text-muted">جارٍ التحميل…</div>';
        const j = await get('/investigations' + (st ? '?status=' + st : '?status='));
        if (!j.success) { box.innerHTML = '<div class="list-group-item text-danger">تعذّر التحميل</div>'; return; }

        const items = j.meta.items || [];
        if (st === 'open' || st === '') {
            document.getElementById('aml-case-count').textContent =
                items.filter(i => i.status !== 'closed').length;
        }

        box.innerHTML = items.length ? items.map(c => `
            <button class="list-group-item list-group-item-action js-aml-case" data-id="${c.id}" data-testid="aml-case-${c.id}">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="font-monospace small">${esc(c.case_number)}</div>
                        <div class="fw-bold">${esc(c.subject_name)}</div>
                        <div class="small text-muted">${esc(c.subject_phone)}</div>
                        ${c.officer ? `<div class="small">الضابط: ${esc(c.officer)}</div>`
                                    : '<div class="small text-danger">بلا ضابط مُسنَد</div>'}
                    </div>
                    <div class="text-end">
                        <span class="badge bg-${prioBadge[c.priority] || 'secondary'}">${esc(prioLabel[c.priority] || c.priority)}</span>
                        <div class="small ${c.age_hours > 168 ? 'text-danger fw-bold' : 'text-muted'} mt-1">${c.age_hours} ساعة</div>
                        <div class="small">${esc(caseStatus[c.status] || c.status)}</div>
                    </div>
                </div>
            </button>`).join('')
            : '<div class="list-group-item text-muted text-center py-4">لا قضايا</div>';
    }

    document.addEventListener('click', async function (e) {
        const b = e.target.closest('.js-aml-case');
        if (!b) return;
        const j = await get('/investigations/' + b.dataset.id);
        if (!j.success) return;
        renderCase(j.meta);
    });

    function renderCase(c) {
        const open = c.status !== 'closed';

        // الخطّ الزمنيّ يُعرَض كاملاً بترتيبه: هو ملفّ القضية، وهو ما يُبرَز
        // للمنظّم. وحذفُ أيّ حدثٍ منه مستحيلٌ بالتصميم — يُضاف إليه ولا يُعدَّل.
        document.getElementById('aml-case-detail').innerHTML = `
            <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
                <div>
                    <div class="font-monospace text-muted">${esc(c.case_number)}</div>
                    <h5 class="mb-0">${esc(c.subject_name)}</h5>
                    <div class="small text-muted">${esc(c.subject_phone)} • #${c.subject_user_id}</div>
                </div>
                <div class="text-end">
                    <span class="badge bg-${prioBadge[c.priority] || 'secondary'}">${esc(prioLabel[c.priority] || c.priority)}</span>
                    <span class="badge bg-${open ? 'primary' : 'dark'}">${esc(caseStatus[c.status] || c.status)}</span>
                    <div class="small text-muted mt-1">${c.age_hours} ساعة</div>
                </div>
            </div>

            ${c.decision ? `<div class="alert alert-dark py-2">
                <strong>القرار:</strong> ${esc(c.decision)}
                ${c.closure_reason ? `<div class="small mt-1">${esc(c.closure_reason)}</div>` : ''}</div>` : ''}

            ${c.reports.length ? `<div class="alert alert-info py-2 small">
                البلاغات على هذه القضية:
                ${c.reports.map(r => `<span class="badge bg-secondary">${esc(r.report_number)} — ${esc(r.status)}</span>`).join(' ')}
            </div>` : ''}

            ${open ? `<div class="d-flex gap-2 flex-wrap mb-3">
                <button class="btn btn-sm btn-outline-primary js-case-do" data-do="evidence" data-id="${c.id}">+ دليل</button>
                <button class="btn btn-sm btn-outline-danger js-case-do" data-do="action" data-id="${c.id}">إجراء امتثال</button>
                <button class="btn btn-sm btn-outline-dark js-case-do" data-do="str" data-id="${c.id}">توليد بلاغ اشتباه</button>
                <button class="btn btn-sm btn-success js-case-do" data-do="close" data-id="${c.id}">إغلاق بقرار</button>
            </div>` : `<div class="mb-3">
                <button class="btn btn-sm btn-outline-warning js-case-do" data-do="reopen" data-id="${c.id}">إعادة الفتح</button>
            </div>`}

            <h6>الخطّ الزمنيّ <span class="small text-muted fw-normal">— يُضاف إليه ولا يُعدَّل</span></h6>
            <ul class="list-group list-group-flush" style="max-height:340px;overflow:auto">
                ${c.timeline.map(t => `
                    <li class="list-group-item px-0">
                        <div class="d-flex justify-content-between">
                            <span class="badge bg-light text-dark">${esc(t.type_label)}</span>
                            <small class="text-muted">${esc((t.at || '').slice(0, 16).replace('T', ' '))}</small>
                        </div>
                        ${t.note ? `<div class="small mt-1">${esc(t.note)}</div>` : ''}
                        <div class="small text-muted">${esc(t.actor)}</div>
                    </li>`).join('') || '<li class="list-group-item text-muted">لا أحداث</li>'}
            </ul>`;
    }

    const CASE_ACTIONS = {
        freeze_account: 'تجميد الحساب', freeze_transaction: 'تجميد العملية',
        request_kyc: 'طلب تحديث الهوية', request_source_of_funds: 'طلب إثبات مصدر الأموال',
        escalate: 'تصعيد التحقيق', blacklist: 'إدراج في القائمة السوداء',
    };
    const CASE_DECISIONS = {
        no_action: 'لا إجراء — نشاط مشروع', warning_issued: 'تنبيه العميل',
        account_frozen: 'تجميد الحساب', blacklisted: 'إدراج في القائمة السوداء',
        str_filed: 'رُفع بلاغ اشتباه',
    };

    document.addEventListener('click', async function (e) {
        const b = e.target.closest('.js-case-do');
        if (!b) return;
        const id = b.dataset.id;
        let j;

        if (b.dataset.do === 'evidence') {
            const note = prompt('ما وجدتَه (١٠ أحرف على الأقل — يبقى في الملفّ للأبد):');
            if (!note || note.trim().length < 10) { alert('نصّ الدليل قصير'); return; }
            j = await send(`/investigations/${id}/evidence`, {note: note.trim()});

        } else if (b.dataset.do === 'action') {
            const keys = Object.keys(CASE_ACTIONS);
            const pick = prompt('الإجراء:\n' + keys.map((k, i) => `${i + 1}) ${CASE_ACTIONS[k]}`).join('\n'));
            const act = keys[parseInt(pick, 10) - 1];
            if (!act) return;
            const reason = prompt(`سبب «${CASE_ACTIONS[act]}» (١٠ أحرف على الأقل):`);
            if (!reason || reason.trim().length < 10) { alert('السبب إلزامي'); return; }
            j = await send(`/investigations/${id}/action`, {action: act, reason: reason.trim()});

        } else if (b.dataset.do === 'str') {
            const narrative = prompt('سرد البلاغ — اشرح لماذا اشتُبه في هذا النشاط (٥٠ حرفاً على الأقل).\nهذا النصّ هو ما يقرؤه المنظّم:');
            if (!narrative || narrative.trim().length < 50) { alert('السرد قصير — البلاغ بلا شرح يُعاد إلينا'); return; }
            j = await send(`/investigations/${id}/str`, {narrative: narrative.trim()});

        } else if (b.dataset.do === 'close') {
            const keys = Object.keys(CASE_DECISIONS);
            const pick = prompt('القرار:\n' + keys.map((k, i) => `${i + 1}) ${CASE_DECISIONS[k]}`).join('\n'));
            const dec = keys[parseInt(pick, 10) - 1];
            if (!dec) return;
            const reason = prompt('سبب الإغلاق (٢٠ حرفاً على الأقل — هو ما يُراجَع لا القرار وحده):');
            if (!reason || reason.trim().length < 20) { alert('سبب الإغلاق قصير'); return; }
            j = await send(`/investigations/${id}/close`, {decision: dec, reason: reason.trim()});

        } else {
            const reason = prompt('سبب إعادة الفتح (١٠ أحرف على الأقل):');
            if (!reason || reason.trim().length < 10) { alert('السبب إلزامي'); return; }
            j = await send(`/investigations/${id}/reopen`, {reason: reason.trim()});
        }

        alert(j.message || (j.success ? 'تم' : 'فشل'));
        const detail = await get('/investigations/' + id);
        if (detail.success) renderCase(detail.meta);
        loadCases();
        loadReports();
    });

    // ---------- البلاغات التنظيمية ----------
    document.getElementById('aml-btn-reports').onclick = loadReports;

    document.getElementById('aml-btn-new-ctr').onclick = async function () {
        const uid = prompt('رقم العميل:');
        if (!uid) return;
        const amount = prompt('المبلغ (يجب أن يكون فوق حدّ بلاغ العملة):');
        if (!amount) return;
        const j = await send('/reports/ctr', {user_id: parseInt(uid, 10), amount: amount});
        alert(j.message || (j.success ? 'تم' : 'فشل'));
        loadReports();
    };

    async function loadReports() {
        const type = document.getElementById('aml-report-type').value;
        const st = document.getElementById('aml-report-status').value;
        const box = document.getElementById('aml-reports-list');
        box.innerHTML = '<div class="text-muted">جارٍ التحميل…</div>';

        const qs = [];
        if (type) qs.push('type=' + type);
        if (st) qs.push('status=' + st);
        const j = await get('/reports' + (qs.length ? '?' + qs.join('&') : ''));
        if (!j.success) { box.innerHTML = '<div class="alert alert-warning">تعذّر التحميل</div>'; return; }

        const s = j.meta.summary || {};
        document.getElementById('aml-report-count').textContent = s.pending_total || 0;

        // البلاغ المتأخّر مخالفةٌ بذاته — فالعدد وحده لا يكفي، ويُعرَض معه
        // أقدمُ بلاغٍ لم يُرسَل.
        const tile = (label, val, sub, cls) => `
            <div class="col-lg-3 col-6"><div class="card p-3 h-100 ${cls || ''}">
                <div class="small text-muted">${label}</div><div class="fs-4 fw-bold">${val}</div>
                ${sub ? `<div class="small text-muted">${sub}</div>` : ''}</div></div>`;

        document.getElementById('aml-report-summary').innerHTML =
            tile('بانتظار الإرسال', s.pending_total || 0,
                 s.oldest_pending_number ? `أقدمها ${esc(s.oldest_pending_number)} منذ ${s.oldest_pending_hours} ساعة` : '',
                 (s.pending_total || 0) > 0 ? 'border-danger' : '') +
            tile('اشتباه بانتظار الإرسال', s.pending_str || 0) +
            tile('عملة بانتظار الإرسال', s.pending_ctr || 0) +
            tile('أُرسل', (s.submitted_str || 0) + (s.submitted_ctr || 0),
                 `اشتباه ${s.submitted_str || 0} • عملة ${s.submitted_ctr || 0}`, 'border-success');

        const rows = (j.meta.items || []).map(r => `
            <tr class="${r.status !== 'submitted' && r.age_hours > 72 ? 'table-danger' : ''}">
                <td class="font-monospace small">${esc(r.report_number)}</td>
                <td><span class="badge bg-${r.report_type === 'STR' ? 'warning text-dark' : 'info'}">${esc(r.type_label)}</span></td>
                <td>${esc(r.subject_name)}<div class="small text-muted">${esc(r.subject_phone)}</div></td>
                <td>${esc(r.amount)}</td>
                <td><span class="badge bg-${r.status === 'submitted' ? 'success' : 'secondary'}">${esc(r.status_label)}</span>
                    ${r.external_reference ? `<div class="small text-muted">${esc(r.external_reference)}</div>` : ''}</td>
                <td class="small">${esc(r.generated_by)}</td>
                <td class="small ${r.status !== 'submitted' && r.age_hours > 72 ? 'text-danger fw-bold' : 'text-muted'}">${r.age_hours} ساعة</td>
                <td>${r.status !== 'submitted'
                    ? `<button class="btn btn-sm btn-success js-report-submit" data-id="${r.id}" data-num="${esc(r.report_number)}">تأكيد الإرسال</button>`
                    : ''}</td>
            </tr>`).join('');

        box.innerHTML = `<div class="table-responsive"><table class="table table-sm" data-testid="aml-reports-table">
            <thead><tr><th>الرقم</th><th>النوع</th><th>العميل</th><th>المبلغ</th><th>الحالة</th><th>المُولِّد</th><th>العمر</th><th></th></tr></thead>
            <tbody>${rows || '<tr><td colspan="8" class="text-muted text-center py-3">لا بلاغات</td></tr>'}</tbody></table></div>`;
    }

    document.addEventListener('click', async function (e) {
        const b = e.target.closest('.js-report-submit');
        if (!b) return;
        const ref = prompt(`مرجع الجهة المستقبِلة للبلاغ ${b.dataset.num} (إلزامي).\n\nبلاغٌ «مُرسَل» بلا مرجعٍ منها ادّعاءٌ لا إثبات.`);
        if (!ref || ref.trim().length < 3) { alert('المرجع إلزامي'); return; }
        const j = await send(`/reports/${b.dataset.id}/submit`,
            {external_reference: ref.trim(), note: prompt('ملاحظة (اختياري):') || null});
        alert(j.message || (j.success ? 'تم' : 'فشل'));
        loadReports();
    });

    // ---------- القواعد ----------
    document.getElementById('aml-btn-rules').onclick = loadRules;

    async function loadRules() {
        const box = document.getElementById('aml-rules-list');
        box.innerHTML = '<div class="text-muted">جارٍ التحميل…</div>';
        const j = await get('/rules');
        if (!j.success) { box.innerHTML = `<div class="alert alert-warning">${esc(j.message)}</div>`; return; }

        const items = j.meta.items || [];

        // قاعدةٌ مفعَّلة في الظلّ تبدو حمايةً وهي إحصاء. تُقال بصراحة في أعلى
        // الصفحة لا في عمودٍ يُمرَّر عليه البصر.
        const shadowed = items.filter(r => r.is_active && r.shadow_mode);
        document.getElementById('aml-shadow-banner').innerHTML = shadowed.length
            ? `<div class="alert alert-warning" data-testid="aml-shadow-banner">
                 <strong>${shadowed.length} قاعدة تعمل في وضع الظل</strong> — تُحصي المخالفات ولا تمنعها:
                 ${shadowed.map(r => esc(r.name_ar || r.code)).join('، ')}.
               </div>`
            : '';

        const actLabel = {allow: 'يسمح', flag: 'يعلّق', hold: 'يحجز', block: 'يمنع'};
        const rows = items.map(r => `
            <tr class="${r.is_active ? '' : 'opacity-50'}">
                <td class="font-monospace small">${esc(r.code)}</td>
                <td>${esc(r.name_ar)}<div class="small text-muted">${esc(r.description_ar || '')}</div></td>
                <td><span class="badge bg-${r.action_on_match === 'block' ? 'danger' : (r.action_on_match === 'hold' ? 'warning text-dark' : 'secondary')}">${esc(actLabel[r.action_on_match] || r.action_on_match)}</span></td>
                <td>${esc(r.risk_score_contribution)}</td>
                <td>${r.is_active
                    ? '<span class="badge bg-success">مفعَّلة</span>'
                    : '<span class="badge bg-secondary">موقوفة</span>'}</td>
                <td>${r.shadow_mode
                    ? '<span class="badge bg-warning text-dark">ظلّ — لا تمنع</span>'
                    : '<span class="badge bg-primary">نافذة</span>'}</td>
                <td class="text-nowrap">
                    <button class="btn btn-sm btn-outline-secondary js-aml-rule" data-do="toggle" data-id="${r.id}">${r.is_active ? 'إيقاف' : 'تفعيل'}</button>
                    <button class="btn btn-sm btn-outline-${r.shadow_mode ? 'primary' : 'warning'} js-aml-rule" data-do="shadow" data-id="${r.id}" data-shadow="${r.shadow_mode ? 1 : 0}" data-name="${esc(r.name_ar || r.code)}">
                        ${r.shadow_mode ? 'إخراج من الظل' : 'إعادة إلى الظل'}</button>
                </td>
            </tr>`).join('');

        box.innerHTML = `<div class="table-responsive"><table class="table table-sm" data-testid="aml-rules-table">
            <thead><tr><th>الرمز</th><th>القاعدة</th><th>الإجراء</th><th>وزن الخطر</th><th>الحالة</th><th>النفاذ</th><th></th></tr></thead>
            <tbody>${rows || '<tr><td colspan="7" class="text-muted text-center py-3">لا قواعد</td></tr>'}</tbody></table></div>`;
    }

    document.addEventListener('click', async function (e) {
        const b = e.target.closest('.js-aml-rule');
        if (!b) return;
        let j;
        if (b.dataset.do === 'toggle') {
            j = await send(`/rules/${b.dataset.id}/toggle`, {});
        } else {
            const wasShadow = b.dataset.shadow === '1';
            // إخراج قاعدةٍ من الظل يبدأ منعاً فوريّاً لعملياتٍ كانت تمرّ أمس.
            // يُؤكَّد لأنّ أثره على العملاء لا على اللوحة.
            const msg = wasShadow
                ? `«${b.dataset.name}» ستبدأ المنع فوراً — عملياتٌ كانت تمرّ أمس تُرفض اليوم. متابعة؟`
                : `«${b.dataset.name}» ستتوقّف عن المنع وتكتفي بالإحصاء. متابعة؟`;
            if (!confirm(msg)) return;
            j = await send(`/rules/${b.dataset.id}`, {shadow_mode: !wasShadow}, 'PATCH');
        }
        alert(j.message || (j.success ? 'تم' : 'فشل'));
        loadRules();
    });

    // أوّل ما يُفتح: المؤشّرات — منها يُعرَف أين يُنظَر.
    // ثمّ ما يحتاج قراراً: المعلَّق والعقوبات والقضايا والبلاغات.
    loadDash();
    loadFlagged();
    loadRules();
    loadSanctions();
    loadCases();
    loadReports();
})();
</script>
@endsection
