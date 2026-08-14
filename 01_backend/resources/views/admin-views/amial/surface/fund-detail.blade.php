@extends('layouts.admin.app')

{{-- AMIAL-FUND-DETAIL-001 — **«أين اختفى المال من الصندوق؟ مَن سحبه؟»**

     ══════════════════════════════════════════════════════════════════
     سؤالٌ يسأله كلُّ مستعمل، وشاشةُ الصناديق كانت خمسةَ أعمدة: الاسم ·
     الأعضاء · **الرصيد** · الحالة · تاريخ الإنشاء.

     **ورصيدٌ بلا حركةٍ لا يُجيب عن شيء.** يرى المديرُ ٧٥٠٠ ولا يعرف كيف
     صارت كذلك، ولا مَن أودع، ولا مَن سحب، ولا متى.

     والبياناتُ كانت موجودةً كلُّها في `family_fund_transactions` —
     **مبنيّةٌ ولا يُوصَل إليها.**
     ══════════════════════════════════════════════════════════════════ --}}

@section('title', 'صندوق: ' . $fund->name)

@section('content')
<div class="content container-fluid" dir="rtl">

    <div class="d-flex align-items-center gap-2 mb-3">
        <a href="{{ route('admin.amial.surface.funds') }}" class="btn btn-sm btn-outline-secondary">→ رجوع</a>
        <h4 class="mb-0 fw-bold" style="color:#053391">👨‍👩‍👧 {{ $fund->name }}</h4>
        <span class="badge badge-soft-secondary text-monospace ms-2">{{ $fund->fund_ulid }}</span>
    </div>

    {{-- ═══ الميزان: المحسوب مقابل المخزَّن ═══

         **الرقمُ يُحسب من مصدره** (القاعدة السادسة): مجموعُ الحركة، لا
         عمودُ `balance`. فمقارنةُ العمود بنفسه تُثبت أنّه يساوي نفسه —
         ولا تكشف شيئاً. والفجوةُ — إن وُجدت — هي بعينها ما يبحث عنه من
         يسأل «أين اختفى المال». --}}
    <div class="row g-3 mb-3">
        <div class="col-md-3">
            <div class="card stat-card p-3 text-center">
                <small class="text-muted">إجماليّ الإيداعات</small>
                <div class="fs-4 fw-bold text-success">{{ number_format((float) $inflow, 0) }}</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card p-3 text-center">
                <small class="text-muted">إجماليّ السحوبات</small>
                <div class="fs-4 fw-bold text-danger">{{ number_format((float) $outflow, 0) }}</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card p-3 text-center">
                <small class="text-muted">الرصيد المحسوب من الحركة</small>
                <div class="fs-4 fw-bold text-primary">{{ number_format((float) $derived, 0) }}</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card p-3 text-center border-{{ $gap === null ? 'success' : 'danger' }}"
                 style="border-width:2px" data-testid="fund-balance-check">
                <small class="text-muted">الرصيد المسجَّل</small>
                <div class="fs-4 fw-bold">{{ number_format((float) $stored, 0) }}</div>
                @if($gap === null)
                    <span class="badge bg-success mt-1">✓ يطابق الحركة</span>
                @else
                    {{-- **الفجوةُ تُقال برقمها** — «لا يطابق» وحدها لا تُرشد. --}}
                    <span class="badge bg-danger mt-1">
                        ⚠ فرقٌ قدره {{ number_format((float) $gap, 2) }}
                    </span>
                @endif
            </div>
        </div>
    </div>

    {{-- ═══ الأعضاء: من أودع ومن استفاد ═══ --}}
    <div class="card mb-3">
        <div class="card-header"><h5 class="card-header-title mb-0">الأعضاء ({{ $fund->members_count }})</h5></div>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead class="thead-light">
                    <tr><th>العضو</th><th>الدور</th><th>الحالة</th>
                        <th>أودع</th><th>استلم</th><th>انضمّ</th></tr>
                </thead>
                <tbody>
                @forelse($members as $m)
                    <tr>
                        <td>
                            @if($m->user_id)
                                <a href="{{ route('admin.amial.hub.account', $m->user_id) }}">
                                    {{ trim(($m->f_name ?? '') . ' ' . ($m->l_name ?? '')) ?: 'بلا اسم' }}
                                </a>
                                <small class="text-muted d-block text-monospace">{{ $m->phone }}</small>
                            @else
                                <span class="text-muted">حسابٌ محذوف</span>
                            @endif
                        </td>
                        <td><span class="badge badge-soft-secondary">{{ $m->role }}</span></td>
                        <td>{{ $m->status }}</td>
                        <td class="text-success">{{ number_format((float) ($m->total_contributed ?? 0), 0) }}</td>
                        <td class="text-danger">{{ number_format((float) ($m->total_disbursed ?? 0), 0) }}</td>
                        <td><small>{{ $m->joined_at ? \Illuminate\Support\Carbon::parse($m->joined_at)->format('Y-m-d') : '—' }}</small></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-muted text-center py-3">لا أعضاء</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- ═══ الحركة: الجواب نفسه ═══

         و`balance_before/after` هما ما يجعل الجواب قاطعاً: كلُّ سطرٍ يقول
         الرصيدَ قبله وبعده، فالفجوةُ تُرى بالعين لا تُستنتج. --}}
    <div class="card">
        <div class="card-header">
            <h5 class="card-header-title mb-0">حركة الصندوق</h5>
            <span class="badge badge-soft-secondary text-dark">{{ $txs->total() }}</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm mb-0" data-testid="fund-transactions">
                <thead class="thead-light">
                    <tr>
                        <th>الوقت</th><th>النوع</th><th>المبلغ</th>
                        <th>قبل</th><th>بعد</th>
                        <th>مَن نفّذ</th><th>المستفيد</th><th>مَن وافق</th>
                        <th>الحالة</th><th>ملاحظة</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($txs as $t)
                    @php
                        // الاتّجاهُ من فرق الرصيدين لا من اسم النوع: قائمةُ
                        // الأسماء تشيخ مع الـenum، والفرقُ لا يشيخ.
                        $isOut = bccomp((string) $t->balance_after, (string) $t->balance_before, 4) < 0;
                    @endphp
                    <tr>
                        <td><small class="text-monospace">{{ $t->created_at }}</small></td>
                        <td>
                            <span class="badge bg-{{ $isOut ? 'danger' : 'success' }}">{{ $t->tx_type }}</span>
                        </td>
                        <td class="fw-bold text-{{ $isOut ? 'danger' : 'success' }}">
                            {{ $isOut ? '−' : '+' }}{{ number_format((float) $t->amount, 0) }}
                        </td>
                        <td><small>{{ number_format((float) $t->balance_before, 0) }}</small></td>
                        <td><small class="fw-bold">{{ number_format((float) $t->balance_after, 0) }}</small></td>
                        <td>
                            @if($t->actor_id)
                                <a href="{{ route('admin.amial.hub.account', $t->actor_id) }}">
                                    {{ trim(($t->actor_f ?? '') . ' ' . ($t->actor_l ?? '')) ?: '#' . $t->actor_id }}
                                </a>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>
                            @if($t->ben_id)
                                <a href="{{ route('admin.amial.hub.account', $t->ben_id) }}">
                                    {{ trim(($t->ben_f ?? '') . ' ' . ($t->ben_l ?? '')) ?: '#' . $t->ben_id }}
                                </a>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>
                            {{-- **«لم يُطلب اعتماد» ليس «لم يُعتمد»** (القاعدة ٧). --}}
                            @if($t->app_id)
                                <small>{{ $t->app_f ?? ('#' . $t->app_id) }}</small>
                            @else
                                <small class="text-muted">بلا اعتماد</small>
                            @endif
                        </td>
                        <td><small>{{ $t->status }}</small></td>
                        <td>
                            <small>{{ \Illuminate\Support\Str::limit($t->note, 40) ?: '—' }}</small>
                            @if($t->wallet_transaction_id)
                                <a class="d-block small text-monospace"
                                   href="{{ url('admin/transaction') }}?search={{ urlencode($t->wallet_transaction_id) }}">
                                    {{ \Illuminate\Support\Str::limit($t->wallet_transaction_id, 18) }}
                                </a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10" class="text-center text-muted py-4">
                            لا حركة على هذا الصندوق بعد — والرصيدُ المسجَّل
                            {{ number_format((float) $stored, 0) }}.
                            @if((float) $stored != 0.0)
                                <div class="text-danger mt-1">
                                    ⚠ رصيدٌ بلا حركةٍ تفسّره — يستحقّ تحقيقاً.
                                </div>
                            @endif
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $txs->links() }}</div>
    </div>
</div>
@endsection
