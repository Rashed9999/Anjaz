# دليل الدمج والنشر — أميال باي

**الإصدار:** v2.7 | **التاريخ:** 2026-05-23
**الغرض:** نقل مشروع أميال باي من الكود الذي بنيناه إلى نظام يعمل على خادمك.

---

## 0. الصورة الكبيرة (افهمها أولاً)

```
ما اشتريته (Cash6 الأصلي، 180M)
  = الكود الفعلي (~3M) + vendor (~100M) + node_modules (~40M) + موارد (~35M)

ما بنيناه (أميال باي)
  = نفس الكود + تعديلات وإضافات AMIAL-*

النشر = الأصل الكامل  +  ملفاتنا فوقه  +  composer install + npm install
```

**القاعدة الذهبية:** لا تبدأ من الصفر. ابدأ من Cash6 الأصلي الكامل، وضع ملفاتنا فوقه.

---

## 1. التحقق من الإصدار (مهم)

المشروع **Laravel 12.44** (وليس 11 كما كان يُفترض مبكراً). كل كود أميال باي
متوافق مع 12 (لا `Kernel.php`، الـ middleware في `bootstrap/app.php`).

```bash
php --version    # يجب أن يكون PHP 8.2+
```

المكتبات المطلوبة (كلها في composer.json الأصلي — لا إضافات):

| المكتبة | الاستخدام في أميال باي |
|---|---|
| laravel/passport ^12 | المصادقة (tokens) |
| barryvdh/laravel-dompdf ^3.1 | إيصالات PDF |
| simplesoftwareio/simple-qrcode | QR codes |
| stevebauman/location v7.6 | كشف المنطقة (zone) |
| twilio/sdk ^6.31 | SMS / OTP |
| nwidart/laravel-modules 9.* | نظام Modules |

**لا حاجة لـ `composer require` أي مكتبة جديدة.** كل ما يستخدمه كودنا موجود.

---

## 2. دمج الـ Backend

### الخطوة 2.1 — ابدأ من الأصل الكامل

```bash
# انسخ Cash6 الأصلي الكامل (180M) كنقطة بداية
cp -r cash6_original_full/ amyal_pay_production/
cd amyal_pay_production/
```

### الخطوة 2.2 — ضع ملفات أميال باي فوقه

من `amial_pay_working_copy/` انسخ هذه المجلدات/الملفات **فوق** الأصل:

```bash
# الكود الجديد والمعدّل
cp -r amial_pay_working_copy/app/*           amyal_pay_production/app/
cp -r amial_pay_working_copy/database/*      amyal_pay_production/database/
cp -r amial_pay_working_copy/routes/*        amyal_pay_production/routes/
cp -r amial_pay_working_copy/config/*        amyal_pay_production/config/
cp     amial_pay_working_copy/bootstrap/app.php amyal_pay_production/bootstrap/app.php
```

⚠️ **لا تستبدل** هذه الملفات (احتفظ بالأصلية):
- `.env` / `.env.example` (إعداداتك)
- `composer.lock` (إلا إذا أردت إصدارات أحدث)
- `vendor/` (يُستعاد لاحقاً)

### الخطوة 2.3 — استعد المكتبات

```bash
composer install --optimize-autoloader
# لو production:
composer install --no-dev --optimize-autoloader
```

---

## 3. الملفات التي عدّلناها (مرجع)

### ملفات Cash6 الأصلية المُعدَّلة (REFACTOR)

| الملف | التعديل (AMIAL) |
|---|---|
| `app/Traits/TransactionTrait.php` | lockForUpdate, idempotency, ledger, حدود الوكيل |
| `app/Services/FinancialGuardService.php` | فحص الرصيد المركزي + getWallet |
| `app/Models/EMoney.php` | DECIMAL بدل float |
| `app/Models/User.php` | حقول PII, 2FA, KYC, zone, sanction |
| `app/CentralLogics/SmsModule.php` | إصلاح echo errors |
| `bootstrap/app.php` | تسجيل middleware aliases |

### ملفات جديدة (ADD) — أهمها

