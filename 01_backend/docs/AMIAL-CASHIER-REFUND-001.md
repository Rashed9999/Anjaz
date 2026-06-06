# AMIAL-CASHIER-REFUND-001 — نظام المرتجعات للكاشير

**الإصدار:** Backend v2.25 + Flutter v2.17  
**التاريخ:** 2026-05-30

## الميزة (من المجموعة الأولى - أولوية 1)

نظام المرتجعات لبيوع الكاشير، يدعم 3 طرق استرداد ويتكامل مع كل من الكاشير ونظام الديون والإشعارات.

## ما تم إصلاحه

النظام القديم (`merchant_refunds` + `MerchantService::processRefund`) كان:
- يفترض `customer_user_id` إجباري ← يمنع المرتجعات لعملاء غير مسجّلين (60% من البيوع نقدية).
- مربوط بجدول `transactions` القديم فقط ← لا يدعم بيوع الكاشير الجديدة (`merchant_sales`).
- يدعم استرداد للمحفظة فقط ← لا نقد ولا حساب دَيْن.

## ما تم بناؤه

### Backend

**Migration معدّل:** `2026_05_24_160001_amial_extend_merchant_refunds.php`
- `customer_user_id` → nullable
- إضافة `original_sale_ulid`، `customer_phone`، `customer_name`
- إضافة `refund_method` (cash | wallet | credit_account)
- إضافة `credit_account_id` + `items` (JSON)
- `status` من enum إلى varchar (لقبول قيم مستقبلية)

**نموذج:** `MerchantRefund`
- علاقات مع التاجر، العميل، حساب الديون
- ثوابت METHODS، STATUSES

**خدمة:** `MerchantSaleRefundService` (340 سطر)
- `refund()` — تنفيذ مرتجع بأي طريقة من الثلاث
- `approve()` — اعتماد الإدارة لمرتجع > 5,000 ر.ي
- `reject()` — رفض الإدارة
- التحقّقات: العملية موجودة، الحالة مقبولة، المبلغ ≤ المتبقّي، الطريقة متناسقة مع البيع الأصلي
- كل العمليات داخل `DB::transaction + lockForUpdate`

**Controller + 4 endpoints:**
- `POST /merchant/cashier/sales/{ulid}/refund` — إنشاء (مع rate-limit 30/دقيقة)
- `GET  /merchant/cashier/sales/{ulid}/refundable` — كم متبقّي + طرق متاحة
- `GET  /merchant/cashier/refunds` — قائمة (paginated)
- `GET  /merchant/cashier/refunds/{id}` — تفاصيل

**اختبارات شاملة:** 9 اختبارات
- كل طريقة استرداد على حدة
- رفض المحفظة لعميل غير مسجّل
- رفض credit_account لبيع غير آجل
- تجاوز المبلغ الأصلي
- مرتجعات جزئية متعدّدة (تراكم)
- threshold 5,000 ر.ي (pending_approval)
- approve يحرّك المال فعلياً
- إشعار العميل بـ `refund_received`

### Flutter

**Repo + Controller** بنفس النمط (GetX، Observable state)

**شاشة `CashierRefundScreen`** (تطابق شاشتي 89/90 من التصميم):
- بطاقة معلومات العملية (رقم، تاريخ، عميل، إجمالي، مُسترد سابقاً، **المتبقّي**)
- قائمة الأصناف مع عدّاد كمية إرجاع لكل صنف (حد أقصى = الكمية الأصلية)
- إجمالي محسوب تلقائياً
- اختيار طريقة الاسترداد (يتاح حسب البيع: 1-3 خيارات)
- سبب الإرجاع
- زر تأكيد + ملاحظة عن الحد 5,000

**حالات خاصة معالَجة:**
- بيع بلا أصناف → حقل مبلغ يدوي
- العملية مُستردة كاملاً → رسالة وقائية
- pending_approval → snackbar مختلف

## التكاملات

### مع نظام الديون
عند `refund_method = credit_account`:
- `CustomerCreditService::recordReturn` يُستدعى تلقائياً
- ينقص الدَّيْن
- يُحفظ `credit_account_id` + `ledger_entry_ulid` (الـ movement_ulid)
- العميل (إن مسجّل) يستلم إشعار

### مع الإشعارات
- `refund_received` للعميل (إن مسجّل) عند الاسترداد المكتمل
- `refund_pending` للتاجر عند المرتجعات > 5,000 ر.ي
- 2 نوعان إشعار جديدان في `NotificationService::TYPES`

### مع الكاشير
- يقرأ مباشرةً من `merchant_sales` بـ `sale_ulid`
- يتحقّق من `payment_method` لتحديد طرق الاسترداد المتاحة
- يحجز lockForUpdate على البيع الأصلي أثناء معالجة المرتجع

## القرارات التصميمية

1. **Migration ملحق بدلاً من إنشاء جدول جديد**: التوافق الخلفي مع `MerchantService::processRefund` السابق.
2. **خدمة منفصلة `MerchantSaleRefundService`**: تجنّب كسر التوافق مع `MerchantService::processRefund`.
3. **النقد بلا حركة مالية**: السجل فقط (`ledger_entry_ulid = 'cash:no_wallet_movement'`).
4. **threshold 5,000 ر.ي ثابت**: مذكور صراحةً في وثيقة المتطلبات §12.
5. **طرق متاحة محسوبة ديناميكياً**: حسب `payment_method` للبيع الأصلي + وجود `customer_user_id`.

## ما يبقى عليك للتحقّق

**فحصي بنيوي فقط** (توازن الأقواس عبر Node). للتحقّق الفعلي:

```bash
# Backend
php artisan migrate
php artisan test --filter=CashierRefundTest

# Flutter
flutter analyze lib/features/merchant/
```

## نقاط الربط النهائية (لم أعدّلها لتركك تختار)

1. **زر "مرتجع"** في شاشة `MerchantTransactionsScreen` (سجل العمليات) أو من تفاصيل البيع:
```dart
Get.to(() => CashierRefundScreen(saleUlid: sale.saleUlid));
```

2. **شاشة قائمة المرتجعات** (بسيطة، يمكن بناؤها لاحقاً) — backend جاهز.

## المرحلة التالية في المجموعة الأولى (بقي 3)

- **Payment Requests** (طلب أموال بـ QR/رابط)
- **PDF تصدير الإيصالات + كشف حساب الديون**
- **شارة Verified visually** + ربط KYC tier
