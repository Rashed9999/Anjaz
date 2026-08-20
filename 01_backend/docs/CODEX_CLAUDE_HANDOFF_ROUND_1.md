# تسليم الجولة الأولى إلى Claude Code — Amial Pay

**الحالة: لا تُنشر إلى Coolify قبل اكتمال الفحص الكامل.**

هذه التغييرات تغطي أول أربع مهام من حزمة العمل:

1. مركز العملاء.
2. مركز الدفتر والمصالحة.
3. مكافحة غسل الأموال AML.
4. بوابة الوكيل والفروع.

## ما أنجزه Codex محلياً

### مركز العملاء

- أضاف `financial_truth` الذي يقارن رصيد `e_money` مع رصيد دفتر مشتق من
  سطور القيود المنشورة، ويعرض `reconciled` أو `mismatch` أو `unverifiable`.
- صحح تفسير KYC: القيمة `1` فقط هي «موثَّق»؛ القيمة القديمة `3` ليست موثقة.
- جعل فك التجميد وإعادة PIN طلبَي اعتماد ثنائيين، لا تنفيذاً فورياً.
- منع توجيه إجراء مركز العملاء إلى وكيل أو موظف.
- حفظ تعديل حد واحد للعميل مع بقية الاستثناءات المالية.

### الدفتر والمصالحة

- أضاف migration/model/service لـ `reconciliation_cases`.
- التشغيل الليلي ينشئ/يحدّث قضية فرق، ويصعّد الخطورة عبر التكرار؛ عودة
  التطابق تنقل القضية إلى `verifying` ولا تغلقها تلقائياً.
- أضاف endpoint وشاشة لعرض القضايا في مركز الدفتر.

### AML

- حرس كامل مساحة AML بصلاحية `platform.audit.view`.
- حرس تغيير القواعد، مراجعة العقوبات، واستثناءات العملاء بصلاحية
  `platform.approvals.decide`.
- القاعدة البيضاء لم تعد تتجاوز كل قواعد AML؛ القواعد الحرجة الفاشلة تضع
  العملية في hold مع سجل امتثال.
- اللوحة تعرض حالة حرجة صريحة عندما لا توجد قواعد فعالة.
- تغييرات قواعد AML والاستثناءات اليدوية تكتب إلى سجل التدقيق.

### الوكيل والفروع

- أضاف حدّ محاولات دخول بوابة الوكيل.
- تقرير النقد اليومي يحسب `difference` على الخادم بـ BCMath، ويقارن الرصيد
  المتوقع برصيد الخزنة الحالي لا بآخر حركة؛ لذلك يظهر العبث اللاحق.

## ما تم فحصه هنا

- `git diff --check`: ناجح.
- تحليل JavaScript في قوالب Blade: ناجح.
- فحص مراجع DOM في القوالب: ناجح.
- لا تعتمد الواجهات المعدّلة لمركز العملاء أو الدفتر على `prompt/confirm/alert`.

## ما لم يمكن فحصه هنا — مطلوب من Claude Code

هذه البيئة تفتقد PHP وMariaDB وChromium وFlutter. شغّل بالترتيب:

```bash
cd 01_backend
bash scripts/verify.sh
```

ثم عالج أي فشل ناتج عن التغييرات قبل النشر، وركّز على:

```bash
php artisan test --filter=CustomerCenterTest
php artisan test --filter=LedgerCenterTest
php artisan test --filter=AmlDashboardAndScreeningTest
php artisan test --filter=AmlScreeningServiceTest
php artisan test --filter=AgentPortalTest
php artisan test --filter=AgentCommissionAndReportTest
```

ويجب اختبار المتصفح الفعلي لـ:

- `/admin/amial/customer`
- `/admin/amial/ledger`
- `/admin/amial/aml`
- `/agent`

## أعمال متبقية لا يجوز وصف الجولة بدونها بأنها مكتملة

- استبدال كل نوافذ `prompt/confirm/alert` الباقية في AML والوكيل بنوافذ
  Bootstrap احترافية فيها زر إغلاق `×` وتحقيق طرف العميل.
- تثبيت أصل قديم مفقود: `resources/views/admin-views/amial/catalog/index.blade.php`
  يشير إلى `storage`.
- فحص Flutter وربط الشاشات وواجهات API الخاصة بهذه المهام.
- تشغيل فحص المال والتزامن والتعافي ثم فقط رفع التغييرات إلى فرع Coolify.

## قاعدة النشر

لا تعلن النجاح ولا تفعّل نشر Coolify قبل أن يخرج `bash scripts/verify.sh`
برمز صفر. هذا commit للتسليم والمراجعة فقط، وليس تصريح إنتاج.
