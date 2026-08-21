@extends('layouts.admin.app')

{{-- AMIAL-CHARITY-ADMIN-UI-002 — **لوحةُ التبرّعات كاملةً.**

     ══════════════════════════════════════════════════════════════════
     **الثمن الذي دُفع:** فتح صاحبُ المشروع اللوحة فوجد جدولاً واحداً
     عالقاً على «جارٍ التحميل…»، وقال: «لا يوجد إنشاء تبرّعات… يجب إنشاء
     تبرّعات مع التفاصيل من صور ومبلغ وتاريخ… ويجب إظهار المتبرّعين
     وطريقة سحب المال».

     **والخادمُ كان كاملاً منذ زمن**: إنشاءُ منظّمةٍ · إنشاءُ حملةٍ
     باستهدافٍ وصورةِ غلافٍ ومعرضٍ ومهلة · اعتمادٌ وإيقاف · توليدُ تسوية.
     **وواجهتُه كانت جدولَ منظّماتٍ بثلاثة أزرار.** مبنيٌّ ولا يُوصَل
     إليه — نمطُ العطل الأكثر تكراراً هنا.

     والمتبرّعون وحدَهم كانوا ناقصين من الخادم أيضاً، فأُضيفت نقطتُهم.
     ══════════════════════════════════════════════════════════════════ --}}

@section('title', translate('لوحة التبرعات'))

