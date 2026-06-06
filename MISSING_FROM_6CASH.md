# الملفات المطلوبة من مشروع 6cash الأصلي

> الحزمة الحالية طبقة تطوير (delta) فوق 6cash. الملفات أدناه **يشير إليها كود
> أميال صراحةً** (composer autoload / bootstrap / routes / tests) لكنها غير
> مضمّنة. بدونها لا يعمل `composer install` ولا يُقلع التطبيق.
>
> ✅ الأسهل والأضمن: ابدأ من **نسخة 6cash نظيفة** وطبّق حزمة أميال فوقها، بدل
> نسخ ملف ملف. القائمة أدناه هي نقاط الإثبات الملموسة.

## 🔴 أولوية قصوى — بدونها لا شيء يعمل

### 1) ملفات autoload.files (10) — مذكورة حرفياً في `composer.json`
```
app/CentralLogics/Helpers.php        ← الأهم (يستدعيه كل النظام: ~70+ دالة)
app/CentralLogics/FilePath.php
app/CentralLogics/FileUploader.php
app/CentralLogics/Translation.php
app/Lib/Helper.php
app/Lib/Transaction.php
app/Lib/Responses.php
app/Lib/Constant.php                 ← يعرّف ثوابت مثل ADMIN_CHARGE, APPLICATION_IMAGE_FORMAT
app/Lib/PaymentResponse.php
app/Lib/PaymentSuccess.php
```

### 2) ملفات الراوت الأساسية — يشير لها `bootstrap/app.php`
```
routes/api.php       ← يحوي راوتات العميل المالية الأساسية (send/add/cash-out)
routes/web.php
```

### 3) مجلد config/ — موجود منه `amial.php` فقط، والباقي مفقود
```
config/app.php  database.php  filesystems.php  auth.php  cache.php
config/queue.php  logging.php  mail.php  services.php  session.php
config/cors.php  sanctum.php  view.php  broadcasting.php  hashing.php
+ أي config مخصّص لـ 6cash (بوابات الدفع، business settings...)
```
> ملاحظة: أضِف disk باسم `private` في `filesystems.php` (يستخدمه upload للـ KYC).

## 🟠 أساسية للتشغيل الكامل

### 4) Middleware أساسي (14) — مذكور في `bootstrap/app.php` كـ alias
```
app/Http/Middleware/Authenticate.php
app/Http/Middleware/AdminMiddleware.php
app/Http/Middleware/AgentMiddleware.php
app/Http/Middleware/CustomerMiddleware.php
app/Http/Middleware/MerchantMiddleware.php
app/Http/Middleware/EncryptCookies.php
app/Http/Middleware/VerifyCsrfToken.php
app/Http/Middleware/TrackLastActiveAt.php
app/Http/Middleware/InactiveAuthCheck.php
app/Http/Middleware/InstallationMiddleware.php
app/Http/Middleware/ActivationCheckMiddleware.php
app/Http/Middleware/DeviceVerifyMiddleware.php
app/Http/Middleware/CheckDeviceId.php
app/Http/Middleware/RedirectIfAuthenticated.php
```

### 5) نماذج أساسية مفقودة
```
app/Models/Merchant.php          ← يستخدمه UnifiedAuth + tests
app/Models/MerchantRiskEvent.php
+ نماذج 6cash الأساسية الأخرى المرتبطة (business settings, etc.)
```
> (مجلدات Aml/ و Ledger/ و Rbac/ موجودة — ليست ناقصة.)

### 6) متحكمات أساسية
```
app/Http/Controllers/Api/V1/Customer/TransactionController.php   ← الدفع المالي للعميل
+ بقية متحكمات 6cash الأساسية (Auth, Profile, Admin...) المشار لها في routes/api.php
```

### 7) قوالب Blade الأساسية (للوحة الأدمن)
```
resources/views/layouts/admin/app.blade.php          ← كل لوحات أميال تمتد منه
resources/views/layouts/app.blade.php
resources/views/layouts/admin/partials/_sidebar.blade.php   ← لإدراج قائمة أميال
+ أصول الثيم في public/ (CSS/JS/الأيقونات tio-*)
```

### 8) هجرات القاعدة (CREATE) — هجرات أميال تعدّل (ALTER) هذه الجداول
```
migration: create users table          (أميال يضيف: transaction_pin, zone_code, role...)
migration: create transactions table   (أميال يعدّلها)
migration: create e_money table         (أميال يعدّلها)
migration: create business_settings, currencies, ... (جداول 6cash الأساسية)
```

### 9) أساسيات إطار العمل
```
bootstrap/providers.php
public/index.php + public/ (الأصول)
resources/lang/ (ملفات اللغة)
.env  (انسخه من .env.example ثم عدّله)
```

## 🎯 الأولوية إن أردت إرسال القليل أولاً

| # | الملف | لماذا أولاً |
|---|---|---|
| 1 | `app/CentralLogics/Helpers.php` | الأكثر استدعاءً — يمكنني عندها دمج `AmialHelperPatchTrait` فعلياً |
| 2 | `app/Lib/Constant.php` | يعرّف الثوابت التي يستخدمها الكود |
| 3 | `config/` كامل (zip) | لتشغيل الإطار |
| 4 | هجرات القاعدة + `routes/api.php` | لتشغيل migrate والراوتات |

أو ببساطة: أرسل **نسخة 6cash كاملة (zip)** وسأطبّق حزمة أميال فوقها وأجعلها تعمل.
