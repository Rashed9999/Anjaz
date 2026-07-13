# نشر أميال باي على Contabo + Coolify — دليل جاهز للّصق

## 0) ما تحتاج شراءه
- خادم **Contabo VPS 10** (8GB) أو **Hetzner CPX21** — Ubuntu 24.04.
  (Hetzner سنغافورة أقرب لليمن = أسرع.)
- **دومين رخيص** (Namecheap / Porkbun / Cloudflare) — .com ~$10/سنة، أو
  .site/.xyz ~$2 للتجربة. (يمكنك التجربة أولاً بلا دومين عبر عنوان IP
  التلقائي من Coolify sslip.io، لكن الدومين أفضل لـ SSL والعرض على البنك.)

## 1) تثبيت Coolify (مرة واحدة على الخادم)
```
curl -fsSL https://cdn.coollabs.io/coolify/install.sh | bash
```
ثم افتح `http://SERVER_IP:8000` وأنشئ حساب المدير.

## 2) الدومين
- وجّه سجل A من دومينك إلى IP الخادم:
  `api.YOURDOMAIN.com  →  SERVER_IP`
- في Coolify سيصدر شهادة SSL تلقائياً لهذا الدومين.

## 3) قاعدة البيانات (داخل Coolify)
Project → Resources → **+ New** → **Database → MySQL 8**.
انسخ من صفحتها: اسم القاعدة، المستخدم، كلمة المرور، والـ Host الداخلي
(يكون اسم الخدمة، مثل `mysql-xxxx`).

## 4) التطبيق (الخادم Laravel)
Resources → **+ New** → **Public/Private Repository** → اختر مستودع GitHub
`rashed9999/anjaz`، الفرع الذي ستنشره، و**Build Pack = Dockerfile**،
و **Base Directory = `01_backend`** (مهم — الـ Dockerfile داخل 01_backend).
Ports Exposes: **80**.  Healthcheck Path: **`/railway-health`**.

## 5) متغيّرات البيئة (الصقها في تبويب Environment Variables للتطبيق)
> بدّل قيم DB بما نسخته من خطوة 3، وضع دومينك في APP_URL، وولّد APP_KEY
> جديداً (`php artisan key:generate --show`) — لا تُبقِ المفاتيح أدناه في
> إنتاج حقيقي، فهي للتجربة فقط.

```
APP_NAME=AmialPay
APP_ENV=production
APP_KEY=base64:REPLACE_WITH_NEW_KEY
APP_DEBUG=false
APP_URL=https://api.YOURDOMAIN.com

LOG_CHANNEL=stack
LOG_LEVEL=error

DB_CONNECTION=mysql
DB_HOST=mysql-xxxx          # اسم خدمة MySQL الداخلي من Coolify
DB_PORT=3306
DB_DATABASE=REPLACE
DB_USERNAME=REPLACE
DB_PASSWORD=REPLACE

CACHE_DRIVER=database
CACHE_STORE=database
SESSION_DRIVER=database
QUEUE_CONNECTION=database

# مفاتيح تشفير بيانات العملاء (PII) — ولّد جديدة للإنتاج:
#   php artisan tinker --execute="echo base64_encode(random_bytes(32));"
AMIAL_PII_ENCRYPTION_KEY=REPLACE_32_BYTE_BASE64
AMIAL_PII_BLIND_INDEX_KEY=REPLACE_32_BYTE_BASE64

# رمز OTP التجريبي للوكيل (بلا بوابة SMS) — احذفه في الإنتاج الحقيقي:
AMIAL_DEMO_OTP=123456
```

## 6) أوامر ما بعد النشر (Coolify → Post-deployment Command)
```
php artisan migrate --force && \
php artisan passport:keys --force && \
php artisan passport:client --personal --no-interaction && \
php artisan amial:ensure-demo && \
php artisan config:clear
```
> `amial:ensure-demo` يُنشئ كل الحسابات التجريبية ويضبط العملة والميزات.
> المجدول والطابور يعملان تلقائياً داخل الحاوية (supervisord) — لا إعداد إضافي.

## 7) النسخ الاحتياطي (إلزامي لمشروع مالي)
في صفحة قاعدة MySQL داخل Coolify → **Backups** → فعّل جدولة يومية
(وربطها بتخزين S3 إن أمكن). لا تؤجّل هذا.

## 8) تحديث التطبيق (Flutter) ليشير للخادم الجديد
في `02_flutter_app/lib/util/app_constants.dart` بدّل الـ baseUrl إلى
`https://api.YOURDOMAIN.com`، ثم أعِد بناء APK عبر Codemagic.

---

## حسابات الدخول التجريبية (بعد ensure-demo)
| الدور | التبويب | البيانات |
|------|---------|----------|
| عميل | عميل | 777100001 / Pass@2026 — PIN 1237 |
| مستلِم | عميل | 777100002 / Pass@2026 |
| تاجر بقالة (Starter) | تاجر | AM-GROC-001 / 777200001 / Pass@2026 |
| مطعم (Business) | تاجر | AM-REST-002 / 777200002 / Pass@2026 |
| صيدلية (Merchant Pro) | تاجر | AM-PHAR-003 / 777200003 / Pass@2026 |
| محطة وقود (Enterprise) | تاجر | AM-FUEL-004 / 777200004 / Pass@2026 |
| جملة (Merchant Pro) | تاجر | AM-WHOL-005 / 777200005 / Pass@2026 |
| **أدمن** | أدمن | admin@amyalpay.com / Pass@2026 |
| **وكيل** | وكيل | AG-001 / 777900001 / Pass@2026 — OTP: 123456 |

كل الحسابات رمز PIN المعاملات فيها **1237**.

## قبل الإنتاج الحقيقي (لا للتجربة)
- APP_DEBUG=false (مضبوط أعلاه).
- احذف `AMIAL_DEMO_OTP` واربط بوابة SMS/واتساب حقيقية.
- ولّد APP_KEY ومفاتيح PII جديدة سرّية.
- غيّر كل كلمات مرور الحسابات التجريبية أو احذفها.
- فعّل المصادقة الثنائية (2FA) لحساب الأدمن.
</content>
