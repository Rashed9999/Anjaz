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
            .otp { font-size: 34px !important; letter-spacing: 8px !important; }
            .header { padding: 24px 20px 20px !important; }
            .footer { padding: 20px !important; }
        }
    </style>
</head>
<body style="margin:0;padding:0;background:#F2F3F7;font-family:Arial,'Helvetica Neue',sans-serif;color:#1A2433;-webkit-text-size-adjust:100%;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">
    {{ $preheader }}
</div>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;background:#F2F3F7;margin:0;padding:0;">
    <tr>
        <td align="center" style="padding:28px 12px;">
            <table role="presentation" class="shell" width="600" cellspacing="0" cellpadding="0" border="0" style="width:600px;max-width:600px;background:#FFFFFF;border-radius:18px;overflow:hidden;border:1px solid #E5E7EB;box-shadow:0 8px 28px rgba(2,31,92,.08);">
                <tr>
                    <td style="height:6px;background:#FECA1E;font-size:0;line-height:0;">&nbsp;</td>
                </tr>
                <tr>
                    <td class="header" align="center" style="padding:28px 32px 22px;background:#FFFFFF;border-bottom:1px solid #E5E7EB;">
                        <img src="{{ $logoUrl }}" width="150" alt="Amial Pay" style="display:block;width:150px;max-width:150px;height:auto;border:0;margin:0 auto 10px;">
                        <div style="font-size:13px;line-height:20px;color:#5F6B7C;font-weight:700;letter-spacing:.2px;">أميال باي · AMIAL PAY</div>
                    </td>
                </tr>
                <tr>
                    <td class="content" style="padding:36px 42px 34px;text-align:right;direction:rtl;">
                        <div style="display:inline-block;background:#EEF3FF;color:#053391;border-radius:999px;padding:7px 12px;font-size:12px;font-weight:700;line-height:18px;margin-bottom:18px;">
                            {{ $purposeLabel }}
                        </div>

                        <h1 style="margin:0 0 10px;font-size:26px;line-height:38px;color:#021F5C;font-weight:800;">رمز التحقق الخاص بك</h1>
                        <p style="margin:0 0 24px;font-size:15px;line-height:26px;color:#5F6B7C;">{{ $purposeDescription }}</p>

                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;margin:0 0 22px;">
                            <tr>
                                <td align="center" style="background:#F8FAFF;border:1px solid #D9E4FF;border-radius:14px;padding:22px 16px;">
                                    <div style="font-size:12px;line-height:18px;color:#5F6B7C;margin-bottom:8px;font-weight:700;">رمز التحقق</div>
                                    <div class="otp" dir="ltr" style="font-family:'Courier New',monospace;font-size:40px;line-height:48px;letter-spacing:10px;color:#053391;font-weight:800;white-space:nowrap;">{{ $otp }}</div>
                                    <div style="font-size:13px;line-height:20px;color:#5F6B7C;margin-top:10px;">صالح لمدة <strong style="color:#1A2433;">{{ $minutes }} دقائق</strong> ويعمل مرة واحدة فقط</div>
                                </td>
                            </tr>
                        </table>

                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;margin:0 0 22px;">
                            <tr>
                                <td style="background:#FFF8E1;border-radius:12px;border-right:4px solid #FECA1E;padding:14px 16px;">
                                    <div style="font-size:14px;line-height:24px;color:#7A5C00;font-weight:700;margin-bottom:2px;">حماية حسابك</div>
                                    <div style="font-size:13px;line-height:22px;color:#5F6B7C;">لا تشارك هذا الرمز مع أي شخص. فريق أميال باي لن يطلب منك كشف رمز التحقق عبر الهاتف أو المحادثات أو البريد.</div>
                                </td>
                            </tr>
                        </table>

                        <p style="margin:0 0 6px;font-size:14px;line-height:24px;color:#5F6B7C;">إذا لم تطلب هذه العملية، تجاهل الرسالة. لن يتم تنفيذ أي تغيير دون إدخال الرمز الصحيح.</p>
                        <p style="margin:18px 0 0;font-size:12px;line-height:20px;color:#8B97A8;">مرجع التحقق: <span dir="ltr" style="font-family:'Courier New',monospace;">{{ $reference }}</span></p>
                    </td>
                </tr>
                <tr>
                    <td class="footer" align="center" style="padding:24px 32px;background:#021F5C;color:#FFFFFF;text-align:center;">
                        <div style="font-size:13px;line-height:22px;font-weight:700;margin-bottom:4px;">أميال باي · أمانك أولويتنا</div>
                        <div style="font-size:12px;line-height:20px;color:#D9E4FF;margin-bottom:10px;">هذه رسالة آلية خاصة بالتحقق الأمني.</div>
                        <a href="{{ $websiteUrl }}" style="color:#FECA1E;text-decoration:none;font-size:12px;font-weight:700;">amialpay.com</a>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
