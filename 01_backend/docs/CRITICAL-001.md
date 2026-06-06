# CRITICAL-001 — Foundation للأدوار + الخطط + Business Types + Features

**التاريخ:** 2026-06-03  
**الإصدار:** Backend v2.32 + Flutter v2.24

## الهدف

**أيّ شخص يدخل أميال باي يرى فقط ما يحتاجه.**

المنطق:
```
final_features = roleBase(role)
               ∪ businessTypeFeatures(business_type)
               ∪ planFeatures(subscription_plan)
               ∪ verificationFeatures(verification_level)
               ∪ extra_features (من الأدمن)
```

## القرارات المُعتمدة

| القرار | الاختيار |
|---|---|
| التسعير | Subscriptions بـ SAR (أرقام مرجعية، لا billing تلقائي) |
| Multi-Currency | مؤجّل لـ v2 |
| Business Types | 6 أنواع: quick_sale, retail, fuel, pharmacy, wholesale, restaurant |
| اختيار business_type | اختياري + تعديل لاحقاً |
| خطّة افتراضية | FREE تلقائياً |
| آلية الدفع | تواصل مع خدمة العملاء → الإدارة تُفعّل يدوياً |

## Backend

### Migration واحد
أعمدة جديدة:
- `users.role` (user/agent/distributor/merchant/admin) — migrated من `type` القديم.
- `users.verification_level` (basic/verified/premium).
- `merchant_profiles.business_type` (nullable).
- `merchant_profiles.subscription_plan` (default: free).
- `merchant_profiles.subscription_expires_at` (للإشارة فقط).
- `merchant_profiles.subscription_notes`.
- `merchant_profiles.extra_features` (JSON — تفعيل يدوي إضافي).

### Files
1. **`app/Support/Access/AccessConstants.php`** — Single Source of Truth لكل الثوابت:
   - 5 أدوار، 3 verification levels، 6 business types، 5 plans.
   - 50+ feature key.
   - أسعار الخطط بـ SAR (FREE=0, STARTER=15, BUSINESS=35, PRO=65, ENTERPRISE=150).

2. **`app/Support/Access/AccessPresets.php`** — قواميس الـ Features:
   - `roleBase($role)` — ميزات أساسية لكل دور.
   - `businessTypeFeatures($type)` — ميزات لكل business_type.
   - `planFeatures($plan)` — ميزات إضافية لكل خطّة.
   - `verificationFeatures($level)` — (multi_currency للـ Premium في v2).
   - `planLimits($plan)` — حدود (max_products، max_employees، max_branches، max_customers).

3. **`app/Services/FeatureAccessService.php`** (الجوهر):
   - `accessFor(User $user)` — يُرجع كل البيانات.
   - `resolveFeatures()` — دمج المصادر الخمسة.
   - `hasFeature()` — فحص سريع.
   - `updateMerchantPlan()` / `updateBusinessType()` — admin.
   - `addExtraFeature()` / `removeExtraFeature()` — تفعيل يدوي.

4. **`app/Http/Controllers/Api/V1/Amial/AccessController.php`** — 6 endpoints:
   ```
   GET  /api/v1/amial/me/access                              ← الجوهر
   PUT  /api/v1/amial/merchant/business-type                 (التاجر يختار)
   
   Admin:
   GET  /api/v1/amial/admin/access-catalog
   POST /api/v1/amial/admin/merchants/{id}/plan
   POST /api/v1/amial/admin/merchants/{id}/business-type
   POST /api/v1/amial/admin/merchants/{id}/extra-feature
   ```

### اختبارات — 10 اختبارات
- ✅ user عادي يرى أساسيات فقط (محفظة + family_fund + safe_pay).
- ✅ agent يرى cash_in/out بدون ميزات تاجر.
- ✅ بائع سمك (quick_sale + free) يرى 4 ميزات فقط، 20 منتج max، 0 موظفين.
- ✅ بقالة retail starter تحصل على inventory + barcode.
- ✅ محطة وقود enterprise تحصل على branches + api_access + RBAC.
- ✅ صيدلية business تحصل على employees لكن ليس prescriptions (Pro فقط).
- ✅ تاجر بدون business_type يرى merchant_verification فقط.
- ✅ admin يستطيع تغيير الخطّة.
- ✅ خطّة غير صحيحة تُرفض.
- ✅ extra_features تُدمج بنجاح.

## Flutter

