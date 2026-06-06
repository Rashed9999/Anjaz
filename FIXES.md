# تقرير الإصلاحات — أميال باي

تاريخ الإصلاح: 2026-06-05

هذا المستند يلخّص الأخطاء التي عُولجت في هذه النسخة، وما يتطلّب خطوة دمج مع
قاعدة Cash6 الأصلية (غير القابل للإصلاح داخل الحزمة وحدها).

---

## ✅ أُصلِحت بالكامل داخل الحزمة

### 1. خطأ صياغة PHP في `Helpers_PATCH.php` (حرج)
- **المشكلة:** الملف احتوى دوال `public static function` خارج أي class →
  `PHP Parse error` (الملف الوحيد الذي فشل في `php -l`).
- **الإصلاح:**
  - حُوِّل المحتوى التنفيذي إلى trait صالح:
    `app/CentralLogics/Concerns/AmialHelperPatchTrait.php` (يجتاز `php -l`).
  - نُقلت تعليمات الدمج ودالة `translate()` العامّة إلى `01_backend/docs/PATCH_Helpers.md`.
  - حُذف الملف المعطوب `app/CentralLogics/Helpers_PATCH.php`.
- **النتيجة:** صفر أخطاء صياغة في كامل `app/` و`routes/` و`database/`.

### 2. نظام الصلاحيات المركزي (RBAC) كان كوداً ميّتاً (مرتفع)
- **المشكلة:** نموذج `User` لم يكن يستخدم trait `HasRoles`، فدالة
  `hasAnyPermission()` غير موجودة، و middleware `RequirePermission` (alias `rbac`)
  كان يُرجع `false` دائماً (يرفض الجميع).
- **الإصلاح:** أُضيف `use HasRoles;` إلى `app/Models/User.php` مع `use` المناسب.
  أصبح `$user->hasPermission()/hasAnyPermission()/assignRole()` فعّالاً ومربوطاً
  بجداول `rbac_*`.

### 3. `DatabaseSeeder.php` مفقود (متوسط)
- **المشكلة:** أمر `php artisan db:seed` الافتراضي يفشل لعدم وجود `DatabaseSeeder`.
- **الإصلاح:** أُنشئ `database/seeders/DatabaseSeeder.php` يشغّل البذور الآمنة
  للتكرار: `RbacDefaultSeeder` + `RbacSeeder` + (Fee/KYC/AML/Charity/BillProviders).

### 4. `.env.example` مفقود (متوسط)
- **المشكلة:** README يطلب `cp .env.example .env` والملف غير موجود.
- **الإصلاح:** أُنشئ `01_backend/.env.example` شامل: مفاتيح Laravel القياسية +
  مفاتيح أميال الخاصّة (من `config/amial.php`) + بوابات الدفع + SMS + Firebase.

### 5. مجلد تالف من أمر `mkdir` خاطئ (تنظيف)
- **المشكلة:** مسار حرفي بأقواس لم تُوسَّع:
  `01_backend/{resources/.../{legal,recovery,...},app/Http/Controllers/Admin}`.
- **الإصلاح:** حُذف المجلد بالكامل (كان مجلدات فارغة).

### 6. placeholder عنوان الـ API في Flutter (تحسين)
- **المشكلة:** `baseUrl = 'YOUR_BASE_URL_HERE'` (قيمة تكسر التطبيق صامتاً).
- **الإصلاح:** أصبح يقرأ من بيئة البناء:
  `String.fromEnvironment('BASE_URL', defaultValue: 'https://api.your-domain.com')`.
  مرّره عبر `--dart-define=BASE_URL=...` أو عدّل القيمة الافتراضية.

### 7. عدم اتساق التوثيق (تنظيف)
- صُحِّح عدد الـ migrations في README من 48 إلى **63**، وعدد الـ seeders إلى 9.
- وُحِّد رقم إصدار Flutter ليطابق `pubspec.yaml` (`0.7.0+70`).
- حُدِّثت تعليمات الـ seeding لتشمل النظامين.

---

## ⚠️ يتطلّب دمجاً مع قاعدة Cash6 الأصلية (خارج نطاق الحزمة)

هذه ليست أخطاء برمجية في الحزمة، بل نتيجة كون الحزمة **delta** فوق Cash6. القاعدة
الأصلية مرخّصة (CodeCanyon) ولا يمكن تضمينها هنا. قبل التشغيل ادمج الحزمة فوقها:

1. **ملفات `composer.json` → `autoload.files`** تشير إلى ملفات قاعدة غير مُرفقة:
   `Helpers.php`, `Translation.php`, `FilePath.php`, `FileUploader.php`,
   `app/Lib/{Helper,Transaction,Responses,Constant,PaymentResponse,PaymentSuccess}.php`.
   هذه الإدخالات **صحيحة بعد الدمج** — لا تحذفها؛ وفّر الملفات من القاعدة.
2. **`config/`** يحوي `amial.php` فقط؛ بقية ملفات Laravel (`app.php`, `database.php`,
   `filesystems.php`, `auth.php` ...) تأتي من القاعدة. أضف disk باسم `private` في
   `filesystems.php` (انظر `docs/PATCH_Helpers.md`).
3. **ثوابت** مثل `ADMIN_CHARGE` و`APPLICATION_IMAGE_FORMAT` تُعرَّف في
   `app/Lib/Constant.php` بالقاعدة (يستخدمها الـ trait الجديد عند التشغيل).
4. طبّق تعديلات Helpers عبر `use AmialHelperPatchTrait;` كما في `docs/PATCH_Helpers.md`.

بعد الدمج: `composer install` → `cp .env.example .env` → `php artisan key:generate`
→ `php artisan migrate` → `php artisan db:seed` → `php artisan test`.

---

## ℹ️ ملاحظات (ليست أخطاء)

- **نظاما RBAC منفصلان عمداً:** المركزي (`rbac_*` للأدمن) و POS
  (`roles/permissions/pos_user_roles` لموظّفي التاجر). لا تصادم على مستوى الجداول.
  لتفعيل الفرض على المسارات استخدم `->middleware('rbac:...')` أو
  `->middleware('amial.pos-permission:...')` حسب الحاجة (لم يُفرض تلقائياً تجنّباً
  لقفل الوصول قبل تشغيل البذور).
- المسارات المكرّرة ظاهرياً (`/dashboard`, `/products`...) موجودة داخل مجموعات
  `prefix` مختلفة — ليست تعارضات.
