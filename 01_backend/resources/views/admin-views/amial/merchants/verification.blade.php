@extends('layouts.admin.app')
@section('title', 'توثيق التجّار')
@section('content')
{{--
    AMIAL-MERCHANT-VERIFY-ADMIN-001 — **التاجرُ يقدّم، ولم يكن أحدٌ يعتمد.**

    ثلاثةُ أفعالٍ مبنيّةٌ في المتحكّم منذ كُتبت وبلا مسارٍ ولا شاشة، فيبقى
    التاجرُ «قيد المراجعة» بلا نهاية ولا خطأ في أيّ سجلّ.

    **وتوثيقُ النشاط لا يوثّق الشخص** — والشاشةُ تعرض حالةَ الهويّة بجانب
    الطلب، فيعرف المراجعُ أنّ اعتمادَه هنا لا يفتح سقفَ المال إن كانت
    هويّةُ صاحبه ناقصة.
--}}
<style>
    .mv-doc{display:inline-block;padding:.25rem .6rem;margin:.15rem;border-radius:999px;
        font-size:var(--amial-text-xs);text-decoration:none}
    .mv-doc.on{background:color-mix(in srgb, var(--amial-info) 12%, transparent);color:var(--amial-info)}
    .mv-doc.off{background:color-mix(in srgb, var(--amial-text-muted) 12%, transparent);
        color:var(--amial-text-muted)}
    .mv-chip{display:inline-block;padding:.15rem .55rem;border-radius:999px;font-size:var(--amial-text-xs)}
</style>

<div class="content container-fluid" style="max-width:1180px">
    <nav aria-label="breadcrumb" class="mb-1">
        <ol class="breadcrumb mb-0" style="font-size:var(--amial-text-xs)">
            <li class="breadcrumb-item"><a href="{{ route('admin.amial.workspace.index') }}">مساحة العمل</a></li>
            <li class="breadcrumb-item active" aria-current="page">توثيق التجّار</li>
        </ol>
    </nav>
    <h2 class="page-header-title mb-1">توثيق التجّار</h2>
    <p class="text-muted mb-3" style="font-size:var(--amial-text-sm)">
        وثائقُ النشاط التجاريّ. <strong>ولا يُوثّق هذا هويّةَ صاحب المتجر</strong> —
        تلك من طابور مراجعة الهويّة، وحالتُها معروضةٌ في كلّ بطاقة.
    </p>

    <ul class="nav nav-pills gap-2 mb-3 flex-wrap" id="mv-tabs">
        @foreach([
            'pending_review' => 'بانتظار المراجعة',
            'resubmission_required' => 'يحتاج إعادة رفع',
            'verified' => 'موثَّق',
            'rejected' => 'مرفوض',
            'all' => 'الكلّ',
        ] as $key => $label)
            <li class="nav-item">
                <button class="nav-link {{ $key === 'pending_review' ? 'active' : '' }}"
                        type="button" data-mv-status="{{ $key }}">
                    {{ $label }} <span class="badge bg-secondary" data-mv-count="{{ $key }}">—</span>
                </button>
            </li>
        @endforeach
    </ul>

    <div id="mv-banner"></div>
    <div class="row g-3" id="mv-cards">
        <div class="col-12 text-muted">جارٍ التحميل…</div>
    </div>
</div>

