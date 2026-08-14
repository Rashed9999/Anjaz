@extends('layouts.admin.app')

@section('title', translate('System Audit Log'))

@section('content')
<div class="content container-fluid">

    <div class="d-flex align-items-center gap-3 mb-4">
        <i class="tio-search" style="font-size:24px"></i>
        <h2 class="page-header-title mb-0">{{ translate('System Audit Log') }}</h2>
        <span class="badge badge-soft-info ms-auto">audit_decisions</span>
    </div>

    {{-- Quick stats (last 24h) --}}
    <div class="row mb-4">
        @foreach(['info' => 'primary', 'notice' => 'info', 'warning' => 'warning', 'critical' => 'danger'] as $sev => $color)
            <div class="col-md-3">
                <div class="card border-{{ $color }}">
                    <div class="card-body py-3">
                        <small class="text-muted text-uppercase">{{ translate($sev) }} (24h)</small>
                        <h3 class="text-{{ $color }} mb-0">{{ number_format($stats_24h[$sev] ?? 0) }}</h3>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Filters --}}
    <div class="card mb-3">
        <div class="card-body">
            <form action="{{ url()->current() }}" method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">{{ translate('Decision code') }}</label>
                    <input type="text" name="decision_code" class="form-control"
                           placeholder="TX_ZONE_BLOCKED, TX_OK, ..." value="{{ $filters['decision_code'] ?? '' }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label">{{ translate('Severity') }}</label>
                    <select name="severity" class="form-control">
                        <option value="">{{ translate('All') }}</option>
                        @foreach(['info', 'notice', 'warning', 'critical'] as $sev)
                            <option value="{{ $sev }}" {{ ($filters['severity'] ?? '') == $sev ? 'selected' : '' }}>{{ $sev }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">{{ translate('Actor user ID') }}</label>
                    <input type="number" name="actor_user_id" class="form-control" value="{{ $filters['actor_user_id'] ?? '' }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">{{ translate('Action contains') }}</label>
                    <input type="text" name="action" class="form-control" value="{{ $filters['action'] ?? '' }}">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">{{ translate('Filter') }}</button>
                </div>

                <div class="col-md-3">
                    <label class="form-label">{{ translate('Date from') }}</label>
                    <input type="date" name="date_from" class="form-control" value="{{ $filters['date_from'] ?? '' }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">{{ translate('Date to') }}</label>
                    <input type="date" name="date_to" class="form-control" value="{{ $filters['date_to'] ?? '' }}">
                </div>

                {{-- AMIAL-SAFEPAY-AUDIT-001: تتبّع شيء بعينه (نزاع، مستخدم، تسوية)
                     من أوّل أثر له إلى آخره. تُملأ آلياً حين يُفتح السجلّ من
                     لوحة النزاعات، ولذلك تبقى محفوظة عبر الفلترة والتصفّح. --}}
                <div class="col-md-3">
                    <label class="form-label">{{ translate('Subject type') }}</label>
                    <input type="text" name="subject_type" class="form-control"
                           placeholder="safe_payment, user, ..." value="{{ $filters['subject_type'] ?? '' }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">{{ translate('Subject ID') }}</label>
                    <input type="text" name="subject_id" class="form-control" dir="ltr"
                           value="{{ $filters['subject_id'] ?? '' }}">
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex flex-wrap align-items-center gap-2">
            <h5 class="card-header-title mb-0">{{ translate('Decisions') }}</h5>
            <span class="badge badge-soft-secondary text-dark">{{ $decisions->total() }}</span>

            {{-- AMIAL-AUDIT-DETAIL-002 — **سلسلةُ البصمات تُتحقَّق لا تُخزَّن فقط.**

                 `prev_hash` و`entry_hash` مكتوبتان منذ `AuditService`، ولم
                 يكن **شيءٌ يتحقّق منهما في أيّ شاشة**. وسلسلةٌ مقاوِمةٌ
                 للعبث لا يُتحقَّق منها ليست مقاوِمةً للعبث: هي عمودان.

                 والفحصُ هنا على آخر مئة قرار — مسبارٌ سريعٌ على الطرف
                 الحيّ. والفحصُ الكاملُ أمرٌ مجدول. --}}
            @if($chain['state'] === 'ok')
                <span class="badge bg-success" data-testid="audit-chain">
                    ✓ سلسلةُ التدقيق سليمة ({{ $chain['checked'] }} قراراً)
                </span>
            @elseif($chain['state'] === 'broken')
                <span class="badge bg-danger" data-testid="audit-chain">
                    ⚠ كُسرت السلسلة في {{ $chain['broken'] }} موضعاً — عُبث بالسجلّ
                </span>
            @else
                {{-- «غير معروف» ليس «سليم» (القاعدة السابعة). --}}
                <span class="badge bg-secondary" data-testid="audit-chain">لا قرارات لتُفحص</span>
            @endif

            <a class="btn btn-sm btn-outline-secondary ms-auto"
               data-testid="audit-export"
               href="{{ route('admin.amial.audit.export', request()->query()) }}">
                ⬇ تصدير CSV (بالفلاتر الحاليّة)
            </a>
        </div>
        <div class="table-responsive">
            <table class="table table-borderless table-thead-bordered table-align-middle">
                <thead class="thead-light">
                <tr>
                    <th>{{ translate('Time') }}</th>
                    <th>{{ translate('Actor') }}</th>
                    <th>{{ translate('Action') }}</th>
                    <th>{{ translate('Decision') }}</th>
                    <th>{{ translate('Severity') }}</th>
                    <th>{{ translate('Subject type') }}</th>
                    <th>{{ translate('Reason') }}</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @forelse($decisions as $d)
                    <tr>
                        <td><small class="text-monospace">{{ $d->created_at?->format('Y-m-d H:i:s') }}</small></td>
                        {{-- AMIAL-AUDIT-DETAIL-002: كان يُعرض «admin#8» — رقمٌ يحتاج
                             استعلاماً آخرَ ليصير اسماً. والأسماءُ تُجلب دفعةً
                             واحدةً في المتحكّم، لا استعلاماً لكلّ صفّ. --}}
                        <td>
                            <div class="fw-bold small">{{ $actors[$d->actor_user_id] ?? '—' }}</div>
                            <small class="text-muted text-monospace">{{ $d->actor_type }}#{{ $d->actor_user_id }}</small>
                        </td>
                        <td><small class="fw-bold">{{ $d->action }}</small></td>
                        <td>
                            @php
                                $code = $d->decision_code ?? '';
                                $isBlocked = str_contains($code, 'BLOCKED') || str_contains($code, 'INSUFFICIENT') || str_contains($code, 'INVALID') || str_contains($code, 'DENIED');
                                $isOk = str_contains($code, 'OK') || str_contains($code, 'COMPLETED') || str_contains($code, 'ACCEPTED');
                                $codeClass = $isBlocked ? 'danger' : ($isOk ? 'success' : 'secondary');
                            @endphp
                            <span class="badge badge-soft-{{ $codeClass }} text-monospace">{{ $code }}</span>
                        </td>
                        <td>
                            @switch($d->severity)
                                @case('critical') <span class="badge bg-danger">{{ $d->severity }}</span> @break
                                @case('warning') <span class="badge bg-warning text-dark">{{ $d->severity }}</span> @break
                                @case('notice') <span class="badge bg-info">{{ $d->severity }}</span> @break
                                @default <span class="badge bg-light text-dark">{{ $d->severity }}</span>
                            @endswitch
                        </td>
                        <td>
                            @if($d->subject_type)
                                <span class="badge badge-soft-secondary">{{ $d->subject_type }}</span>
                                <small class="text-muted d-block text-monospace">{{ $d->subject_id }}</small>
                            @else
                                <small class="text-muted">—</small>
                            @endif
                        </td>
                        <td><small>{{ \Illuminate\Support\Str::limit($d->reason, 80) }}</small></td>
                        {{-- **زرٌّ يفتح القرار كاملاً** — كانت كتلةُ JSON مطويّةً
                             في خانة السبب، تُظهر السياقَ خاماً ولا تُظهر المعاملة
                             ولا الموضوع ولا بصمة السلسلة. --}}
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-primary py-0 px-2"
                                    data-audit-open="{{ $d->id }}" data-testid="audit-open">
                                {{ translate('Details') }}
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">
                            <i class="tio-search" style="font-size:48px;opacity:.3"></i>
                            <div class="mt-2">{{ translate('No audit decisions found') }}</div>
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

{{-- ══════════════════════════════════════════════════════════════════
     AMIAL-AUDIT-DETAIL-002 — **لوحُ القرار الكامل.**

     كان الجدولُ يعرض ستّةً من سبعة عشر عموداً. وهذا اللوحُ يعرض الباقيَ:
     المعاملة · الموضوع (برابطٍ يُنقر) · المعرّف · مفتاح التكرار ·
     النطاق · السياق كاملاً · وبصمةَ السلسلة وحالةَ تطابقها.
     ══════════════════════════════════════════════════════════════════ --}}
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
           <div class="flex-grow-1 small ${mono ? 'text-monospace' : ''}">${value}</div></div>`;

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

            // **الموضوعُ رابطٌ يُنقر** — رقمٌ لا يُفضي إلى صاحبه ليس تتبّعاً.
            const subject = d.subject.url
                ? `<a href="${esc(d.subject.url)}">${esc(d.subject.label)}</a>`
                : esc(d.subject.label);

            const actor = d.actor
                ? `<a href="{{ url('admin/amial/hub/account') }}/${d.actor.id}">${esc(d.actor.name)}</a>
                   <span class="text-muted">· ${esc(d.actor.phone ?? '')}</span>`
                : esc(d.actor_type ?? '—');

            // **وحالةُ البصمة تُقال صراحةً** — لا تُعرض البصمةُ وحدها
            // فيظنّ القارئُ أنّ وجودَها يعني صحّتَها.
            const integrity = d.integrity.hash_matches
                ? '<span class="badge bg-success">✓ البصمة تطابق المحتوى</span>'
                : '<span class="badge bg-danger">⚠ البصمة لا تطابق المحتوى — عُدِّل هذا الصفّ</span>';

            const ctx = Object.keys(d.context || {}).length
                ? `<pre class="bg-light p-3 small rounded mb-0" style="max-height:280px;overflow:auto">${
                    esc(JSON.stringify(d.context, null, 2))}</pre>`
                : '<span class="text-muted small">لا سياق مسجَّل</span>';

            body.innerHTML = `
                ${row('الوقت', esc(d.created_at), true)}
                ${row('المنفِّذ', actor)}
                ${row('الفعل', `<span class="fw-bold">${esc(d.action)}</span>`)}
                ${row('رمز القرار', `<span class="badge badge-soft-secondary text-monospace">${esc(d.decision_code)}</span>`)}
                ${row('الدرجة', esc(d.severity))}
                ${row('الموضوع', subject)}
                ${row('السبب', esc(d.reason))}
                ${row('المعاملة', d.transaction_id
                    ? `<a href="{{ url('admin/transaction') }}?search=${encodeURIComponent(d.transaction_id)}">${esc(d.transaction_id)}</a>`
                    : null, true)}
                ${row('النطاق', esc(d.zone_code), true)}
                ${row('معرّف القرار', esc(d.decision_id), true)}
                ${row('مفتاح التكرار', esc(d.idempotency_key), true)}
                <div class="mt-3"><div class="text-muted small mb-1">السياق</div>${ctx}</div>
                <div class="mt-3">
                  <div class="text-muted small mb-1">سلسلة التدقيق</div>
                  <div class="mb-2">${integrity}</div>
                  ${row('بصمة السجلّ', esc(d.integrity.entry_hash), true)}
                  ${row('بصمة السابق', esc(d.integrity.prev_hash), true)}
                </div>
                <div class="mt-3">
                  <a class="btn btn-sm btn-outline-secondary"
                     href="?subject_type=${encodeURIComponent(d.subject.type ?? '')}&subject_id=${encodeURIComponent(d.subject.id ?? '')}">
                     كلّ ما جرى على هذا الموضوع
                  </a>
                </div>`;
        } catch (err) {
            body.innerHTML = '<div class="alert alert-danger mb-0">تعذّر الاتصال بالخادم</div>';
        }
    });
})();
</script>
@endpush
