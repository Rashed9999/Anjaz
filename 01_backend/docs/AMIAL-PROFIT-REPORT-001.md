# AMIAL-PROFIT-REPORT-001 — تقرير الأرباح في لوحة الأدمن

## الفكرة
يُغلق حلقة "لوحة تحكم الأرباح": بعد ضبط النسب وربطها، الآن ترى الإدارة
الأرباح المتولّدة فعلاً — من نفس بيانات الرسوم (AMIAL-FEE-ENGINE-001).

## الملفات
| النوع | المسار |
|---|---|
| MERGE | `app/Http/Controllers/Admin/FeeSchemeController.php` (دالة `webProfit`) |
| MERGE | `routes/admin/amial.php` (مسار `fees/profit`) |
| ADD | `resources/views/admin-views/amial/fees/profit.blade.php` |
| MERGE | `resources/views/admin-views/amial/fees/index.blade.php` (زر التقرير) |
| ADD | `tests/Feature/ProfitReportTest.php` (اختباران) |

## ما يعرضه (admin/amial/fees/profit)
- **الربح الصافي (الفترة)** = مجموع قيود الأدمن (بعد حصة الوكلاء).
- **إجمالي الرسوم (الفترة)** = SUM(charge) على صفوف العمليات.
- **عمولات الوكلاء** = الإجمالي − الصافي.
- **ربح اليوم** + **الربح التراكمي** (charge_earned لمحفظة الأدمن).
- **تفصيل حسب نوع العملية** (تحويل، سحب، دفع تاجر، دفع آمن، تقسيم...).
- فلتر نطاق زمني (افتراضي: هذا الشهر).

## المصادر (دقيقة وقابلة للتحقق)
- الصافي التراكمي: `e_money.charge_earned` للأدمن.
- الفترة: قيود `ADMIN_CHARGE` (صافي) و`charge>0` (إجمالي) ضمن النطاق.

## التحقق (صدق)
- ✅ فحص بنيوي ناجح (PHP + Blade).
- ⚠️ **لم تُشغَّل الاختبارات** (لا PHP). شغّل: `php artisan test --filter=ProfitReport`.
- إجمالي اختبارات سلسلة الرسوم: **40**.