<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(function () {
    // **المعرّفاتُ تُقرأ ويُفحَص وجودُها** — سطرٌ يقرأ `null` يموت بصمت،
    // فتُضغط الأزرارُ ولا يحدث شيء ولا رسالة. (القاعدة التاسعة.)
    var cards = document.getElementById('mv-cards');
    var tabs = document.getElementById('mv-tabs');
    var banner = document.getElementById('mv-banner');
    if (!cards || !tabs || !banner) return;

    var base = @json(url('admin/amial/merchants/verification'));
    var token = @json(csrf_token());
    var status = 'pending_review';

    function esc(v) {
        return String(v === null || v === undefined ? '' : v)
            .replace(/[&<>"']/g, function (c) {
                return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
            });
    }

    function say(msg, kind) {
        banner.innerHTML = '<div class="alert alert-' + (kind || 'info') + ' py-2">' + esc(msg) + '</div>';
    }

    async function post(url, body) {
        var r = await fetch(url, {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': token,
                'Accept': 'application/json'},
            body: JSON.stringify(body || {}),
        });
        var j = await r.json().catch(function () { return {}; });
        // **والرفضُ يصل بنصّه** — «فشل» وحدَها تُرسل المراجعَ إلى الدعم.
        return {ok: r.ok, body: j};
    }

    function card(r) {
        var docs = (r.documents || []).map(function (d) {
            return d.uploaded
                ? '<a class="mv-doc on" href="' + esc(d.url) + '" target="_blank" rel="noopener">📎 ' + esc(d.label) + '</a>'
                : '<span class="mv-doc off">✗ ' + esc(d.label) + '</span>';
        }).join('');

        var id = r.identity || {};
        // **وحالةُ الهويّة تُقال بجانب الطلب** — فلا يظنّ المراجعُ أنّ
        // اعتمادَه هنا فتح سقفَ المال وهو لم يفتحه.
        var idNote = id.verified
            ? '<div class="small" style="color:var(--amial-success)">✓ هويّة صاحب المتجر موثّقة</div>'
            : '<div class="small" style="color:var(--amial-warning)">⚠ هويّة صاحب المتجر غير موثّقة'
              + ((id.missing && id.missing.length)
                  ? ' — ينقص: ' + esc(id.missing.join('، ')) : '')
              + '<br>توثيقُ المتجر لا يفتح سقفَ المال بدونها.</div>';

        // AMIAL-KYC-DUP-001 — **تاريخُ انتهاء الهويّة يُقال حيث يُعتمَد.**
        //
        // و«لا تاريخَ عندنا» تُكتب صراحةً ولا تُترك فراغاً: الفراغُ يُقرأ
        // «سليمة»، وهو ما تمنعه القاعدةُ السابعة.
        var ex = id.expiry || {};
        var exText = {
            EXPIRED: ['danger', '⛔ هويّةٌ منتهية'],
            DUE:     ['warning', '⏳ تقترب من الانتهاء'],
            VALID:   ['success', '✓ هويّةٌ سارية'],
            UNKNOWN: ['muted', '؟ لا تاريخَ انتهاءٍ مسجَّل']
        }[ex.state || 'UNKNOWN'] || ['muted', '؟ حالةٌ غيرُ معروفة'];

        var exNote = '<div class="small" style="color:var(--amial-' + exText[0] + ')">'
            + exText[1]
            + (ex.expires_at ? ' — ' + esc(ex.expires_at) : '')
            + (ex.days !== null && ex.days !== undefined
                ? ' (' + (ex.days < 0 ? 'مضى ' + Math.abs(ex.days) : 'بقي ' + ex.days) + ' يوماً)' : '')
            + '</div>';

        // **وخانةُ فحص الهويّة بجانب زرّ التوثيق** — لا في شاشةٍ أخرى:
        // القرارُ يُتّخذ هنا، فالفحصُ يقع هنا.
        var probe = ''
            + '<div class="mt-2 p-2 rounded" style="background:color-mix(in srgb, var(--amial-info) 6%, transparent)">'
            +   '<label class="small text-muted d-block mb-1">فحصُ رقم الهويّة قبل الاعتماد'
            +     (id.id_masked ? ' — المسجَّل: <span dir="ltr">' + esc(id.id_masked) + '</span>' : ' — لا رقمَ مسجَّل')
            +   '</label>'
            +   '<div class="d-flex gap-1">'
            +     '<input type="text" class="form-control form-control-sm" dir="ltr" '
            +       'id="mv-nid-' + r.id + '" placeholder="أدخل الرقم كما في البطاقة">'
            +     '<button class="btn btn-sm btn-outline-info" data-act="lookup" data-id="' + r.id + '">افحص</button>'
            +   '</div>'
            +   '<div class="small mt-1" id="mv-nid-out-' + r.id + '"></div>'
            + '</div>';

        var open = r.status === 'pending_review';

        return '<div class="col-md-6 col-xl-4"><div class="card h-100"><div class="card-body d-flex flex-column">'
            + '<div class="d-flex justify-content-between align-items-start mb-1">'
            +   '<strong>' + esc(r.business_name || r.store_name || '—') + '</strong>'
            +   '<span class="mv-chip" style="background:color-mix(in srgb, var(--amial-info) 12%, transparent);color:var(--amial-info)">'
            +     esc(r.status_label) + '</span>'
            + '</div>'
            + '<div class="small text-muted">السجلّ: ' + esc(r.commercial_register_number || '—')
            +   ' · ' + esc(r.city || '—') + '</div>'
            + '<div class="small text-muted" dir="ltr">' + esc(r.contact_phone || '') + '</div>'
            + '<div class="small text-muted mb-2">قُدِّم: ' + esc(r.submitted_at || '—') + '</div>'
            + '<div class="mb-2">' + docs + '</div>'
            + idNote
            + exNote
            + (open ? probe : '')
            + (r.admin_note ? '<div class="small text-muted mt-1">ملاحظة: ' + esc(r.admin_note) + '</div>' : '')
            + '<div class="mt-auto pt-2 d-flex gap-2 flex-wrap">'
            + (open ? '<button class="btn btn-sm btn-success flex-fill" data-act="approve" data-id="' + r.id + '">توثيق</button>'
                    + '<button class="btn btn-sm btn-outline-warning flex-fill" data-act="resubmit" data-id="' + r.id + '">إعادة رفع</button>'
                    + '<button class="btn btn-sm btn-outline-danger flex-fill" data-act="reject" data-id="' + r.id + '">رفض</button>'
                    : '<span class="small text-muted">لا إجراء — الطلب ليس بانتظار مراجعة.</span>')
            + '</div></div></div></div>';
    }

    async function load() {
        cards.innerHTML = '<div class="col-12 text-muted">جارٍ التحميل…</div>';
        try {
            var r = await fetch(base + '/list.json?status=' + encodeURIComponent(status),
                {headers: {'Accept': 'application/json'}});
            if (!r.ok) { cards.innerHTML = ''; say('تعذّر تحميل الطلبات (' + r.status + ')', 'danger'); return; }
            var j = await r.json();

            var summary = j.summary || {};
            document.querySelectorAll('[data-mv-count]').forEach(function (el) {
                var k = el.dataset.mvCount;
                var total = 0;
                Object.keys(summary).forEach(function (s) { total += Number(summary[s]) || 0; });
                el.textContent = k === 'all' ? total : (Number(summary[k]) || 0);
            });

            // **وقائمةٌ فارغةٌ تُقال بسببها** — «لا شيء» تُقرأ عطلاً.
            cards.innerHTML = (j.data || []).length
                ? j.data.map(card).join('')
                : '<div class="col-12 text-muted">لا طلبات في هذه الحالة.</div>';
        } catch (e) {
            cards.innerHTML = '';
            say('خطأ في الشبكة — لم تُحمَّل الطلبات.', 'danger');
        }
    }

    tabs.addEventListener('click', function (e) {
        var b = e.target.closest('[data-mv-status]');
        if (!b) return;
        tabs.querySelectorAll('.nav-link').forEach(function (n) { n.classList.remove('active'); });
        b.classList.add('active');
        status = b.dataset.mvStatus;
        load();
    });

    cards.addEventListener('click', async function (e) {
        var btn = e.target.closest('[data-act]');
        if (!btn) return;
        var id = btn.dataset.id;
        var act = btn.dataset.act;

        if (act === 'approve') {
            if (!confirm('توثيقُ هذا المتجر؟ يُفتح له وسمُ «موثَّق» في التطبيق.')) return;
            var a = await post(base + '/' + id + '/approve');
            say(a.body.message || (a.ok ? 'وُثِّق' : 'تعذّر التوثيق'), a.ok ? 'success' : 'danger');
            if (a.ok && a.body.identity && !a.body.identity.verified) {
                say('وُثِّق المتجر. وتبقى هويّةُ صاحبه غير موثّقة — لم يُفتح له سقفُ المال.', 'warning');
            }
            load();
            return;
        }

        // AMIAL-KYC-DUP-001 — **فحصُ الهويّة، ولا يمنع بنفسه.**
        if (act === 'lookup') {
            // **ولا يُقرأ عنصرٌ قد لا يوجد** — سطرٌ مثل `$('x').value`
            // على عنصرٍ أُزيل يموت صامتاً فلا يحدث شيءٌ عند الضغط،
            // وهو ما عطّل زرَّ الإيداع في الشبّاك. (القاعدة التاسعة.)
            var inp = document.getElementById('mv-nid-' + id);
            var out = document.getElementById('mv-nid-out-' + id);
            if (!inp || !out) { say('تعذّر قراءة حقل الهويّة — أعِد تحميل الصفحة.', 'danger'); return; }

            var val = (inp.value || '').trim();
            if (!val) { out.innerHTML = '<span style="color:var(--amial-warning)">أدخل الرقم أوّلاً.</span>'; return; }

            out.textContent = 'جارٍ الفحص…';
            var keep = confirm('احفظ هذا الرقم في ملفّ الحساب؟\n\n'
                + 'الحفظُ يجعل الفحصَ الآليَّ يمسك التكرارَ مستقبلاً.\n'
                + 'اضغط «إلغاء» للفحص دون حفظ.');

            var lk = await post(base + '/' + id + '/identity-lookup',
                {national_id: val, remember: keep ? 1 : 0});

            if (!lk.ok) {
                out.innerHTML = '<span style="color:var(--amial-danger)">'
                    + esc(lk.body.message || 'تعذّر الفحص') + '</span>';
                return;
            }

            var b = lk.body;
            if (!b.found) {
                out.innerHTML = '<span style="color:var(--amial-success)">✓ غيرُ مسجَّلةٍ لأيّ حسابٍ آخر — '
                    + esc(b.masked || '') + '</span>';
            } else {
                out.innerHTML = '<span style="color:var(--amial-danger)">⛔ مسجَّلةٌ لـ'
                    + b.matches.length + ' حسابٍ آخر:</span><ul class="mb-0 ps-3">'
                    + b.matches.map(function (m) {
                        return '<li>' + esc(m.name_masked) + ' — ' + esc(m.kind)
                            + ' · #' + m.id
                            + (m.is_verified ? ' · موثَّق' : ' · غيرُ موثَّق')
                            + (m.is_active ? '' : ' · موقوف')
                            + ' · سُجّل ' + esc(m.registered_at || '—') + '</li>';
                    }).join('')
                    + '</ul><span class="text-muted">القرارُ لك: تطابقُ هويّةِ تاجرٍ مع حسابِ '
                    + 'عميلٍ لنفس الشخص أمرٌ مشروع.</span>';
            }

            if (b.store_note) {
                out.innerHTML += '<div class="text-muted">' + esc(b.store_note) + '</div>';
            } else if (b.stored) {
                out.innerHTML += '<div style="color:var(--amial-success)">حُفظ الرقمُ في ملفّ الحساب.</div>';
            }
            return;
        }

        // ③ الرفضُ وإعادةُ الرفع بسببٍ مكتوبٍ يصل التاجر.
        var reason = prompt(act === 'reject'
            ? 'سبب الرفض (يصل التاجر ويُسجَّل في التدقيق):'
            : 'ما الذي يجب إعادةُ رفعه؟ (يصل التاجر):');
        if (reason === null) return;
        if (reason.trim().length < 5) { say('سببٌ واضحٌ مطلوب (٥ أحرف على الأقل).', 'danger'); return; }

        var res = await post(base + '/' + id + '/' + (act === 'reject' ? 'reject' : 'resubmit'),
            {reason: reason});
        say(res.body.message || (res.ok ? 'تمّ' : 'تعذّر الإجراء'), res.ok ? 'success' : 'danger');
        load();
    });

    load();
})();
</script>
@endsection
