@extends('layouts.admin.app')
{{-- AMIAL-EXPENSE-001: تقرير مصاريف المنصّة (كانت الواجهة مفقودة). --}}
@section('title', translate('المصاريف'))
@section('content')
<div class="content container-fluid" dir="rtl">
    <h4 class="fw-bold mb-3" style="color:var(--amial-primary)">💸 {{ translate('مصاريف المنصّة') }}</h4>

    {{-- ملخّص --}}
    <div class="row g-3 mb-3">
        <div class="col-6 col-md-4">
            <div class="card border-0 shadow-sm" style="border-radius:14px;border-bottom:3px solid #C0392B">
                <div class="card-body py-3">
                    <small class="text-muted">{{ translate('إجمالي المصاريف') }}</small>
                    <div class="fw-bold" style="font-size:20px;color:#C0392B">
                        {{ \App\CentralLogics\Helpers::set_symbol($totalExpense ?? 0) }}
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="card border-0 shadow-sm" style="border-radius:14px;border-bottom:3px solid var(--amial-primary)">
                <div class="card-body py-3">
                    <small class="text-muted">{{ translate('عدد المستفيدين') }}</small>
                    <div class="fw-bold" style="font-size:20px;color:var(--amial-primary)">{{ number_format($totalUsers ?? 0) }}</div>
                </div>
            </div>
        </div>
    </div>

    {{-- فلاتر --}}
    <div class="card border-0 shadow-sm mb-3" style="border-radius:16px">
        <div class="card-body">
            <form method="GET" action="{{ route('admin.expense.index') }}" class="row g-2 align-items-end">
                <div class="col-md-6">
                    <label class="form-label small text-muted">{{ translate('بحث (اسم/هاتف/معرّف)') }}</label>
                    <input type="text" name="search" value="{{ $search ?? '' }}" class="form-control" placeholder="{{ translate('اكتب للبحث...') }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted">{{ translate('الفترة') }}</label>
                    <select name="date_range" class="form-control">
                        @php $dr = request('date_range'); @endphp
                        <option value="">{{ translate('كل الفترات') }}</option>
                        <option value="this_week"  {{ $dr==='this_week'?'selected':'' }}>{{ translate('هذا الأسبوع') }}</option>
                        <option value="this_month" {{ $dr==='this_month'?'selected':'' }}>{{ translate('هذا الشهر') }}</option>
                        <option value="last_month" {{ $dr==='last_month'?'selected':'' }}>{{ translate('الشهر الماضي') }}</option>
                        <option value="this_year"  {{ $dr==='this_year'?'selected':'' }}>{{ translate('هذه السنة') }}</option>
                        <option value="last_year"  {{ $dr==='last_year'?'selected':'' }}>{{ translate('السنة الماضية') }}</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button class="btn btn-primary flex-grow-1" style="background:var(--amial-primary);border:none">{{ translate('تطبيق') }}</button>
                    <a href="{{ route('admin.expense.index') }}" class="btn btn-outline-secondary">{{ translate('تعيين') }}</a>
                </div>
            </form>
        </div>
    </div>

    {{-- الجدول --}}
    <div class="card border-0 shadow-sm" style="border-radius:16px">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-align-middle m-0">
                    <thead class="thead-light">
                        <tr>
                            <th>#</th>
                            <th>{{ translate('رقم العملية') }}</th>
                            <th>{{ translate('الوصف') }}</th>
                            <th class="text-end">{{ translate('المبلغ') }}</th>
                            <th>{{ translate('التاريخ') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($transactions as $key => $trx)
                            <tr>
                                <td>{{ $transactions->firstItem() + $key }}</td>
                                <td style="font-family:monospace">{{ $trx->transaction_no ?? $trx->transaction_id }}</td>
                                <td>{{ $trx->note ?? '—' }}</td>
                                <td class="text-end fw-semibold text-danger">
                                    {{ \App\CentralLogics\Helpers::set_symbol((float)$trx->debit + (float)$trx->credit) }}
                                </td>
                                <td><small>{{ date('d M Y', strtotime($trx->created_at)) }}</small></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center py-5 text-muted">{{ translate('لا توجد مصاريف مسجّلة') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if($transactions->hasPages())
            <div class="card-footer border-0 d-flex justify-content-center">{!! $transactions->links() !!}</div>
        @endif
    </div>
</div>
@endsection
