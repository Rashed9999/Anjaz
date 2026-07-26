{{--
    AMIAL-NOTICE-001 — قالب الإشعار الموحّد.

    وثيقة واحدة لكل أنواع العمليات (سحب/إيداع/تحويل/دفع/سداد/استرجاع)،
    يتغيّر فيها العنوان والافتتاحية والبيان فقط — يبنيها ReceiptNoticeService.

    البنية مأخوذة من إشعارات شركات الصرافة اليمنية: إطار واحد في أعلى الصفحة،
    شريط جانبي للهوية، وشبكة خلايا محدودة الحواف. تُطبع على A4 عادي وتُرسل
    عبر واتساب كما هي.

    يُصيَّر عبر mPDF (ArabicPdf) لا DomPDF — الأخير لا يدعم تشكيل العربية
    ولا الاتجاه، فتخرج الحروف مقطّعة ومعكوسة.
--}}
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>{{ $title }} — {{ $receipt->receipt_number }}</title>
    <style>
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 11px;
            color: #111;
            margin: 0;
        }

        .notice {
            border: 2px solid #111;
            width: 100%;
            border-collapse: collapse;
        }

        .notice td {
            border: 1px solid #111;
            padding: 5px 7px;
            vertical-align: middle;
        }

        /* الشريط الجانبي: الهوية والعنوان الكبير */
        .brand {
            width: 22%;
            text-align: center;
            border-left: 2px solid #111 !important;
        }

        .brand .logo {
            width: 78px;
            margin-bottom: 4px;
        }

        .brand .contact {
            font-size: 8px;
            color: #444;
            line-height: 1.6;
        }

        .doc-title {
            font-size: 21px;
            font-weight: bold;
            letter-spacing: 1px;
            padding-top: 6px;
        }

        .label {
            font-weight: bold;
            width: 15%;
        }

        /* الخانات المؤطّرة: رقم الإشعار أحمر، المبلغ أزرق — كما في المرجع */
        .boxed {
            border: 1px solid #111;
            padding: 3px 10px;
            display: inline-block;
            font-weight: bold;
            text-align: center;
        }

        .ref-no { color: #C1121F; }
        .amount-no { color: #053391; font-size: 14px; }

        .opening {
            font-weight: bold;
            text-align: center;
        }

        .words {
            font-weight: bold;
            font-size: 12px;
        }

        .narrative {
            line-height: 1.9;
        }

        .footer-note {
            font-size: 10px;
            text-align: center;
        }

        .muted { color: #555; font-size: 9px; }
    </style>
</head>
<body>

<table class="notice">
    {{-- الشريط الجانبي يمتدّ على كل الصفوف --}}
    <tr>
        {{-- AMIAL-RECEIPT-TYPE-001: 8 لا 7 بعد إضافة صفّ «نوع العملية».
             رقم أقلّ من عدد الصفوف يدفع الخلايا الزائدة خارج الشريط الجانبي. --}}
        <td class="brand" rowspan="8">
            @if(!empty($logoData))
                {{-- AMIAL-RECEIPT-LOGO-001: العرض بنمط مباشر وسمة width معاً.
                     mPDF لم يطبّق class="logo" على <img> فخرج الشعار بحجمه
                     الأصلي وطمس الإشعار كلّه. الأنماط المباشرة يحترمها دائماً. --}}
                <img src="{{ $logoData }}" width="78"
                     style="width:78px;height:auto;margin-bottom:4px;" alt="أميال باي">
            @endif
            <div style="font-weight:bold;font-size:12px;margin-top:2px;">أميال باي</div>
            <div class="contact">
                دفع سريع وآمن<br>
                {{ $supportPhone ?? '' }}<br>
                {{ $supportSite ?? '' }}
            </div>
            <div class="doc-title">{{ $title }}</div>
        </td>

        {{-- الصف 1: التاريخ + رقم الإشعار --}}
        <td class="label">رقم الإشعار</td>
        <td><span class="boxed ref-no">{{ $receipt->receipt_number }}</span></td>
        <td style="text-align:left;font-weight:bold;">
            التاريخ: {{ optional($receipt->issued_at)->format('d-m-Y') }}
        </td>
    </tr>

    {{-- الصف 2: صاحب الحساب + رقم الحساب --}}
    <tr>
        <td class="label">رقم الحساب</td>
        <td><span class="boxed">{{ $accountNumber ?: '—' }}</span></td>
        <td style="text-align:left;font-weight:bold;">
            السيد: {{ $ownerName ?: '—' }}
        </td>
    </tr>

    {{-- AMIAL-RECEIPT-TYPE-001: نوع العملية صريحاً.
         العنوان الجانبي يصنّف المستند («إشعار دفع») لا العملية؛ حقل معنون
         يميّز دفع المشتريات من دفع نقطة البيع من الدفع بمسح رمز. --}}
    <tr>
        <td class="label">نوع العملية</td>
        <td><span class="boxed">{{ $typeLabel }}</span></td>
        <td style="text-align:left;">
            <span class="muted">اتجاه القيد:</span>
            <strong>{{ $receipt->direction === 'debit' ? 'مدين' : 'دائن' }}</strong>
        </td>
    </tr>

    {{-- الصف 3: الافتتاحية --}}
    <tr>
        <td colspan="3" class="opening">{{ $opening }}</td>
    </tr>

    {{-- الصف 4: المبلغ رقماً --}}
    <tr>
        <td class="label">المبلغ</td>
        <td>
            <span class="boxed amount-no">[ {{ $amountFormatted }} ]</span>
            <span class="boxed">ريال يمني</span>
        </td>
        <td style="text-align:left;">
            <span class="muted">رسوم العملية:</span>
            <strong>{{ $feeFormatted }}</strong>
            &nbsp;|&nbsp;
            <span class="muted">الإجمالي:</span>
            <strong>{{ $totalFormatted }}</strong>
        </td>
    </tr>

    {{-- الصف 5: المبلغ بالحروف (التفقيط) --}}
    <tr>
        <td colspan="3" class="words">{{ $amountInWords }}</td>
    </tr>

    {{-- الصف 6: البيان --}}
    <tr>
        <td colspan="3" class="narrative">{{ $narrative }}</td>
    </tr>

    {{-- الصف 7: التذييل --}}
    <tr>
        <td colspan="2" class="footer-note">
            هذا الإشعار آلي ولا يحتاج إلى ختم أو توقيع
        </td>
        <td style="text-align:left;">
            <span class="muted">كود التحقق:</span>
            <strong>{{ $receipt->verification_code }}</strong>
        </td>
    </tr>
</table>

<div class="muted" style="margin-top:6px;text-align:center;">
    للتحقق من صحة هذا الإشعار استخدم كود التحقق أعلاه داخل تطبيق أميال باي.
</div>

</body>
</html>
