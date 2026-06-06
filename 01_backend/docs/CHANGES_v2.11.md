# سجل التغييرات — v2.11 (نظام التقارير والتصدير)

**التاريخ:** 2026-05-23 | **النطاق:** AMIAL-REPORTS-001

---

## السياق: سؤالك كشف فجوة حقيقية

سألت عن "إيصالات PDF والتقارير". الفحص كشف:

| | الحالة قبل v2.11 |
|---|---|
| **إيصالات PDF** | ✅ مكتملة (v0.9) — توليد + QR + تحقق + تخزين |
| **التقارير/التصدير** | ❌ غير موجودة (رغم طلب الوثيقة الأصلية) |

**الفرق الجوهري:**
```
إيصال  =  وثيقة لعملية واحدة          ✅ كان موجوداً
تقرير  =  تجميع وتصدير لعمليات كثيرة   ❌ بُني الآن
```

الوثيقة الأصلية طلبت: "تقارير الأداء والتصدير" + "التصدير CSV/Excel/PDF
للتقارير الكبيرة عبر queue". هذا ما بنيناه في v2.11.

---

## ما بُني

### 5 أنواع تقارير

| التقرير | لمن | المحتوى |
|---|---|---|
| `merchant_ledger` | التاجر | دفتره المحاسبي (من الـ ledger الفعلي) |
| `user_transactions` | المستخدم | كشف عملياته |
| `platform_performance` | الأدمن | مؤشرات المنصة |
| `aml_compliance` | الأدمن/البنك المركزي | قرارات AML غير العادية |
| `agent_settlement` | الأدمن | تسويات الوكلاء |

### 3 صيغ

- **CSV** (مع BOM لدعم العربية في Excel) — الافتراضي، أخف
- **PDF** (عبر dompdf الموجود) — بهوية أميال باي
- **Excel** (CSV متوافق)

### المبدأ المعماري: عبر queue

```
المستخدم يطلب تقرير
  → ReportExport (status: pending)
  → GenerateReportJob (queue — الخلفية)
  → التوليد لا يبطئ النظام ولا يستهلك ذاكرته
  → status: ready
  → المستخدم يحمّله
```

من الوثيقة حرفياً: "التقارير الكبيرة عبر queue". ✓

### دورة حياة التقرير

```
pending → processing → ready → (تحميل) → expired (بعد 7 أيام، يُحذف تلقائياً)
                          ↓
                       failed (مع رسالة خطأ)
```

---

## API Endpoints

```
GET  /api/v1/amial/reports                  → قائمة تقاريري
POST /api/v1/amial/reports/request          → طلب تقرير (→ queue)
GET  /api/v1/amial/reports/{ulid}/status    → حالة التوليد
GET  /api/v1/amial/reports/{ulid}/download  → تحميل عند الجاهزية
```

### مثال الطلب

```json
POST /reports/request
{
  "report_type": "merchant_ledger",
  "format": "csv",
  "from": "2026-05-01",
  "to": "2026-05-31"
}
→ { "export_ulid": "...", "status": "pending",
    "message": "يُولَّد في الخلفية..." }
```

### الصلاحيات

التقارير الإدارية (`platform_performance`, `aml_compliance`, `agent_settlement`)
للأدمن فقط (type=0). محاولة مستخدم عادي → 403.

---

## المكوّنات

| المكوّن | الوظيفة |
|---|---|
| `report_exports` (جدول) | تتبّع كل طلب تقرير |
| `ReportExport` model | + isReady() + isExpired() |
| `ReportService` | بناء البيانات + كتابة CSV/PDF + تنظيف |
| `GenerateReportJob` | التوليد في الخلفية (timeout 5 دقائق) |
| `ReportController` | request/status/download/index |
| `reports/pdf.blade.php` | قالب PDF بهوية أميال باي |
| تنظيف مجدول | يومي — يحذف المنتهية (7 أيام) |

---

## Tests (10)

### `ReportServiceTest.php`

| Test | يثبت |
|---|---|
| `it_creates_report_request` | إنشاء الطلب |
| `it_rejects_invalid_report_type` | التحقق |
| `it_rejects_invalid_format` | الصيغ |
| `it_generates_user_transactions_csv` | التوليد الفعلي ⭐ |
| `csv_has_bom_for_arabic` | دعم العربية في Excel ⭐ |
| `platform_performance_report_works` | تقرير الأدمن |
| `failed_generation_marks_status` | معالجة الفشل |
| `cleanup_removes_expired_reports` | التنظيف |
| `is_ready_checks_expiry` | الصلاحية |
| `merchant_ledger_report_type_is_valid` | دفتر التاجر |

---

## النشر السريع v2.11

```bash
php artisan migrate
php artisan test --filter="ReportService"

# تأكد أن queue worker يعمل (التوليد في الخلفية):
php artisan queue:work
```

⚠️ **يحتاج queue worker** (Supervisor) — التقارير تُولّد في الخلفية.

---

## النسبة الإجمالية

```
v2.10:  ██████████████████████████████ 100%
v2.11:  ██████████████████████████████ 100% (+ التقارير)
```

### Total Tests

```
v0.6-v2.10: ~288
v2.11:      10 ← جديد
───────────
المجموع: ~298 test
```

---

## الإجابة الكاملة على سؤالك

### إيصالات PDF؟
✅ **موجودة ومكتملة** — كل عملية ناجحة لها إيصال PDF بـ QR وكود تحقق،
قابل للتحميل والمشاركة والتحقق العام. (لم تكن تحتاج عملاً.)

### التقارير؟
✅ **بُنيت الآن** — 5 أنواع، 3 صيغ، عبر queue، مع صلاحيات وتنظيف تلقائي.

---

## لماذا التقارير مهمة (ليست رفاهية)

| السبب | التفصيل |
|---|---|
| **معيار قبول** | الوثيقة الأصلية طلبتها صراحة |
| **حاجة التاجر** | يحتاج دفتره كـ Excel لمحاسبته |
| **حاجة الأدمن** | متابعة الأداء واتخاذ القرارات |
| **الترخيص** ⭐ | البنك المركزي سيطلب تقارير AML/امتثال دورية |

النقطة الأخيرة مهمة: `aml_compliance` report ليس للاستخدام الداخلي فقط —
عند الترخيص، الجهات التنظيمية تطلب تقارير دورية عن العمليات المشبوهة.
بنيناه جاهزاً لذلك.

---

## الصدق المعتاد

**لم أشغّل الـ 298 اختبار** — بيئتي بلا PHP. تحققت من البنية وتوازن الأقواس.
وفقاً لقاعدة المشروع: ادعاءات حتى تشغّلها أنت.

```bash
php artisan test --filter="ReportService"
```
