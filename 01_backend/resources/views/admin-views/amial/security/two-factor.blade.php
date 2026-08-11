@extends('layouts.admin.app')

{{--
    AMIAL-2FA-DOOR-001 — شاشةُ المصادقة الثنائية.

    خمسُ نقاطٍ تعمل منذ v1.8 بلا شاشةٍ واحدة تفتحها. فالميزةُ مبنيّةٌ
    ولا يستطيع أحدٌ تفعيلها — وهو نمطُ العطل الأكثر تكراراً في المشروع.
--}}

@section('title', 'المصادقة الثنائية')

@section('content')
<div class="content container-fluid" id="tf" data-testid="two-factor-admin">

    <div class="page-header">
        <h1 class="page-header-title">🔐 المصادقة الثنائية</h1>
        <p class="text-muted mb-0">
            رمزٌ ثانٍ من تطبيق المصادقة يُطلب بعد كلمة المرور. وكلمةُ مرورٍ
            مسروقةٌ وحدها لا تفتح اللوحة.
        </p>
    </div>

    <div id="tf-banner"></div>

    <div class="row">
        <div class="col-lg-7">
            <div class="card mb-3"><div class="card-body" id="tf-state">
                <div class="text-center text-muted py-4">جارٍ التحميل…</div>
            </div></div>
        </div>

        <div class="col-lg-5">
            <div class="card"><div class="card-body small">
                <h6 class="mb-2">كيف تعمل</h6>
                <ol class="ps-3 mb-2">
                    <li>اضغط «ابدأ الإعداد» — يظهر رمزُ QR.</li>
                    <li>امسحه بتطبيق Google Authenticator أو Authy.</li>
                    <li>أدخل الرمز الظاهر في التطبيق لتأكيد الربط.</li>
                    <li><strong>احفظ رموز الاسترداد</strong> — بها تدخل لو ضاع هاتفك.</li>
                </ol>
                <div class="alert alert-warning py-2 mb-0">
                    رموزُ الاسترداد تُعرض <strong>مرّةً واحدة</strong>. ومن لم يحفظها
                    وفقد هاتفه يحتاج مديراً آخر ليُعطّل المصادقة عن حسابه.
                </div>
            </div></div>
        </div>
    </div>
</div>

{{-- نافذةُ الإعداد --}}
<div class="modal fade" id="tf-setup-modal" tabindex="-1">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header">
      <h5 class="modal-title">إعداد المصادقة الثنائية</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body" id="tf-setup-body"></div>
  </div></div>
</div>

