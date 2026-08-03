{{-- AMIAL-AGENT-SETTINGS-001 — إعدادات الشركة.

     ══════════════════════════════════════════════════════════════════
     **لماذا لم تكن موجودة؟** لأنّ كلّ إعدادٍ بُني مع ميزته وبقي عندها،
     ولم يُسأل يوماً: «أين يذهب المستعمل ليضبطه؟».

     فساعاتُ عمل الفرع لها نقطةُ نهاية منذ شهور **ولا زرّ لها**. وحدُّ
     تنبيه النقد المنخفض — الذي تُبنى عليه تنبيهات واتساب كلُّها — لم
     يكن يُضبط من أيّ مكان، فكان تنبيهٌ مبنيٌّ ومُختبَر يعمل على عتبةٍ
     صفريّة لا تُطلق شيئاً أبداً. --}}

<div class="tab-pane fade" id="ag-settings">

    {{-- الأمان أوّلاً: وهو الوحيد الذي لا يُضبط من هنا، ويجب أن يُقال. --}}
    <div class="card p-3 mb-3">
        <h6 class="mb-2">🔐 أمان الاتّصال</h6>
        <div id="sg-security"></div>
    </div>

    <div class="row g-3">
        <div class="col-lg-7">
            {{-- حدود الخزائن وساعات العمل — لكلّ فرع --}}
            <div class="card p-3 mb-3">
                <h6 class="mb-2">🏬 إعدادات الفروع</h6>
                <div class="form-text mb-2">
                    حدّ التنبيه هو ما تُبنى عليه رسالة «نقد فرعك منخفض».
                    <strong>وبقاؤه صفراً يعني أنّها لن تصل أبداً.</strong>
                </div>
                <div id="sg-branches"></div>
            </div>

            {{-- التعاميم --}}
            <div class="card p-3 mb-3">
                <div class="d-flex align-items-center mb-2">
                    <h6 class="mb-0">📢 تعاميم لشبابيكك</h6>
                    <button class="btn btn-sm btn-primary ms-auto" id="sg-ann-new">+ تعميم</button>
                </div>
                <div class="form-text mb-2">
                    تظهر أعلى شاشة الموظّف قبل أن يبدأ — لا في بريدٍ لا يُفتح.
                </div>
                <div id="sg-announcements"></div>
            </div>
        </div>

        <div class="col-lg-5">
            {{-- بيانات الشركة ورابط الدخول --}}
            <div class="card p-3 mb-3">
                <h6 class="mb-2">🏦 شركتك</h6>
                <div id="sg-company" class="small"></div>
            </div>

            {{-- كلمة سرّي --}}
            <div class="card p-3 mb-3">
                <h6 class="mb-2">🔑 كلمة سرّي</h6>
                <div class="form-text mb-2">
                    مديرُك يستطيع تصفيرها — <strong>وحدك تستطيع تغييرها بلا أن يعرفها</strong>.
                </div>
                <div class="mb-2"><label class="form-label">كلمة السرّ الحاليّة</label>
                    <input type="password" class="form-control" id="sg-cur" dir="ltr"></div>
                <div class="mb-2"><label class="form-label">الجديدة (٦ أحرف فأكثر)</label>
                    <input type="password" class="form-control" id="sg-new" dir="ltr"></div>
                <div class="mb-2"><label class="form-label">تأكيد الجديدة</label>
                    <input type="password" class="form-control" id="sg-new2" dir="ltr"></div>
                <div class="text-danger small mb-2" id="sg-pw-err"></div>
                <button class="btn btn-outline-primary w-100" id="sg-pw-save">تغيير كلمة السرّ</button>
            </div>
        </div>
    </div>
</div>

