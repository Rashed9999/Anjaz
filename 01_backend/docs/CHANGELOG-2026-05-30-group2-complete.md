# CHANGELOG — المجموعة الثانية مكتملة

**التاريخ:** 2026-05-30  
**الإصدار:** Backend v2.27 + Flutter v2.19

## ✅ ميزات المجموعة الثانية (3/3)

### 1. ✅ §17 الإيصالات الموحّدة — **ربط فقط** (الميزة مبنية أصلاً)

**اكتشاف مهم:** Receipts كانت موجودة بالكامل في backend وFlutter:
- Backend: `receipts` table + `Receipt` model + `ReceiptService` + `ReceiptController` (index، show، download، verifyPublic)
- Flutter: Repo (87 سطر) + Controller + شاشتان (ReceiptsListScreen 225 سطر، ReceiptDetailScreen 258 سطر) + DI مسجّل

**ما فعلت:** ربط `ReceiptsListScreen` في شاشة "خدماتي" بدلاً من "سجل المعاملات" coming-soon.

### 2. ✅ §14 الدفع الآمن (Safe Payment) — **ربط فقط** (الميزة مبنية أصلاً)

**اكتشاف مهم:** Safe Payment كان مبنياً بالكامل:
- Backend: 3 migrations + `SafePaymentService` (12 method: createAndFund, sellerAccept/Reject, sellerMarkInDelivery/Delivered, buyerConfirm/Cancel/Dispute, adminResolveRelease/Refund/Partial, expireUnresponsive) + `SafePaymentController` (12 endpoint)
- Flutter: Repo + Controller + 3 شاشات (create + detail + list) + DI مسجّل

**ما فعلت:** ربط `MySafePaymentsScreen` في شاشة "خدماتي".

### 3. ✅ §13 توثيق التاجر — **بناء كامل من الصفر**

كانت `KycTierService` موجودة (للحسابات العامة)، لكن **نظام توثيق التاجر بمستندات ووثائق** كان مفقوداً.

#### Backend:
- **Migration `merchant_verification_requests`**: 7 مسارات وثائق + بيانات بنك + بيانات نشاط + admin_note + reviewed_at.
- **Model `MerchantVerificationRequest`** بـ 5 حالات: pending_review → verified | rejected | resubmission_required | verification_suspended.
- **خدمة `MerchantVerificationService`** (280 سطر):
  - `ensureMerchantProfile()` — يخلق MerchantProfile إن لم يوجد.
  - `submit()` — يرفع وثائق + ينشئ/يحدّث طلب (للـ resubmission).
  - `approve()` / `reject()` / `requestResubmission()` (للأدمن).
  - رفع آمن للملفات في `storage/app/private/merchant_verifications/{merchantId}/` مع تحقّق MIME + حجم.
  - 4 إشعارات تلقائية للتاجر.
- **`MerchantVerificationController` + 6 endpoints**:
  - `GET /merchant/verification` — الحالة الحالية.
  - `POST /merchant/verification` — تقديم/تحديث (multipart).
  - `GET /merchant/verification/document/{type}` — تنزيل مستند خاص.
  - `POST /admin/verifications/{id}/approve|reject|request-resubmission`.
- **4 أنواع إشعارات جديدة**: `merchant_verified`، `merchant_verification_rejected`، `merchant_verification_submitted`، `merchant_resubmission_required`.

#### Flutter:
- `MerchantVerificationRepo` + `MerchantVerificationController`.
- **شاشة `MerchantVerificationScreen`** كاملة:
  - بطاقة الحالة مع ألوان حسب status.
  - 6 حقول بيانات (اسم النشاط، رقم السجل، نوع، مدينة، عنوان، هاتف تواصل).
  - 7 بطاقات مستندات (4 إلزامية، 3 اختيارية):
    - وجه الهوية الأمامي/الخلفي (إلزامي)
    - السجل التجاري (إلزامي)
    - صورة المحل (إلزامي)
    - إثبات العنوان، رخصة المهنة، وثيقة اختيارية
  - **كل بطاقة:** معاينة صورة + زرّان (معرض/كاميرا).
  - عرض ملاحظة الإدارة عند `resubmission_required` أو `rejected`.
  - شاشة احتفال لمن هو verified بالفعل (مع تمييز Gold).
- استخدام `image_picker: ^1.2.1` (موجود في pubspec).

## ملخّص الأرقام
| المقياس | القيمة |
|---|---|
| Endpoints جديدة | +6 (للتاجر) + 3 (للأدمن) = 9 |
| جداول جديدة | +1 (merchant_verification_requests) |
| ملفات backend جديدة | 4 |
| شاشات Flutter جديدة | 1 (شاشة شاملة، 480 سطر) |
| ميزات مربوطة سابقاً بلا واجهة | 2 (Receipts، Safe Payment) |

## ملاحظات صدق

- **فحصي بنيوي فقط** (توازن الأقواس عبر Node). لم أشغّل اختبارات.
- **توثيق التاجر بلا اختبارات بعد** — هذا غياب حقيقي. أوصي بإضافة 6-8 اختبارات في الجولة القادمة.
- **شاشة Admin Review غير مبنية** — endpoints موجودة، لكن لا واجهة Admin مخصّصة. يمكن استخدام Admin Panel الموجود من Cash6 أو بناء واجهة Admin Flutter لاحقاً.
- **Safe Payment وReceipts** كانتا في فجوة **التكامل فقط** — أقل عمل من المتوقّع.

## التحقّق المطلوب
```bash
# Backend
php artisan migrate
php artisan test --filter=PaymentRequestsTest
php artisan test --filter=CashierRefundTest

# Flutter
flutter pub get
flutter analyze lib/features/merchant_verification/ lib/features/me/
```

## ما يبقى من خطة الإطلاق
- **اختبارات لـ MerchantVerification** (مهم).
- **شاشة Admin Review** (لإدارة الطلبات).
- **ربط زر التوثيق** للتجّار غير الموثَّقين في لوحة التاجر.
- **اختبار MerchantPayment + KycTier** للتأكّد من تطبيق الحدود.

## المجموعة الثالثة المقبلة (مؤجَّلة):
- §18 الصندوق العائلي.
- §19 تسديد الفواتير وشحن الاتصالات.
- نظام الفروع + الموردين.
- قطاعات POS متخصّصة (مطعم، صيدلية، وقود).
