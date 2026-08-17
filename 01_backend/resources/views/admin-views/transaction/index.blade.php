@extends('layouts.admin.app')
{{-- AMIAL-TXN-ADMIN-001: جدول المعاملات الكامل بفلاتر + تصدير (كانت الواجهة مفقودة). --}}
@section('title', translate('كشف المعاملات'))
@section('content')
@php
    $dateType  = request('date_type', 'all');
    $startDate = request('start_date');
    $endDate   = request('end_date');
@endphp
<div class="content container-fluid" dir="rtl">

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
        <h4 class="fw-bold m-0" style="color:var(--amial-primary)">📊 {{ translate('كشف المعاملات') }}</h4>
        <div class="btn-group">
            <a class="btn btn-sm btn-outline-success"
               href="{{ route('admin.transaction.export', array_merge(request()->query(), ['export_type' => 'excel'])) }}">
                <i class="tio-file-outlined"></i> {{ translate('تصدير Excel') }}
            </a>
            <a class="btn btn-sm btn-outline-secondary"
               href="{{ route('admin.transaction.export', array_merge(request()->query(), ['export_type' => 'csv'])) }}">
                <i class="tio-download-to"></i> {{ translate('تصدير CSV') }}
            </a>
            <a class="btn btn-sm btn-outline-danger"
               href="{{ route('admin.transaction.export', array_merge(request()->query(), ['export_type' => 'pdf'])) }}">
                <i class="tio-print"></i> PDF
            </a>
        </div>
    </div>

    {{-- شريط الفلاتر --}}
    <div class="card border-0 shadow-sm mb-3" style="border-radius:16px">
        <div class="card-body">
            <form method="GET" action="{{ route('admin.transaction.index') }}" id="filterForm">
                <div class="row g-2 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label small text-muted">{{ translate('بحث (رقم العملية / اسم / هاتف / معرّف)') }}</label>
                        <input type="text" name="search" value="{{ $search ?? '' }}"
                               class="form-control" placeholder="{{ translate('اكتب للبحث...') }}">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small text-muted">{{ translate('النوع') }}</label>
                        <select name="trx_type" class="form-control">
                            <option value="all"    {{ ($trx_type ?? 'all') === 'all' ? 'selected' : '' }}>{{ translate('الكل') }}</option>
                            <option value="debit"  {{ ($trx_type ?? '') === 'debit' ? 'selected' : '' }}>{{ translate('مدين (خصم)') }}</option>
                            <option value="credit" {{ ($trx_type ?? '') === 'credit' ? 'selected' : '' }}>{{ translate('دائن (إضافة)') }}</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small text-muted">{{ translate('الفترة') }}</label>
                        <select name="date_type" class="form-control" id="dateType">
                            <option value="all"     {{ $dateType === 'all' ? 'selected' : '' }}>{{ translate('كل الفترات') }}</option>
                            <option value="last_30" {{ $dateType === 'last_30' ? 'selected' : '' }}>{{ translate('آخر 30 يوماً') }}</option>
                            <option value="custom"  {{ $dateType === 'custom' ? 'selected' : '' }}>{{ translate('مخصّص') }}</option>
                        </select>
                    </div>
                    <div class="col-md-2 date-custom" style="{{ $dateType === 'custom' ? '' : 'display:none' }}">
                        <label class="form-label small text-muted">{{ translate('من') }}</label>
                        <input type="date" name="start_date" value="{{ $startDate }}" class="form-control">
                    </div>
                    <div class="col-md-2 date-custom" style="{{ $dateType === 'custom' ? '' : 'display:none' }}">
                        <label class="form-label small text-muted">{{ translate('إلى') }}</label>
                        <input type="date" name="end_date" value="{{ $endDate }}" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100" style="background:var(--amial-primary);border:none">
                            <i class="tio-filter-list"></i> {{ translate('تطبيق') }}
                        </button>
                    </div>
                    <div class="col-md-2">
                        <a href="{{ route('admin.transaction.index') }}" class="btn btn-outline-secondary w-100">
                            {{ translate('إعادة تعيين') }}
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    {{-- الجدول --}}
    <div class="card border-0 shadow-sm" style="border-radius:16px">
        <div class="card-header border-0 d-flex justify-content-between align-items-center">
            <span class="fw-bold">{{ translate('النتائج') }}</span>
            <span class="badge bg-soft-primary text-primary">
                {{ translate('الإجمالي') }}: {{ $transactions->total() }}
            </span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-align-middle m-0">
                    <thead class="thead-light">
                        <tr>
                            <th>#</th>
                            <th>{{ translate('رقم العملية') }}</th>
                            <th>{{ translate('النوع') }}</th>
                            <th>{{ translate('من') }}</th>
                            <th>{{ translate('إلى') }}</th>
                            <th class="text-end">{{ translate('مدين') }}</th>
                            <th class="text-end">{{ translate('دائن') }}</th>
                            <th class="text-end">{{ translate('الرسوم') }}</th>
                            <th class="text-end">{{ translate('الرصيد') }}</th>
                            <th>{{ translate('التاريخ') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($transactions as $key => $trx)
                            @php
                                $sender   = $parties->get((int) ($trx->from_user_id ?? 0));
                                $receiver = $parties->get((int) ($trx->to_user_id ?? 0));
                            @endphp
                            <tr>
                                <td>{{ $transactions->firstItem() + $key }}</td>
                                <td>
                                    <span class="fw-semibold" style="font-family:monospace">
                                        {{ $trx->transaction_no ?? $trx->transaction_id }}
                                    </span>
                                </td>
                                <td><span class="badge bg-soft-info text-info">{{ ucwords(str_replace('_', ' ', $trx->transaction_type)) }}</span></td>
                                <td>
                                    @if($sender)
                                        {{ trim($sender->f_name . ' ' . $sender->l_name) }}<br>
                                        <small class="text-muted">{{ $sender->phone }}</small>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    @if($receiver)
                                        {{ trim($receiver->f_name . ' ' . $receiver->l_name) }}<br>
                                        <small class="text-muted">{{ $receiver->phone }}</small>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="text-end {{ $trx->debit > 0 ? 'text-danger' : 'text-muted' }}">
                                    {{ (float) ($trx->debit ?? 0) > 0 ? \App\CentralLogics\Helpers::set_symbol((float) $trx->debit) : '—' }}
                                </td>
                                <td class="text-end {{ $trx->credit > 0 ? 'text-success' : 'text-muted' }}">
                                    {{ (float) ($trx->credit ?? 0) > 0 ? \App\CentralLogics\Helpers::set_symbol((float) $trx->credit) : '—' }}
                                </td>
                                <td class="text-end">{{ \App\CentralLogics\Helpers::set_symbol((float) ($trx->charge ?? 0)) }}</td>
                                <td class="text-end fw-semibold">{{ is_null($trx->balance) ? '—' : \App\CentralLogics\Helpers::set_symbol((float) $trx->balance) }}</td>
                                <td><small>{{ date('d M Y', strtotime($trx->created_at)) }}<br>{{ date('h:i A', strtotime($trx->created_at)) }}</small></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="text-center py-5 text-muted">
                                    <i class="tio-search" style="font-size:2rem"></i><br>
                                    {{ translate('لا توجد معاملات مطابقة') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if($transactions->hasPages())
            <div class="card-footer border-0 d-flex justify-content-center">
                {!! $transactions->links() !!}
            </div>
        @endif
    </div>
</div>

<script nonce="{{ request()->attributes->get('csp_nonce') }}">
    (function () {
        var sel = document.getElementById('dateType');
        if (!sel) return;
        sel.addEventListener('change', function () {
            var show = this.value === 'custom';
            document.querySelectorAll('.date-custom').forEach(function (el) {
                el.style.display = show ? '' : 'none';
            });
        });
    })();
</script>
@endsection
