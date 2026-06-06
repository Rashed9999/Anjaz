# AMIAL-CUSTOMER-CREDIT-001 — نظام ديون العملاء

## الغرض
يحلّ مشكلة "دفتر الديون" التقليدي للتاجر اليمني. كل عميل آجل عند التاجر له حساب ائتماني دائم، سجل حركات بأرصدة جارية، تصنيف تلقائي، وتكامل مباشر مع الكاشير.

## ما اكتمل

### Backend
- **Migration**: `customer_credit_accounts` + `customer_credit_movements` (append-only).
- **Models**: `CustomerCreditAccount` (utilization، over_limit) + `CustomerCreditMovement`.
- **خدمة `CustomerCreditService`**:
  - `findOrCreateAccount` — يربط تلقائياً بمستخدم أميال باي إن طابق رقمه.
  - `recordSale` / `recordPayment` / `recordReturn` / `recordAdjustment`.
  - `getStatement(from, to)` — افتتاحي + حركات + إجماليات + إقفال.
  - `calculateClassification` — gold/silver/bronze من السلوك (آخر سداد + نسبة استهلاك).
  - `dashboardSummary` + `listCustomers(search, filter)`.
- **التكامل مع `CashierService`**: بيع آجل في الكاشير يُنشئ/يجد الحساب ويسجّل movement تلقائياً مع `due_date`.
- **متحكّم + 9 endpoints**: dashboard، customers (CRUD)، statement، payment، return، adjustment.
- **اختبارات**: 9 جديدة لنظام الديون + 1 اختبار تكامل في `CashierTest`.

### Frontend (Flutter)
- `customer_credit_repo.dart` + `customer_credit_controller.dart` (GetX).
- **4 شاشات**:
  - `credit_dashboard_screen.dart` — إجمالي الديون، مدينون، تجاوز الحد، توزيع تصنيف.
  - `credit_customers_screen.dart` — بحث + 4 فلاتر + بطاقات بشريط استهلاك ملوّن.
  - `credit_customer_statement_screen.dart` — رصيد + تصنيف + فلتر تاريخ + حركات + 3 عمليات.
  - `credit_add_customer_dialog.dart` — حوار إضافة عميل.
- تسجيل DI في `get_di.dart`.

## القرارات التصميمية

- **الهاتف معرّف العميل** (وليس user_id) — كثير من العملاء لن يكونوا مستخدمين مسجّلين.
- **append-only ledger مع snapshot للرصيد** — كشف حساب رخيص + تدقيق سهل.
- **DB::transaction + lockForUpdate** لكل تعديل على current_balance.
- **التصنيف يُحسب تلقائياً** بعد كل حركة (gold: <30 يوم سداد + <60% استهلاك).
- **منع السداد > الرصيد** — حماية من خطأ المستخدم.
- **القاعدة القديمة `customer_name/phone` في `merchant_sales` تبقى** للتوافق الخلفي. النظام الجديد canonical للديون.

## ما لم يُبنَ (مؤجَّل بقرار)

- تذكيرات SMS — لا مزوّد محدّد بعد.
- ضمانات بيع الأجل (بطاقة شخصية، توقيع).
- تنبيهات تجاوز الحد التلقائية (push).
- تخصيص نص الرسالة + طلب سداد بإشعار.
- تصدير PDF لكشف الحساب (موجود backend عام، يُربط لاحقاً).
- UI لإعداد حد ائتماني افتراضي للتاجر.

## ما يبقى عليك للتحقّق
**أنا لم أشغّل** `php artisan test` ولا `flutter analyze` (لا بيئة لديّ). الفحص لديّ كان **بنيوياً فقط** (توازن الأقواس).

في بيئتك:
```bash
# Backend
cd amial_pay_working_copy
php artisan migrate
php artisan test --filter=CustomerCredit
php artisan test --filter=CashierTest

# Flutter
cd amyal_pay_user_app
flutter analyze lib/features/merchant/
```

## نقاط الربط مع الواجهة الرئيسية

أضف زر "إدارة الديون" في شاشة التاجر الرئيسية:
```dart
Get.to(() => const CreditDashboardScreen());
```

## مراجع
- وثيقة المتطلبات: قسم §15 (الفاتورة المقسّمة)، §16 (الدفتر المحاسبي للتاجر).
- الشاشات المرجعية: 59، 60، 61-63، 64، 79، 84.
