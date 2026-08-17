@extends('layouts.admin.app')

{{--
    AMIAL-WITHDRAW-DOOR-001 — طلبات السحب: بابُ خروج المال.

    **الشاشة التي لم تكن موجودة.** والمتحكّم مكتملٌ منذ البداية، والمسارُ
    غائب، والقالبُ غائب — بينما نقطةُ العميل حيّة. فكلّ طلب سحبٍ يحجز
    مالاً لا يخرج.

    وكلّ قرارٍ هنا: تأكيدٌ ← سببٌ يُسجَّل ← تنفيذٌ ← أثر. وقرارٌ يُنفَّذ
    بضغطةٍ بلا تأكيد يُضغط سهواً، والمال لا يُضغط عليه سهواً.
--}}

@section('title', 'طلبات السحب')

@section('content')
<div class="content container-fluid" data-testid="withdraw-panel">

    <div class="page-header">
        <h1 class="page-header-title">طلبات السحب</h1>
        <p class="text-muted mb-0">
            اعتمادُ طلبٍ يصرف المال من خزنة المنصّة، ورفضُه يُعيد المبلغ
            المحجوز إلى رصيد العميل المتاح. وكلاهما يُسجَّل في الدفتر.
        </p>
    </div>

    {{-- ══ المؤشّرات: المعلّقُ أوّلاً، فهو ما ينتظر قراراً ══ --}}
    @php
        $pendingCount = $withdrawRequests->where('request_status', 'pending')->count();
        $pendingSum = $withdrawRequests->where('request_status', 'pending')
            ->sum(fn ($r) => (float) $r->amount + (float) ($r->admin_charge ?? 0));
    @endphp

    <div class="row mb-3">
        <div class="col-sm-6 col-lg-3 mb-2">
            <div class="card"><div class="card-body">
                <div class="text-muted small">معلّقة في هذه الصفحة</div>
                <div class="fs-3 fw-bold {{ $pendingCount ? 'text-warning' : 'text-success' }}"
                     data-testid="wd-pending-count">{{ $pendingCount }}</div>
            </div></div>
        </div>
        <div class="col-sm-6 col-lg-3 mb-2">
            <div class="card"><div class="card-body">
                <div class="text-muted small">قيمتها (ريال)</div>
                <div class="fs-3 fw-bold text-primary">{{ number_format($pendingSum, 2) }}</div>
            </div></div>
        </div>
        <div class="col-lg-6 mb-2">
            <div class="card h-100"><div class="card-body small text-muted">
                <strong>المبلغ المعروض يشمل الرسم الإداري.</strong>
                والمحجوزُ في محفظة العميل هو المجموع — فالرفضُ يُعيده كاملاً،
                والاعتمادُ يصرفه كاملاً.
            </div></div>
        </div>
    </div>

    {{-- ══ المرشّحات ══ --}}
    <div class="card mb-3"><div class="card-body">
        <form method="GET" action="{{ route('admin.withdraw.index') }}" class="row g-2 align-items-end">
            <div class="col-md-5">
                <label class="form-label small mb-1">بحث</label>
                <input type="text" name="search" value="{{ $search }}" class="form-control"
                       placeholder="الاسم · الهاتف · رقم الحساب" data-testid="wd-search">
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">الحالة</label>
                <select name="request_status" class="form-select" data-testid="wd-status">
                    @foreach (['all' => 'الكل', 'pending' => 'معلّقة',
                               'approved' => 'معتمدة', 'denied' => 'مرفوضة'] as $v => $label)
                        <option value="{{ $v }}" @selected($requestStatus === $v)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">طريقة السحب</label>
                <select name="withdrawal_method" class="form-select">
                    <option value="all">الكل</option>
                    @foreach ($withdrawalMethods as $m)
                        <option value="{{ $m->id }}" @selected($method == $m->id)>{{ $m->method_name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-1">
                <button class="btn btn-primary w-100" data-testid="wd-filter">تصفية</button>
            </div>
        </form>
    </div></div>

    {{-- ══ الجدول ══ --}}
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="thead-light">
                    <tr>
                        <th>#</th>
                        <th>العميل</th>
                        <th>المبلغ</th>
                        <th>الرسم</th>
                        <th>الطريقة</th>
                        <th>الحالة</th>
                        <th>التاريخ</th>
                        <th class="text-end">القرار</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($withdrawRequests as $r)
                    <tr>
                        <td class="text-muted">{{ $r->id }}</td>
                        <td>
                            @if ($r->user)
                                <div class="fw-bold">{{ $r->user->f_name }} {{ $r->user->l_name }}</div>
                                <div class="small text-muted" dir="ltr">{{ $r->user->phone }}</div>
                            @else
                                {{-- (القاعدة ٧) الغيابُ يُقال، ولا يُعرض صفّاً فارغاً --}}
                                <span class="badge bg-danger">الحساب محذوف</span>
                            @endif
                        </td>
                        <td class="fw-bold">{{ number_format((float) $r->amount, 2) }}</td>
                        <td class="text-muted">{{ number_format((float) ($r->admin_charge ?? 0), 2) }}</td>
                        <td>{{ $r->withdrawal_method->method_name ?? 'غير محدّدة' }}</td>
                        <td>
                            @php
                                $badge = match ($r->request_status) {
                                    'approved' => 'success', 'denied' => 'danger', default => 'warning',
                                };
                                $label = match ($r->request_status) {
                                    'approved' => 'معتمدة', 'denied' => 'مرفوضة', default => 'معلّقة',
                                };
                            @endphp
                            <span class="badge bg-{{ $badge }}">{{ $label }}</span>
                        </td>
                        <td class="small text-muted">{{ $r->created_at?->format('Y-m-d H:i') }}</td>
                        <td class="text-end text-nowrap">
                            @if ($r->request_status === 'pending' && $r->user)
                                <button class="btn btn-sm btn-success wd-decide"
                                        data-id="{{ $r->id }}" data-decision="approve"
                                        data-name="{{ $r->user->f_name }}"
                                        data-total="{{ number_format((float) $r->amount + (float) ($r->admin_charge ?? 0), 2) }}"
                                        data-testid="wd-approve-{{ $r->id }}">اعتماد</button>
                                <button class="btn btn-sm btn-outline-danger wd-decide"
                                        data-id="{{ $r->id }}" data-decision="deny"
                                        data-name="{{ $r->user->f_name }}"
                                        data-total="{{ number_format((float) $r->amount + (float) ($r->admin_charge ?? 0), 2) }}"
                                        data-testid="wd-deny-{{ $r->id }}">رفض</button>
                            @else
                                {{-- **ولا زرَّ لطلبٍ بُتّ فيه**: قرارٌ ثانٍ يُحرّك المال
                                     مرّتين، والخادمُ يمنعه — والشاشةُ لا تعرضه أصلاً. --}}
                                <span class="small text-muted">بُتَّ فيه</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted py-5">
                        لا طلبات مطابقة
                    </td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $withdrawRequests->links() }}</div>
    </div>
