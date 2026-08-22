@extends('layouts.admin.app')

@section('title', 'سجلّ تدقيق النظام')

@php use App\Support\AuditVocabulary as V; @endphp

@section('content')
<div class="content container-fluid">

    <div class="d-flex align-items-center gap-3 mb-4">
        <i class="tio-search" style="font-size:24px"></i>
        <h2 class="page-header-title mb-0">سجلّ تدقيق النظام</h2>
        <span class="badge badge-soft-info ms-auto text-monospace">audit_decisions</span>
    </div>

    <div class="row mb-4">
        @foreach(['critical' => 'danger', 'warning' => 'warning', 'notice' => 'info', 'info' => 'primary'] as $sev => $color)
            <div class="col-md-3">
                <div class="card border-{{ $color }}">
                    <div class="card-body py-3">
                        <small class="text-muted">{{ V::severity($sev)['label'] }} · آخر ٢٤ ساعة</small>
                        <h3 class="text-{{ $color }} mb-0">{{ number_format($stats_24h[$sev] ?? 0) }}</h3>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{--
        AMIAL-AUDIT-GUIDANCE-001
        هذه اللافتة لا تستنتج النية من اختلاف البصمة. اختلاف البصمة دليل سلامة
        يحتاج تحقيقاً، لكنه لا يثبت وحده عبثاً متعمداً أو خسارة مالية.
    --}}
    @if($chain['state'] === 'broken')
        <div class="alert alert-danger" data-testid="audit-chain-panel" data-chain-state="{{ $chain['state'] }}">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                <strong data-testid="audit-chain">
                    ⚠ سلامة سجل التدقيق تحتاج تحقيقًا — {{ number_format($chain['broken']) }} سجلًا غير مفسر
                </strong>
                <span class="ms-auto small">فُحص آخرُ {{ number_format($chain['checked']) }} قرار</span>
            </div>

            <div class="row g-2 mb-3" data-testid="audit-chain-guidance">
                <div class="col-md-4">
                    <div class="bg-white rounded p-2 h-100 border">
                        <div class="small text-muted">ما الذي نعرفه؟</div>
                        <strong class="small">يوجد اختلاف سلامة حقيقي في السجلات المفحوصة.</strong>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="bg-white rounded p-2 h-100 border">
                        <div class="small text-muted">ما الذي لا نعرفه بعد؟</div>
                        <strong class="small">السبب والنية غير محسومين من فحص البصمة وحده.</strong>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="bg-white rounded p-2 h-100 border">
                        <div class="small text-muted">هل ثبت أثر مالي؟</div>
                        <strong class="small">لا. هذا الفحص وحده لا يثبت أثرًا ماليًا.</strong>
                    </div>
                </div>
            </div>

            <div class="small">
                @if($chain['tampered'] > 0)
                    <div>
                        · <strong>{{ number_format($chain['tampered']) }}</strong> سجلًا بصمتها لا تطابق محتواها الحالي،
                        ولم يجد الفحص تفسيرًا تقنيًا معروفًا لها — <b>تحتاج تحقيقًا، ولا تُعدّ تلقائيًا عبثًا متعمدًا.</b>
                    </div>
                @endif
                @if($chain['link_breaks'] > 0)
                    <div>
                        · <strong>{{ number_format($chain['link_breaks']) }}</strong> حلقة لا ترتبط ببصمة سابقتها —
                        <b>السبب غير محسوم</b> وقد يكون حذفًا أو إدراجًا أو ترحيلًا غير مفسر؛ يلزم التحقق.
                    </div>
                @endif
                @if($chain['rewritten'] > 0)
                    <div class="text-muted">
                        · {{ number_format($chain['rewritten']) }} سجلًا تغيّرت بسبب تقني معروف — <b>مفسّرة ولا تحتاج تحقيق سلامة.</b>
                    </div>
                @endif
                @if($chain['unsigned'] > 0)
                    <div class="text-muted">
                        · {{ number_format($chain['unsigned']) }} سجلًا أقدم من سلسلة البصمات ولم تُوقّع أصلًا — <b>ليست دليل عبث.</b>
                    </div>
                @endif
            </div>

            <div class="bg-white border rounded p-3 mt-3" data-testid="audit-next-action">
                <div class="fw-bold mb-1">ماذا أفعل الآن؟</div>
                <ol class="small mb-2 ps-3">
                    <li>افتح السجلات غير المفسرة وافحص الموضوع والمعاملة والسياق لكل سجل.</li>
                    <li>اربط وقت أول اختلاف بعمليات النشر والهجرات وأخطاء النظام في الفترة نفسها.</li>
                    <li>شغّل الفحص الكامل للسلسلة قبل الحكم النهائي؛ فهذه الصفحة تفحص نافذة سريعة فقط.</li>
                    <li><b>لا تعد كتابة البصمات ولا تعدّل السجلات لإخفاء التحذير.</b> احتفظ بالدليل حتى يُحسم السبب.</li>
                </ol>
                <div class="small text-muted">
                    الفحص الكامل الهندسي: <code dir="ltr">php artisan amial:audit-verify</code>
                </div>
            </div>

            <div class="d-flex flex-wrap gap-2 mt-3">
                <a class="btn btn-sm btn-danger" data-testid="audit-filter-broken"
                   href="{{ route('admin.amial.audit.index', ['integrity' => 'broken']) }}">
                    ابدأ التحقيق: اعرض السجلات غير المفسرة
                </a>
                <a class="btn btn-sm btn-outline-danger"
                   href="{{ route('admin.amial.audit.export', ['integrity' => 'broken']) }}">
                    ⬇ تصدير السجلات غير المفسرة
                </a>
            </div>
        </div>
    @elseif($chain['state'] === 'rewritten')
        <div class="alert alert-info" data-testid="audit-chain-panel" data-chain-state="{{ $chain['state'] }}">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <strong data-testid="audit-chain">
                    ⓘ تغيير معروف السبب — لا يحتاج إجراء
                </strong>
                <span class="ms-auto small">فُحص آخرُ {{ number_format($chain['checked']) }} قرار</span>
            </div>
            <div class="small mt-2">
                {{ number_format($chain['rewritten']) }} سجلًا تغيّرت بعد بصمها، لكن الفحص وجد لها سببًا تقنيًا معروفًا ومفسرًا.
                <div class="mt-1">
                    @foreach($chain['causes'] as $cause => $count)
                        <div>· <strong>{{ number_format($count) }}</strong> — {{ $cause }}</div>
                    @endforeach
                </div>
                <div class="mt-2">
                    <b>الإجراء:</b> لا شيء ما لم تظهر أدلة أخرى. لا تُعاد كتابة البصمات لمجرد جعل الحالة خضراء؛
                    إعادة البصمة تمحو الدليل الذي صُممت السلسلة لحفظه.
                </div>
            </div>
            <a class="btn btn-sm btn-outline-info mt-2"
               href="{{ route('admin.amial.audit.index', ['integrity' => 'rewritten']) }}">
                أرني السجلات المفسرة
            </a>
        </div>
    @elseif($chain['state'] === 'legacy')
        <div class="alert alert-secondary" data-testid="audit-chain-panel" data-chain-state="{{ $chain['state'] }}">
            <strong data-testid="audit-chain">ⓘ تغطية السلامة جزئية — سجلات قديمة بلا بصمة</strong>
            <div class="small mt-1">
                فُحص آخرُ {{ number_format($chain['checked']) }} قرار: لا توجد بصمة مكسورة داخل الجزء الموقع،
                لكن {{ number_format($chain['unsigned']) }} سجلًا كُتبت قبل إنشاء سلسلة البصمات ولم تُوقّع أصلًا.
                <b>هذا لا يعني أنها سليمة أو معبث بها؛ يعني فقط أنه لا توجد بصمة تاريخية يمكن التحقق منها.</b>
                @if($chain['first_signed_id'])
                    وأول قرار موقّع هو <span class="text-monospace">#{{ $chain['first_signed_id'] }}</span> — وما بعده محروس.
                @endif
            </div>
            <a class="btn btn-sm btn-outline-secondary mt-2"
               href="{{ route('admin.amial.audit.index', ['integrity' => 'unsigned']) }}">
                أرني السجلات غير الموقعة
            </a>
        </div>
    @elseif($chain['state'] === 'ok')
        <div class="alert alert-success py-2" data-testid="audit-chain-panel" data-chain-state="{{ $chain['state'] }}">
            <strong data-testid="audit-chain">✓ سلسلة التدقيق سليمة</strong>
            <span class="small text-muted">— فُحص آخرُ {{ number_format($chain['checked']) }} قرار، وكلها موقعة ومتّصلة.</span>
        </div>
    @else
        <div class="alert alert-secondary py-2" data-testid="audit-chain-panel" data-chain-state="{{ $chain['state'] }}">
            <strong data-testid="audit-chain">لا توجد بيانات كافية للحكم على سلامة السلسلة</strong>
            <span class="small text-muted">— غياب القرارات ليس حالة «سليمة» ولا «معطلة»؛ الحالة غير معروفة.</span>
        </div>
    @endif

    <div class="card mb-3">
        <div class="card-body">
            <form action="{{ url()->current() }}" method="GET" class="row g-3">

                <div class="col-md-4">
                    <label class="form-label">بحثٌ حرّ</label>
                    <input type="text" name="q" class="form-control"
                           placeholder="كلمةٌ في السبب أو الفعل أو رمز القرار أو معرّفه"
                           value="{{ $filters['q'] ?? '' }}">
                </div>

                <div class="col-md-4">
                    <label class="form-label">المجال</label>
                    <select name="domain" class="form-control">
                        <option value="">كلُّ المجالات</option>
                        @foreach($domains as $key => $label)
                            <option value="{{ $key }}" @selected(($filters['domain'] ?? '') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label">الدرجة</label>
                    <select name="severity" class="form-control">
                        <option value="">كلُّ الدرجات</option>
                        @foreach($severities as $key => $label)
                            <option value="{{ $key }}" @selected(($filters['severity'] ?? '') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label">السلامة</label>
                    <select name="integrity" class="form-control" data-testid="audit-integrity-filter">
                        <option value="">الكلّ</option>
                        <option value="broken" @selected(($filters['integrity'] ?? '') === 'broken')>يحتاج تحقيقًا</option>
                        <option value="rewritten" @selected(($filters['integrity'] ?? '') === 'rewritten')>مفسّر تقنيًا</option>
                        <option value="unsigned" @selected(($filters['integrity'] ?? '') === 'unsigned')>غير موقّع</option>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label">الفعل</label>
                    <select name="action" class="form-control">
                        <option value="">كلُّ الأفعال</option>
                        @foreach($actions_by_domain as $domainKey => $rows)
                            <optgroup label="{{ $domainKey === '__unclassified__' ? 'أفعالٌ غيرُ مصنّفة' : ($domains[$domainKey] ?? $domainKey) }}">
                                @foreach($rows as $code => $label)
                                    <option value="{{ $code }}" @selected(($filters['action'] ?? '') === $code)>{{ $label }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">نوعُ الموضوع</label>
                    <select name="subject_type" class="form-control">
                        <option value="">كلُّ الأنواع</option>
                        @foreach($subject_types as $code => $label)
                            <option value="{{ $code }}" @selected(($filters['subject_type'] ?? '') === $code)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label">معرّفُ الموضوع</label>
                    <input type="text" name="subject_id" class="form-control" dir="ltr"
                           value="{{ $filters['subject_id'] ?? '' }}">
                </div>

                <div class="col-md-3">
                    <label class="form-label">معرّفُ المنفِّذ</label>
                    <input type="number" name="actor_user_id" class="form-control" dir="ltr"
                           value="{{ $filters['actor_user_id'] ?? '' }}">
                </div>

                <div class="col-md-3">
                    <label class="form-label">رقمُ المعاملة</label>
                    <input type="text" name="transaction_id" class="form-control" dir="ltr"
                           data-testid="audit-tx-filter"
                           value="{{ $filters['transaction_id'] ?? '' }}">
                </div>

                <div class="col-md-2">
                    <label class="form-label">النطاق</label>
                    <input type="text" name="zone_code" class="form-control" dir="ltr"
                           value="{{ $filters['zone_code'] ?? '' }}">
                </div>

                <div class="col-md-2">
                    <label class="form-label">رمزُ القرار</label>
                    <input type="text" name="decision_code" class="form-control" dir="ltr"
                           value="{{ $filters['decision_code'] ?? '' }}">
                </div>

                <div class="col-md-2">
                    <label class="form-label">من تاريخ</label>
                    <input type="date" name="date_from" class="form-control" value="{{ $filters['date_from'] ?? '' }}">
                </div>

                <div class="col-md-2">
                    <label class="form-label">إلى تاريخ</label>
                    <input type="date" name="date_to" class="form-control" value="{{ $filters['date_to'] ?? '' }}">
                </div>

                <div class="col-md-12 d-flex gap-2">
                    <button type="submit" class="btn btn-primary px-4" data-testid="audit-filter-submit">تصفية</button>
                    <a href="{{ url()->current() }}" class="btn btn-outline-secondary">مسحُ المرشِّحات</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex flex-wrap align-items-center gap-2">
            <h5 class="card-header-title mb-0">القرارات</h5>
            <span class="badge badge-soft-secondary text-dark">{{ number_format($decisions->total()) }}</span>

            @if(($filters['integrity'] ?? '') !== '')
                <span class="badge bg-warning text-dark">
                    ضمن آخر {{ number_format($chain['checked']) }} قرارٍ مفحوصٍ فقط
                </span>
            @endif

            <a class="btn btn-sm btn-outline-secondary ms-auto"
               data-testid="audit-export"
               href="{{ route('admin.amial.audit.export', request()->query()) }}">
                ⬇ تصدير CSV (بالمرشِّحات الحاليّة)
            </a>
        </div>
        <div class="table-responsive">
            <table class="table table-borderless table-thead-bordered table-align-middle">
                <thead class="thead-light">
                <tr>
                    <th>الوقت</th>
                    <th>المنفِّذ</th>
                    <th>الفعل</th>
                    <th>القرار</th>
                    <th>الدرجة</th>
                    <th>الموضوع</th>
                    <th>السبب</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @forelse($decisions as $d)
                    @php
                        $act = V::action($d->action);
                        $dec = V::decisionCode($d->decision_code);
                        $sev = V::severity($d->severity);
                        $toneClass = ['danger' => 'danger', 'success' => 'success'][$dec['tone']] ?? 'secondary';
                    @endphp
                    <tr>
                        <td>
                            <small class="text-monospace" dir="ltr">{{ $d->created_at?->format('Y-m-d H:i:s') }}</small>
                            @if($d->created_at)
                                <small class="text-muted d-block">{{ $d->created_at->diffForHumans() }}</small>
                            @endif
                        </td>
                        <td>
                            <div class="fw-bold small">{{ $actors[$d->actor_user_id] ?? '—' }}</div>
                            <small class="text-muted">
                                {{ V::actorType($d->actor_type) }}@if($d->actor_user_id)
                                    <span class="text-monospace" dir="ltr">#{{ $d->actor_user_id }}</span>
                                @endif
                            </small>
                        </td>
                        <td>
                            <div class="fw-bold small">{{ $act['label'] }}</div>
                            @if($act['translated'])
                                <small class="text-muted text-monospace" dir="ltr">{{ $act['raw'] }}</small>
                            @else
                                <small class="badge badge-soft-warning">بلا ترجمة</small>
                            @endif
                        </td>
                        <td>
                            <span class="badge badge-soft-{{ $toneClass }}">{{ $dec['label'] }}</span>
                            @if($d->decision_code && $d->decision_code !== 'UNKNOWN')
                                <small class="text-muted d-block text-monospace" dir="ltr">{{ $d->decision_code }}</small>
                            @endif
                        </td>
                        <td><span class="badge {{ $sev['class'] }}">{{ $sev['label'] }}</span></td>
                        <td>
                            @if($d->subject_type)
                                <span class="badge badge-soft-secondary">{{ V::subjectType($d->subject_type) }}</span>
                                <small class="text-muted d-block text-monospace" dir="ltr">{{ $d->subject_id }}</small>
                            @else
                                <small class="text-muted">—</small>
                            @endif
                        </td>
                        <td><small>{{ \Illuminate\Support\Str::limit($d->reason, 80) ?: '—' }}</small></td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-primary py-0 px-2"
                                    data-audit-open="{{ $d->id }}" data-testid="audit-open">
                                التفاصيل
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">
                            <i class="tio-search" style="font-size:48px;opacity:.3"></i>
                            <div class="mt-2">لا قرارات تطابق المرشِّحات</div>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">
            {{ $decisions->links() }}
        </div>
    </div>
</div>

<div class="modal fade" id="audit-detail" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">تفاصيل القرار</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
      </div>
      <div class="modal-body" id="audit-detail-body" data-testid="audit-detail-body">
        <div class="text-center text-muted py-4">جارٍ التحميل…</div>
      </div>
    </div>
  </div>
</div>
@endsection

@push('script')
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(() => {
    const modalEl = document.getElementById('audit-detail');
    const body    = document.getElementById('audit-detail-body');
    if (!modalEl) return;

    const modal = new bootstrap.Modal(modalEl);
    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, c =>
        ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    const row = (label, value, mono) => value === null || value === undefined || value === ''
        ? ''
        : `<div class="d-flex border-bottom py-2"><div class="text-muted small" style="min-width:150px">${esc(label)}</div>
           <div class="flex-grow-1 small ${mono ? 'text-monospace' : ''}" ${mono ? 'dir="ltr"' : ''}>${value}</div></div>`;

    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-audit-open]');
        if (!btn) return;

        body.innerHTML = '<div class="text-center text-muted py-4">جارٍ التحميل…</div>';
        modal.show();

        try {
            const r = await fetch(`{{ url('admin/amial/audit') }}/${btn.dataset.auditOpen}.json`,
                { headers: { 'Accept': 'application/json' } });
            const j = await r.json();

            if (!j.success) {
                body.innerHTML = `<div class="alert alert-danger mb-0">${esc(j.message || 'تعذّر جلب القرار')}</div>`;
                return;
            }

            const d = j.data;

            const subject = d.subject.url
                ? `<a href="${esc(d.subject.url)}">${esc(d.subject.label)}</a>`
                : esc(d.subject.label);

            const actor = d.actor
                ? `<a href="{{ url('admin/amial/hub/account') }}/${d.actor.id}">${esc(d.actor.name)}</a>
                   <span class="text-muted">· ${esc(d.actor_type_ar)} · ${esc(d.actor.phone ?? '')}</span>`
                : esc(d.actor_type_ar || '—');

            const verdict = {
                ok:        ['success',   '✓ '],
                unsigned:  ['secondary', 'ⓘ '],
                rewritten: ['info text-dark', 'ⓘ '],
                tampered:  ['danger',    '⚠ '],
            }[d.integrity.verdict] || ['secondary', ''];

            const integrity =
                `<span class="badge bg-${verdict[0]}">${verdict[1]}${esc(d.integrity.verdict_label)}</span>`;

            const ctx = Object.keys(d.context || {}).length
                ? `<pre class="bg-light p-3 small rounded mb-0" style="max-height:280px;overflow:auto" dir="ltr">${
                    esc(JSON.stringify(d.context, null, 2))}</pre>`
                : '<span class="text-muted small">لا سياق مسجَّل مع هذا القرار</span>';

            const actionCell = d.action_ar.translated
                ? `<span class="fw-bold">${esc(d.action_ar.label)}</span>
                   <span class="text-muted text-monospace" dir="ltr">${esc(d.action)}</span>`
                : `<span class="fw-bold text-monospace" dir="ltr">${esc(d.action)}</span>
                   <span class="badge badge-soft-warning">بلا ترجمة</span>`;

            body.innerHTML = `
                ${row('الوقت', esc(d.created_at), true)}
                ${row('المنفِّذ', actor)}
                ${row('الفعل', actionCell)}
                ${row('القرار', `<span class="badge badge-soft-secondary">${esc(d.decision_ar.label)}</span>
                   <span class="text-muted text-monospace" dir="ltr">${esc(d.decision_code)}</span>`)}
                ${row('الدرجة', `<span class="badge ${esc(d.severity_ar.class)}">${esc(d.severity_ar.label)}</span>`)}
                ${row('الموضوع', `${subject} <span class="text-muted">· ${esc(d.subject.type_ar)}</span>`)}
                ${row('السبب', esc(d.reason))}
                ${row('المعاملة', d.transaction_id
                    ? `<a href="{{ url('admin/transaction') }}?search=${encodeURIComponent(d.transaction_id)}">${esc(d.transaction_id)}</a>`
                    : null, true)}
                ${row('النطاق', esc(d.zone_code), true)}
                ${row('معرّفُ القرار', esc(d.decision_id), true)}
                ${row('مفتاحُ منع التكرار', esc(d.idempotency_key), true)}
                <div class="mt-3"><div class="text-muted small mb-1">السياق المسجَّل</div>${ctx}</div>
                <div class="mt-3">
                  <div class="text-muted small mb-1">سلسلةُ التدقيق</div>
                  <div class="mb-2">${integrity}</div>
                  ${row('بصمةُ السجلّ', esc(d.integrity.entry_hash), true)}
                  ${row('بصمةُ السابق', esc(d.integrity.prev_hash), true)}
                </div>
                <div class="mt-3 d-flex flex-wrap gap-2">
                  <a class="btn btn-sm btn-outline-secondary"
                     href="?subject_type=${encodeURIComponent(d.subject.type ?? '')}&subject_id=${encodeURIComponent(d.subject.id ?? '')}">
                     كلُّ ما جرى على هذا الموضوع
                  </a>
                  ${d.actor ? `<a class="btn btn-sm btn-outline-secondary"
                     href="?actor_user_id=${encodeURIComponent(d.actor.id)}">كلُّ ما فعله هذا المنفِّذ</a>` : ''}
                  ${d.transaction_id ? `<a class="btn btn-sm btn-outline-secondary"
                     href="?transaction_id=${encodeURIComponent(d.transaction_id)}">كلُّ ما جرى على هذه المعاملة</a>` : ''}
                </div>`;
        } catch (err) {
            body.innerHTML = '<div class="alert alert-danger mb-0">تعذّر الاتصال بالخادم</div>';
        }
    });
})();
</script>
@endpush
