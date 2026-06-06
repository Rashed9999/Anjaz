# 🛡️ الحارس المخفي (Security Sentinel) — أميال باي

أداة دفاعية صغيرة "تختبئ بين الكود": middleware يجلس أمام **كل** طلب
web/api، يفحصه بصمت، يحسب نقاط خطورة، يسجّل المشبوه، ويحظر المهاجمين عند
الحاجة. طبقة كشف تطفّل على مستوى التطبيق (Application-level IDS / RASP مصغّر).

## لماذا؟

تطبيق مالي = هدف دائم للفحص الآلي ومحاولات الحقن. الحارس يكشف:
- حقن SQL، XSS، Path Traversal، Command/Code Injection، SSTI، NoSQL.
- طلبات "الطُّعم" (`.env`, `wp-login.php`, `phpmyadmin`...) — لا يطلبها مستخدم شرعي.
- أدوات الفحص الآلي (`sqlmap`, `nikto`, `nmap`...) عبر بصمة User-Agent.

## المكوّنات

| الملف | الدور |
|---|---|
| `app/Http/Middleware/SecuritySentinel.php` | البوابة المخفية (fail-open) |
| `app/Services/Security/SecuritySentinelService.php` | الفحص + التسجيل + القرار |
| `app/Services/Security/ThreatSignatures.php` | مكتبة الأنماط/البصمات |
| `app/Models/SentinelEvent.php` + migration `sentinel_events` | تخزين الأحداث |
| `config/security_sentinel.php` | الإعدادات والعتبات |
| `app/Console/Commands/SentinelReportCommand.php` | تقرير `amial:sentinel-report` |
| `app/Services/Security/SecurityAlertService.php` | تنبيهات Sentry + Webhook |
| `app/Models/SentinelBlockedIp.php` + migration `sentinel_blocked_ips` | قائمة الحظر |
| `app/Http/Controllers/Admin/SentinelDashboardController.php` + `resources/views/admin-views/amial/sentinel/index.blade.php` | **لوحة تحكم الأدمن** |

## كيف يعمل

```
كل طلب → SecuritySentinel
   ├─ مُدرَج في القائمة البيضاء؟ → مرور
   ├─ analyze(): فحص UA + المسار + المدخلات → score (0-100) + توقيعات
   ├─ score > 0 → تسجيل في sentinel_events (+ account_security_events لو مُصادَق)
   │              + سجل (structured/Sentry)
   └─ وضع block وscore ≥ 80 → رد 403 عام، وإلا → مرور
```

**مبدآن ذهبيان:**
- **fail-open:** أي خطأ داخلي في الحارس يمرّر الطلب — لا يكسر التطبيق أبداً.
- **monitor افتراضياً:** لا يحظر شيئاً حتى تضبط العتبات على بيانات حقيقية.

## الإعداد

أُدمج تلقائياً في `bootstrap/app.php` (prepend لمجموعتي web و api) +
alias `amial.sentinel`. في `.env`:

```dotenv
SENTINEL_ENABLED=true
SENTINEL_MODE=monitor          # غيّرها إلى block عند الجاهزية
SENTINEL_BLOCK_THRESHOLD=80
SENTINEL_WARNING_THRESHOLD=40
SENTINEL_STORE_DB=true
SENTINEL_LOG_CHANNEL=structured
SENTINEL_WHITELIST_IPS=        # IPs بوابات الدفع/المراقبة، مفصولة بفواصل

# الحظر التلقائي لـ IP المتكرر
SENTINEL_AUTO_BLOCK=true
SENTINEL_AUTO_BLOCK_THRESHOLD=5     # أحداث حرجة
SENTINEL_AUTO_BLOCK_WINDOW=60       # دقيقة
SENTINEL_AUTO_BLOCK_DURATION=1440   # مدّة الحظر (دقيقة)

# التنبيهات
SENTINEL_ALERT_SENTRY=true
SENTINEL_ALERT_WEBHOOK=             # Slack/Telegram/Discord webhook (اختياري)
SENTRY_LARAVEL_DSN=                 # DSN من حساب Sentry (إن استُخدم)
```

## التنبيهات والحظر التلقائي

- **تنبيه فوري** عند كل حدث حرج عبر `SecurityAlertService`: يرسل إلى **Sentry**
  (إن كان `sentry/sentry-laravel` مثبّتاً — محميّ بـ `function_exists`) و/أو إلى
  **Webhook عام** (Slack/Telegram/Discord) بلا أي تبعية. كلاهما fail-safe.
- **حظر تلقائي**: إذا سجّل عنوان IP عدد `THRESHOLD` أحداث حرجة خلال `WINDOW`
  دقيقة، يُحظر تلقائياً لمدّة `DURATION`. العناوين المحظورة تُرفض فوراً (403)
  حتى في وضع `monitor`. الحظر مُخزَّن في `sentinel_blocked_ips` + cache سريع.

## لوحة تحكم الأدمن

شاشة كاملة ضمن لوحة الأدمن: **Amial Pay → Security Sentinel**
(`/admin/amial/sentinel`). تعرض:
- بطاقات إحصاء (إجمالي/حرجة/تحذير/المحظورون الآن) مع نطاق زمني (1h/24h/3d/7d).
- أعلى عناوين IP المهاجِمة + زر **حظر** فوري.
- العناوين المحظورة + زر **رفع الحظر**.
- جدول أحدث الأحداث مع فلترة بالـ IP/الخطورة والتوقيعات المطابِقة.

> ⚠️ ابدأ بـ `monitor` أسبوعاً، راجع `amial:sentinel-report`، اضبط العتبات
> والقائمة البيضاء (لتفادي false positives من webhooks الدفع)، ثم فعّل `block`.

## الاستخدام

```bash
php artisan migrate                       # ينشئ جدول sentinel_events
php artisan amial:sentinel-report                 # آخر 24 ساعة
php artisan amial:sentinel-report --hours=72 --top=20
```

تطبيق الحارس على مسار محدّد فقط (بدل العالمي):
```php
Route::post('/login', ...)->middleware('amial.sentinel');
```

## الاختبارات

`tests/Feature/Security/SecuritySentinelTest.php` يثبت أن التوقيعات تلتقط
SQLi/XSS/Traversal/Bait/Scanner وأن الطلبات الشرعية تمرّ بنقاط صفرية.

## التكامل مع أدوات أكبر (موصى به)

الحارس طبقة **داخل** التطبيق. كمّلها بطبقات خارجية:
- **WAF**: Cloudflare / ModSecurity + OWASP CRS (طبقة شبكة).
- **fail2ban**: يقرأ سجل الحارس ويحظر IP على مستوى الخادم.
- **Sentry**: استقبال تنبيهات `sentinel.alert` الحرجة فوراً.

راجع `TESTING.md` و`FIXES.md` لبقية أدوات الجودة والأمن (Larastan, Pint,
Gitleaks, CI).