@section('content')
<div class="content container-fluid" dir="rtl">
    <h4 class="fw-bold mb-3" style="color:var(--amial-primary)">🎗️ {{ translate('لوحة التبرعات — الجمعيات الخيرية') }}</h4>

    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-orgs" type="button">الجمعيات</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-camps" type="button">الحملات</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-settle" type="button">سحب المال (التسويات)</button></li>
    </ul>

    <div class="tab-content">

        {{-- ═══ الجمعيات ═══ --}}
        <div class="tab-pane fade show active" id="tab-orgs">
            <div class="card mb-3">
                <div class="card-header"><h5 class="card-header-title mb-0">➕ جمعية جديدة</h5></div>
                <div class="card-body">
                    <form id="org-form" data-testid="org-create-form">
                        <div class="row g-2">
                            <div class="col-md-4"><label class="form-label small">الاسم بالعربيّة *</label>
                                <input name="name_ar" class="form-control" required maxlength="200"></div>
                            <div class="col-md-4"><label class="form-label small">رقم الترخيص *</label>
                                <input name="license_number" class="form-control" required maxlength="100"></div>
                            <div class="col-md-4"><label class="form-label small">هاتف التواصل *</label>
                                <input name="contact_phone" class="form-control" required dir="ltr"></div>
                            <div class="col-12"><label class="form-label small">الوصف *</label>
                                <textarea name="description_ar" class="form-control" rows="2" required maxlength="5000"></textarea></div>
                            <div class="col-md-4"><label class="form-label small">البنك</label>
                                <input name="bank_name" class="form-control"></div>
                            <div class="col-md-4"><label class="form-label small">رقم الحساب البنكيّ</label>
                                <input name="bank_account_number" class="form-control" dir="ltr"></div>
                            <div class="col-md-4"><label class="form-label small">صاحب الحساب</label>
                                <input name="bank_account_holder" class="form-control"></div>
                            {{-- AMIAL-CHARITY-UPLOAD-001 — من الجهاز هنا أيضاً.
                                 فالشكوى واحدةٌ في النموذجين، ولا معنى لأن
                                 يُرفع غلافُ الحملة ويُلصق شعارُ الجمعيّة. --}}
                            <div class="col-md-6"><label class="form-label small">شعار الجمعية</label>
                                <input type="file" id="org-logo-file" class="form-control"
                                       accept="image/jpeg,image/png,image/webp" data-testid="org-logo-file">
                                <input type="hidden" name="logo_url" id="org-logo-url">
                                <div class="mt-2" id="org-logo-preview"></div></div>
                            <div class="col-md-6"><label class="form-label small">صورة الغلاف</label>
                                <input type="file" id="org-cover-file" class="form-control"
                                       accept="image/jpeg,image/png,image/webp" data-testid="org-cover-file">
                                <input type="hidden" name="cover_image_url" id="org-cover-url">
                                <div class="mt-2" id="org-cover-preview"></div></div>
                        </div>
                        <button class="btn btn-primary mt-3" type="submit">إنشاء الجمعية</button>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h5 class="card-header-title mb-0">الجمعيات</h5></div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0" id="orgsTable">
                        <thead class="thead-light"><tr>
                            <th>الجمعية</th><th>الحالة</th><th>الحملات</th>
                            <th>إجمالي التبرعات</th><th>إجراءات</th>
                        </tr></thead>
                        <tbody><tr><td colspan="5" class="text-muted">جارٍ التحميل…</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- ═══ الحملات ═══ --}}
        <div class="tab-pane fade" id="tab-camps">
            <div class="card mb-3">
                <div class="card-header"><h5 class="card-header-title mb-0">➕ حملة تبرّع جديدة</h5></div>
                <div class="card-body">
                    <form id="camp-form" data-testid="campaign-create-form">
                        <div class="row g-2">
                            <div class="col-md-4"><label class="form-label small">الجمعية *</label>
                                <select name="org_ulid" id="camp-org" class="form-select" required></select></div>
                            <div class="col-md-4"><label class="form-label small">التصنيف *</label>
                                <select name="category_id" id="camp-cat" class="form-select" required></select></div>
                            <div class="col-md-4"><label class="form-label small">المبلغ المستهدف *</label>
                                <input name="target_amount" type="number" min="1" step="1" class="form-control" required></div>
                            <div class="col-12"><label class="form-label small">عنوان الحملة *</label>
                                <input name="title_ar" class="form-control" required maxlength="200"></div>
                            <div class="col-12"><label class="form-label small">الوصف *</label>
                                <textarea name="description_ar" class="form-control" rows="2" required maxlength="5000"></textarea></div>
                            {{-- AMIAL-CHARITY-META-001 — **بدايةٌ ونهاية.** كان
                                 النموذجُ يسأل عن الانتهاء وحده، فحملةٌ تُجهَّز
                                 مقدَّماً تُنشر ساعةَ اعتمادها لا ساعةَ موسمها. --}}
                            <div class="col-md-3"><label class="form-label small">تاريخ البداية</label>
                                <input name="start_at" type="date" class="form-control">
                                <div class="form-text">فارغٌ = تبدأ فور الاعتماد.</div></div>
                            <div class="col-md-3"><label class="form-label small">تاريخ الانتهاء</label>
                                <input name="deadline_at" type="date" class="form-control">
                                <div class="form-text">بعد الغد فأبعد.</div></div>
                            <div class="col-md-3"><label class="form-label small">عدد المستفيدين</label>
                                <input name="beneficiary_count" type="number" min="1" class="form-control"></div>
                            <div class="col-md-3"><label class="form-label small">الموقع</label>
                                <input name="location_ar" class="form-control" maxlength="200"></div>

                            {{-- **العلامات.** «عاجل» حكمٌ إداريّ يرفع الحملةَ في
                                 ترتيب التطبيق ويضع عليها شارةً حمراء — لا وصفٌ
                                 تكتبه الجمعيّةُ عن نفسها. --}}
                            <div class="col-12">
                                <div class="border rounded p-2 d-flex flex-wrap gap-4">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" value="1"
                                               name="is_urgent" id="camp-urgent" data-testid="campaign-urgent">
                                        <label class="form-check-label" for="camp-urgent">
                                            <span class="badge bg-danger">عاجل</span>
                                            <small class="text-muted d-block">يتصدّر قائمة التطبيق</small>
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" value="1"
                                               name="is_featured" id="camp-featured" data-testid="campaign-featured">
                                        <label class="form-check-label" for="camp-featured">
                                            <span class="badge bg-warning text-dark">مميّزة</span>
                                            <small class="text-muted d-block">تظهر في شريط الواجهة</small>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            {{-- AMIAL-CHARITY-UPLOAD-001 — **من الجهاز لا برابط.**
                                 مكتبُ جمعيّةٍ لا يملك مستضيفَ صور، فطلبُ رابطٍ
                                 يعني حملةً بلا صورة — أو لا حملةَ أصلاً. --}}
                            <div class="col-md-6">
                                <label class="form-label small">صورة الغلاف</label>
                                <input type="file" id="camp-cover-file" class="form-control"
                                       accept="image/jpeg,image/png,image/webp"
                                       data-testid="campaign-cover-file">
                                <input type="hidden" name="cover_image_url" id="camp-cover-url">
                                <div class="mt-2" id="camp-cover-preview"></div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small">صور إضافية</label>
                                <input type="file" id="camp-gallery-file" class="form-control" multiple
                                       accept="image/jpeg,image/png,image/webp"
                                       data-testid="campaign-gallery-file">
                                <div class="form-text">عشرةٌ حدّاً أقصى · ٥ ميغابايت للصورة.</div>
                                <div class="mt-2 d-flex flex-wrap gap-2" id="camp-gallery-preview"></div>
                            </div>
                        </div>
                        <button class="btn btn-primary mt-3" type="submit"
                                data-testid="campaign-submit">إنشاء الحملة</button>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h5 class="card-header-title mb-0">الحملات</h5></div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0" id="campsTable">
                        <thead class="thead-light"><tr>
                            <th>الحملة</th><th>الجمعية</th><th>الاكتمال</th>
                            <th>المتبرّعون</th><th>الحالة</th><th>إجراءات</th>
                        </tr></thead>
                        <tbody><tr><td colspan="6" class="text-muted">جارٍ التحميل…</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- ═══ التسويات: كيف يخرج المال إلى الجمعية ═══ --}}
        <div class="tab-pane fade" id="tab-settle">
            <div class="alert alert-soft-info">
                <strong>كيف يخرج مالُ التبرّعات:</strong>
                يتبرّع المستخدمُ من محفظته فيُخصم فوراً ويُضاف إلى رصيد الحملة،
                وتحتفظ المنصّةُ برسمها. ثمّ تُولَّد <strong>تسويةٌ لفترةٍ محدّدة</strong>
                تجمع صافيَ ما استحقّته الجمعيةُ فيها، فتُصرف إلى حسابها البنكيّ
                المسجَّل أو عبر وكيلٍ نقداً. <strong>ولا يُصرف قبل تسوية</strong> —
                فالمالُ يبقى محسوباً في المنصّة إلى أن يُوثَّق خروجُه.
            </div>

            <div class="card mb-3">
                <div class="card-header"><h5 class="card-header-title mb-0">توليد تسوية</h5></div>
                <div class="card-body">
                    <form id="settle-form" data-testid="settlement-form">
                        <div class="row g-2 align-items-end">
                            <div class="col-12 col-xl-5"><label class="form-label small">الجمعية *</label>
                                <select name="org_ulid" id="settle-org" class="form-select" required></select></div>
                            <div class="col-6 col-xl-3"><label class="form-label small">من *</label>
                                <input name="period_start" type="date" class="form-control" required></div>
                            <div class="col-6 col-xl-3"><label class="form-label small">إلى *</label>
                                <input name="period_end" type="date" class="form-control" required></div>
                            <div class="col-12 col-xl-1"><button class="btn btn-primary w-100" type="submit">ولّد التسوية</button></div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h5 class="card-header-title mb-0">التسويات</h5></div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0" id="settleTable">
                        <thead class="thead-light"><tr>
                            <th>الجمعية</th><th>الفترة</th><th>الصافي</th>
                            <th>الحالة</th><th>قناة الصرف</th><th>أُنشئت</th><th></th>
                        </tr></thead>
                        <tbody><tr><td colspan="7" class="text-muted">جارٍ التحميل…</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- لوحُ المتبرّعين --}}
    <div class="modal fade" id="donors-modal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">متبرّعو الحملة</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
          </div>
          <div class="modal-body" id="donors-body" data-testid="donors-body">جارٍ التحميل…</div>
        </div>
      </div>
    </div>

    {{-- AMIAL-CHARITY-PAYOUT-001 — **لوحُ صرف المال.**

         ثلاثُ قنواتٍ لا واحدة، والمستلمُ يُطلب برقم هاتفه لا برقمه
         الداخليّ. ونافذةُ المشروع لا نافذةُ المتصفّح: هذا نموذجٌ فيه
         خياراتٌ وحقول، و`prompt` لا تحمل غيرَ سطرٍ واحد. --}}
    <div class="modal fade" id="payout-modal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <form id="payout-form" data-testid="payout-form">
            <div class="modal-header">
              <h5 class="modal-title">صرف مال التبرّعات</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body">
              <div class="alert alert-soft-warning mb-3">
                المبلغ المستحقّ: <b id="payout-amount" data-testid="payout-amount">—</b> ر.ي.
                <div class="small mt-1">
                  يُخصم من <b>عهدة التبرّعات</b> في الدفتر، ولا يُصرف مرّتين.
                </div>
              </div>
              <input type="hidden" name="_ulid" id="payout-ulid">
              <div class="mb-2">
                <label class="form-label small">قناة الصرف *</label>
                <select name="method" id="payout-method" class="form-select" required>
                  <option value="wallet">إلى محفظة أميال باي — يُشحن الرصيد فوراً</option>
                  <option value="agent">عبر وكيل — يدفع نقداً ويأخذ رصيداً مقابله</option>
                  <option value="bank">حوالة بنكيّة — لا يمرّ بمحفظة</option>
                </select>
              </div>
              <div class="mb-2" id="payout-recipient-wrap">
                <label class="form-label small">رقم هاتف المستلم *</label>
                <input name="recipient_phone" id="payout-recipient" class="form-control" dir="ltr"
                       placeholder="7XXXXXXXX">
                <div class="form-text" id="payout-recipient-hint">صاحبُ المحفظة التي يدخلها المال.</div>
              </div>
              <div class="mb-2">
                <label class="form-label small">المرجع *</label>
                <input name="reference" class="form-control" required minlength="3" maxlength="100"
                       placeholder="رقم الحوالة أو رقم الإيصال">
              </div>
              <div class="mb-0">
                <label class="form-label small">ملاحظات</label>
                <textarea name="notes" class="form-control" rows="2" maxlength="500"></textarea>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إلغاء</button>
              <button type="submit" class="btn btn-success" data-testid="payout-submit">صرف</button>
            </div>
          </form>
        </div>
      </div>
    </div>
