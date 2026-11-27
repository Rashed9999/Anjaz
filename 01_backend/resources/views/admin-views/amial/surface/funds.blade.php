@extends('layouts.admin.app')
@section('title', translate('صناديق العائلة'))
@section('content')
<div class="content container-fluid" dir="rtl">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h4 class="fw-bold mb-1" style="color:var(--amial-primary)">👨‍👩‍👧 {{ translate('صناديق العائلة') }}</h4>
            <small class="text-muted">{{ translate('متابعة الصناديق المشتركة وحوكمتها وأرصدتها وأعضائها النشطين') }}</small>
        </div>
        <a class="btn btn-sm btn-outline-primary" href="{{ route('admin.amial.surface.funds', request()->except('page')) }}">
            ↻ {{ translate('تحديث') }}
        </a>
    </div>

    @php
        $statusLabels = [
            'active' => 'نشط',
            'frozen' => 'مجمّد',
            'archived' => 'مؤرشف',
        ];
        $statusClasses = [
            'active' => 'success',
            'frozen' => 'warning text-dark',
            'archived' => 'secondary',
        ];
    @endphp

    <form method="GET" action="{{ route('admin.amial.surface.funds') }}" class="card border-0 shadow-sm mb-3" style="border-radius:16px" aria-label="{{ translate('فلاتر صناديق العائلة') }}">
        <div class="card-body">
            <div class="row g-2 align-items-end">
                <div class="col-12 col-md-5">
                    <label class="form-label small">{{ translate('بحث') }}</label>
                    <input type="text" name="search" value="{{ request('search') }}" class="form-control" placeholder="{{ translate('اسم الصندوق، ULID، اسم المالك أو هاتفه') }}">
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label small">{{ translate('الحالة') }}</label>
                    <select name="status" class="form-control">
                        <option value="">{{ translate('الكل') }}</option>
                        @foreach($statusLabels as $key => $label)
                            <option value="{{ $key }}" {{ request('status') === $key ? 'selected' : '' }}>{{ translate($label) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-4 d-flex gap-2">
                    <button type="submit" class="btn btn-primary">{{ translate('تطبيق الفلاتر') }}</button>
                    <a href="{{ route('admin.amial.surface.funds') }}" class="btn btn-outline-secondary">{{ translate('مسح') }}</a>
                </div>
            </div>
        </div>
    </form>

    <div class="card border-0 shadow-sm" style="border-radius:16px">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>{{ translate('الصندوق') }}</th>
                            <th>{{ translate('المالك') }}</th>
                            <th>{{ translate('الأعضاء النشطون') }}</th>
                            <th>{{ translate('الرصيد') }}</th>
                            <th>{{ translate('المبلغ المستهدف') }}</th>
                            <th>{{ translate('الحالة') }}</th>
                            <th>{{ translate('أُنشئ') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($funds as $f)
                        @php
                            $ownerName = trim(($f->owner?->f_name ?? '') . ' ' . ($f->owner?->l_name ?? ''));
                            $activeMembers = $f->active_members_count ?? $f->members_count ?? 0;
                            $balanceFloat = (float) ($f->balance ?? 0);
                            $targetFloat = $f->target_amount !== null ? (float) $f->target_amount : 0.0;
                            $progressPct = $targetFloat > 0 ? min(100, (int) round(($balanceFloat / $targetFloat) * 100)) : null;
                            $st = (string) ($f->status ?? '');
                        @endphp
                        <tr>
                            {{-- AMIAL-FUND-DETAIL-001 — **الاسمُ يُنقر فيفتح الحركة.** --}}
                            <td>
                                <a href="{{ route('admin.amial.surface.funds.detail', $f->id) }}"
                                   data-testid="fund-open"><b>{{ $f->name }}</b></a>
                                <br><small class="text-muted text-monospace">{{ $f->fund_ulid }}</small>
                            </td>
                            <td>
                                @if($f->owner_user_id)
                                    <a href="{{ route('admin.amial.hub.account', $f->owner_user_id) }}">
                                        {{ $ownerName !== '' ? $ownerName : ('#' . $f->owner_user_id) }}
                                    </a>
                                    @if($f->owner?->phone)
                                        <br><small class="text-muted text-monospace" dir="ltr">{{ $f->owner->phone }}</small>
                                    @endif
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>
                                <span class="fw-semibold">{{ $activeMembers }}</span>
                                @if(isset($f->active_members_count, $f->members_count) && $f->members_count != $f->active_members_count)
                                    <small class="text-muted">/ {{ $f->members_count }}</small>
                                @endif
                            </td>
                            <td class="fw-bold text-nowrap">{{ number_format($balanceFloat, 0) }} ر.ي</td>
                            <td class="text-nowrap">
                                @if($targetFloat > 0)
                                    <span>{{ number_format($targetFloat, 0) }} ر.ي</span>
                                    <small class="badge bg-light text-dark ms-1">{{ $progressPct }}%</small>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>
                                <span class="badge bg-{{ $statusClasses[$st] ?? 'secondary' }}">
                                    {{ translate($statusLabels[$st] ?? ($st ?: '—')) }}
                                </span>
                            </td>
                            <td><small>{{ $f->created_at?->format('Y-m-d') ?? '—' }}</small></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-4">{{ translate('لا صناديق تطابق معايير البحث') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <div class="p-3 border-top">
                {{ method_exists($funds, 'withQueryString') ? $funds->withQueryString()->links() : $funds->links() }}
            </div>
        </div>
    </div>
</div>
@endsection
