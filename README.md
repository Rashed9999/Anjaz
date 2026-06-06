# أميال باي — المشروع الكامل
**النسخة:** Backend v2.48 + Flutter v0.7.0+70
**التاريخ:** يونيو 2026

> ⚠️ **مهم — قاعدة Cash6:** هذه الحزمة طبقة تعديلات/إضافات (delta) فوق مشروع
> Cash6/6Cash الأصلي. ملفات القاعدة (مثل `app/CentralLogics/Helpers.php`،
> معظم `config/*`، `app/Lib/*`، vendor) **غير مُرفقة هنا** ويجب دمجها أولاً.
> راجع `FIXES.md` لتفاصيل الإصلاحات وخطوات الدمج، و`01_backend/docs/PATCH_Helpers.md`
> لطريقة تطبيق تعديلات Helpers.

## 📁 محتوى المشروع

```
amial_pay_complete/
├── 01_backend/        # Laravel 12.44 backend
│   ├── app/           # Models, Services, Controllers, Middleware
│   ├── database/      # 63 migration + 9 seeders (RBAC + بيانات مرجعية)
│   ├── routes/        # API routes (185+ endpoint)
│   ├── resources/legal/ar/  # 4 وثائق قانونية
│   ├── tests/         # 141+ اختبار
│   └── ...
└── 02_flutter_app/    # Flutter user app
    ├── lib/           # 400+ Dart file
    │   ├── features/  # 34 ميزة
    │   ├── data/      # API client
    │   ├── helper/    # DI + helpers
    │   ├── theme/     # AmyalColors
    │   └── util/      # ContactConstants
    ├── pubspec.yaml   # 58 dependency
    └── ...
```

## 🚀 خطوات التشغيل

### Backend
```bash
cd 01_backend
composer install
cp .env.example .env
# عدّل .env: DB credentials, APP_URL, ...
php artisan key:generate
php artisan migrate
php artisan db:seed                         # DatabaseSeeder: RBAC المركزي + POS + بيانات مرجعية
# أو بشكل انتقائي:
#   php artisan db:seed --class=RbacDefaultSeeder   # RBAC المركزي (rbac_* tables)
#   php artisan db:seed --class=RbacSeeder          # RBAC الخاص بـ POS
php artisan test                           # 141+ test
php artisan serve
```

### Cron (مهم جداً)
```bash
crontab -e
# أضف:
* * * * * cd /path/to/01_backend && php artisan schedule:run >> /dev/null 2>&1
```

### Queue Worker
```bash
php artisan queue:work --tries=2 --timeout=600
```

### Flutter
```bash
cd 02_flutter_app
flutter clean
flutter pub get
flutter run
```

## ⚙️ قبل الإطلاق

1. **استبدل أرقام التواصل:** `02_flutter_app/lib/util/contact_constants.dart`
2. **اختبر mysqldump:** `which mysqldump`
3. **اختبر backup:** `php artisan amial:backup`
4. **اختبر ping:** `curl https://your-domain.com/api/v1/amial/ping`
5. **حدّث تواريخ Legal Docs:** `01_backend/resources/legal/ar/*.md`
6. **اعرض Legal Docs للموافقة:** عبر `/api/v1/amial/legal-docs`

## 📊 الإحصائيات

| البند | الإجمالي |
|---|---|
| ميزات | 34 |
| Endpoints | 185+ |
| Migrations | 63 |
| Services | 67 |
| Middleware | 12 |
| Notifications | 9 |
| Scheduled Jobs | 2 |
| Console Commands | 1 |
| Legal Docs | 4 |
| Tests | 141+ |
| Flutter Screens | 44+ |
| Dart files | 400+ |

## 📋 خريطة الميزات

### 🎯 P0 — البنية الأساسية
- ✅ Monitoring (Health checks + /ping)
- ✅ Backups (mysqldump يومي 3 ص + retention 7+4+6)
- ✅ Legal Docs (Terms + Privacy + KYC + AML)

### 🏢 P1 — منصّة المؤسّسات
- ✅ Branches (CRUD + plan limits + report)
- ✅ Branches Linking (sales + POS users + reports)
- ✅ RBAC (6 system roles + 30+ permissions + branch-scoped)
- ✅ Flutter UI (BranchesManagementScreen + DI)

### 💼 الميزات الأساسية
- ✅ Subscription Management (5 plans + audit log + MRR)
- ✅ Plans Catalog + Usage Limits
- ✅ Wholesale POS (full system + PDF invoices)
- ✅ Fuel Station (variance + shifts)
- ✅ Pharmacy POS
- ✅ Cashier POS
- ✅ Restaurant POS
- ✅ Safe Pay (escrow)
- ✅ Split Bills
- ✅ Merchant Risk + AML
- ✅ KYC verification
- ✅ Notifications system
- ✅ Receipts (PDF)
- ✅ Recovery
- ✅ Policy zones

## 🔴 معروف غير مُكتمل

- ❌ Multi-Currency حقيقي (feature flag فقط)
- ❌ Disputes Center متكامل (Safe Pay فقط)
- ❌ Semi-Offline (لم يُبنَ)
- ❌ تطبيق Admin منفصل (دمج في user app حالياً)
- ❌ PosUserRolesScreen في Flutter (Controller جاهز)

## 📜 الترخيص
هذا المشروع مبنيّ على Cash6 CodeCanyon (مرخّص بشكل قانوني) + إضافات مخصّصة لأميال باي.

