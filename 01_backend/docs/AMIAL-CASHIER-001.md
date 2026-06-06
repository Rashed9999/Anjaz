# AMIAL-CASHIER-001 — كاشير خفيف داخل دور التاجر

## الفلسفة
لا منطق مالي جديد. الدفع يعيد استخدام `merchant_payment` القائم. الكاشير طبقة بيع
فوقه: **جدولان فقط**، السلة في التطبيق (لا جدول خادم)، التقرير اليومي استعلامات.
عام لكل التصنيفات (مطعم/جملة/صيدلية...) عبر حقل category حرّ — بلا افتراضات نوع.

## الملفات (backend)
| النوع | المسار |
|---|---|
| ADD | `database/migrations/2026_05_23_170001_amial_create_cashier.php` |
| ADD | `app/Models/MerchantProduct.php`, `app/Models/MerchantSale.php` |
| ADD | `app/Services/CashierService.php` |
| ADD | `app/Http/Controllers/Api/V1/Amial/CashierController.php` |
| MERGE | `routes/api/amial.php` (مجموعة `merchant/cashier`) |
| ADD | `tests/Feature/CashierTest.php` (6 اختبارات) |

## طرق الدفع (حرية التاجر قبل الدفع)
- **نقد (cash)**: يُسجَّل completed فوراً — لا حركة مال.
- **أجل (credit)**: يُسجَّل credit_unpaid مع اسم/رقم العميل (يرقمن دفتر الديون).
  تسوية لاحقة عبر `sales/{id}/settle`.
- **أميال باي (amial_pay)**: العميل يدفع عبر مسار دفع التاجر القائم، والتطبيق
  يمرّر `paid_transaction_id` لربط البيع — لا منطق مالي جديد.

## API
```
GET  merchant/cashier/products            POST merchant/cashier/products
PUT  merchant/cashier/products/{id}
POST merchant/cashier/sales               {total, payment_method, items?, customer?, paid_transaction_id?}
POST merchant/cashier/sales/{id}/settle   GET merchant/cashier/report?date=
```

## التقرير اليومي
عدد المبيعات، الإجمالي، **الإيراد الفعلي (نقد + أميال باي)**، تفصيل حسب الطريقة،
**إجمالي الأجل المستحق**، وأكثر 5 منتجات مبيعاً.

## ملاحظات تصميم
- المنتجات **اختيارية**: بيع بمبلغ حرّ مدعوم (يحلّ قاتل التبنّي — لن يُدخل البقّال
  مئات الأصناف يدوياً).
- **أونلاين-أولاً**؛ الأوفلاين (سجل محلي + مزامنة) مرحلة ثانية منفصلة.
  تنبيه: الدفع لا يكون أوفلاين أبداً (تأكيد الدفع يحتاج الخادم).

## يتبقّى (Flutter — مرحلة قادمة)
شاشات: كتالوج المنتجات، شاشة البيع/السلة مع اختيار طريقة الدفع، سجل المبيعات،
التقرير اليومي. كلها فوق بنية دفع التاجر الموجودة.

## التحقق (صدق)
- ✅ فحص بنيوي ناجح. ⚠️ لم تُشغَّل الاختبارات (لا PHP). شغّل `php artisan test --filter=Cashier`.

---

# شاشات التطبيق (Flutter v2.12)

| النوع | المسار |
|---|---|
| ADD | `lib/features/merchant/domain/repositories/cashier_repo.dart` |
| ADD | `lib/features/merchant/controllers/cashier_controller.dart` |
| ADD | `lib/features/merchant/screens/cashier_sale_screen.dart` (البيع + السلة + اختيار الدفع) |
| ADD | `lib/features/merchant/screens/cashier_products_screen.dart` (الكتالوج) |
| ADD | `lib/features/merchant/screens/cashier_report_screen.dart` (التقرير اليومي) |
| MERGE | `lib/helper/get_di.dart` |

- شاشة البيع: مبلغ حرّ أو منتجات سريعة → سلة → اختيار الدفع (نقد/أجل/أميال باي).
  - نقد/أجل يُسجَّلان فوراً؛ الأجل يطلب بيانات العميل.
  - أميال باي يوجّه العميل للدفع عبر QR (مسار دفع التاجر الموجود).
- السلة حالة محلية فقط (لا جدول خادم) — خفيف.

## يتبقّى عليك
زر فتح "الكاشير" في واجهة التاجر/POS → `CashierSaleScreen`.

## التحقق (صدق)
- ✅ فحص بنيوي ناجح. ⚠️ لم يُشغَّل `flutter analyze` (لا Flutter في البيئة).

---

# توسعة v2 — حقول المنتج + ربط أميال باي التلقائي

## حقول المنتج الجديدة
| الحقل | الوصف |
|---|---|
| quantity | المخزون (يدعم كسور) — يُخصم تلقائياً عند بيع عنصر يحمل product_id |
| production_date / expiry_date | الإنتاج والانتهاء (تنبيه أحمر قبل 30 يوماً) — مهم للصيدليات/الأغذية |
| cost_price | التكلفة (للهامش) |
| price | سعر البيع الأساسي |
| offer_price | سعر العرض — السعر الفعّال = العرض إن وُجد وإلا البيع |

## ربط أميال باي التلقائي
- بيع amial_pay بلا مرجع دفع → حالة `pending_payment` بمرجع `sale_ulid`.
- عند دفع العميل عبر `merchant/pay` بتمرير `sale_ulid` → يُربط البيع تلقائياً
  (status=completed + paid_transaction_id) ويُخصم المخزون.
- آمن: ربط غير مطابق يُتجاهل (null).

## التطبيق
- حوار المنتج: الكمية، التكلفة، البيع، العرض، الإنتاج، الانتهاء.
- العرض: المخزون، تنبيه الانتهاء الأحمر، السعر مع شطب الأصلي عند وجود عرض.
- الكاشير amial_pay: ينشئ بيعاً معلّقاً ويعرض مرجعه.

## يتبقّى عليك (قرارات/تشغيل)
1. **زر فتح الكاشير** في واجهة التاجر/POS → `CashierSaleScreen` (مكانه قرارك).
2. **شاشة مسح QR للعميل** تمرّر `sale_ulid` لإكمال الربط التلقائي بصرياً
   (الـ backend جاهز؛ ينقص ربط الماسح في تطبيق العميل).
3. **تشغيل البناء** (لا أستطيعه — لا PHP/Flutter في بيئتي):
   `php artisan migrate && php artisan test --filter=Cashier` و `flutter analyze`.

## التحقق (صدق)
- ✅ فحص بنيوي ناجح لكل ملفات PHP وDart. 9 اختبارات للكاشير.
- ⚠️ لم تُشغَّل الاختبارات/التحليل (لا PHP/Flutter في البيئة).
