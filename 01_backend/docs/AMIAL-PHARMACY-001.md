# AMIAL-PHARMACY-001 — قطاع الصيدليات

**التاريخ:** 2026-06-02  
**الإصدار:** Backend v2.31 + Flutter v2.23

## النطاق المُتفق عليه
- **بيع تجاري** أساسي — الوصفة الطبية حقل اختياري.
- **Batches + FIFO + تواريخ صلاحية** + تنبيهات قُرب الانتهاء.
- **حساسيات العميل المسجّل** (نهج عملي بدون قاعدة تفاعلات أدوية).
- علم `requires_prescription` على المنتج بدون تتبّع حكومي.

## Backend

### 8 جداول
- `pharmacies` — صيدلية واحدة لكل تاجر.
- `pharmacy_categories` — تصنيفات.
- `pharmacy_products` — منتجات (SKU + باركود + trade/generic + سعر + يحتاج وصفة + threshold).
- `pharmacy_batches` — دفعات (batch_number + expiry + quantity_received/remaining + status).
- `pharmacy_customers` — عملاء (حساسيات JSON + أمراض مزمنة + حمل/إرضاع).
- `pharmacy_sales` — مبيعات (مع prescription + warnings_acknowledged JSON).
- `pharmacy_sale_items` — عناصر (مع تتبّع batch_id المخصوم).
- `pharmacy_stock_alerts` — تنبيهات (low_stock/near_expiry/expired + severity).

### 3 خدمات
1. **PharmacyService** — إنشاء + إدارة منتجات + Batches + عملاء.
2. **PharmacySaleService** (الجوهر، ~210 سطر):
   - فحص المخزون الكلّي.
   - فحص الوصفة للمنتجات التي تستلزمها.
   - **فحص الحساسيات** بمقارنة allergies vs trade/generic name.
   - **`deductFromBatchesFifo()`** — يخصم من أقدم انتهاءً أوّلاً، يوزّع على عدّة batches إن لزم، يقفل المنتج + Batches بـ lockForUpdate.
   - تحديث current_stock تلقائياً.
   - تشغيل checkLowStock بعد كل بيع.
3. **PharmacyAlertService** — تنبيهات low_stock + scan دوري لـ Batches + summary للـ Dashboard.

### Controller — 18 endpoint
```
GET    /pharmacy/dashboard
GET    /pharmacy                          → بيانات الصيدلية
POST   /pharmacy                          → upsert
GET    /pharmacy/products                 → بحث + low_stock filter
POST   /pharmacy/products
PUT    /pharmacy/products/{id}
GET    /pharmacy/products/{id}/batches
POST   /pharmacy/products/{id}/batches
GET    /pharmacy/customers                → بحث
GET    /pharmacy/customers/by-phone       → استدعاء سريع للبيع
POST   /pharmacy/customers
PUT    /pharmacy/customers/{id}
POST   /pharmacy/sales                    → الجوهر (rate 300/دقيقة)
GET    /pharmacy/sales
GET    /pharmacy/alerts
POST   /pharmacy/alerts/scan
POST   /pharmacy/alerts/{id}/dismiss
```

### 13 اختباراً
- ✅ تفرّد الصيدلية لكل تاجر.
- ✅ Batch يزيد current_stock.
- ✅ Batch منتهي يُرفض.
- ✅ **FIFO**: الأقدم انتهاءً يُخصم أوّلاً.
- ✅ **Multi-batch split**: 8 وحدات على 2 batches.
- ✅ رفض مخزون غير كاف.
- ✅ منع البيع بدون رقم وصفة.
- ✅ نجاح البيع برقم وصفة.
- ✅ **حظر بيع** عند تعارض حساسية (Penicillin + Augmentin).
- ✅ تجاوز التحذير عند acknowledgment.
- ✅ إنشاء تنبيه low_stock تلقائياً.
- ✅ كشف Batches منتهية عبر scan.
- ✅ تنبيه near_expiry لـ 15 يوم.

## Flutter