{{-- نافذة التعميم --}}
<div class="modal fade" id="sg-ann-modal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">تعميم جديد</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="mb-2"><label class="form-label">العنوان</label>
                <input class="form-control" id="sg-ann-title" placeholder="مثال: صيانة الليلة"></div>
            <div class="mb-2"><label class="form-label">النصّ</label>
                <textarea class="form-control" id="sg-ann-body" rows="3"></textarea></div>
            <div class="row g-2 mb-2">
                <div class="col-6"><label class="form-label">الشدّة</label>
                    <select class="form-select" id="sg-ann-sev">
                        <option value="info">معلومة</option>
                        <option value="warning">تحذير</option>
                        <option value="critical">حرِج</option>
                    </select></div>
                <div class="col-6"><label class="form-label">من يقرؤه</label>
                    <select class="form-select" id="sg-ann-aud">
                        <option value="all">الجميع</option>
                        <option value="tellers">الصرّافون</option>
                        <option value="managers">المديرون</option>
                    </select></div>
            </div>
            <div class="mb-2"><label class="form-label">ينتهي في (اختياريّ)</label>
                <input type="date" class="form-control" id="sg-ann-end">
                <div class="form-text">تعميمٌ بلا نهاية يبقى معروضاً حتى يُوقفه أحد.</div></div>
            <div class="text-danger small" id="sg-ann-err"></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-primary" id="sg-ann-save">نشر</button>
        </div>
    </div></div>
</div>

