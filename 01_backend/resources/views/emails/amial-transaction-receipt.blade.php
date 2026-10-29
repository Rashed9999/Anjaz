<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ $subject }}</title>
</head>
<body style="margin:0;background:#f5f7fa;font-family:Tahoma,Arial,sans-serif;color:#17202a">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f5f7fa;padding:24px 12px">
    <tr><td align="center">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;background:#fff;border-radius:16px;overflow:hidden;border:1px solid #e7ebef">
            <tr>
                <td style="padding:22px 28px;background:#0b3d91;color:#fff">
                    <div style="font-size:22px;font-weight:700">أميال باي</div>
                    <div style="font-size:13px;margin-top:5px;opacity:.9">إيصالات المعاملات</div>
                </td>
            </tr>
            <tr>
                <td style="padding:28px">
                    <p style="margin:0 0 12px;font-size:16px">مرحباً {{ $customerName }}،</p>
                    <h1 style="font-size:22px;margin:0 0 8px">{{ $typeLabel }}</h1>
                    <p style="margin:0 0 24px;color:#5b6573">تم تسجيل المعاملة في حسابك بنجاح. هذه نسخة إلكترونية من تفاصيل الحركة.</p>

                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse">
                        <tr><td style="padding:11px 0;color:#697386;border-bottom:1px solid #edf0f2">الحركة</td><td style="padding:11px 0;text-align:left;font-weight:700;border-bottom:1px solid #edf0f2">{{ $directionLabel }}</td></tr>
                        <tr><td style="padding:11px 0;color:#697386;border-bottom:1px solid #edf0f2">المبلغ</td><td style="padding:11px 0;text-align:left;font-weight:700;border-bottom:1px solid #edf0f2">{{ $amount }}</td></tr>
                        @if($fee)
                        <tr><td style="padding:11px 0;color:#697386;border-bottom:1px solid #edf0f2">الرسوم</td><td style="padding:11px 0;text-align:left;border-bottom:1px solid #edf0f2">{{ $fee }}</td></tr>
                        @endif
                        @if($counterparty)
                        <tr><td style="padding:11px 0;color:#697386;border-bottom:1px solid #edf0f2">الطرف الآخر</td><td style="padding:11px 0;text-align:left;border-bottom:1px solid #edf0f2">{{ $counterparty }}</td></tr>
                        @endif
                        @if($balance)
                        <tr><td style="padding:11px 0;color:#697386;border-bottom:1px solid #edf0f2">الرصيد بعد العملية</td><td style="padding:11px 0;text-align:left;border-bottom:1px solid #edf0f2">{{ $balance }}</td></tr>
                        @endif
                        <tr><td style="padding:11px 0;color:#697386;border-bottom:1px solid #edf0f2">رقم العملية</td><td style="padding:11px 0;text-align:left;font-family:monospace;border-bottom:1px solid #edf0f2">{{ $reference }}</td></tr>
                        <tr><td style="padding:11px 0;color:#697386">التاريخ والوقت</td><td style="padding:11px 0;text-align:left">{{ $date }}</td></tr>
                    </table>

                    <div style="margin-top:26px;padding:14px 16px;background:#f7f9fc;border-radius:10px;color:#5b6573;font-size:13px;line-height:1.8">
                        هذا البريد آلي ولا يطلب منك كلمة المرور أو رمز PIN أو رمز التحقق. إذا لم تتعرف على هذه المعاملة فراجع حسابك وتواصل مع دعم أميال باي.
                    </div>

                    <div style="margin-top:24px;text-align:center">
                        <a href="{{ $websiteUrl }}" style="display:inline-block;background:#0b3d91;color:#fff;text-decoration:none;padding:11px 22px;border-radius:9px">أميال باي</a>
                    </div>
                </td>
            </tr>
            <tr><td style="padding:18px 28px;background:#fafbfc;color:#7b8490;font-size:12px;text-align:center">أُرسل هذا الإيصال إلى البريد الإلكتروني الموثق في حسابك.</td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