</div>
@endsection

@push('script')
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(() => {
    const base = '{{ url('admin/amial/charity') }}';
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c =>
        ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const fmt = (n) => Number(n || 0).toLocaleString('en-US', {maximumFractionDigits: 4});

    let orgs = [];

    async function api(url, opts = {}) {
        // مفتاح التفرّد تضيفه `amial-idempotency.js` المحمّل من قالب الإدارة
        // قبل هذا الملف. ذلك الملف يملك fallback للـ WebView القديم؛ النداء
        // المباشر لـ crypto.randomUUID كان يرمي قبل أن يصل زر الإيقاف/التسوية
        // إلى الخادم، ويحوّل زرّاً حقيقياً إلى زرٍ يوهم المستخدم بالعمل.
        const headers = {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf};
        const r = await fetch(url, {
            headers,
            ...opts,
        });
        const j = await r.json().catch(() => ({}));
        if (!r.ok || j.success === false) throw new Error(j.message || ('خطأ ' + r.status));
        return j;
    }

    const pick = (j, ...keys) => {
        for (const k of keys) {
            if (j?.meta?.[k]) return j.meta[k];
            if (j?.data?.[k]) return j.data[k];
            if (j?.[k]) return j[k];
        }
        return [];
    };

    // ── الجمعيات ───────────────────────────────────────────────────
    async function loadOrgs() {
        const tb = document.querySelector('#orgsTable tbody');
        try {
            const j = await api(`${base}/organizations`);
            orgs = pick(j, 'organizations', 'items', 'orgs');

            tb.innerHTML = orgs.length ? orgs.map(o => `
                <tr>
                    <td><b>${esc(o.name_ar ?? o.name ?? '')}</b>
                        <small class="text-muted d-block text-monospace">${esc(o.org_ulid ?? o.ulid ?? '')}</small></td>
                    <td><span class="badge badge-soft-secondary">${esc(o.verification_status ?? o.status ?? '—')}</span></td>
                    <td>${fmt(o.campaigns_count ?? o.total_campaigns)}</td>
                    <td>${fmt(o.total_collected ?? o.total_donations)}</td>
                    <td>
                        ${o.verification_status === 'pending_verification' ? `<button class="btn btn-sm btn-success" data-org-act="verify" data-ulid="${esc(o.org_ulid ?? o.ulid)}">اعتماد</button>
                        <button class="btn btn-sm btn-outline-danger" data-org-act="reject" data-ulid="${esc(o.org_ulid ?? o.ulid)}">رفض</button>` : ''}
                        ${o.verification_status === 'verified' ? `<button class="btn btn-sm btn-outline-secondary" data-org-act="suspend" data-ulid="${esc(o.org_ulid ?? o.ulid)}">تعليق</button>` : ''}
                    </td>
                </tr>`).join('')
                : '<tr><td colspan="5" class="text-muted">لا جمعيات بعد — أنشئ واحدة من الأعلى</td></tr>';

            const opts = orgs.map(o =>
                `<option value="${esc(o.org_ulid ?? o.ulid)}">${esc(o.name_ar ?? o.name)}</option>`).join('');
            document.getElementById('camp-org').innerHTML = opts;
            document.getElementById('settle-org').innerHTML = opts;
        } catch (e) {
            tb.innerHTML = `<tr><td colspan="5" class="text-danger">${esc(e.message)}</td></tr>`;
        }
    }

    // ── الحملات ────────────────────────────────────────────────────
    async function loadCamps() {
        const tb = document.querySelector('#campsTable tbody');
        try {
            const j = await api(`${base}/campaigns`);
            const camps = pick(j, 'campaigns', 'items');

            tb.innerHTML = camps.length ? camps.map(c => {
                const target = Number(c.target_amount || 0);
                const cur = Number(c.current_amount || 0);
                // **نسبةُ الاكتمال تُحسب من المبلغين** لا من عمودٍ مخزَّن.
                // وهدفٌ صفرٌ لا يُقسم عليه — تُقال «بلا هدف».
                const pct = target > 0 ? Math.min(100, Math.round((cur / target) * 100)) : null;

                // **العلاماتُ تُرى حيث تُتَّخذ القرارات** — ولوحةٌ تُخزّن
                // «عاجل» ولا تعرضه تجعل المديرَ يعيد ضبطَ ما ضبطه.
                const tags = [
                    c.is_urgent ? '<span class="badge bg-danger">عاجل</span>' : '',
                    c.is_featured ? '<span class="badge bg-warning text-dark">مميّزة</span>' : '',
                    c.category?.name_ar
                        ? `<span class="badge badge-soft-info">${esc(c.category.name_ar)}</span>` : '',
                ].filter(Boolean).join(' ');

                const window_ = [
                    c.start_at ? `من ${esc(String(c.start_at).slice(0, 10))}` : '',
                    c.deadline_at ? `إلى ${esc(String(c.deadline_at).slice(0, 10))}` : '',
                ].filter(Boolean).join(' · ');

                return `<tr>
                    <td>
                        ${c.cover_image_url
                            ? `<img src="${esc(c.cover_image_url)}" alt="" loading="lazy"
                                    style="width:56px;height:40px;object-fit:cover;border-radius:6px"
                                    class="float-start ms-2">` : ''}
                        <b>${esc(c.title_ar ?? '')}</b>
                        <div class="mt-1">${tags || '<span class="text-muted small">بلا علامات</span>'}</div>
                        ${window_ ? `<small class="text-muted d-block">${window_}</small>` : ''}
                        <small class="text-muted d-block text-monospace">${esc(c.campaign_ulid ?? '')}</small></td>
                    <td><small>${esc(c.organization?.name_ar ?? '—')}</small></td>
                    <td style="min-width:160px">
                        ${pct === null
                            ? '<span class="text-muted small">بلا هدف محدَّد</span>'
                            : `<div class="progress" style="height:16px">
                                 <div class="progress-bar bg-success" style="width:${pct}%">${pct}%</div>
                               </div>
                               <small class="text-muted">${fmt(cur)} / ${fmt(target)}</small>`}
                    </td>
                    <td>${fmt(c.donor_count)}</td>
                    <td><span class="badge badge-soft-secondary">${esc(c.status ?? '')}</span></td>
                    <td>
                        <button class="btn btn-sm btn-outline-primary" data-donors="${esc(c.campaign_ulid)}">المتبرّعون</button>
                        ${c.status === 'pending_approval' || c.status === 'paused' ? `<button class="btn btn-sm btn-success" data-camp-act="approve" data-ulid="${esc(c.campaign_ulid)}">اعتماد</button>` : ''}
                        ${c.status === 'active' ? `<button class="btn btn-sm btn-outline-warning" data-camp-act="pause" data-ulid="${esc(c.campaign_ulid)}">إيقاف</button>` : ''}
                    </td>
                </tr>`;
            }).join('') : '<tr><td colspan="6" class="text-muted">لا حملات بعد</td></tr>';
        } catch (e) {
            tb.innerHTML = `<tr><td colspan="6" class="text-danger">${esc(e.message)}</td></tr>`;
        }
    }

    async function loadCats() {
        try {
            const j = await api(`${base}/categories`);
            const cats = pick(j, 'categories', 'items');
            const icons = {
                emergency: '🚨', food: '🍚', medical: '🏥', water: '💧', home: '🏠',
                school: '📚', child: '🧒', mosque: '🕌', heart: '❤️',
            };
            document.getElementById('camp-cat').innerHTML = cats.map(c =>
                `<option value="${c.id}">${icons[c.icon] ?? '•'} ${esc(c.name_ar ?? c.code)}</option>`
            ).join('');
        } catch (e) {
            // **الغيابُ يُقال بسببه** — «لا تصنيفات» وحدها تُقرأ «الجدولُ
            // فارغ»، وقد يكون المسارُ ساقطاً. والفرقُ يغيّر ما يُفعل.
            document.getElementById('camp-cat').innerHTML =
                `<option value="">— تعذّر جلبُ التصنيفات: ${esc(e.message)} —</option>`;
        }
    }

    async function loadSettlements() {
        const tb = document.querySelector('#settleTable tbody');
        try {
            const j = await api(`${base}/settlements`);
            const rows = pick(j, 'settlements', 'items');
            // **الاسمُ الحقيقيّ للعمود `payable_amount`.** كان السطر يقرأ
            // `net_amount ?? amount` — وكلاهما غيرُ موجود، فيُطبع «٠» على
            // تسويةٍ فيها مالٌ حقيقيّ. (القاعدة السادسة: الرقمُ من مصدره.)
            tb.innerHTML = rows.length ? rows.map(s => {
                const paid = s.status === 'transferred';
                const chan = {bank: 'حوالة بنكيّة', wallet: 'محفظة أميال', agent: 'وكيل'}[s.payout_method];
                return `
                <tr>
                    <td><small>${esc(s.organization?.name_ar ?? s.org_name ?? '—')}</small></td>
                    <td><small>${esc(s.period_start ?? '')} → ${esc(s.period_end ?? '')}</small></td>
                    <td class="fw-bold">${fmt(s.payable_amount)}</td>
                    <td><span class="badge badge-soft-${paid ? 'success' : 'warning'}">${esc(s.status ?? '')}</span></td>
                    <td><small>${paid ? esc(chan ?? '—') : '<span class="text-muted">لم تُصرف</span>'}</small></td>
                    <td><small>${esc(s.created_at ?? '')}</small></td>
                    <td>${paid
                        ? `<small class="text-muted">${esc(s.bank_transfer_reference ?? '')}</small>`
                        : `<button class="btn btn-sm btn-success" data-payout="${esc(s.settlement_ulid)}"
                                   data-amount="${esc(s.payable_amount)}"
                                   data-testid="settlement-payout">صرف المال</button>`}</td>
                </tr>`; }).join('')
                : '<tr><td colspan="7" class="text-muted">لا تسويات بعد</td></tr>';
        } catch (e) {
            tb.innerHTML = `<tr><td colspan="7" class="text-danger">${esc(e.message)}</td></tr>`;
        }
    }

    // ── الأفعال ────────────────────────────────────────────────────
    document.addEventListener('click', async (e) => {
        const org = e.target.closest('[data-org-act]');
        const camp = e.target.closest('[data-camp-act]');
        const donors = e.target.closest('[data-donors]');

        if (org) {
            const label = {verify: 'اعتماد', reject: 'رفض', suspend: 'تعليق'}[org.dataset.orgAct];
            // **نافذةُ المشروع لا نافذةُ المتصفّح** (AMIAL-UI-DIALOGS-002).
            if (!await amialDialog.ask(`${label} هذه الجمعية؟`, {okLabel: label, danger: org.dataset.orgAct !== 'verify'})) return;
            let reason = null;
            if (org.dataset.orgAct !== 'verify') {
                reason = await amialDialog.request('اكتب سبب القرار (10 أحرف على الأقل):', {title: label, okLabel: label});
                if (!reason || reason.trim().length < 10) return;
            }
            try { await api(`${base}/organizations/${org.dataset.ulid}/${org.dataset.orgAct}`, {method: 'POST', body: JSON.stringify(reason ? {reason: reason.trim()} : {})});
                  await amialDialog.show('تمّ'); loadOrgs(); }
            catch (err) { await amialDialog.show(err.message); }
        }

        if (camp) {
            const label = camp.dataset.campAct === 'approve' ? 'اعتماد' : 'إيقاف';
            if (!await amialDialog.ask(`${label} هذه الحملة؟`, {okLabel: label})) return;
            let reason = null;
            if (camp.dataset.campAct === 'pause') {
                reason = await amialDialog.request('اكتب سبب الإيقاف (10 أحرف على الأقل):', {title: label, okLabel: label});
                if (!reason || reason.trim().length < 10) return;
            }
            try { await api(`${base}/campaigns/${camp.dataset.ulid}/${camp.dataset.campAct}`, {method: 'POST', body: JSON.stringify(reason ? {reason: reason.trim()} : {})});
                  await amialDialog.show('تمّ'); loadCamps(); }
            catch (err) { await amialDialog.show(err.message); }
        }

        if (donors) {
            const body = document.getElementById('donors-body');
            body.innerHTML = 'جارٍ التحميل…';
            new bootstrap.Modal(document.getElementById('donors-modal')).show();
            try {
                const j = await api(`${base}/campaigns/${donors.dataset.donors}/donors`);
                const d = j.meta ?? j.data ?? j;
                const list = d.donors ?? [];

                body.innerHTML = `
                    <div class="mb-3">
                        <b>${esc(d.campaign?.title ?? '')}</b>
                        <div class="small text-muted">${fmt(d.campaign?.current_amount)} من ${fmt(d.campaign?.target_amount)}
                        ${d.campaign?.progress === null ? '' : `· ${d.campaign?.progress}%`}</div>
                    </div>
                    ${list.length ? `<div class="table-responsive"><table class="table table-sm">
                        <thead><tr><th>المتبرّع</th><th>المبلغ</th><th>رسم المنصّة</th><th>صافي الجمعية</th><th>الحالة</th><th>الوقت</th></tr></thead>
                        <tbody>${list.map(x => `<tr>
                            <td>${x.is_anonymous
                                ? '<span class="text-muted">متبرّع مجهول</span>'
                                : `${esc(x.donor?.name ?? '—')}<small class="text-muted d-block text-monospace">${esc(x.donor?.phone ?? '')}</small>`}
                                ${x.message ? `<small class="d-block fst-italic">${esc(x.message)}</small>` : ''}</td>
                            <td class="fw-bold">${fmt(x.amount)}</td>
                            <td>${fmt(x.platform_fee)}</td>
                            <td class="text-success">${fmt(x.net_to_charity)}</td>
                            <td><small>${esc(x.status)}</small></td>
                            <td><small>${esc(x.donated_at)}</small></td>
                        </tr>`).join('')}</tbody></table></div>`
                    : '<div class="text-muted">لا متبرّعين بعد</div>'}`;
            } catch (err) {
                body.innerHTML = `<div class="alert alert-danger mb-0">${esc(err.message)}</div>`;
            }
        }

        // AMIAL-CHARITY-PAYOUT-001 — فتحُ لوح الصرف.
        const pay = e.target.closest('[data-payout]');
        if (pay) {
            document.getElementById('payout-ulid').value = pay.dataset.payout;
            document.getElementById('payout-amount').textContent = fmt(pay.dataset.amount);
            document.getElementById('payout-form').reset();
            document.getElementById('payout-ulid').value = pay.dataset.payout;
            syncRecipient();
            new bootstrap.Modal(document.getElementById('payout-modal')).show();
        }
    });

    // **الحقلُ يُخفى ويُنزع إلزامُه معاً.** ولو خُفي وبقي `required` لرفض
    // المتصفّحُ الإرسالَ بلا رسالةٍ مرئيّة — زرٌّ يُضغط ولا يحدث شيء.
    function syncRecipient() {
        const m = document.getElementById('payout-method').value;
        const wrap = document.getElementById('payout-recipient-wrap');
        const input = document.getElementById('payout-recipient');
        const hint = document.getElementById('payout-recipient-hint');
        const needed = m !== 'bank';
        wrap.style.display = needed ? '' : 'none';
        input.required = needed;
        if (!needed) input.value = '';
        hint.textContent = m === 'agent'
            ? 'رقمُ الوكيل — يُشحن رصيدُه ويدفع النقدَ الورقيّ للجمعية.'
            : 'صاحبُ المحفظة التي يدخلها المال.';
    }
    document.getElementById('payout-method').addEventListener('change', syncRecipient);

    const formData = (f) => Object.fromEntries(new FormData(f).entries());

    document.getElementById('org-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        try {
            const d = formData(e.target);
            Object.keys(d).forEach(k => { if (d[k] === '') delete d[k]; });
            await api(`${base}/organizations`, {method: 'POST', body: JSON.stringify(d)});
            await amialDialog.show('أُنشئت الجمعية — اعتمدها لتظهر في التطبيق');
            e.target.reset();
            ['org-logo-preview', 'org-cover-preview'].forEach(id =>
                document.getElementById(id).innerHTML = '');
            loadOrgs();
        } catch (err) { await amialDialog.show(err.message); }
    });

    // ── AMIAL-CHARITY-UPLOAD-001: الصور من الجهاز ──────────────────
    //
    // **الرفعُ لا يمرّ بـ`api()`**: تلك تفرض `Content-Type: application/json`،
    // ومع `FormData` يجب تركُ المتصفّح يضع `multipart/form-data` بحدوده —
    // وإلّا وصل الملفُّ ولا يُقرأ، والرسالةُ «الصورة مطلوبة» على ملفٍّ أُرسل.
    async function uploadOne(file) {
        const fd = new FormData();
        fd.append('file', file);
        const r = await fetch(`${base}/uploads`, {
            method: 'POST',
            // يدير قالب الإدارة Idempotency-Key مع إعادة المحاولة بأمان.
            headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': csrf},
            body: fd,
        });
        const j = await r.json().catch(() => ({}));
        if (!r.ok || j.success === false) throw new Error(j.message || `تعذّر رفعُ ${file.name}`);
        return (j.meta ?? j.data ?? j).url;
    }

    let galleryUrls = [];

    /** صورةٌ واحدةٌ: تُرفع، ويُملأ الحقلُ المخفيّ، وتُعرض معاينتُها. */
    function wireSingleUpload(fileId, urlId, previewId) {
        document.getElementById(fileId).addEventListener('change', async (e) => {
            const f = e.target.files[0];
            const box = document.getElementById(previewId);
            const hidden = document.getElementById(urlId);
            if (!f) { box.innerHTML = ''; hidden.value = ''; return; }
            box.innerHTML = '<span class="text-muted small">جارٍ الرفع…</span>';
            try {
                const url = await uploadOne(f);
                hidden.value = url;
                // **المعاينةُ إقرارُ وصول**: بلا صورةٍ تُرى، لا يعرف الرافعُ
                // أوصلت أم لا حتّى تُنشر الحملةُ بلا غلاف.
                box.innerHTML = `<img src="${esc(url)}" alt="" style="max-height:90px;border-radius:8px">`;
            } catch (err) {
                // **ويُفرَّغ الحقلُ عند الفشل** — رابطٌ قديمٌ باقٍ بعد رفعةٍ
                // ساقطةٍ يحفظ صورةً غيرَ التي اختارها.
                hidden.value = '';
                e.target.value = '';
                box.innerHTML = `<span class="text-danger small">${esc(err.message)}</span>`;
            }
        });
    }

    wireSingleUpload('camp-cover-file', 'camp-cover-url', 'camp-cover-preview');
    wireSingleUpload('org-logo-file', 'org-logo-url', 'org-logo-preview');
    wireSingleUpload('org-cover-file', 'org-cover-url', 'org-cover-preview');

    document.getElementById('camp-gallery-file').addEventListener('change', async (e) => {
        const files = Array.from(e.target.files);
        const box = document.getElementById('camp-gallery-preview');
        if (!files.length) return;

        if (galleryUrls.length + files.length > 10) {
            box.innerHTML = '<span class="text-danger small">عشرةٌ حدّاً أقصى.</span>';
            e.target.value = '';
            return;
        }

        for (const f of files) {
            const cell = document.createElement('div');
            cell.className = 'small text-muted';
            cell.textContent = `${f.name}…`;
            box.appendChild(cell);
            try {
                const url = await uploadOne(f);
                galleryUrls.push(url);
                cell.className = '';
                cell.innerHTML = `<img src="${esc(url)}" alt=""
                     style="width:64px;height:64px;object-fit:cover;border-radius:6px">`;
            } catch (err) {
                cell.className = 'small text-danger';
                cell.textContent = err.message;
            }
        }
        e.target.value = '';
    });

    document.getElementById('camp-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const d = formData(e.target);
        const orgUlid = d.org_ulid; delete d.org_ulid;

        // **الروابطُ تأتي من الرفع لا من حقلٍ نصّيّ.**
        if (galleryUrls.length) d.gallery_images = galleryUrls;

        // خانةُ التأشير تُرسل '1' حين تُؤشَّر ولا تُرسل أصلاً حين لا تُؤشَّر —
        // فالغيابُ يُقال صراحةً `false` لئلّا يبقى ما أُلغي مؤشَّراً.
        d.is_urgent = d.is_urgent ? 1 : 0;
        d.is_featured = d.is_featured ? 1 : 0;

        Object.keys(d).forEach(k => { if (d[k] === '') delete d[k]; });

        try {
            await api(`${base}/organizations/${orgUlid}/campaigns`, {method: 'POST', body: JSON.stringify(d)});
            await amialDialog.show('أُنشئت الحملة — اعتمدها لتظهر في التطبيق');
            e.target.reset();
            galleryUrls = [];
            document.getElementById('camp-cover-url').value = '';
            document.getElementById('camp-cover-preview').innerHTML = '';
            document.getElementById('camp-gallery-preview').innerHTML = '';
            loadCamps();
        } catch (err) { await amialDialog.show(err.message); }
    });

    document.getElementById('settle-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        try {
            await api(`${base}/settlements/generate`, {method: 'POST', body: JSON.stringify(formData(e.target))});
            await amialDialog.show('وُلّدت التسوية — راجعها قبل الصرف');
            loadSettlements();
        } catch (err) { await amialDialog.show(err.message); }
    });

    document.getElementById('payout-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const d = formData(e.target);
        const ulid = d._ulid; delete d._ulid;
        Object.keys(d).forEach(k => { if (d[k] === '') delete d[k]; });

        // **مالٌ يخرج — فيُسأل قبل خروجه**، والسؤالُ يذكر المبلغَ والقناة
        // لا «هل أنت متأكّد؟» وحدها.
        const label = {bank: 'حوالةً بنكيّة', wallet: 'إلى محفظة أميال', agent: 'عبر وكيل'}[d.method];
        const amount = document.getElementById('payout-amount').textContent;
        if (!await amialDialog.ask(`صرفُ ${amount} ر.ي ${label}؟ لا رجعةَ بعد التنفيذ.`,
                                   {okLabel: 'صرف', danger: true})) return;

        try {
            const j = await api(`${base}/settlements/${ulid}/payout`,
                                {method: 'POST', body: JSON.stringify(d)});
            bootstrap.Modal.getInstance(document.getElementById('payout-modal'))?.hide();
            await amialDialog.show(j.message ?? 'تمّ الصرف');
            loadSettlements();
        } catch (err) { await amialDialog.show(err.message); }
    });

    loadOrgs(); loadCamps(); loadCats(); loadSettlements();
})();
</script>
@endpush
