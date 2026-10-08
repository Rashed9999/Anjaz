@extends('layouts.admin.app')

{{-- AMIAL-WRONG-TRANSFER-001 — **بلاغاتُ العملاء: شاشةٌ لم تكن موجودة.**

     ══════════════════════════════════════════════════════════════════════
     **ما قِيس عند إصلاح «السجلّ الذي يكذب»:** `DisputeController::list()`
     و`changeStatus()` مبنيّتان منذ زمن، **ولا مسارَ لهما في `routes/
     admin.php` ولا قالبَ `admin-views.disputes.index` على القرص**.

     أي أنّ العميل يرفع بلاغاً من التطبيق (`POST /api/v1/customer/dispute/
     create` — موصولةٌ وتعمل)، فيدخل السطرُ جدولَ `disputes` **ولا يراه
     أحد**. لا شاشة، لا رابط، لا مسار.

     ولوحةُ «النزاعات» في القائمة الجانبيّة نزاعاتُ **الدفع الآمن**
     (`safe_payments`) — جدولٌ آخرُ وقصّةٌ أخرى، فلا تُغني عن هذه.

     **وهو نمطُ العطل الأكثر تكراراً في أميال باي**: مبنيٌّ ولا يُوصَل
     إليه. (القاعدة الثانيةَ عشرة.)
     ══════════════════════════════════════════════════════════════════════

     يظهر في : لوحة الإدارة ← خدمات المنصّة ← «📣 بلاغات العملاء».
     ويُوصل إليه من : القائمة الجانبيّة. --}}

@section('title', 'بلاغات العملاء على العمليات')

@section('content')
<div class="content container-fluid">

    <div class="d-flex align-items-center gap-3 mb-4">
        <h2 class="page-header-title mb-0">بلاغات العملاء على العمليات</h2>
        <span class="badge badge-soft-info ms-auto">AMIAL-DISPUTES</span>
    </div>

    <div class="alert alert-light border small">
        حسمُ البلاغ بـ«نقلُ المبلغ» يحرّك المال فعلاً بقيدٍ مزدوج.
        وإن لم يكفِ رصيدُ الطرف الآخر <strong>لا يتغيّر شيء ولا يُحفَظ القرار</strong>،
        وتُقترَح عندها دعوى «تحويلٌ إلى رقمٍ خاطئ» من منصّة الدعم:
        تحجز الموجودَ فوراً وتسجّل الباقيَ ذمّةً تُقتطَع من الوارد.
    </div>

    <div class="card stat-card mb-3">
        <div class="card-body">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">الحالة</label>
                    <select name="trx_type" class="form-select">
                        @foreach ([
                            'pending' => 'قيد المراجعة',
                            'approved' => 'مقبولة',
                            'disputed' => 'حُسمت بنقل المبلغ',
                            'denied' => 'مرفوضة',
                        ] as $key => $label)
                            <option value="{{ $key }}" @selected($transactionType === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">نوع المُبلِّغ</label>
                    <select name="sender_type" class="form-select">
                        <option value="all" @selected($senderType === 'all')>الكل</option>
                        <option value="customer" @selected($senderType === 'customer')>عميل</option>
                        <option value="agent" @selected($senderType === 'agent')>وكيل</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">بحث — هاتف أو اسم أو رقم عملية</label>
                    <input name="search" value="{{ $search }}" class="form-control" placeholder="مثال: 700111222 أو TX…">
                </div>
                <div class="col-md-2">
                    <button class="btn btn-primary w-100" data-testid="disputes-filter">تصفية</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card stat-card">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>المُبلِّغ</th>
                        <th>رقم العملية</th>
                        <th>المبلغ</th>
                        <th>السبب</th>
                        <th>الحالة</th>
                        <th>رُفع في</th>
                        <th>القرار</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($disputes as $d)
                    <tr>
                        <td>{{ $d->id }}</td>
                        <td>
                            {{ trim(($d->sender->f_name ?? '').' '.($d->sender->l_name ?? '')) ?: '—' }}
                            <div class="small text-muted">{{ $d->sender->phone ?? '—' }} · {{ $d->sender_type }}</div>
                        </td>
                        <td class="font-monospace small">{{ $d->trx_id ?: '—' }}</td>
                        <td class="money">{{ $d->amount }}</td>
                        <td class="small">{{ $d->report_reason ?: '—' }}<div class="text-muted">{{ $d->comment }}</div></td>
                        <td>
                            <span class="badge badge-soft-{{ ['pending' => 'warning', 'approved' => 'info', 'disputed' => 'success', 'denied' => 'secondary'][$d->status] ?? 'secondary' }}">
                                {{ ['pending' => 'قيد المراجعة', 'approved' => 'مقبولة', 'disputed' => 'حُسمت بنقل المبلغ', 'denied' => 'مرفوضة'][$d->status] ?? $d->status }}
                            </span>
                            @if ($d->denied_note)<div class="small text-muted">{{ $d->denied_note }}</div>@endif
                        </td>
                        <td class="small">{{ $d->created_at }}</td>
                        <td class="text-nowrap">
                            @if ($d->status === 'pending')
                                {{-- **ثلاثةُ أزرارٍ لا زرٌّ واحد.** «مقبولة» تعني قُرئ البلاغُ
                                     ويُتابَع، و«حُسمت بنقل المبلغ» وحدَها تحرّك المال —
                                     وخلطُهما يجعل الموظّفَ ينقل مالاً وهو يظنّ أنّه يؤشّر. --}}
                                <form method="POST" action="{{ route('admin.disputes.change-status') }}" class="d-inline">
                                    @csrf
                                    <input type="hidden" name="dispute_id" value="{{ $d->id }}">
                                    <input type="hidden" name="status" value="approved">
                                    <button class="btn btn-sm btn-outline-info" data-testid="dispute-approve">قبول البلاغ</button>
                                </form>
                                <form method="POST" action="{{ route('admin.disputes.change-status') }}" class="d-inline">
                                    @csrf
                                    <input type="hidden" name="dispute_id" value="{{ $d->id }}">
                                    <input type="hidden" name="status" value="disputed">
                                    <button class="btn btn-sm btn-success" data-testid="dispute-resolve">نقلُ المبلغ</button>
                                </form>
                                <form method="POST" action="{{ route('admin.disputes.change-status') }}" class="d-inline">
                                    @csrf
                                    <input type="hidden" name="dispute_id" value="{{ $d->id }}">
                                    <input type="hidden" name="status" value="denied">
                                    <input type="hidden" name="denied_note" value="رُفض بعد المراجعة">
                                    <button class="btn btn-sm btn-outline-danger" data-testid="dispute-deny">رفض</button>
                                </form>
                            @else
                                <span class="text-muted small">حُسم</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    {{-- **«لا بلاغات في هذه الحالة» لا «لا بلاغات».** والفرقُ ليس لفظيّاً:
                         الأولى تدلّ على المرشِّح، والثانيةُ تُقرأ «النظامُ فارغ». --}}
                    <tr><td colspan="8" class="text-muted text-center py-4">لا بلاغات في هذه الحالة.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $disputes->links() }}</div>
    </div>

</div>
@endsection