### 4 ملفات
1. **`AccessRepo`** — استدعاء `/me/access`.
2. **`AccessController`** (permanent — موجود طوال الجلسة):
   - حقول reactive: role, verificationLevel, businessType, subscriptionPlan, features (Set), limits.
   - Helpers: `has()`, `hasAny()`, `hasAll()`.
   - Boolean shortcuts: `isUser`, `isMerchant`, `isFuel`, `isPharmacy`, `needsBusinessTypeSelection`...
   - `updateMyBusinessType()`.
3. **`AccessGate` widget** — يُظهر/يُخفي عناصر حسب الميزات:
   ```dart
   AccessGate(feature: 'inventory', child: _myWidget)
   AccessGate(anyOf: ['fuel_pos', 'pharmacy_pos'], child: _myWidget)
   ```
4. **`BusinessTypeSelectionScreen`** — شاشة اختيار النشاط بـ 6 خيارات بصرية.

### الربط
- DI: `AccessController` مسجّل **permanent** (لا يُحذف من الذاكرة).
- يُحمَّل تلقائياً بعد:
  - تسجيل دخول ناجح (في `unified_auth_controller`).
  - verifyOtp ناجح.
- شاشة "خدماتي" تُغلّف كل بطاقة بـ `AccessGate`.
- بائع السمك (quick_sale) لن يرى بطاقات: محطة وقود، الصيدلية، فواتير، عائلية... فقط الميزات الخاصّة به.
- Banner أصفر يظهر للتاجر الذي لم يختر نوع نشاطه بعد، يفتح شاشة الاختيار.

## أمثلة تطبيقية

### مثال 1: محمد — بائع سمك (Quick Sale + FREE)
**يرى في "خدماتي":**
- ✅ المساعدة (دائماً)
- ✅ إيصالاتي

**لا يرى:**
- ❌ محطة وقود
- ❌ الصيدلية
- ❌ الفواتير
- ❌ صندوق العائلة
- ❌ Safe Pay (هذه للمستخدم العادي وليس التاجر)
- ❌ المخزون، الباركود (Starter+)

### مثال 2: سالم — محطة وقود (Fuel + Enterprise)
**يرى:**
- ✅ بطاقة "محطة الوقود" تقوده لـ Fuel Dashboard
- ✅ إيصالاتي
- ✅ المساعدة
- ✅ توثيق المتجر

**+ ميزات Enterprise داخل Fuel Dashboard:**
- بطاقات الشركة، النوبات + variance، RBAC، الفروع.

### مثال 3: راشد — مستخدم عادي (User + Basic)
**يرى:**
- ✅ سحب نقدي، رقم حسابي، طلب أموال
- ✅ Safe Pay، الصناديق العائلية، الفواتير
- ✅ إيصالاتي

**لا يرى:**
- ❌ أيّ ميزة تاجر/محطة/صيدلية

## الإنجاز التراكمي

| البند | قبل | الآن |
|---|---|---|
| ميزات | 18 | **19** |
| Endpoints | 107 | **113+** |
| Migrations | 39 | **40** |
| اختبارات | 76 | **86+** |
| Services | 55 | **56** |
| شاشات Flutter | 27 | **28** |

## للتحقّق
```bash
php artisan migrate
php artisan test --filter=FeatureAccessTest    # 10 اختبارات
flutter analyze lib/features/access/
```

## ما يبقى لاحقاً

1. **6 شاشات Home متمايزة** (`HomeUserScreen`, `HomeMerchantQuickSaleScreen`, ...) — الآن الـ Home واحد للجميع لكن "خدماتي" مُفلتر.
2. **Onboarding step** — إجبار التاجر على اختيار النوع عند أوّل دخول.
3. **شاشة Admin Panel** Flutter لإدارة خطط التجّار (الآن endpoints جاهزة).
4. **Audit شامل** — تطبيق AccessGate على كل شاشات التطبيق (الكاشير، الديون، الفواتير، إلخ).
5. **Multi-Branch system** (v2 — يحتاج جدول branches + ربط كل المبيعات).
6. **RBAC داخلي للموظفين** (v2 — Owner/Manager/Cashier/Auditor).

## ملاحظات صدق

- **فحصي بنيوي فقط** عبر Node. لم أشغّل أيّ اختبار.
- **migration `users.role`** يفترض أن العمود غير موجود؛ يُضاف بأمان فقط إذا لم يكن موجوداً.
- **التحديث التلقائي للأدوار** من `type` يعمل لـ MySQL (DB::statement مع CASE).
- **Banner اختيار النشاط** يظهر بعد كل refresh access — مفيد للتجّار الموجودين قبل هذه الترقية.
- **الـ AccessController مسجّل permanent** — لا يُمسح حتى عند تنقّل بين الشاشات.
