# دليل بيئة التشغيل والتثبيت — أميال باي

**للمطوّر أو المساعد التقني (بشري أو ذكاء اصطناعي)**
**المنصة:** Cloudways (DigitalOcean 8GB) | **التاريخ:** 2026-05-23

> 📌 **لمن هذا المستند؟** صاحب المشروع ليس مطوّراً. هذا الدليل مكتوب ليفهمه
> مطوّر خارجي أو ذكاء اصطناعي مساعد، ويُنفّذ التثبيت خطوة بخطوة. كل أمر مشروح.

---

## القسم 1: نظرة عامة على ما نُشغّله

أميال باي = نظامان:

```
1. Backend (الخادم)     → Laravel 12 + PHP 8.2 + MySQL + Redis
   يعمل على Cloudways

2. تطبيق Flutter         → يُبنى كـ APK/IPA منفصلاً
   يتصل بالـ Backend عبر API
```

**هذا الدليل يركّز على Backend** (الخادم على Cloudways). تطبيق Flutter يُبنى منفصلاً.

---

## القسم 2: متطلبات الخادم (مهم للمطوّر)

| المتطلب | القيمة | ملاحظة |
|---|---|---|
| PHP | **8.2 أو أحدث** | إلزامي (Laravel 12) |
| إضافات PHP | curl, gd, json, openssl, zip, bcmath, pdo_mysql | bcmath حرج للحسابات المالية |
| قاعدة بيانات | MySQL 8.0+ أو MariaDB 10.6+ | |
| Redis | **مطلوب** | cache + queue + sessions |
| Composer | 2.x | لتثبيت مكتبات PHP |
| Node.js | 18+ | لبناء الأصول (npm) |
| الذاكرة | 8GB (الخطة المختارة) | كافية للبداية |

**Cloudways يوفّر معظم هذا جاهزاً** (PHP, MySQL, Redis مثبّتة). المطوّر يحتاج فقط
ضبطها وتثبيت المشروع.

---

## القسم 3: خطوات التثبيت على Cloudways (للمطوّر)

### الخطوة 1: إنشاء الخادم

```
1. سجّل في Cloudways
2. Launch Server:
   - Cloud Provider: DigitalOcean
   - Server Size: 8GB (4 vCPU, 50GB SSD)
   - Application: PHP (Laravel)
   - PHP Version: 8.2
   - Location: اختر الأقرب لليمن (مثلاً London أو Frankfurt)
```

### الخطوة 2: تفعيل المتطلبات في Cloudways

```
في لوحة Cloudways:
1. Server Settings → Packages:
   - تأكد PHP 8.2
   - فعّل Redis (Add-on مجاني)
2. تأكد من إضافات PHP: bcmath, gd, curl, openssl, zip
   (معظمها مفعّل افتراضياً — اطلب من دعم Cloudways تفعيل bcmath لو مفقود)
```

### الخطوة 3: رفع المشروع

```bash
# الطريقة الموصى بها: Git
# 1. ارفع المشروع على GitHub (private repo)
# 2. في Cloudways: Application → Deployment via Git
#    اربط الـ repo

# أو رفع مباشر عبر SFTP (Cloudways يعطيك بيانات الدخول)
```

### الخطوة 4: تثبيت المكتبات

```bash
# اتصل بالخادم عبر SSH (Cloudways يوفّر بيانات SSH)
cd applications/[your-app]/public_html

# ثبّت مكتبات PHP (~100MB تُحمّل)
composer install --no-dev --optimize-autoloader

# ثبّت وابنِ أصول Node
npm install
npm run build
```

### الخطوة 5: إعداد ملف البيئة (.env)

```bash
cp .env.example .env
php artisan key:generate
```

ثم عدّل `.env` (Cloudways يعطيك بيانات قاعدة البيانات):

```env
APP_NAME="Amyal Pay"
APP_ENV=production
APP_DEBUG=false              # ← مهم جداً: false في الإنتاج
APP_URL=https://your-domain.com

# قاعدة البيانات (من لوحة Cloudways)
DB_CONNECTION=mysql
DB_HOST=localhost
DB_DATABASE=[من Cloudways]
DB_USERNAME=[من Cloudways]
DB_PASSWORD=[من Cloudways]

# Redis (مهم للأداء)
CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379

# البريد (لإشعارات/OTP — اضبط لاحقاً)
MAIL_MAILER=smtp
```

### الخطوة 6: قاعدة البيانات والإعداد

```bash
# جرّب الـ migrations أولاً (يعرض دون تنفيذ)
php artisan migrate --pretend

# نفّذ
php artisan migrate

# الـ seeders بالترتيب (راجع DEPLOYMENT_GUIDE.md للتفاصيل)
php artisan db:seed --class=RbacDefaultSeeder
php artisan db:seed --class=AmlDefaultRulesSeeder
php artisan db:seed --class=KycTierLimitsSeeder
php artisan db:seed --class=MerchantProfileBackfillSeeder

# مفاتيح التشفير والأمان
php artisan passport:keys
php artisan amial:generate-pii-keys
```

### الخطوة 7: المهام المجدولة (Cron) — إلزامي

```
في Cloudways: Application → Cron Job Management
أضف:
* * * * * php /path/to/applications/[app]/public_html/artisan schedule:run

⚠️ بدون هذا: التحويلات المؤجّلة لن تُسلّم، والفواتير لن تُسوّى!
```

### الخطوة 8: عامل الطوابير (Queue Worker) — إلزامي

```
في Cloudways: Application → Supervisor
أضف worker:
php artisan queue:work --tries=3 --timeout=90

⚠️ بدونه: الإشعارات وPDF والتصدير لن تعمل.
```