### 7 شاشات
1. **PharmacyDashboardScreen** — Entry point مع إحصائيات اليوم وتحذير عند وجود تنبيهات.
2. **PharmacySaleScreen** (الجوهر، ~570 سطر):
   - شريط هاتف العميل لاستحضار حساسياته.
   - بحث منتجات لحظي.
   - سلّة لحظية مع إجمالي محسوب.
   - **تحذيرات بصرية**:
     - 🟠 السلّة تستلزم وصفة.
     - 🔴 تعارض حساسية مع X منتج.
   - Dialog حساسية يطلب تأكيد التجاوز.
   - 3 طرق دفع.
   - شاشة نجاح بـ ULID للإيصال.
3. **PharmacyProductsScreen** — قائمة + بحث + فلتر low_stock + إضافة + Bottom Sheet Batches.
4. **PharmacyCustomersScreen** — قائمة + Dialog شامل (اسم/هاتف/جنس/حساسيات/أمراض مزمنة).
5. **PharmacyAlertsScreen** — ملخّص بصري + قائمة مرتّبة بالشدّة + dismiss + scan زر.
6. **PharmacySalesHistoryScreen** — قائمة مبيعات مع شارة طريقة دفع.

### الـ Cart logic داخل Controller
- `addToCart` / `removeFromCart` / `clearCart`.
- `cartTotal` getter.
- `cartRequiresPrescription` — يفحص أيّ منتج بـ `requires_prescription`.
- `cartAllergyConflicts` — يحسب لحظياً تعارضات الحساسية بمقارنة اسم المنتج بقائمة حساسيات العميل المحدّد.

## نقطة الوصول
- بطاقة "الصيدلية" في **"خدماتي"** للتجّار (لون بنفسجي للتمييز عن محطة الوقود الأخضر).

## مثال عملي
**سيناريو 1: عميل جديد (بدون تسجيل)**
1. الصيدلي يفتح بيع جديد.
2. يبحث عن "Augmentin" → يضيفه بكمية 2.
3. **تحذير**: السلّة تستلزم وصفة.
4. يدخل رقم الوصفة "RX-2026-100".
5. اختيار "نقد" → تأكيد البيع.
6. FIFO يخصم من أقدم batch (مثلاً batch ينتهي خلال 3 شهور).

**سيناريو 2: عميل مسجّل بحساسية**
1. الصيدلي يدخل هاتف العميل "+967711222" → استحضار.
2. النظام يعرض: "محمد علي • ⚠️ حساسية: Penicillin".
3. الصيدلي يضيف Augmentin (يحتوي Penicillin).
4. السلّة تعرض شريطين تحذيرين:
   - 🟠 يستلزم وصفة.
   - 🔴 تعارض حساسية مع 1 منتج.
5. تأكيد البيع → Dialog حساسية بـ Augmentin يحتوي Penicillin.
6. الصيدلي يختار "تجاوز التحذير" (مسؤوليته).
7. النظام يحفظ acknowledged في `warnings_acknowledged` JSON.

## ملاحظات صدق
- **فحصي بنيوي فقط** (Node). لم أشغّل أيّ اختبار.
- **الحساسيات keyword-based** — لا قاعدة بيانات تفاعلات أدوية حقيقية. تعتمد على مطابقة نصّية للأسماء.
- **إضافة منتج** بسيطة في الواجهة — للمنتجات الحقيقية يُنصح بإدخال SKU + barcode + كل التفاصيل من شاشة منفصلة.
- **لا PDF إيصال** للصيدلية في هذه الجولة.
- **scan التواريخ** يُستدعى يدوياً من شاشة التنبيهات؛ في الإنتاج يجب جدولته في cron.

## ما يبقى لجولات قادمة
1. PDF إيصال صيدلية متخصّص.
2. تصنيفات (Categories) — جدول جاهز، الواجهة غير مبنية.
3. Cron تلقائي لـ scanExpiringBatches.
4. سجل دقيق للأدوية المنظّمة (إن طُلبت من الدولة لاحقاً).
5. تكامل مع باركود scanner.

## الإنجاز التراكمي

| البند | قبل | الآن |
|---|---|---|
| ميزات | 17 | **18** |
| Endpoints | 89+ | **107+** |
| جداول | 31+ | **39+** |
| اختبارات | 63+ | **76+** |
| Services | 52 | **55** |
| شاشات Flutter | 21+ | **27+** |
