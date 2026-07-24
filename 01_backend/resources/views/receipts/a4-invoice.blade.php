{{--
    AMIAL-INVOICE-A4-001 — فاتورة رسمية بمقاس A4.

    وثيقة رسمية (وليست إيصالاً مصغّراً): ترويسة الشركة + رقم الفاتورة + تاريخ الإصدار
    + بيانات المُصدِر/العميل + بنود (إن وُجدت في metadata.items) + الإجماليات
    + المبلغ كتابةً + منطقة الختم والتوقيع + QR للتحقق.

    Variables:
      - $receipt       : Receipt model
      - $user          : مالك الإيصال (المُصدِر أو العميل حسب الاتجاه)
      - $counterparty  : الطرف الآخر (nullable)
      - $qrUrl         : data-URI لصورة QR (nullable)
      - $businessName  : اسم النشاط
      - $amountWords   : المبلغ كتابةً (عربي)
      - $items         : مصفوفة بنود [{name, qty, price, total}] أو []
--}}
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>فاتورة {{ $receipt->receipt_number }}</title>
    <style>
        /* AMIAL-FIX(PDF-RTL): أُزيلت @page — مُحرّك mPDF يضبط المقاس
           والهوامش. وجودها هنا كان يُربك مُقسِّم الصفحات فيولّد مئات
           الصفحات الفارغة (رُصد: 356 صفحة لإيصال واحد). */
        * { box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 12px;
            color: #1a1a1a;
            margin: 0;
            direction: rtl;
            line-height: 1.5;
        }

        .doc-header {
            width: 100%;
            border-bottom: 3px solid #053391;
            padding-bottom: 12px;
            margin-bottom: 18px;
        }
        .doc-header td { vertical-align: top; }
        .brand-block .brand {
            font-size: 26px;
            font-weight: bold;
            color: #053391;
            letter-spacing: 1px;
        }
        .brand-block .biz {
            font-size: 13px;
            color: #333;
            margin-top: 2px;
        }
        .brand-block .tagline {
            font-size: 10px;
            color: #888;
        }
        .doc-title-block { text-align: left; }
        .doc-title {
            font-size: 22px;
            font-weight: bold;
            color: #053391;
        }
        .doc-title-en {
            font-size: 10px;
            color: #888;
            letter-spacing: 2px;
        }

        .meta-bar {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 18px;
        }
        .meta-bar td {
            padding: 8px 10px;
            background: #f5f7fb;
            border: 1px solid #e2e8f0;
            font-size: 11px;
        }
        .meta-bar .k { color: #666; }
        .meta-bar .v { font-weight: bold; color: #053391; font-family: monospace; }

        .parties {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 16px;
        }
        .parties td {
            width: 50%;
            vertical-align: top;
            padding: 12px 14px;
            border: 1px solid #e2e8f0;
        }
        .parties .ptitle {
            font-size: 10px;
            color: #888;
            text-transform: uppercase;
            margin-bottom: 6px;
            border-bottom: 1px solid #eee;
            padding-bottom: 4px;
        }
        .parties .pname { font-size: 14px; font-weight: bold; }
        .parties .pline { font-size: 11px; color: #555; }

        table.items {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 4px;
        }
        table.items thead th {
            background: #053391;
            color: #fff;
            padding: 9px 8px;
            font-size: 11px;
            text-align: right;
        }
        table.items tbody td {
            padding: 8px;
            border-bottom: 1px solid #eee;
            font-size: 11px;
        }
        table.items tbody tr:nth-child(even) td { background: #fafbfc; }
        table.items .num { text-align: center; width: 6%; }
        table.items .amt { text-align: left; font-family: monospace; white-space: nowrap; }

        .totals {
            width: 45%;
            margin-right: 55%;
            border-collapse: collapse;
            margin-top: 8px;
        }
        .totals td {
            padding: 6px 10px;
            font-size: 12px;
        }
        .totals .tl { color: #666; }
        .totals .tv { text-align: left; font-family: monospace; font-weight: 600; }
        .totals .grand td {
            background: #FFF8E1;
            border-top: 2px solid #FECA1E;
            font-size: 15px;
            font-weight: bold;
            color: #053391;
        }

        .words {
            margin: 14px 0;
            padding: 10px 14px;
            background: #f5f7fb;
            border-right: 3px solid #053391;
            font-size: 12px;
        }
        .words .k { color: #888; font-size: 10px; }

        .foot {
            width: 100%;
            border-collapse: collapse;
            margin-top: 26px;
        }
        .foot td { vertical-align: top; width: 33%; }
        .sign-box {
            text-align: center;
            padding-top: 40px;
        }
        .sign-line {
            border-top: 1px solid #999;
            width: 80%;
            margin: 0 auto;
            padding-top: 4px;
            font-size: 10px;
            color: #666;
        }
        .qr-box { text-align: center; }
        .qr-box img { width: 90px; height: 90px; }
        .qr-code {
            font-family: monospace;
            font-size: 11px;
            letter-spacing: 1px;
            color: #053391;
            font-weight: bold;
        }
        .legal {
            margin-top: 22px;
            padding-top: 10px;
            border-top: 1px dashed #ccc;
            text-align: center;
            font-size: 9px;
            color: #999;
        }
    </style>
</head>
<body>

<table class="doc-header">
    <tr>
        <td class="brand-block">
            <div class="brand">أميال باي</div>
            <div class="biz">{{ $businessName }}</div>
            <div class="tagline">نظام دفع رقمي معتمد — AMYAL PAY</div>
        </td>
        <td class="doc-title-block">
            <div class="doc-title">فاتورة رسمية</div>
            <div class="doc-title-en">TAX / OFFICIAL INVOICE</div>
        </td>
    </tr>
</table>

<table class="meta-bar">
    <tr>
        <td width="34%"><span class="k">رقم الفاتورة:</span> <span class="v">{{ $receipt->receipt_number }}</span></td>
        <td width="33%"><span class="k">تاريخ الإصدار:</span> <span class="v">{{ $receipt->issued_at?->format('Y-m-d') }}</span></td>
        <td width="33%"><span class="k">الوقت:</span> <span class="v">{{ $receipt->issued_at?->format('H:i') }}</span></td>
    </tr>
    <tr>
        <td><span class="k">المرجع:</span> <span class="v">{{ $receipt->reference_transaction_id }}</span></td>
        <td><span class="k">المنطقة:</span> <span class="v">{{ $receipt->zone_code }}</span></td>
        <td><span class="k">الحالة:</span> <span class="v">مدفوعة</span></td>
    </tr>
</table>

<table class="parties">
    <tr>
        <td>
            <div class="ptitle">المُصدِر / البائع</div>
            <div class="pname">{{ $businessName }}</div>
            <div class="pline">عبر منصّة أميال باي</div>
            <div class="pline">support@amialpay.com</div>
        </td>
        <td>
            <div class="ptitle">العميل / المستفيد</div>
            @if($counterparty)
                <div class="pname">{{ $counterparty->f_name }} {{ $counterparty->l_name }}</div>
                <div class="pline">{{ substr($counterparty->phone ?? '', 0, 4) }}***{{ substr($counterparty->phone ?? '', -3) }}</div>
            @elseif($user)
                <div class="pname">{{ $user->f_name }} {{ $user->l_name }}</div>
                <div class="pline">{{ $user->phone }}</div>
            @else
                <div class="pname">—</div>
            @endif
        </td>
    </tr>
</table>

<table class="items">
    <thead>
        <tr>
            <th class="num">#</th>
            <th>البيان / الوصف</th>
            <th class="num">الكمية</th>
            <th class="amt">السعر</th>
            <th class="amt">الإجمالي</th>
        </tr>
    </thead>
    <tbody>
        @if(!empty($items))
            @foreach($items as $i => $it)
                <tr>
                    <td class="num">{{ $i + 1 }}</td>
                    <td>{{ $it['name'] ?? '—' }}</td>
                    <td class="num">{{ $it['qty'] ?? 1 }}</td>
                    <td class="amt">{{ number_format((float)($it['price'] ?? 0), 2) }}</td>
                    <td class="amt">{{ number_format((float)($it['total'] ?? 0), 2) }}</td>
                </tr>
            @endforeach
        @else
            <tr>
                <td class="num">1</td>
                <td>
                    @switch($receipt->receipt_type)
                        @case('send_money') تحويل أموال @break
                        @case('cash_in') إيداع نقدي @break
                        @case('cash_out') سحب نقدي @break
                        @case('add_money') إضافة رصيد @break
                        @case('withdraw') طلب سحب @break
                        @case('pay_merchant') دفع لتاجر @break
                        @case('pos_payment') دفع نقطة بيع @break
                        @case('qr_payment') دفع عبر QR @break
                        @case('refund') استرجاع مبلغ @break
                        @case('split_bill_payment') تقسيم فاتورة @break
                        @case('bank_settlement') تسوية بنكية @break
                        @case('fee_charge') رسوم خدمة @break
                        @default {{ $receipt->receipt_type }}
                    @endswitch
                </td>
                <td class="num">1</td>
                <td class="amt">{{ number_format((float)$receipt->amount, 2) }}</td>
                <td class="amt">{{ number_format((float)$receipt->amount, 2) }}</td>
            </tr>
        @endif
    </tbody>
</table>

<table class="totals">
    <tr>
        <td class="tl">المجموع الفرعي</td>
        <td class="tv">{{ number_format((float)$receipt->amount, 2) }} ر.ي</td>
    </tr>
    @if((float)$receipt->fee > 0)
        <tr>
            <td class="tl">رسوم الخدمة</td>
            <td class="tv">{{ number_format((float)$receipt->fee, 2) }} ر.ي</td>
        </tr>
    @endif
    <tr class="grand">
        <td>الإجمالي المستحق</td>
        <td class="tv">{{ number_format((float)$receipt->net_amount, 2) }} ر.ي</td>
    </tr>
</table>

<div class="words">
    <span class="k">المبلغ كتابةً:</span><br>
    {{ $amountWords }}
</div>

<table class="foot">
    <tr>
        <td class="qr-box">
            @if($qrUrl)
                <img src="{{ $qrUrl }}" alt="QR">
            @endif
            <div class="qr-code">{{ $receipt->verification_code }}</div>
            <div style="font-size:9px;color:#888">كود التحقق من صحة الفاتورة</div>
        </td>
        <td class="sign-box">
            <div class="sign-line">توقيع المستلم</div>
        </td>
        <td class="sign-box">
            <div class="sign-line">الختم والتوقيع</div>
        </td>
    </tr>
</table>

<div class="legal">
    <strong>أميال باي — Amyal Pay</strong> · فاتورة صادرة إلكترونياً ومحفوظة في السجلات الرسمية.
    <br>
    يمكن التحقق من صحة هذه الفاتورة عبر مسح رمز QR أعلاه · support@amialpay.com
</div>

</body>
</html>