### الخطوة 9: SSL وHTTPS

```
Cloudways → Application → SSL Certificate
→ Let's Encrypt (مجاني) → فعّله
⚠️ إلزامي: تطبيق مالي يجب أن يعمل على HTTPS فقط.
```

### الخطوة 10: تحسين الأداء

```bash
php artisan config:cache
php artisan route:cache
php artisan event:cache
php artisan optimize
```

---

## القسم 4: قائمة تحقق سريعة للمطوّر

```
[ ] خادم 8GB DigitalOcean على Cloudways
[ ] PHP 8.2 + bcmath + كل الإضافات
[ ] Redis مفعّل
[ ] المشروع مرفوع (Git/SFTP)
[ ] composer install --no-dev نجح
[ ] npm install && npm run build نجح
[ ] .env مضبوط (APP_DEBUG=false، Redis، DB)
[ ] php artisan migrate نجح
[ ] كل الـ seeders شُغّلت
[ ] مفاتيح PII + Passport مولّدة
[ ] Cron (schedule:run) مفعّل
[ ] Queue worker (Supervisor) يعمل
[ ] SSL/HTTPS مفعّل
[ ] الاختبارات تنجح: php artisan test
[ ] super admin معيّن
```

---

## القسم 5: سعة خطة 8GB (سؤال صاحب المشروع)

### التقدير الواقعي

| المقياس | التقدير | ملاحظة |
|---|---|---|
| مستخدمون مسجّلون | 30,000 – 80,000 | التسجيل خفيف |
| نشطون شهرياً | 15,000 – 40,000 | |
| نشطون يومياً | 3,000 – 8,000 | |
| عمليات متزامنة (ذروة) | 50 – 150 / ثانية | الحد الحقيقي |

### لماذا ليس رقماً قاطعاً؟

أميال باي **أثقل** من تطبيق عادي. كل عملية مالية فيها:
- قفل صف (lockForUpdate)
- محاسبة مزدوجة
- فحص AML + zone + KYC
- تشفير PII

هذه تحمي الأموال لكنها تستهلك موارد. **الرقم الحقيقي يُعرف فقط بـ load test.**

### القاعدة العملية

```
8GB كافية لـ:  Pilot + النمو المبكر (حتى ~30-50 ألف مسجّل)
الترقية عند:   بطء ملحوظ أو تجاوز ~150 عملية/ثانية
الميزة:        Cloudways يرقّي بضغطة زر (ادفع للمستخدَم فقط)
```

**التوصية:** ابدأ بـ 8GB، راقب الأداء (Cloudways فيه monitoring)، ارفع عند الحاجة.

---

## القسم 6: ماذا يفعل صاحب المشروع بنفسه؟

حتى لو لم تكن مطوّراً، يمكنك:

| المهمة | الصعوبة | كيف |
|---|---|---|
| إنشاء خادم Cloudways | سهل | واجهة رسومية بالضغط |
| تفعيل Redis/SSL | سهل | زر في اللوحة |
| إعداد Cron/Queue | متوسط | انسخ الأوامر من هذا الدليل |
| تثبيت المشروع (composer/migrate) | **يحتاج مساعدة** | مطوّر أو ذكاء اصطناعي |
| ضبط .env | متوسط | انسخ القالب أعلاه |

**الخلاصة:** الأجزاء الرسومية تفعلها بنفسك. أجزاء سطر الأوامر (composer, migrate)
تحتاج مطوّراً أو مساعدة ذكاء اصطناعي ينفّذ الأوامر خطوة بخطوة.

---

## القسم 7: تقدير التكلفة الشهرية

| البند | التكلفة الشهرية (تقريبي) |
|---|---|
| Cloudways 8GB DigitalOcean | ~$88-99 |
| نطاق (domain) | ~$1-2 |
| (لاحقاً) خدمة SMS/OTP | حسب الاستخدام |
| (لاحقاً) خدمة sanction | اشتراك |
| **الإجمالي للبداية** | **~$90-100/شهر** |

ملاحظة: السعر pay-as-you-go، يمكن تصغير الخادم في فترات الهدوء لتوفير المال.

---

## القسم 8: تحذيرات حرجة (لا تتجاوزها)

```
🔴 APP_DEBUG=false دائماً في الإنتاج (لو true = تسريب معلومات حساسة)
🔴 SSL/HTTPS إلزامي (تطبيق مالي)
🔴 Cron + Queue worker إلزاميان (وإلا تتعطل ميزات)
🔴 backup تلقائي (Cloudways يوفّره — فعّله)
🔴 لا تضع مفاتيح حقيقية في Git (.env خارج الـ repo)
🔴 شغّل الاختبارات قبل أي إطلاق
```

---

## القسم 9: المراجع

- `DEPLOYMENT_GUIDE.md` — تفاصيل الدمج والـ migrations
- `LAUNCH_LEGAL_AND_PILOT_PLAN.md` — الجانب القانوني (مهم قبل الإطلاق)
- وثائق Cloudways: https://support.cloudways.com
- وثائق Laravel deployment: https://laravel.com/docs/deployment

---

## ملخص للمطوّر الخارجي (إن استعنت بأحد)

> "المشروع Laravel 12 + Flutter. الـ backend يُنشر على Cloudways 8GB DigitalOcean.
> كل المكتبات في composer.json (لا إضافات). يحتاج: PHP 8.2 + bcmath، MySQL،
> Redis، Cron (schedule:run)، Queue worker (Supervisor)، SSL.
> اتبع HOSTING_SETUP_GUIDE.md + DEPLOYMENT_GUIDE.md خطوة بخطوة.
> الاختبارات: php artisan test (يجب أن تنجح قبل الإطلاق)."
```
```
