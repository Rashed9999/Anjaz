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

    @php
        $fundStatusLabels = ['active' => 'نشط', 'frozen' => 'مجمّد', 'archived' => 'مؤرشف'];
        $fundStatusClasses = ['active' => 'success', 'frozen' => 'warning text-dark', 'archived' => 'secondary'];
        $fundStatus = (string) ($fund->status ?? 'active');

        $ownerName = trim(($fund->owner?->f_name ?? '') . ' ' . ($fund->owner?->l_name ?? ''));
        $targetFloat = $fund->target_amount !== null ? (float) $fund->target_amount : 0.0;
        $storedFloat = (float) $stored;
        $targetPct = $targetFloat > 0 ? min(100, round(($storedFloat / $targetFloat) * 100, 1)) : null;

        $txTypeLabels = [
            'contribute' => 'مساهمة',
            'disbursement' => 'صرف',
            'disburse_to_member' => 'صرف لعضو',
            'disburse_to_external' => 'صرف خارجي',
            'adjustment' => 'تسوية',
        ];
        $txStatusLabels = [
            'completed' => 'مكتمل',
            'pending_approval' => 'بانتظار الموافقة',
            'rejected' => 'مرفوض',
        ];
    @endphp

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif
    @if($errors->any())
        <div class="alert alert-danger" role="alert">
            <ul class="mb-0">
                @foreach($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <a href="{{ route('admin.amial.surface.funds') }}" class="btn btn-sm btn-outline-secondary">→ رجوع</a>
            <h4 class="mb-0 fw-bold" style="color:var(--amial-primary)">👨‍👩‍👧 {{ $fund->name }}</h4>
            <span class="badge bg-{{ $fundStatusClasses[$fundStatus] ?? 'secondary' }}">
                {{ $fundStatusLabels[$fundStatus] ?? $fundStatus }}
            </span>
            <span class="badge badge-soft-secondary text-monospace ms-1">{{ $fund->fund_ulid }}</span>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.amial.surface.funds.detail', [$fund->id, 'export' => 'csv']) }}"
               class="btn btn-sm btn-success" data-testid="fund-export-csv">
                تصدير CSV للحركات
            </a>
        </div>
    </div>

    {{-- ═══ الميزان: المحسوب مقابل المخزَّن ═══ --}}
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
                <div class="fs-4 fw-bold">{{ number_format($storedFloat, 0) }}</div>
                @if($gap === null)
                    <span class="badge bg-success mt-1">✓ يطابق الحركة</span>
                @else
                    <span class="badge bg-danger mt-1">
                        ⚠ فرقٌ قدره {{ number_format((float) $gap, 2) }}
                    </span>
                @endif
            </div>
        </div>
    </div>

    {{-- ═══ حوكمة الصندوق والإجراءات الإدارية ═══ --}}
    <div class="card mb-3 border-0 shadow-sm" style="border-radius:14px">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="card-header-title mb-0">حوكمة الصندوق</h5>
            <span class="badge bg-{{ $fundStatusClasses[$fundStatus] ?? 'secondary' }}">
                الحالة: {{ $fundStatusLabels[$fundStatus] ?? $fundStatus }}
            </span>
        </div>
        <div class="card-body">
            <div class="row g-3 mb-3">
                <div class="col-md-3">
                    <small class="text-muted d-block">المالك</small>
                    @if($fund->owner_user_id)
                        <a href="{{ route('admin.amial.hub.account', $fund->owner_user_id) }}" class="fw-semibold">
                            {{ $ownerName !== '' ? $ownerName : ('#' . $fund->owner_user_id) }}
                        </a>
                        @if($fund->owner?->phone)
                            <small class="text-muted d-block text-monospace" dir="ltr">{{ $fund->owner->phone }}</small>
                        @endif
                    @else
                        <span class="text-muted">—</span>
                    @endif
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block">المبلغ المستهدف ونسبة الإنجاز</small>
                    @if($targetFloat > 0)
                        <div class="fw-semibold">{{ number_format($targetFloat, 0) }} ر.ي <span class="badge bg-primary ms-1">{{ $targetPct }}%</span></div>
                        <div class="progress mt-1" style="height:6px">
                            <div class="progress-bar bg-primary" role="progressbar" style="width: {{ $targetPct }}%" aria-valuenow="{{ $targetPct }}" aria-valuemin="0" aria-valuemax="100"></div>
                        </div>
                    @else
                        <span class="text-muted">غير محدد</span>
                    @endif
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block">الحد اليومي للمساهمة</small>
                    @if($fund->max_member_contribution_per_day && (float) $fund->max_member_contribution_per_day > 0)
                        <span class="fw-semibold">{{ number_format((float) $fund->max_member_contribution_per_day, 0) }} ر.ي / يوم</span>
                    @else
                        <span class="text-muted">بلا حد يومي</span>
                    @endif
                </div>
                <div class="col-md-3">
                    <small class="text-muted d-block">موافقة المالك للصرف</small>
                    @if($fund->require_owner_approval_for_disbursement)
                        <span class="badge bg-info text-dark">مطلوبة قبل التنفيذ</span>
                    @else
                        <span class="badge bg-secondary">غير مطلوبة (صرف مباشر)</span>
                    @endif
                </div>
            </div>

            @if($fundStatus === 'active')
                <form method="POST" action="{{ route('admin.amial.surface.funds.status', $fund->id) }}" class="border-top pt-3">
                    @csrf
                    <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::ulid() }}">
                    <input type="hidden" name="action" value="freeze">
                    <input type="hidden" name="status" value="frozen">
                    <div class="row g-2 align-items-end">
                        <div class="col-12 col-md-8">
                            <label class="form-label small fw-semibold">سبب تجميد الصندوق (إلزامي، 5 أحرف على الأقل)</label>
                            <input type="text" name="reason" value="{{ old('reason') }}" class="form-control form-control-sm"
                                   minlength="5" maxlength="500" required placeholder="اذكر سبب التجميد الإداري للتدقيق...">
                        </div>
                        <div class="col-12 col-md-4">
                            <button type="submit" class="btn btn-sm btn-danger w-100"
                                    onclick="return confirm('هل أنت متأكد من تجميد هذا الصندوق؟ سيُمنع الإيداع والصرف والدعوات مؤقتاً.');">
                                تجميد الصندوق
                            </button>
                        </div>
                    </div>
                </form>
            @elseif($fundStatus === 'frozen')
                <form method="POST" action="{{ route('admin.amial.surface.funds.status', $fund->id) }}" class="border-top pt-3">
                    @csrf
                    <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::ulid() }}">
                    <input type="hidden" name="action" value="unfreeze">
                    <input type="hidden" name="status" value="active">
                    <div class="row g-2 align-items-end">
                        <div class="col-12 col-md-8">
                            <label class="form-label small fw-semibold">سبب فك التجميد (إلزامي، 5 أحرف على الأقل)</label>
                            <input type="text" name="reason" value="{{ old('reason') }}" class="form-control form-control-sm"
                                   minlength="5" maxlength="500" required placeholder="اذكر سبب إعادة تنشيط الصندوق للتدقيق...">
                        </div>
                        <div class="col-12 col-md-4">
                            <button type="submit" class="btn btn-sm btn-success w-100"
                                    onclick="return confirm('هل أنت متأكد من فك تجميد هذا الصندوق وإعادته للحالة النشطة؟');">
                                فك تجميد الصندوق
                            </button>
                        </div>
                    </div>
                </form>
            @else
                <div class="border-top pt-2 text-muted small">
                    هذا الصندوق مؤرشف ولا تتوفر إجراءات تجميد أو فك تجميد عليه.
                </div>
            @endif
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

    {{-- ═══ الحركة: الجواب نفسه ═══ --}}
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2">
                <h5 class="card-header-title mb-0">حركة الصندوق</h5>
                <span class="badge badge-soft-secondary text-dark">{{ $txs->total() }}</span>
            </div>
            <a href="{{ route('admin.amial.surface.funds.detail', [$fund->id, 'export' => 'csv']) }}"
               class="btn btn-sm btn-outline-success">
                تصدير CSV
            </a>
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
                        $txType = (string) ($t->tx_type ?? '');
                        // الصرف المعتمد قد يحتفظ بـ balance_before == balance_after من لحظة الاقتراح،
                        // لذا نعتمد نوع الحركة (disbursement / disburse_*) أولاً بجانب فرق الرصيدين.
                        $isDisbursement = $txType === 'disbursement'
                            || str_starts_with($txType, 'disburse_')
                            || in_array($txType, ['disburse_to_member', 'disburse_to_external'], true);
                        $isAdjustment = $txType === 'adjustment';
                        $isOut = $isDisbursement || bccomp((string) $t->balance_after, (string) $t->balance_before, 4) < 0;

                        $badgeClass = $isAdjustment ? 'secondary' : ($isOut ? 'danger' : 'success');
                        $amountClass = $isAdjustment ? 'text-secondary' : ($isOut ? 'text-danger' : 'text-success');
                        $sign = $isAdjustment ? '' : ($isOut ? '−' : '+');
                    @endphp
                    <tr>
                        <td><small class="text-monospace">{{ $t->created_at }}</small></td>
                        <td>
                            <span class="badge bg-{{ $badgeClass }}">{{ $txTypeLabels[$txType] ?? $txType }}</span>
                        </td>
                        <td class="fw-bold {{ $amountClass }}">
                            {{ $sign }}{{ number_format((float) $t->amount, 0) }}
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
                        <td><small>{{ $txStatusLabels[$t->status] ?? $t->status }}</small></td>
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
                            {{ number_format($storedFloat, 0) }}.
                            @if($storedFloat != 0.0)
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
