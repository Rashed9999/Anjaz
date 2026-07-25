{{--
    AMIAL-STATEMENT-001 — كشف حساب المحفظة (PDF).

    بيان محاسبي لفترة: كل الحركات بعمودَي مدين ودائن ورصيد جارٍ — لا مستند
    عملية واحدة. الشكل يتبع كشوف شركات الصرافة: ترويسة بالهوية والفترة،
    ثم جدول، ثم إجماليات.

    أفقي (A4-L) لأن الأعمدة سبعة ولا يتّسع لها العمودي بخطّ مقروء.
--}}
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>كشف حساب — {{ $accountNumber }}</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5px; color: #111; }

        .head { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        .head td { padding: 3px 6px; vertical-align: top; }
        .brand { font-size: 15px; font-weight: bold; color: #053391; }
        .doc { font-size: 17px; font-weight: bold; }
        .muted { color: #555; font-size: 9px; }

        table.rows { width: 100%; border-collapse: collapse; }
        table.rows th {
            background: #053391; color: #fff; font-size: 9.5px;
            padding: 5px 4px; border: 1px solid #053391;
        }
        table.rows td {
            border: 1px solid #C9CFDA; padding: 4px;
            vertical-align: top;
        }
        table.rows tr:nth-child(even) td { background: #F6F8FB; }

        .num { text-align: center; white-space: nowrap; font-weight: bold; }
        .credit { color: #16A34A; }
        .debit  { color: #C1121F; }

        .totals { width: 100%; border-collapse: collapse; margin-top: 8px; }
        .totals td {
            border: 1px solid #111; padding: 5px 8px;
            font-weight: bold; text-align: center;
        }

        .note { margin-top: 8px; text-align: center; font-size: 9px; color: #555; }
    </style>
</head>
<body>

<table class="head">
    <tr>
        <td style="width:34%">
            <div class="brand">أميال باي</div>
            <div class="muted">دفع سريع وآمن</div>
        </td>
        <td style="width:32%; text-align:center;">
            <div class="doc">كشف حساب</div>
            <div class="muted">
                من {{ $from->format('Y-m-d') }} إلى {{ $to->format('Y-m-d') }}
            </div>
        </td>
        <td style="width:34%; text-align:left;">
            <div><strong>السيد:</strong> {{ $ownerName ?: '—' }}</div>
            <div><strong>رقم الحساب:</strong> {{ $accountNumber ?: '—' }}</div>
            <div class="muted">تاريخ الإصدار: {{ now()->format('Y-m-d H:i') }}</div>
        </td>
    </tr>
</table>

<table class="rows">
    <thead>
        <tr>
            <th style="width:4%">م</th>
            <th style="width:11%">التاريخ</th>
            <th style="width:13%">نوع العملية</th>
            <th style="width:34%">البيان</th>
            <th style="width:12%">مدين (عليكم)</th>
            <th style="width:12%">دائن (لكم)</th>
            <th style="width:14%">الرصيد</th>
        </tr>
    </thead>
    <tbody>
        @forelse($rows as $i => $r)
            <tr>
                <td class="num">{{ $i + 1 }}</td>
                <td class="num">{{ \Illuminate\Support\Carbon::parse($r['date'])->format('Y-m-d') }}</td>
                <td>{{ $r['type_label'] }}</td>
                <td>{{ $r['statement'] }}</td>
                <td class="num debit">
                    {{ (float) $r['debit'] > 0 ? \App\CentralLogics\Helpers::money($r['debit']) : '—' }}
                </td>
                <td class="num credit">
                    {{ (float) $r['credit'] > 0 ? \App\CentralLogics\Helpers::money($r['credit']) : '—' }}
                </td>
                <td class="num">{{ \App\CentralLogics\Helpers::money($r['balance']) }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="7" style="text-align:center; padding:18px;">
                    لا توجد حركات في هذه الفترة
                </td>
            </tr>
        @endforelse
    </tbody>
</table>

<table class="totals">
    <tr>
        <td>الرصيد الافتتاحي: {{ \App\CentralLogics\Helpers::money($openingBalance) }}</td>
        <td class="debit">إجمالي المدين: {{ \App\CentralLogics\Helpers::money($totalDebit) }}</td>
        <td class="credit">إجمالي الدائن: {{ \App\CentralLogics\Helpers::money($totalCredit) }}</td>
        <td>الرصيد الختامي: {{ \App\CentralLogics\Helpers::money($closingBalance) }}</td>
    </tr>
</table>

<div class="note">
    هذا الكشف آلي ولا يحتاج إلى ختم أو توقيع — صادر عن تطبيق أميال باي.
</div>

</body>
</html>