```
app/Services/         → 20+ خدمة (Aml, Ledger, Zone, Recipient, PendingTransfer...)
app/Aml/              → محرك AML (Rules, Decision, Context)
app/Models/Ledger/    → المحاسبة المزدوجة
app/Http/Middleware/  → EnforceIdempotency, EnforceZone, PerUserRateLimit, terms
app/Traits/           → PostsToLedger, EnforcesFinancialPolicy, HasRoles, HasEncryptedPII
database/migrations/  → 30+ migration بـ AMIAL-*
```

---

## 4. قاعدة البيانات

### الخطوة 4.1 — جرّب أولاً (pretend)

```bash
php artisan migrate --pretend    # يعرض SQL دون تنفيذ — راجعه
```

### الخطوة 4.2 — نفّذ الـ migrations

```bash
php artisan migrate
```

⚠️ **ترتيب مهم:** migrations أميال باي تفترض وجود جداول Cash6 الأصلية
(users, transactions, e_money). تأكد أنها هاجرت أولاً (تواريخها أقدم، فتعمل تلقائياً).

### الخطوة 4.3 — شغّل الـ seeders بالترتيب

```bash
php artisan db:seed --class=RbacDefaultSeeder          # الأدوار والصلاحيات
php artisan db:seed --class=AmlDefaultRulesSeeder       # قواعد AML (الجديدة في shadow)
php artisan db:seed --class=KycTierLimitsSeeder         # حدود KYC
php artisan db:seed --class=CharityCategoriesSeeder     # فئات التبرع (إن استخدمت)
```

---

## 5. التهيئة الأمنية (إلزامي قبل أي مال حقيقي)

```bash
# 1. مفاتيح التشفير
php artisan key:generate
php artisan passport:keys
php artisan amial:generate-pii-keys     # مفاتيح تشفير PII

# 2. ترحيل بيانات PII الموجودة للتشفير
php artisan amial:migrate-pii

# 3. تعيين أول super admin
php artisan tinker
>>> $u = App\Models\User::find(1);
>>> $u->assignRole('super_admin');   // أو حسب نظام RBAC

# 4. تهيئة حسابات النظام المحاسبية
>>> $l = app(App\Services\LedgerService::class);
>>> $l->getOrCreateSystemAccount('CASH_RESERVE', 'asset', 'احتياطي النقد', 'debit');
>>> $l->getOrCreateSystemAccount('PLATFORM_FEE', 'revenue', 'رسوم المنصة', 'credit');
>>> $l->getOrCreateSystemAccount('ESCROW_HOLD', 'liability', 'حجز الدفع الآمن', 'credit');
```

---

## 6. مراجعة المناطق (Zone) — حرج

بعد إصلاح v2.0، المستخدمون الجدد يبدأون بـ `zone_code = UNKNOWN` (آمن).
لكن المستخدمين الحاليين قد يكونون كلهم `SOUTH`. راجع:

```bash
php artisan tinker
>>> DB::table('users')->select('zone_code', DB::raw('count(*) as n'))
       ->groupBy('zone_code')->get();
```

ادمج `ZoneAssignmentService::assignOnRegistration` في `RegisterController`
بعد `$user->save()`، و`assignFromKyc` عند موافقة KYC.

---

## 7. الوكلاء (شبكة الصرافات)

الوكلاء الحاليون (type=1) بلا `AgentProfile`. أنشئها:

```bash
php artisan tinker
>>> foreach (App\Models\User::where('type', 1)->get() as $a) {
...   App\Models\AgentProfile::firstOrCreate(['user_id' => $a->id], [
...     'agent_level' => 'independent', 'status' => 'active',
...     'daily_cash_in_limit' => '500000', 'single_transaction_limit' => '100000',
...     'min_float_balance' => '10000', 'commission_rate' => '0.50',
...   ]);
... }
```

---

## 8. الـ Scheduler (إلزامي — وإلا تتعطل ميزات)

أضف لـ crontab على الخادم:

```bash
* * * * * cd /path/to/amyal_pay_production && php artisan schedule:run >> /dev/null 2>&1
```

