# 📊 اللوحة التنفيذية العليا (Executive Dashboard)

رؤية شاملة للنظام لحظة بلحظة في **شاشة واحدة** للإدارة العليا — بدل توزيع
المؤشّرات على صفحات منفصلة.

## الوصول
- لوحة الأدمن: **Amial Pay → Executive Dashboard** (`/admin/amial/executive`)
- JSON API (لتطبيق إدارة Flutter): `GET /admin/amial/executive/summary`

## المؤشّرات المعروضة

| المؤشّر | المصدر |
|---|---|
| 💰 إجمالي أرصدة المحافظ | `e_money.current_balance` (SUM) |
| 💳 حجم المدفوعات اليوم (عدد + قيمة) | `transactions` (اليوم) |
| 🛒 عدد عمليات الشراء | `merchant_sales` + `fuel_sales` + `pharmacy_sales` + `wholesale_invoices` |
| 🏪 أكثر التجار نشاطاً | `transactions` ↔ `users(type=1)` (top 5) |
| ⛽ أكثر محطات الوقود مبيعاً | `fuel_sales` ↔ `fuel_stations` (top 5) |
| 👥 المستخدمون النشطون اليوم | `users.last_active_at` |
| 🆕 المستخدمون الجدد | `users.created_at` (اليوم) |
| ⚠️ التنبيهات الأمنية | `sentinel_events` + `account_security_events` (حرجة) |
| 🚫 الحسابات الموقوفة | `users.security_hold_until > now` |
| 📈 الإيرادات | `platform_fee_entries` + `charge_earned` + اشتراكات حسب الباقة |
| 🖥️ حالة الخوادم/API | فحوص DB + Cache + Queue حيّة |

## المكوّنات

| الملف | الدور |
|---|---|
| `app/Services/ExecutiveDashboardService.php` | تجميع كل المؤشّرات (fail-safe) |
| `app/Http/Controllers/Admin/ExecutiveDashboardController.php` | Blade + JSON API |
| `resources/views/admin-views/amial/executive/index.blade.php` | الواجهة (تحديث تلقائي 60s) |
| `routes/admin/amial.php` | المسارات (`admin.amial.executive.*`) |
| `tests/Feature/ExecutiveDashboardTest.php` | اختبارات التجميع |

## مبدأ التصميم
كل مؤشّر معزول في `try/catch` ويُرجع قيمة آمنة (0/`—`) إن غاب جدوله — لذا
تعمل اللوحة **حتى قبل دمج كامل قاعدة 6cash**، وتمتلئ المؤشّرات تلقائياً كلما
توفّرت بياناتها. التحديث اللحظي عبر `<meta refresh>` كل 60 ثانية (يمكن لاحقاً
ترقيته إلى polling على `/summary` أو WebSockets).