<script>
(function () {
    const B = '{{ url("admin/amial/2fa") }}';
    const CSRF = '{{ csrf_token() }}';

    const esc = s => String(s ?? '—').replace(/[&<>"']/g,
        c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    async function req(path, method, body) {
        const r = await fetch(B + path, {
            method: method || 'GET',
            headers: {'Accept': 'application/json', 'Content-Type': 'application/json',
                      'X-CSRF-TOKEN': CSRF},
            body: body ? JSON.stringify(body) : undefined,
        });
        const j = await r.json().catch(() => ({}));
        if (!r.ok || j.success === false) {
            throw new Error(j.message || ('تعذّر الطلب (' + r.status + ')'));
        }
        return j.meta || {};
    }

    function banner(msg, kind) {
        document.getElementById('tf-banner').innerHTML =
            '<div class="alert alert-' + (kind || 'info') + '">' + esc(msg) + '</div>';
    }

    async function load() {
        const box = document.getElementById('tf-state');
        try {
            const d = await req('/status');

            box.innerHTML = d.enabled
                ? ('<div class="d-flex align-items-center gap-3 flex-wrap">'
                   + '<span class="badge bg-success fs-6">مفعّلة</span>'
                   + '<div class="small text-muted">منذ ' + esc(d.confirmed_at || '—') + '</div>'
                   + '<div class="ms-auto">'
                   + '<button class="btn btn-sm btn-outline-primary me-1" data-act="regen"'
                   + ' data-testid="tf-regen">رموز استرداد جديدة</button>'
                   + '<button class="btn btn-sm btn-outline-danger" data-act="disable"'
                   + ' data-testid="tf-disable">تعطيل</button>'
                   + '</div></div>')
                // **«غير مفعّلة» تُقال صراحةً** — لا يُترك الفراغُ يُقرأ
                // أماناً. (القاعدة ٧)
                : ('<div class="d-flex align-items-center gap-3 flex-wrap">'
                   + '<span class="badge bg-danger fs-6">غير مفعّلة</span>'
                   + '<div class="small text-muted">كلمةُ المرور وحدها تفتح حسابك</div>'
                   + '<button class="btn btn-sm btn-primary ms-auto" data-act="setup"'
                   + ' data-testid="tf-setup">ابدأ الإعداد</button>'
                   + '</div>');
        } catch (e) {
            box.innerHTML = '<div class="alert alert-danger mb-0">' + esc(e.message) + '</div>';
        }
    }

    document.addEventListener('click', async function (e) {
        const btn = e.target.closest('[data-act]');
        if (!btn) return;

        const act = btn.getAttribute('data-act');

        try {
            if (act === 'setup') {
                const d = await req('/setup', 'POST');

                document.getElementById('tf-setup-body').innerHTML =
                    '<div class="alert alert-warning">'
                    + '<strong>احفظ رموز الاسترداد الآن</strong> — لن تُعرض مرّةً أخرى.'
                    + '</div>'
                    + '<div class="mb-3"><div class="small text-muted mb-1">'
                    + 'أضف هذا العنوان يدويّاً في تطبيق المصادقة:</div>'
                    + '<code class="d-block p-2 bg-light rounded" dir="ltr"'
                    + ' style="word-break:break-all">' + esc(d.qr_uri) + '</code></div>'
                    + '<div class="mb-3"><div class="small text-muted mb-1">أو أدخل المفتاح:</div>'
                    + '<code class="d-block p-2 bg-light rounded" dir="ltr">' + esc(d.secret) + '</code></div>'
                    + '<div class="mb-3"><div class="small fw-bold mb-1">رموز الاسترداد:</div>'
                    + '<div class="p-2 bg-light rounded font-monospace" dir="ltr">'
                    + (d.recovery_codes || []).map(esc).join('<br>') + '</div></div>'
                    + '<label class="form-label">أدخل الرمز الظاهر في التطبيق للتأكيد</label>'
                    + '<div class="input-group">'
                    + '<input class="form-control" id="tf-confirm-code" maxlength="6"'
                    + ' inputmode="numeric" data-testid="tf-confirm-code" placeholder="······">'
                    + '<button class="btn btn-primary" data-act="confirm"'
                    + ' data-testid="tf-confirm">تأكيد</button></div>';

                new bootstrap.Modal(document.getElementById('tf-setup-modal')).show();
                return;
            }

            if (act === 'confirm') {
                const code = (document.getElementById('tf-confirm-code').value || '').trim();
                if (code.length !== 6) { banner('الرمز ستّةُ أرقام', 'danger'); return; }

                await req('/confirm', 'POST', {code});
                bootstrap.Modal.getInstance(document.getElementById('tf-setup-modal')).hide();
                banner('فُعّلت المصادقة الثنائية — ستُطلب عند الدخول القادم', 'success');
                load();
                return;
            }

            if (act === 'disable') {
                const pwd = prompt('كلمة المرور للتأكيد:');
                if (!pwd) return;

                await req('/disable', 'POST', {password: pwd});
                banner('عُطّلت المصادقة الثنائية', 'warning');
                load();
                return;
            }

            if (act === 'regen') {
                const code = prompt('أدخل الرمز من التطبيق:');
                if (!code) return;

                const d = await req('/regenerate-recovery-codes', 'POST', {code});
                document.getElementById('tf-setup-body').innerHTML =
                    '<div class="alert alert-warning"><strong>احفظها الآن</strong> — '
                    + 'الرموزُ القديمة بطلت.</div>'
                    + '<div class="p-2 bg-light rounded font-monospace" dir="ltr">'
                    + (d.recovery_codes || []).map(esc).join('<br>') + '</div>';
                new bootstrap.Modal(document.getElementById('tf-setup-modal')).show();
                return;
            }
        } catch (err) {
            banner(err.message, 'danger');
        }
    });

    load();
})();
</script>
@endsection
