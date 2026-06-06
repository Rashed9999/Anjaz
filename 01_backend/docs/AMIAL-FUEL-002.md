# CHANGELOG — AMIAL-FUEL-002 (الجولة الثانية الكاملة)

**التاريخ:** 2026-05-31  
**الإصدار:** Backend v2.30 + Flutter v2.22

## الميزات الـ6 المُضافة في هذه الجولة

### 1. ✅ شاشات الشركات Flutter (قائمة + سداد + إضافة)
- `FuelCompaniesScreen` — قائمة كل الشركات مع شريط دَيْن مرئي.
- مؤشّر بصري (أحمر/أخضر) عند تجاوز 80% من الحد الائتماني.
- زر "سداد" يُغلق تلقائياً عند الدَّيْن صفر.
- Dialog لإضافة شركة جديدة + Dialog للسداد.

### 2. ✅ بطاقات الشركات (Cards) — نظام كامل
**Backend:**
- جدول `fuel_company_cards`: رقم البطاقة + سائق + سيارة + حد يومي/شهري.
- `FuelCompanyCardService` مع تحقّق `assertCardLimits()`:
  - يجمع مصاريف اليوم/الشهر لتلك البطاقة.
  - يرفض إن سيتجاوز الحد.
- 3 endpoints: list, add, update.

**Flutter:**
- Bottom Sheet لكل شركة يعرض بطاقاتها.
- Dialog لإضافة بطاقة (6 حقول: رقم + وصف + سائق + سيارة + حدود).

### 3. ✅ شاشة سجل المبيعات (FuelSalesHistoryScreen)
- قائمة كاملة للمبيعات مع أيقونات ملوّنة لكل طريقة دفع.
- **فلتر شريطي** بأربعة خيارات (الكل / نقدي / أميال باي / شركات).
- Bottom Sheet لتفاصيل البيع.
- **زر تنزيل إيصال PDF** يولّد رابط `/sales/{ulid}/receipt`.
- Pull-to-refresh + تحميل ديناميكي.

### 4. ✅ نظام النوبات (Shifts) — شامل
**Backend:**
- جدول `fuel_shifts`: opened/closed + opening_cash + جميع المجاميع.
- جدول `fuel_shift_pump_summaries`: ملخّص لكل مضخّة (عدّاد افتتاحي/نهائي).
- `FuelShiftService`:
  - `openShift()`: يلتقط قراءات العدّاد الافتتاحية تلقائياً لكل المضخّات النشطة.
  - **`closeShift()`** الجوهر:
    - يجمع كل مبيعات النوبة (cash, amial_pay, company).
    - يحسب `expected_cash = opening_cash + total_cash_sales`.
    - `variance = actual_cash − expected_cash` (موجب = فائض، سالب = عجز).
    - يقارن `recorded_liters` بـ `expected_liters` (من العدّاد) لكل مضخّة.
    - يحدّث `current_meter_reading` للمضخّات تلقائياً.
- 5 endpoints: current, open, close, list, show.

**Flutter:**
- `FuelShiftsScreen` موحّدة تعرض:
  - **بطاقة النوبة الحالية** خضراء (إن مفتوحة) مع زمن مستمر.
  - **زر فتح نوبة** كبير (إن لا توجد مفتوحة).
  - Dialog فتح: النقد الافتتاحي + ملاحظة.
  - **Dialog إغلاق متقدّم**: قراءات العدّاد لكل مضخّة + النقد الفعلي + سبب الفرق + ملاحظات.
  - **Dialog نتيجة الإغلاق** ملوّن (أخضر/أزرق/أحمر) حسب variance.
  - سجل النوبات مع ألوان (مطابق/فائض/عجز).
  - أيقونة تحذير ⚠️ للنوبات التي تحتاج مراجعة إدارية.

### 5. ✅ PDF إيصال بيع وقود متخصّص
- View Blade `pdf/fuel-sale-receipt.blade.php`:
  - مقاس A5 (إيصال أصغر، يناسب الطباعة الحرارية).
  - رأس المحطة + رخصة.
  - **بطاقة المبلغ الكبير** بلون أصفر بارز.
  - بانر اللترات بأزرق.
  - شارة طريقة الدفع ملوّنة.
  - معلومات السيارة/السائق/الشركة/البطاقة.
  - قراءات العدّاد قبل/بعد.
  - رمز تحقّق + Branding أميال باي.
- `FuelReceiptPdfService` + endpoint `GET /sales/{ulid}/receipt`.