</div>

{{-- نافذة التأكيد — **واحدة للقرارين**، فلا يُنسى التأكيد في أحدهما --}}
<div class="modal fade" id="wd-modal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <form method="POST" action="{{ route('admin.withdraw.status-update') }}" id="wd-form">
      @csrf
      <input type="hidden" name="request_id" id="wd-id">
      <input type="hidden" name="request_status" id="wd-decision">
      <div class="modal-header">
        <h5 class="modal-title" id="wd-title">تأكيد القرار</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p id="wd-body" class="mb-3"></p>
        <label class="form-label">ملاحظة إدارية</label>
        <textarea name="admin_note" id="wd-note" class="form-control" rows="2"
                  data-testid="wd-note" placeholder="سبب القرار — يُحفظ مع الطلب"></textarea>
        <div class="form-text">تُحفظ مع الطلب ويراها من يراجعه بعدك.</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
        <button type="submit" class="btn btn-primary" id="wd-confirm" data-testid="wd-confirm">تنفيذ</button>
      </div>
    </form>
  </div></div>
</div>

<script nonce="{{ request()->attributes->get('csp_nonce') }}">
(function () {
    var modalEl = document.getElementById('wd-modal');

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.wd-decide');
        if (!btn) return;

        var approve = btn.getAttribute('data-decision') === 'approve';
        var name = btn.getAttribute('data-name') || 'العميل';
        var total = btn.getAttribute('data-total') || '';

        document.getElementById('wd-id').value = btn.getAttribute('data-id');
        document.getElementById('wd-decision').value = btn.getAttribute('data-decision');
        document.getElementById('wd-note').value = '';
        document.getElementById('wd-title').textContent = approve ? 'اعتماد السحب' : 'رفض السحب';
        document.getElementById('wd-body').textContent = approve
            ? ('سيُصرف ' + total + ' ريال إلى ' + name + ' من خزنة المنصّة، ويُرحَّل القيد.')
            : ('سيعود ' + total + ' ريال المحجوز إلى رصيد ' + name + ' المتاح، ويُرحَّل القيد.');

        var confirmBtn = document.getElementById('wd-confirm');
        confirmBtn.className = 'btn ' + (approve ? 'btn-success' : 'btn-danger');
        confirmBtn.textContent = approve ? 'اعتماد' : 'رفض';

        new bootstrap.Modal(modalEl).show();
    });

    // **ولا يُرسَل مرّتين**: ضغطتان على «تنفيذ» تُرسلان طلبين، والخادمُ
    // يردّ الثاني — لكنّ منعَه هنا أوضح للموظّف من رسالة رفض.
    document.getElementById('wd-form').addEventListener('submit', function () {
        var b = document.getElementById('wd-confirm');
        b.disabled = true;
        b.textContent = 'جارٍ التنفيذ…';
    });
})();
</script>
@endsection
