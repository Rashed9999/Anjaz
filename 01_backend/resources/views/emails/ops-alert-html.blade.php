<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subject }}</title>
</head>
<body style="margin:0;padding:0;background:#f3f6fb;font-family:Tahoma,Arial,sans-serif;color:#162033;direction:rtl;text-align:right;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f3f6fb;padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:680px;background:#ffffff;border-radius:18px;overflow:hidden;border:1px solid #e5eaf2;box-shadow:0 8px 24px rgba(18,36,74,.08);">
                <tr>
                    <td style="background:#053391;padding:22px 28px;color:#ffffff;">
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                            <tr>
                                <td style="vertical-align:middle;">
                                    <div style="font-size:13px;opacity:.82;margin-bottom:6px;">تنبيه تشغيلي — أميال باي</div>
                                    <div style="font-size:24px;font-weight:700;line-height:1.5;">{{ $alertTitle }}</div>
                                </td>
                                <td width="90" style="vertical-align:middle;text-align:left;">
                                    @if(!empty($logoUrl))
                                        <img src="{{ $logoUrl }}" alt="Amial Pay" width="74" style="display:inline-block;max-width:74px;height:auto;border:0;">
                                    @endif
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td style="padding:24px 28px 8px;">
                        <div style="display:inline-block;background:{{ $severityCode === 'critical' ? '#fff1f1' : ($severityCode === 'high' ? '#fff7e6' : ($severityCode === 'test' ? '#eef5ff' : '#fffbea')) }};color:{{ $severityCode === 'critical' ? '#a10f18' : ($severityCode === 'high' ? '#8a4b00' : ($severityCode === 'test' ? '#174a96' : '#705c00')) }};padding:7px 12px;border-radius:999px;font-size:13px;font-weight:700;">
                            مستوى الخطورة: {{ $severityLabel }}
                        </div>
                    </td>
                </tr>

                <tr>
                    <td style="padding:12px 28px;">
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:separate;border-spacing:0 8px;font-size:14px;">
                            <tr>
                                <td style="width:34%;color:#6b7586;">المكوّن</td>
                                <td style="font-weight:700;">{{ $component }}</td>
                            </tr>
                            <tr>
                                <td style="color:#6b7586;">وقت الاكتشاف</td>
                                <td style="font-weight:700;direction:ltr;text-align:right;">{{ $detectedAt }}</td>
                            </tr>
                            <tr>
                                <td style="color:#6b7586;">البيئة</td>
                                <td style="font-weight:700;direction:ltr;text-align:right;">{{ $environment }}</td>
                            </tr>
                            <tr>
                                <td style="color:#6b7586;">مرجع الإنذار</td>
                                <td style="font-family:monospace;font-weight:700;direction:ltr;text-align:right;">{{ $reference }}</td>
                            </tr>
                            <tr>
                                <td style="color:#6b7586;">مفتاح الرصد</td>
                                <td style="font-family:monospace;direction:ltr;text-align:right;word-break:break-all;">{{ $alertKey }}</td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td style="padding:10px 28px;">
                        <div style="font-size:14px;font-weight:700;margin-bottom:8px;">التفاصيل</div>
                        <div style="background:#f7f9fc;border:1px solid #e6ebf3;border-radius:12px;padding:16px;line-height:1.9;white-space:pre-line;font-size:14px;">{{ $detail }}</div>
                    </td>
                </tr>

                <tr>
                    <td style="padding:10px 28px;">
                        <div style="font-size:14px;font-weight:700;margin-bottom:8px;">الإجراء المقترح</div>
                        <div style="background:#fff9e8;border:1px solid #f1df9c;border-radius:12px;padding:16px;line-height:1.9;font-size:14px;">{{ $recommendedAction }}</div>
                    </td>
                </tr>

                <tr>
                    <td style="padding:18px 28px 26px;text-align:center;">
                        <a href="{{ $dashboardUrl }}" style="display:inline-block;background:#053391;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:10px;font-size:14px;font-weight:700;">فتح صحة النظام</a>
                    </td>
                </tr>

                <tr>
                    <td style="border-top:1px solid #edf0f5;padding:16px 28px 22px;color:#7a8392;font-size:12px;line-height:1.8;">
                        هذه رسالة تشغيلية آلية. لا تحتوي الرسالة على رمز OTP أو PIN أو كلمات مرور أو مفاتيح سرية. التفاصيل الحساسة تبقى داخل لوحة الإدارة المحمية بالصلاحيات.
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
