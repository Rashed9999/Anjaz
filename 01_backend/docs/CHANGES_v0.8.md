# سجل التغييرات — Amial Pay v0.8 (Admin Panel — Amial Features)

**التاريخ:** 2026-05-16
**النطاق:** AMIAL-ADMIN-001 — لوحة إدارة Blade لميزات v0.7-A
**يفترض:** v0.7-A.1 (Backend) منشورة وتعمل

---

## ملخص

v0.7-A أنشأت الـ API endpoints للأدمن (JSON). v0.8 تضيف **Blade UI** فوقها كي يتمكن الأدمن من إدارة الميزات عبر المتصفح بدلاً من curl.

## الملفات الجديدة

### Controllers (3 جديدة)

| الملف | الغرض |
|---|---|
| `app/Http/Controllers/Admin/ZoneManagementController.php` | إدارة zone للمستخدمين (UI + معالجة form) |
| `app/Http/Controllers/Admin/AuditDecisionsController.php` | عرض audit_decisions مع filters |
| `app/Http/Controllers/Admin/SecurityEventsController.php` | عرض account_security_events + كشف brute-force |

### Controllers المعدّلة (2)

| الملف | التعديل |
|---|---|
| `Admin/LegalTermsController.php` | إضافة `webIndex`, `webCreate`, `webStore`, `webShow` (Blade) — JSON endpoints مُحتفظ بها للـ API |
| `Admin/AccountRecoveryController.php` | إضافة `webIndex`, `webShow`, `webApprove`, `webReject` (Blade) |

### Blade Views (8)

| المسار | الشاشة |
|---|---|
| `admin-views/amial/partials/_sidebar.blade.php` | Partial لإضافة قائمة Amial للـ sidebar |
| `admin-views/amial/zones/index.blade.php` | Stats cards + جدول users + modal لتغيير zone |
| `admin-views/amial/legal/index.blade.php` | قائمة كل الإصدارات + acceptance counts |
| `admin-views/amial/legal/create.blade.php` | نموذج نشر إصدار جديد |
| `admin-views/amial/legal/show.blade.php` | عرض إصدار + إحصاءات قبول |
| `admin-views/amial/recovery/index.blade.php` | tabs بحسب الحالة + جدول الطلبات |
| `admin-views/amial/recovery/show.blade.php` | تفاصيل + risk score + approve/reject modals |
| `admin-views/amial/audit/index.blade.php` | Stats 24h + filters + جدول audit_decisions |
| `admin-views/amial/security_events/index.blade.php` | كشف brute-force + جدول الأحداث |

### Routes (1)

| الملف | الغرض |
|---|---|
| `routes/admin/amial.php` | 13 admin route لكل ميزات Amial |

### Files المعدّلة

| الملف | التعديل |
|---|---|
| `bootstrap/app.php` | تسجيل `routes/admin/amial.php` مع middleware `['web', 'admin']` و prefix `admin/amial` |

---

## Routes المُضافة

| Method | URL | Name |
|---|---|---|
| GET | `/admin/amial/zones` | `admin.amial.zones.index` |
| POST | `/admin/amial/zones/update` | `admin.amial.zones.update` |
| GET | `/admin/amial/legal` | `admin.amial.legal.index` |
| GET | `/admin/amial/legal/create` | `admin.amial.legal.create` |
| POST | `/admin/amial/legal` | `admin.amial.legal.store` |
| GET | `/admin/amial/legal/{id}` | `admin.amial.legal.show` |
| GET | `/admin/amial/recovery` | `admin.amial.recovery.index` |
| GET | `/admin/amial/recovery/{ulid}` | `admin.amial.recovery.show` |
| POST | `/admin/amial/recovery/{ulid}/approve` | `admin.amial.recovery.approve` |
| POST | `/admin/amial/recovery/{ulid}/reject` | `admin.amial.recovery.reject` |
| GET | `/admin/amial/audit` | `admin.amial.audit.index` |
| GET | `/admin/amial/security-events` | `admin.amial.security-events.index` |

---

## خطوة دمج إلزامية: Sidebar

أضف هذا السطر داخل الـ `<ul>` الرئيسي في `resources/views/layouts/admin/partials/_sidebar.blade.php`:

```blade
@include('admin-views.amial.partials._sidebar')
```

الموضع المُوصى به: **بعد** قسم "user management" الموجود، حتى تظهر مجموعة Amial كقسم منفصل في الأسفل.

---

## معايير القبول — التحديث

| # | المعيار | حالة v0.7 | حالة v0.8 |
|---|---|---|---|
| 6 | كل عملية خارج SOUTH ترفض | ✅ | ✅ + admin UI لإدارة zones |
| 7 | قبول السياسة | ✅ | ✅ + admin UI لنشر إصدارات |
| | Admin يستطيع مراجعة Recovery | ⚠️ (API فقط) | ✅ Blade UI كامل |
| | Admin يرى audit log | ⚠️ (DB فقط) | ✅ Blade UI مع filters |
| | كشف brute-force PIN attempts | ❌ | ✅ Banner تلقائي |

---

## نسبة التطور الجديدة

```
v0.7-CD: ████████████████░░░░  60%
v0.8:    ███████████████████░  75%
```

| القسم | قبل | بعد |
|---|---|---|
| Section 21 (Admin Panel) | 25% | **75%** |
| Section 23 (Acceptance criteria) | 67% | **80%** |
| Section 13 (Merchant verification UI) | 0% | **40%** (Zone+Legal+Recovery موجودة، Merchant verification يحتاج merchant source) |

---

## التحقق بعد النشر

```bash
# 1. routes:list يظهر الـ admin routes الجديدة
php artisan route:list | grep "admin.amial"
# يتوقع: 13 سطر

# 2. تسجيل دخول كـ admin وافتح:
#    https://yourdomain.com/admin/amial/zones
#    https://yourdomain.com/admin/amial/legal
#    https://yourdomain.com/admin/amial/recovery
#    https://yourdomain.com/admin/amial/audit
#    https://yourdomain.com/admin/amial/security-events

# 3. اختبار workflow كامل:
#    a. افتح /admin/amial/legal/create
#    b. انشر إصدار v1.0 من السياسة
#    c. افتح /admin/amial/legal — يجب أن يظهر الإصدار كـ "Current"
#    d. افتح /admin/amial/zones — اختر مستخدم وغيّر zone من SOUTH لـ NORTH
#    e. افتح /admin/amial/audit — يجب أن ترى قرار ZONE_CHANGED
```

---

## ما لم يُنجَز (يحتاج رفع كود الموردين)

| البند | السبب |
|---|---|
| Merchant verification UI | يحتاج كود التاجر المُشترى |
| POS users management | يحتاج كود التاجر |
| Safe payment dispute UI | يحتاج merchant module |
| Merchant ledger UI | يحتاج merchant module |
| Receipts management | يحتاج عمل backend (v0.9) |
| Bill providers management | يحتاج عمل backend (v0.9) |

هذه الميزات ستضاف في v0.9 و v1.0 بعد توفر كود التاجر/الوكيل.
