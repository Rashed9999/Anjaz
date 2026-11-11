<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light only">
    <meta name="supported-color-schemes" content="light">
    <title>{{ $subject }}</title>
    <style>
        @media only screen and (max-width: 620px) {
            .shell { width: 100% !important; }
            .content { padding: 28px 20px !important; }
            .header { padding: 24px 20px 20px !important; }
            .footer { padding: 20px !important; }
        }
    </style>
</head>
<body style="margin:0;padding:0;background:#F2F3F7;font-family:Arial,'Helvetica Neue',sans-serif;color:#1A2433;-webkit-text-size-adjust:100%;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">{{ $preheader }}</div>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;background:#F2F3F7;margin:0;padding:0;">
    <tr>
        <td align="center" style="padding:28px 12px;">
            <table role="presentation" class="shell" width="600" cellspacing="0" cellpadding="0" border="0" style="width:600px;max-width:600px;background:#FFFFFF;border-radius:18px;overflow:hidden;border:1px solid #E5E7EB;">
                <tr><td style="height:6px;background:#FECA1E;font-size:0;line-height:0;">&nbsp;</td></tr>
                <tr>
                    <td class="header" align="center" style="padding:28px 32px 22px;background:#FFFFFF;border-bottom:1px solid #E5E7EB;">
                        <img src="{{ $logoUrl }}" width="150" alt="Amial Pay" style="display:block;width:150px;max-width:150px;height:auto;border:0;margin:0 auto 10px;">
                        <div style="font-size:13px;line-height:20px;color:#5F6B7C;font-weight:700;">أميال باي · AMIAL PAY</div>
                    </td>
                </tr>
                <tr>
                    <td class="content" style="padding:36px 42px 34px;text-align:right;direction:rtl;">
                        <div style="display:inline-block;background:#E3F4EB;color:#0F7A46;border-radius:999px;padding:7px 12px;font-size:12px;font-weight:700;line-height:18px;margin-bottom:18px;">تم اعتماد حسابك</div>
                        <h1 style="margin:0 0 10px;font-size:26px;line-height:38px;color:#021F5C;font-weight:800;">مرحباً {{ $name }}</h1>
                        <p style="margin:0 0 22px;font-size:15px;line-height:26px;color:#5F6B7C;">تم اعتماد {{ $kindLabel }} في أميال باي. هذه خطوتك التالية للدخول:</p>

                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;margin:0 0 22px;">
                            @foreach ($steps as $i => $step)
                            <tr>
                                <td style="padding:6px 0;vertical-align:top;">
                                    <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="width:100%;">
                                        <tr>
                                            <td width="40" valign="top" style="padding-left:12px;"><div style="width:30px;height:30px;line-height:30px;text-align:center;border-radius:50%;background:#053391;color:#FFFFFF;font-weight:800;font-size:14px;">{{ $i + 1 }}</div></td>
                                            <td style="font-size:15px;line-height:30px;color:#1A2433;">{{ $step }}</td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>
                            @endforeach
                        </table>

                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;margin:0 0 22px;">
                            <tr>
                                <td align="center" style="background:#F8FAFF;border:1px solid #D9E4FF;border-radius:14px;padding:16px;">
                                    <div style="font-size:12px;line-height:18px;color:#5F6B7C;margin-bottom:6px;font-weight:700;">البريد الذي تُدخله في «نسيت كلمة المرور»</div>
                                    <div dir="ltr" style="font-size:16px;line-height:24px;color:#053391;font-weight:800;">{{ $email }}</div>
                                </td>
                            </tr>
                        </table>

                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;margin:0 0 22px;">
                            <tr>
                                <td style="background:#FFF8E1;border-radius:12px;border-right:4px solid #FECA1E;padding:14px 16px;">
                                    <div style="font-size:14px;line-height:24px;color:#7A5C00;font-weight:700;margin-bottom:2px;">حماية حسابك</div>
                                    <div style="font-size:13px;line-height:22px;color:#5F6B7C;">لن يطلب منك فريق أميال باي كلمة المرور أو رمز PIN أو رمز التحقق عبر الهاتف أو المحادثات أو البريد.</div>
                                </td>
                            </tr>
                        </table>
                        <p style="margin:18px 0 0;font-size:12px;line-height:20px;color:#8B97A8;">مرجع الطلب: <span dir="ltr" style="font-family:'Courier New',monospace;">{{ $reference }}</span></p>
                    </td>
                </tr>
                <tr>
                    <td class="footer" align="center" style="padding:24px 32px;background:#021F5C;color:#FFFFFF;text-align:center;">
                        <div style="font-size:13px;line-height:22px;font-weight:700;margin-bottom:4px;">أميال باي · أمانك أولويتنا</div>
                        <div style="font-size:12px;line-height:20px;color:#D9E4FF;margin-bottom:10px;">هذه رسالة آلية بعد اعتماد حسابك.</div>
                        <a href="{{ $websiteUrl }}" style="color:#FECA1E;text-decoration:none;font-size:12px;font-weight:700;">amialpay.com</a>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