**بدونه تتعطل:**
- تسليم التحويلات بعد نافذة الإلغاء (تبقى معلّقة للأبد!)
- تسوية فواتير المزودين

تحقق:
```bash
php artisan schedule:list
# يجب أن يظهر: ReleasePendingTransfersJob, ReconcilePendingBillOrdersJob
```

---

## 9. الـ Queue (إلزامي للأداء)

الإشعارات، PDF، التصدير تعمل عبر queue:

```bash
# .env
QUEUE_CONNECTION=redis    # أو database
CACHE_DRIVER=redis
SESSION_DRIVER=redis

# شغّل worker (مع supervisor للإنتاج)
php artisan queue:work --tries=3
```

---

## 10. دمج تطبيق Flutter

**خبر جيد: الدمج تم بالفعل أثناء البناء.**

```
amyal_pay_user_app (320 ملف) = User_app الأصلي (262) + إضافات أميال باي (58)
  • 0 ملف أصلي ناقص
  • 196 ملف: تغيير الـ branding فقط (six_cash → amyal_pay)
  • 4 ملفات: تحسينات (api_client + auth_repo + get_di + app_constants)
  • 58 ملف جديد: agent, merchant, donations, family_fund, bill_pay,
                  receipts, safe_payment, 2FA, QR, float dashboard
```

### للبناء

```bash
cd amyal_pay_user_app
flutter pub get

# عدّل base URL في lib/util/app_constants.dart → خادمك
# أذونات الكاميرا (QR): AndroidManifest.xml + iOS Info.plist (NSCameraUsageDescription)

flutter build apk --release        # Android
flutter build ios --release        # iOS
```

⚠️ في النسخة النهائية: تأكد أن `cleartext traffic` معطّل، والتوقيع release حقيقي،
والـ token يُحفظ في secure storage (مطبّق في api_client).

---

## 11. الاختبارات (الدليل الوحيد على الجاهزية)

```bash
php artisan test                          # كل الـ ~274 اختبار
php artisan test --filter="Ledger"        # مجموعة محددة
php artisan test --filter="PendingTransfer|RecipientVerification"
```

⚠️ **حسب قاعدة المشروع: لا تثق بأي ميزة حتى ترى اختباراتها تنجح فعلاً.**

---

## 12. قائمة التحقق قبل الإطلاق

### تقني
- [ ] `composer install` + `npm install` نجحا
- [ ] كل الـ migrations هاجرت + seeders شُغّلت
- [ ] الـ ~274 اختبار تنجح
- [ ] الـ cron (`schedule:run`) يعمل
- [ ] queue worker يعمل (supervisor)
- [ ] Redis مفعّل (cache + queue + session)
- [ ] مفاتيح PII مُولّدة + migrate-pii نُفّذ
- [ ] حسابات النظام المحاسبية مُهيّأة
- [ ] super admin معيّن
- [ ] مراجعة zone_code للمستخدمين الحاليين
- [ ] AgentProfile للوكلاء الحاليين
- [ ] `.env`: APP_DEBUG=false، APP_ENV=production

### غير تقني (لا تتجاهله — قد يكون أهم من الكود)
- [ ] 🔴 **محامٍ مالي يمني** — هل تحتاج رخصة من البنك المركزي؟
- [ ] 🔴 **Pen-test طرف ثالث** ($3-10k) — سيكشف ما لم نره
- [ ] 🔴 **خدمة sanction متخصصة** (ComplyAdvantage/Refinitiv)
- [ ] 🟡 staging + load test (K6) قبل production
- [ ] 🟡 عقد تأمين سيبراني
- [ ] 🟡 AWS Secrets Manager للمفاتيح (لا تتركها في .env)
- [ ] 🟡 backup تلقائي + monitoring

---

## 13. الفرق 180M ← 3M (للتذكير)

```
180M = 3M كود  +  ~140M مكتبات (vendor + node_modules)  +  ~35M أصول
        ↑ ما يهم      ↑ composer install + npm install        ↑ npm run build
```

**لم تفقد شيئاً.** الكود موجود، المكتبات تُستعاد بأمر.

---

## 14. خلاصة الحالة

