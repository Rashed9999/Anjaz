# قناة OTP عبر WhatsApp — إعداد متعدّد المزوّدين (AMIAL-WHATSAPP-OTP-001)

أُضيفت قناة **WhatsApp Business API** لإرسال رموز التحقّق/الإشعارات، تدعم **كل
المزوّدين** الشائعين مع **fallback تلقائي إلى SMS**. كل المتصلين القدامى
(`SmsModule::send`، ~14 موضعاً) يكتسبون واتساب **شفّافاً دون أي تغيير في الكود**.

## كيف يعمل
```
SmsModule::send()  →  OtpDispatcher::send()  →  واتساب أولاً (WhatsappModule) ─┐
                                              └→ ثم SMS (SmsModule) ←──────────┘
```
- الترتيب يحكمه تفضيل قابل للضبط (الافتراضي: **واتساب أولاً ثم SMS**).
- إن نجح واتساب → يُرجع `success`. إن فشل/غاب → يسقط تلقائياً إلى SMS.

## المزوّدون المدعومون
| المزوّد | `key_name` | الملاحظة |
|---|---|---|
| Meta WhatsApp Cloud API | `meta_cloud` | الرسمي، غالباً الأرخص |
| Twilio WhatsApp | `twilio` | رسالة نصّية |
| 360dialog | `360dialog` | BSP، قوالب |
| WATI | `wati` | قوالب |
| UltraMsg | `ultramsg` | الأبسط/الأرخص، نصّ حرّ |

## الإعداد (مثل SMS تماماً — جدول `addon_settings`)
أدرج صفّاً لكل مزوّد تريد تفعيله:
- `key_name` = اسم المزوّد، `settings_type` = `whatsapp_config`
- `live_values` = JSON بالقيم، و`status: 1` للتفعيل.

### أمثلة `live_values`
```jsonc
// meta_cloud
{"status":1,"access_token":"EAAxxx","phone_number_id":"100200300",
 "template_name":"otp_code","lang_code":"ar","otp_button":true}

// twilio (واتساب)
{"status":1,"sid":"ACxxx","token":"xxx","from":"14155238886",
 "otp_template":"رمز التحقق: #OTP#"}

// 360dialog
{"status":1,"api_key":"xxx","template_name":"otp_code","lang_code":"ar"}

// wati
{"status":1,"api_endpoint":"https://live-server-XXXX.wati.io",
 "access_token":"xxx","template_name":"otp_code","broadcast_name":"otp"}

// ultramsg (الأبسط)
{"status":1,"instance_id":"instance12345","token":"xxx",
 "otp_template":"رمزك في أميال: #OTP#"}
```

### ضبط ترتيب القناة (اختياري)
صفّ في `addon_settings`: `key_name='otp_channel'`, `settings_type='otp_config'`,
`live_values`:
```jsonc
{"value":"whatsapp_first"}   // أو: sms_first | whatsapp_only | sms_only
```
الافتراضي عند غياب الإعداد: `whatsapp_first`.

## ملاحظات تشغيلية
- **القوالب:** قوالب المصادقة في Meta/360dialog تتطلّب اعتماد قالب مسبق + زر «نسخ
  الرمز» (`otp_button` مُفعّل افتراضياً). UltraMsg/Twilio يرسلان نصّاً حرّاً (`#OTP#`).
- **الأمان:** لا يُسجَّل الرمز الفعلي إطلاقاً (يُخفى الرقم جزئياً في اللوقات).
- **التكلفة:** واتساب (Auth) غالباً أرخص من SMS الدولي لليمن — راجع `PRODUCTION_READINESS.md`.
- **الاختبار:** `tests/Feature/WhatsappOtpTest.php` (6 اختبارات، Http مُزيّف) يغطّي
  الإرسال، الـ fallback، التفضيل، وقالب Meta.
```bash
php artisan test tests/Feature/WhatsappOtpTest.php
```
