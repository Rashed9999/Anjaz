# Amial Pay — تسليم Codex إلى Claude Code: الجولة الثانية

**الحالة:** ❌ `NOT COMPLETE` — هذه دفعة تحصين واكتشاف للحزمة 5–8، وليست
إغلاقاً كاذباً للمهام. لا تنشرها إلى الإنتاج قبل اجتياز أوامر التحقق أدناه.

## ما الذي تغير فعلاً

### 5) التاجر والتطبيق والقطاعات وWooCommerce

🟢 **سطح تشغيل التاجر:** بوابة تاجر 6cash الويب ليست مسجَّلة أصلاً في
`RouteServiceProvider`؛ التطبيق Flutter هو السطح الحي للتاجر/موظف POS عبر
`/api/v1/amial/merchant/*`. أضيف اختبار `MerchantOperationSurfaceTest` كي لا
يعيد تعديل لاحق تسجيل `routes/merchant.php` ويخلق لوحة تشغيل ثانية.

🟡 **القطاعات والقدرات:** المسارات الحية والـ Flutter موجودة لقطاعات متعددة
(وقود، صيدلية، جملة، بيع سريع، تجزئة)، ويجب أن يبقى أي تطوير من التطبيق
نحو API ثم خدمة القطاع؛ لا تعِد تشغيل dashboard الويب القديم.

🔴 **WooCommerce:** لا يوجد أي تنفيذ أو تكامل أو webhook أو خزن آمن لأسرار
WooCommerce في المصدر الحالي (`rg -i woocommerce` بلا نتائج). لا تنشئه
كـ"زر ربط". المطلوب قبل ادعاء الدعم:

1. connection model مشفّر للأسرار + اختبار اتصال + تدقيق؛
2. webhook signing/idempotency/replay protection؛
3. mapping مستقل للطلب/الفاتورة/طلب الدفع؛
4. reconciliation/refund/stock propagation واختبارات فشل الشبكة.

### 6) موظفو المنصة، RBAC وMFA

🟢 تم منع إنشاء أو تحديث موظف نشط بلا دور (`role_ids` إلزامية وغير فارغة).
🟢 تم منع role injection: تقبل صفحة الموظفين فقط الأدوار التي
`merchant_user_id IS NULL` و`code LIKE platform_%`.
🟢 أضيفت اختبارات منع إفراغ الأدوار وحقن دور تاجر.
🟢 أصلحت بوابة دخول الإدارة: حساب `is_active = 0` لا يفتح جلسة، وكذلك إن
عُطّل بين كلمة المرور وتحدي TOTP.

🟡 2FA المفعل صار بوابة فعلية، لكن **الإلزام العام** لـ `platform_admin`
قبل كل دخول غير مكتمل كي لا تُقفل الحسابات الحالية بلا مسار تسجيل أولي.

🔴 `OperatorRolesController::store` ما زال ينشئ حساباً فعّالاً ويعيّن
`is_phone_verified = 1` من إدخال المدير. لا تعالج ذلك بتبديل الحقل فقط:
ابنِ دورة Provisioning كاملة (دعوة قصيرة العمر، OTP للموظف نفسه، تفعيل بعد
التحقق، إبطال/إعادة إرسال، session invalidation، audit) ثم اجعل الحساب
المعلّق بلا login/session. هذه طبقة حرجة باقية.

### 7) الوكيل والفروع والتسويات

🟢 صار الإقفال اليومي الطبيعي يرفض صراحة:

- وردية مفتوحة؛
- فرق وردية ما زال `pending_review`.

🟢 أضيف اختبار لكل حالة. هذا يمنع تحويل فرق أو درج غير موثق إلى تسوية
"مرفوعة".

🟡 النموذج الحالي يحسب النقد والـfloat والفروق، لكن **مسار override مستقل**
لفرق غير محلول (صلاحية خاصة، سبب، maker/checker، تدقيق) غير موجود؛ لا تحول
الاستثناء إلى زر "متابعة" عادي.

### 8) المناطق وتوزيع المستخدمين

🟢 أغلقت شاشة توزيع المستخدمين المكررة: `GET /admin/amial/zones` يعيد إلى
مركز سياسة المناطق الموحد، وحُذف رابطها المكرر من sidebar.

🟢 كل الإسناد من الواجهة وAPI وCLI يمر بخدمة `ZoneAssignmentService`؛ لم
يعد أمر CLI يكتب `zone_code` مباشرة أو ينفذ `whereRaw`.

🟢 CLI الآن يطلب `--admin-id` لموظف يملك `platform.approvals.decide` و`--reason`
(10–500 حرفاً)، ولا يسمح في bulk إلا بمرشح محدود (`type=`, `zone_code=`, `is_active=`)، ويكتب
سجلاً لكل مستخدم ثم audit ملخّصاً.

🟢 أضيفت حراسة الصلاحيات: عرض السياسة/السجل يحتاج `platform.audit.view`،
والإسناد يحتاج `platform.approvals.decide`. لا يوجد actor افتراضي مثل `1`
ولا رقم هاتف في سياق audit الجديد.

🟡 مركز المناطق يعرض التشغيل الحقيقي وإعادة الإسناد من KYC؛ مسار الإسناد
المباشر API-only حالياً. إن أضيفت نافذة إسناد يدوي، يجب أن تكون داخل المركز
نفسه، مع سبب إلزامي، صلاحية backend، زر إغلاق، وسجل audit — لا شاشة ثانية.

## اختبارات يجب أن يشغلها Claude Code

من `01_backend` وبعد تجهيز PHP وMariaDB و`.env.testing`:

```bash
bash scripts/verify.sh
php artisan test --filter=AgentDailySettlementTest
php artisan test --filter=OperatorRolesAssignmentTest
php artisan test --filter=AdminTwoFactorDoorGuardTest
php artisan test --filter=MerchantOperationSurfaceTest
php artisan test --filter=ZoneAssignmentTest
php artisan test --filter=ZoneCommandGuardTest
php artisan test --filter=AdminCommandCenterGuardTest
php artisan route:list | rg 'admin/amial/(zones|zone|hub/zones)'
```

ومن `02_flutter_app`:

```bash
flutter analyze
flutter test
```

ثم تحقق يدوياً بحسابات ذات أدوار مختلفة:

1. موظف دعم: 403 على سجل/إسناد المنطقة؛
2. امتثال: يقرأ مركز المناطق ويعيد إسناد حساب KYC مع audit حقيقي؛
3. مدير معطّل: كلمة المرور الصحيحة لا تفتح لوحة ولا يكمل TOTP؛
4. وكيل بورديّة مفتوحة أو فرق كبير: لا يستطيع رفع الإقفال؛
5. تاجر: لا توجد بوابة ويب تشغيلية، والتطبيق يفتح القطاع الصحيح.

## تحقق ساكن أجراه Codex

🟢 `git diff --check` بلا أخطاء مسافات.

🟡 بيئة Codex لا تحتوي `php` أو `composer` أو `flutter`، لذلك لم تشغّل
اختبارات Laravel/Flutter التنفيذية هنا. نتائجها ليست معروفة حتى يشغّلها
Claude Code في البيئة الكاملة.