@once
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
document.addEventListener('DOMContentLoaded', function () {
    const root = '{{ url('agent') }}';
    const $ = (id) => document.getElementById(id);
    if (!$('sg-security')) return;              // ليس في هذه الصفحة

    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c =>
        ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const fmt = (n) => Number(n || 0).toLocaleString('en-US', {maximumFractionDigits: 0});
    const csrf = () => (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    async function get(p) {
        const r = await fetch(root + p, {headers: {'Accept': 'application/json'}});
        const j = await r.json().catch(() => ({}));
        if (!r.ok) throw new Error(j.message || 'تعذّر الاتّصال');
        return j;
    }

    async function post(p, body) {
        const r = await fetch(root + p, {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'Accept': 'application/json',
                      'X-CSRF-TOKEN': csrf()},
            body: JSON.stringify(body || {}),
        });
        const j = await r.json().catch(() => ({}));
        if (!r.ok) throw new Error(j.message || 'تعذّر التنفيذ');
        return j;
    }

    // ── الأمان ───────────────────────────────────────────────────────
    //
    // **يُقال العطل ويُقال حلُّه.** «الاتّصال غير مشفَّر» وحدها تُخيف ولا
    // تُرشد، فيقرؤها صاحب الشركة ولا يعرف ما يفعل بها.
    function renderSecurity(s) {
        if (s.secure) {
            $('sg-security').innerHTML =
                `<div class="alert alert-success py-2 mb-0">
                    <strong>${esc(s.headline)}</strong>
                    <div class="small mt-1">النطاق: <code dir="ltr">${esc(s.host)}</code>
                    · كوكي الجلسة ${s.cookie_secure ? 'محميّة' : 'غير محميّة'}</div>
                 </div>`;
            return;
        }

        // الشهادة المجّانيّة لا تُصدَر لعنوان IP — وهذه أوّل عقبةٍ عمليّة
        // ولا يذكرها أحدٌ حتى تُجرَّب وتفشل.
        const ipNote = s.host_is_ip
            ? `<li>عنوانك الحاليّ <code dir="ltr">${esc(s.host)}</code> رقمٌ لا اسم —
                 <strong>والشهادات المجّانيّة لا تُصدَر لعنوان رقميّ</strong>.
                 فالخطوة الأولى اسمُ نطاق.</li>`
            : '';

        $('sg-security').innerHTML =
            `<div class="alert alert-danger py-2 mb-2">
                <strong>${esc(s.headline)}</strong>
                <div class="small mt-1">
                    كلُّ ما يُكتب في هذه الصفحة — ومنه كلمات سرّ موظّفيك —
                    يمرّ عبر الشبكة بلا تشفير. ومن يعترضه يقرؤه كما تقرؤه.
                </div>
             </div>
             <div class="small">
                <strong>الحلّ في ثلاث خطوات:</strong>
                <ol class="mb-0 ps-3">
                    ${ipNote}
                    <li>اشترِ اسم نطاق ووجّهه (سجلّ A) إلى عنوان خادمك.</li>
                    <li>في Coolify: افتح التطبيق ← <strong>Domains</strong> ← اكتب
                        <code dir="ltr">https://your-domain</code> ← احفظ.
                        تُصدَر الشهادة تلقائياً من Let's Encrypt خلال دقائق.</li>
                    <li>لا شيء بعدها في التطبيق: كوكي الجلسة تصير محميّةً
                        <strong>من نفسها</strong> حين يصير الاتّصال مشفَّراً.</li>
                </ol>
             </div>`;
    }

    // ── الفروع ───────────────────────────────────────────────────────
    function renderBranches(rows) {
        if (!rows.length) {
            $('sg-branches').innerHTML =
                '<div class="text-muted small">لا فروع بعد — أنشئ فرعك الأوّل من تبويب «الفروع».</div>';
            return;
        }

        $('sg-branches').innerHTML = rows.map(b => `
            <div class="border rounded p-2 mb-2" data-sg-branch="${b.id}">
                <div class="d-flex align-items-center flex-wrap gap-2 mb-2">
                    <strong>${esc(b.name)}</strong>
                    <span class="badge bg-dark">${esc(b.code)}</span>
                    ${b.alerts_configured
                        ? '<span class="badge bg-success">التنبيه مضبوط</span>'
                        : '<span class="badge bg-warning text-dark">⚠️ لا حدّ تنبيه — لن تصلك رسالة نقدٍ منخفض</span>'}
                    <span class="ms-auto small text-muted">في الخزنة ${fmt(b.cash_on_hand)}</span>
                </div>
                <div class="row g-2">
                    <div class="col-6">
                        <label class="form-label small mb-0">حدّ التنبيه (نقدٌ منخفض)</label>
                        <input type="number" min="0" class="form-control form-control-sm"
                               data-sg="min" value="${esc(Math.round(Number(b.min_cash_alert)))}" dir="ltr">
                    </div>
                    <div class="col-6">
                        <label class="form-label small mb-0">السقف الأعلى (٠ = بلا سقف)</label>
                        <input type="number" min="0" class="form-control form-control-sm"
                               data-sg="max" value="${esc(Math.round(Number(b.max_cash_on_hand)))}" dir="ltr">
                    </div>
                </div>
                <button class="btn btn-sm btn-outline-primary mt-2" data-sg-save="${b.id}">حفظ حدود الفرع</button>
            </div>`).join('');
    }

    $('sg-branches').addEventListener('click', async (e) => {
        const b = e.target.closest('button[data-sg-save]');
        if (!b) return;
        const box = b.closest('[data-sg-branch]');
        try {
            const j = await post(`/branches/${b.dataset.sgSave}/thresholds`, {
                min_cash_alert: box.querySelector('[data-sg="min"]').value || 0,
                max_cash_on_hand: box.querySelector('[data-sg="max"]').value || 0,
            });
            alert(j.message);
            load();
        } catch (err) { alert(err.message); }
    });

    // ── التعاميم ─────────────────────────────────────────────────────
    function renderAnnouncements(rows) {
        $('sg-announcements').innerHTML = rows.length ? rows.map(a => `
            <div class="d-flex align-items-center gap-2 border-bottom py-2">
                <span class="badge bg-${a.severity === 'critical' ? 'danger'
                    : (a.severity === 'warning' ? 'warning text-dark' : 'info text-dark')}">
                    ${esc(a.title)}</span>
                <span class="small text-muted flex-grow-1">${esc(a.body)}</span>
                <button class="btn btn-sm ${a.is_active ? 'btn-outline-danger' : 'btn-outline-success'}"
                        data-sg-ann="${a.id}">${a.is_active ? 'إيقاف' : 'إعادة'}</button>
            </div>`).join('')
            : '<div class="text-muted small">لا تعاميم.</div>';
    }

    $('sg-announcements').addEventListener('click', async (e) => {
        const b = e.target.closest('button[data-sg-ann]');
        if (!b) return;
        try {
            const j = await post(`/announcements/${b.dataset.sgAnn}/toggle`, {});
            alert(j.message);
            load();
        } catch (err) { alert(err.message); }
    });

    $('sg-ann-new').addEventListener('click', () => {
        $('sg-ann-err').textContent = '';
        new bootstrap.Modal($('sg-ann-modal')).show();
    });

    $('sg-ann-save').addEventListener('click', async () => {
        $('sg-ann-err').textContent = '';
        try {
            const j = await post('/announcements', {
                title: $('sg-ann-title').value,
                body: $('sg-ann-body').value,
                severity: $('sg-ann-sev').value,
                audience: $('sg-ann-aud').value,
                ends_at: $('sg-ann-end').value || null,
            });
            bootstrap.Modal.getInstance($('sg-ann-modal')).hide();
            alert(j.message);
            $('sg-ann-title').value = $('sg-ann-body').value = '';
            load();
        } catch (e) { $('sg-ann-err').textContent = e.message; }
    });

    // ── كلمة سرّي ────────────────────────────────────────────────────
    $('sg-pw-save').addEventListener('click', async () => {
        $('sg-pw-err').textContent = '';

        // **التأكيد يُفحص هنا قبل الإرسال.** ومن غيره يُغيّرها الخادم إلى
        // ما كُتب في الحقل الأوّل، فيُقفل الحساب على صاحبه بخطأ حرف.
        if ($('sg-new').value !== $('sg-new2').value) {
            $('sg-pw-err').textContent = 'الجديدة وتأكيدها غير متطابقين';
            return;
        }

        try {
            const j = await post('/settings/password', {
                current: $('sg-cur').value,
                password: $('sg-new').value,
                password_confirmation: $('sg-new2').value,
            });
            alert(j.message);
            $('sg-cur').value = $('sg-new').value = $('sg-new2').value = '';
        } catch (e) { $('sg-pw-err').textContent = e.message; }
    });

    // ── التحميل ──────────────────────────────────────────────────────
    async function load() {
        let m;
        try { m = (await get('/settings')).meta || {}; }
        catch (e) {
            $('sg-security').innerHTML =
                `<div class="alert alert-danger py-2 mb-0">${esc(e.message)}</div>`;
            return;
        }

        renderSecurity(m.security || {});
        renderBranches(m.branches || []);
        renderAnnouncements(m.announcements || []);

        const c = m.company || {};
        const me = m.me || {};
        $('sg-company').innerHTML =
            `<div class="d-flex justify-content-between py-1">
                <span>الاسم</span><strong>${esc(c.name)}</strong></div>
             <div class="d-flex justify-content-between py-1">
                <span>الهاتف</span><span dir="ltr">${esc(c.phone)}</span></div>
             <hr class="my-2">
             <div class="d-flex justify-content-between py-1">
                <span>أنت</span><strong>${esc(me.name || '—')}</strong></div>
             <div class="d-flex justify-content-between py-1">
                <span>رمزك</span>
                <span class="badge bg-dark font-monospace" dir="ltr">${esc(me.username || '—')}</span></div>
             <div class="d-flex justify-content-between py-1">
                <span>دورك</span><span>${esc(me.role_label || '—')}</span></div>
             <hr class="my-2">
             <div class="small text-muted">موظّفوك يدخلون من:</div>
             <code class="user-select-all d-block mt-1" dir="ltr">${esc(c.portal_url)}</code>`;
    }

    // تُحمَّل عند فتح التبويب لا عند تحميل الصفحة: إعداداتٌ تُقرأ مرّةً
    // في الشهر لا تستحقّ نداءً في كلّ فتحةِ شبّاك.
    //
    // **وإن لم يوجد زرُّ التبويب تُحمَّل فوراً.** فمكوّنٌ لا يعمل إلّا
    // بوجود عنصرٍ خارجه هشّ: يكفي أن يتغيّر `data-bs-target` في ملفٍّ
    // آخر ليصمت هذا بلا خطأٍ في أيّ سجلّ — شاشةٌ تُفتح فارغةً أبداً.
    const tab = document.querySelector('[data-bs-target="#ag-settings"]');

    if (tab) {
        tab.addEventListener('shown.bs.tab', load, {once: false});
    } else {
        load();
    }
});
</script>
@endonce