| الجانب | الحالة |
|---|---|
| Backend (كل ميزات AMIAL) | ✅ مبني + ~274 اختبار |
| Flutter (أصلي + إضافات) | ✅ مدموج |
| composer.json | ✅ كل المكتبات موجودة |
| دليل الدمج | ✅ هذا المستند |
| تشغيل الاختبارات | ⏳ مسؤوليتك |
| الترخيص القانوني | ⏳ الأهم — استشر محامياً |
| Pen-test | ⏳ قبل أي مال حقيقي |

**الكود انتهى. الإطلاق المسؤول يبدأ من القائمة في القسم 12.**

---

## 15. بعد كلّ نشرة — فحصُ الدخان والعودة إلى الوراء

> **أُضيف في AMIAL-PROD-READINESS-006.** وقِيس قبل إضافته:
> `grep -inE 'rollback|smoke' docs/DEPLOYMENT_GUIDE.md` ⇒ **صفرُ نتائج.**
> أي أنّ هذا الدليلَ كان يقول كيف تُنشَر ولا يقول **كيف تعرف أنّها نجحت**،
> ولا **ماذا تفعل إن لم تنجح**.

### ١٥.١ — فحصُ الدخان (دقيقةٌ واحدة)

من طرفيّة Coolify (‏Terminal) بعد كلّ نشرة:

```bash
bash scripts/smoke.sh https://amialpay.com
```

سبعةُ فحوصٍ على **الخادم المنشور** لا على الشيفرة:

| # | ما يُفحص | ما يمسكه ولا يمسكه غيرُه |
|---|---|---|
| ١ | `/railway-health` | القاعدةُ والتخزينُ والطابور — **بالعمل** لا بالإعداد |
| ٢ | `/api/v1/amial/ping` | نقطةُ الرصد الخارجيّة |
| ٣ | `/admin/auth/login` + رمزُ CSRF | حلقةُ ٤١٩ التي منعت الدخول مرّةً من قبل |
| ٤ | الصفحةُ الرئيسة | الجذرُ بلا مسار (وقع فعلاً في تحوُّل النطاق) |
| ٥ | ترويساتُ الأمان | CSP · X-Frame · nosniff · HSTS (على HTTPS وحدَه) |
| ٦ | `X-Request-Id` | بدونه لا تُربَط شكوى تاجرٍ بسطرٍ في السجلّ |
| ٧ | صفحةُ الخطأ | **الأهمُّ أمنيّاً**: `APP_DEBUG=true` يُفشي المسارات والاستعلامات |

ويخرج بصفرٍ إن سلمت كلُّها، وبواحدٍ إن سقط منها شيء.

**ولا يُغني `scripts/sweep-admin.php` عنه:** ذاك يُقلع التطبيقَ **داخل
العمليّة** ويفتح المسارات بالنواة — يفحص **الشيفرة**. وهذا يفحص **الخادمَ
المنشور**: الشهادةَ، والوسيط، وnginx، وphp-fpm، والقاعدةَ، والبيئةَ — أي
كلَّ ما لا تراه شيفرةٌ سليمة.

### ١٥.٢ — العودةُ إلى الوراء

**في Coolify هي نشرةٌ لا زرّ.** `Source: Manual` ولا نشرَ تلقائيّاً، فيُختار
الالتزامُ السابقُ ويُنشر. **و`rolling update is not supported`** — أي أنّ
العودةَ نفسَها انقطاعٌ ثانٍ، فتُحسب.

**ولا يُردّ مخطّطُ القاعدة بيدٍ على نشرةٍ ساقطة.** هجرةٌ رُدَّت هكذا تُنتج
قاعدةً لا تطابق لا الشيفرةَ القديمة ولا الجديدة — وهي حالةٌ أسوأُ من
الاثنتين.

### ١٥.٣ — وإن كان العطلُ في البيانات لا في الشيفرة

`docs/التعافي.md` — صفحةٌ واحدة: RPO/RTO، ومواضعُ المفاتيح التي بدونها
لا تُقرأ نسخةٌ احتياطيّة، وأوامرُ أربعِ حالات.
