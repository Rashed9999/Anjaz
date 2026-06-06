# CHANGELOG — المجموعة الأولى مكتملة

**التاريخ:** 2026-05-30  
**الإصدار:** Backend v2.26 + Flutter v2.18

## الميزات الأربع المكتملة:

### 1. ✅ AMIAL-CASHIER-REFUND-001 — المرتجعات (§12)
- Migration ملحق لـ `merchant_refunds` يدعم 3 طرق استرداد + nullable customer.
- خدمة `MerchantSaleRefundService` بـ DB transaction + lockForUpdate.
- 4 endpoints + 9 اختبارات + شاشة Flutter كاملة.
- تكامل مع نظام الديون + الإشعارات.

### 2. ✅ AMIAL-PAYMENT-REQUESTS-001 — طلب أموال
- جدول `payment_requests` + Model + `PaymentRequestService`.
- short_code (6 أحرف من 32-char alphabet بلا التباس).
- 5 endpoints: create، list، show-by-code، pay، cancel.
- إشعارات تلقائية للطرفين عند الدفع.
- 10 اختبارات شاملة (إنشاء، رمز فريد، رفض self، رفض expired، إشعارات، انتهاء صلاحية).
- Flutter: Repo + Controller + شاشتان (إنشاء + عرض QR/رابط).
- يدعم التكرار (daily/weekly/monthly).

### 3. ✅ AMIAL-CREDIT-PDF-001 — تصدير PDF لكشف الحساب
- `barryvdh/laravel-dompdf` ^3.1 (مُثبَّت أصلاً).
- `CreditStatementPdfService` يستخدم Blade view.
- View RTL عربي بـ DejaVu Sans، A4 portrait.
- Endpoint `GET /merchant/credit/customers/{id}/statement/pdf`.
- زر "تحميل PDF" في شاشة كشف الحساب Flutter.

### 4. ✅ AMIAL-VERIFIED-BADGE-001 — شارة موثَّق
- Widget `VerifiedBadge` بـ 3 مستويات: verified | premium | gold.
- 3 أحجام (small/medium/large).
- نسختان: أيقونة فقط أو مع نص.
- backend يرجع tier في `/me` endpoint بناءً على `MerchantProfile.tier` + `verification_status`.
- مستخدم في شاشة "خدماتي" على بطاقة رقم الحساب.

## ملخّص الأرقام
| المقياس | القيمة |
|---|---|
| Endpoints جديدة | +14 |
| جداول جديدة | +2 (payment_requests + extend merchant_refunds) |
| ملفات backend جديدة | 11 |
| اختبارات جديدة | +19 (9 + 10) |
| شاشات Flutter جديدة | 4 |
| Widgets shared | 1 |

## ملاحظات صدق
- **فحصي بنيوي فقط** (توازن الأقواس عبر Node). لم أشغّل أيّ test.
- **زر PDF يفتح URL في المتصفّح** (clipboard للنسخ) — لا مكتبة WebView/Downloader متكاملة. للمستخدم النهائي يفتح المتصفّح يدوياً.
- **شاشة الدفع للـ Payment Request غير مبنية** بعد (تحتاج deep link handling). الـ backend جاهز للدفع. شاشة بسيطة يمكن بناؤها لاحقاً.
- **QR code في شاشة العرض حالياً أيقونة بصرية فقط** — لا توليد فعلي. تحتاج `qr_flutter` package لتوليد QR حقيقي.

## التحقّق المطلوب منك
```bash
# Backend
php artisan migrate
php artisan test --filter=PaymentRequestsTest
php artisan test --filter=CashierRefundTest
php artisan test --filter=CustomerCredit

# Flutter
flutter analyze lib/features/
```

## نقاط ربط متبقّية يدوياً
1. **زر "خدماتي"** في Profile/قائمة جانبية:
```dart
ListTile(
  leading: Icon(Icons.apps),
  title: Text('خدماتي'),
  onTap: () => Get.to(() => const MyServicesScreen()),
);
```

2. **زر "مرتجع"** في تفاصيل عملية الكاشير:
```dart
Get.to(() => CashierRefundScreen(saleUlid: sale.saleUlid));
```

3. **إضافة `qr_flutter` package** لتوليد QR حقيقي بدل الأيقونة:
```yaml
qr_flutter: ^4.1.0
```

## ما يبقى من المجموعات لاحقاً
- المرحلة الثانية: توثيق التاجر §13، الإيصالات الموحّدة §17، Safe Payment §14.
- المرحلة الثالثة: الصندوق العائلي §18، البلّ باي §19، نظام الفروع.
- v2: Offline-First، Push Notifications، قطاعات POS متخصّصة.
