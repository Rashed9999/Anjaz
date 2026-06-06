# CRITICAL-001-ADMIN — Admin Panel

**التاريخ:** 2026-06-03  
**الإصدار:** Backend v2.35 + Flutter v2.28

## الهدف
بدون Admin Panel، endpoints `/admin/*` غير قابلة للاستخدام عملياً. هذه الجولة تُكمل **الحلقة المفقودة** بين الإجراءات الإدارية والـ Backend الجاهز.

## Backend

### AdminPanelController جديد — 5 endpoints
```
GET  /admin/dashboard                  ← إحصائيات (تجار + قيد التوثيق + variances)
GET  /admin/merchants                  ← قائمة + بحث + فلاتر (plan/business_type/verification)
GET  /admin/variances/pending          ← العجز/الفائض قيد المراجعة
POST /admin/variances/{id}/resolve     ← حلّ variance (4 خيارات)
POST /admin/merchants/{id}/verify      ← توثيق سريع (approve/reject/resubmission)
```

كلها محميّة بفحص `isAdmin($user)` (`role === 'admin'` أو `type === 1`).

## Flutter

### 3 ملفات
1. **AdminRepo** — استدعاءات HTTP لكل endpoints.
2. **AdminController** — state management:
   - dashboardData, merchants, variances (Rx).
   - فلاتر بحث (plan, business_type, verification, search).
   - `loadDashboard`, `loadMerchants`, `loadPendingVariances`.
   - `updateMerchantPlan`, `verifyMerchant`, `resolveVariance`.
3. **AdminDashboardScreen** — موحّدة بـ 3 تبويبات:
   - **Tab 1 (التجار):** قائمة + بحث + bottom sheet لتغيير الخطّة وتوثيق.
   - **Tab 2 (العجز/الفائض):** قائمة pending + dialog حلّ بـ 4 خيارات.
   - **Tab 3 (إحصائيات):** أرقام إجمالية + توزيع بالخطّة + بـ business_type.

### ربط الـ Routing
- `HomeDispatcher` الآن يكتشف `isAdmin` ويوجّه مباشرة إلى `AdminDashboardScreen`.
- AdminController مسجّل lazyPut + fenix (يُعاد بناؤه عند الحاجة).

## التدفّق العملي الكامل

**سيناريو:** تاجر اشترى خطّة STARTER (15 ر.س) ودفع عبر التحويل البنكي:

1. التاجر يتواصل مع خدمة العملاء.
2. الإدارة تستلم الإثبات.
3. الأدمن يفتح التطبيق → HomeDispatcher يحوّله مباشرة لـ AdminDashboard.
4. Tab "التجار" → بحث بهاتف التاجر.
5. ضغط على البطاقة → Bottom Sheet → "تغيير الخطّة".
6. اختيار "البداية (15 ر.س)" → كتابة ملاحظة "دفع بنكي 03-06-2026" → حفظ.
7. النظام يُحدّث `merchant_profiles.subscription_plan` + `subscription_notes`.
8. عند الـ login القادم للتاجر، `/me/access` يعيد الـ features الجديدة (inventory + barcode).

## الإنجاز التراكمي النهائي

| البند | الإجمالي |
|---|---|
| ميزات | **20** |
| Endpoints API | **118+** |
| Migrations | **40** |
| اختبارات (مكتوبة) | **86+** |
| Services | **56** |
| Controllers (Amial) | **28** |
| شاشات Flutter | **32+** |
| ملفات PHP | **220** |
| ملفات Dart | **377** |

## ملاحظات صدق
- **فحصي بنيوي فقط** — لم أشغّل اختبارات.
- **بدون pagination حقيقي** في الواجهة — حالياً يعرض أوّل 20 تاجر فقط. للنشر الواسع، أضف "تحميل المزيد".
- **بدون تأكيد** لإجراءات حساسة (تغيير خطّة 65 ر.س مثلاً) — مجرد تأكيد بسيط في الـ dialog.
- **`FutureBuilder` في tab variances** قد يُعيد تحميل البيانات في كل rebuild — للنسخة المُحسّنة يُفضّل استخدام `initState` فقط.

## ما يبقى لاحقاً
1. **شاشة Verification details** كاملة (عرض المستندات المرفوعة).
2. **Pagination** للتجار.
3. **Audit log** لكل إجراء إداري.
4. **Multi-Branch + RBAC** للـ Enterprise (v2).