### 6. ✅ **العجز والفائض (Variance) — نظام كامل**
**Backend:**
- جدول `fuel_variance_records`:
  - `variance_type`: cash_variance | liters_variance.
  - `direction`: shortage (عجز) | surplus (فائض).
  - `amount` قيمة الفرق (موجبة دائماً).
  - `resolution_status`: pending → accepted | covered_by_employee | charged_to_petty_cash | written_off.
- **Logic في `FuelShiftService::closeShift()`**:
  - **عجز نقدي**: actual < expected → ينشئ record بـ direction=shortage.
  - **فائض نقدي**: actual > expected → record بـ direction=surplus.
  - **عجز/فائض اللترات لكل مضخّة**: recorded vs expected (من العدّاد).
- **عتبات الموافقة الإدارية**:
  - نقد: > 500 ر.ي + > 2% من المبيعات → `requires_admin_review=true`.
  - لترات: > 5 لتر → سجل variance تلقائي.
- `resolveVariance()` للأدمن: 4 قرارات حلّ.

**Flutter:**
- يظهر تلقائياً في **Dialog نتيجة الإغلاق**:
  - **مطابق**: ✓ أخضر.
  - **فائض**: ⬆️ أزرق مع المبلغ.
  - **عجز**: ⬇️ أحمر مع المبلغ.
  - تفصيل: المتوقّع/الفعلي + كل طرق الدفع + إجمالي اللترات.
  - تحذير "يتطلّب مراجعة إدارية" إن variance كبير.

## الإحصاءات النهائية

| المقياس | قيمة |
|---|---|
| Migrations جديدة | +1 (4 جداول) |
| Models جديدة | +4 |
| Services جديدة | +3 |
| Endpoints جديدة | +13 |
| اختبارات جديدة | +10 (نوبات + variance) |
| شاشات Flutter جديدة | +3 |

## أمثلة عملية على العجز/الفائض

### مثال 1: نوبة مطابقة
```
opening_cash:    5,000
مبيعات نقد:    20,000
expected_cash:  25,000
actual_cash:    25,000
variance:            0  ✓ مطابق
```

### مثال 2: عجز كبير (يحتاج موافقة)
```
opening_cash:        0
مبيعات نقد:     20,000
expected:        20,000
actual:          18,500
variance:        -1,500  ⬇️ عجز
→ requires_admin_review = true (> 500 و > 2%)
→ ينشئ variance_record direction=shortage
```

### مثال 3: فقد لترات (مشكلة كبيرة)
```
العدّاد الافتتاحي:   1,000
العدّاد النهائي:     1,100
expected_liters:        100  ← استُهلكت من المضخّة
recorded_liters:         85  ← مُسجَّل في النظام
liters_variance:        -15  ⚠️ 15 لتر مفقود!
→ ينشئ variance_record type=liters_variance direction=shortage
→ تنبيه قوي: قد يدلّ على سرقة/تسريب
```

## بنية شاشة محطة الوقود النهائية

```
لوحة المحطة (Dashboard)
├── إحصائيات اليوم (auto-refresh)
├── 🟢 بيع جديد         → FuelSaleScreen (الجوهر اليومي)
├── 📅 النوبات           → FuelShiftsScreen (فتح/إغلاق + العجز)
├── 🏢 الشركات           → FuelCompaniesScreen (سداد + بطاقات)
├── 📜 سجل المبيعات      → FuelSalesHistoryScreen (PDF)
└── ⚙️ الإعدادات          → FuelSettingsScreen (مضخّات + أنواع)
```

## للتحقّق
```bash
php artisan migrate
php artisan test --filter=FuelShiftTest      # 10 اختبارات
php artisan test --filter=FuelStationTest    # 13 اختبار
flutter analyze lib/features/fuel_station/
```

## ملاحظات صدق

1. **فحصي بنيوي فقط** (Node). لم أشغّل اختبارات.
2. **زر تنزيل PDF** ينسخ الـ URL للحافظة فقط — يحتاج المستخدم فتح المتصفّح يدوياً (تكامل `url_launcher` لاحقاً).
3. **الـ Dialog لإغلاق النوبة** يحتاج `flutter_keyboard_visibility` للأداء الأفضل على الشاشات الصغيرة (اختياري).
4. **شاشة Admin Review للـ variances** غير مبنية — endpoints جاهزة، يمكن استخدام Admin Panel من Cash6.

## ما يبقى للجولات القادمة (v3 للوقود)

1. تكامل المضخّات الإلكترونية (Real-time meter API).
2. تقارير شهرية/سنوية للمحطة.
3. تنبيهات Push عند تجاوز حدود البطاقات.
4. تنبيه Admin بـ Push عند variance كبير.
5. تصدير Excel للنوبات والمبيعات.
6. شاشة "تحذيرات النشاط المشبوه" (نمط عجز متكرّر، إلخ).
