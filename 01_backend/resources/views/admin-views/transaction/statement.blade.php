<!DOCTYPE html>
{{-- AMIAL-TXN-ADMIN-001: كشف المعاملات للطباعة (PDF via DomPDF). --}}
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #1f2937; font-size: 11px; }
        .head { text-align: center; margin-bottom: 12px; }
        .head h2 { color: #053391; margin: 0 0 4px; }
        .meta { font-size: 10px; color: #6b7280; }
        .summary { width: 100%; margin: 10px 0; border-collapse: collapse; }
        .summary td { padding: 4px 8px; border: 1px solid #e5e7eb; }
        table.data { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.data th { background: #053391; color: #fff; padding: 5px; font-size: 10px; text-align: right; }
        table.data td { padding: 4px 5px; border-bottom: 1px solid #eee; font-size: 10px; }
        .num { text-align: left; direction: ltr; }
        .credit { color: #1b7a34; }
        .debit { color: #b3261e; }
    </style>
</head>
<body>
    <div class="head">
        <h2>{{ \App\CentralLogics\Helpers::get_business_settings('business_name') ?? 'أميال باي' }}</h2>
        <div>كشف المعاملات</div>
        <div class="meta">
            @if($start_date && $end_date) الفترة: {{ $start_date }} — {{ $end_date }} @else كل الفترات @endif
            &nbsp;|&nbsp; النوع: {{ ucwords(str_replace('_', ' ', $transaction_type)) }}
            &nbsp;|&nbsp; تاريخ الإصدار: {{ date('d M Y h:i A') }}
        </div>
    </div>

    <table class="summary">
        <tr>
            <td>إجمالي المدين</td>
            <td class="num">{{ \App\CentralLogics\Helpers::set_symbol($totalDebit) }}</td>
            <td>إجمالي الدائن</td>
            <td class="num">{{ \App\CentralLogics\Helpers::set_symbol($totalCredit) }}</td>
            <td>عدد المعاملات</td>
            <td class="num">{{ $transactions->count() }}</td>
        </tr>
    </table>

    <table class="data">
        <thead>
            <tr>
                <th>#</th>
                <th>رقم العملية</th>
                <th>النوع</th>
                <th>من</th>
                <th>إلى</th>
                <th>مدين</th>
                <th>دائن</th>
                <th>الرسوم</th>
                <th>الرصيد</th>
                <th>التاريخ</th>
            </tr>
        </thead>
        <tbody>
            @foreach($transactions as $i => $trx)
                @php
                    $sender   = \App\CentralLogics\Helpers::get_user_info($trx->from_user_id);
                    $receiver = \App\CentralLogics\Helpers::get_user_info($trx->to_user_id);
                @endphp
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $trx->transaction_no ?? $trx->transaction_id }}</td>
                    <td>{{ ucwords(str_replace('_', ' ', $trx->transaction_type)) }}</td>
                    <td>{{ $sender ? trim($sender->f_name . ' ' . $sender->l_name) . ' — ' . $sender->phone : '—' }}</td>
                    <td>{{ $receiver ? trim($receiver->f_name . ' ' . $receiver->l_name) . ' — ' . $receiver->phone : '—' }}</td>
                    <td class="num debit">{{ $trx->debit > 0 ? \App\CentralLogics\Helpers::set_symbol($trx->debit) : '—' }}</td>
                    <td class="num credit">{{ $trx->credit > 0 ? \App\CentralLogics\Helpers::set_symbol($trx->credit) : '—' }}</td>
                    <td class="num">{{ \App\CentralLogics\Helpers::set_symbol($trx->charge) }}</td>
                    <td class="num">{{ \App\CentralLogics\Helpers::set_symbol($trx->balance) }}</td>
                    <td class="num">{{ date('d M Y h:i A', strtotime($trx->created_at)) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
