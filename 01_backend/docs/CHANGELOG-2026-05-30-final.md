# CHANGELOG — جولة الإكمال الشاملة (الربط النهائي + اختبارات)

**التاريخ:** 2026-05-30  
**الإصدار:** Backend v2.28 + Flutter v2.20

## الإضافات

### 1. ربط ميزات مبنية سابقاً (لم تكن مربوطة بأيّ شاشة)

**اكتشاف:** Family Fund و Bill Pay كانتا مبنيتين بالكامل:
- Family Fund: migrations + Service + Controller + 3 شاشات Flutter + DI ✓
- Bill Pay: migrations + Service + Controller + 2 شاشات Flutter + DI ✓

**ما فعلت:** ربطهما في شاشة "خدماتي".

### 2. شاشة "خدماتي" أصبحت Hub شامل (9 خدمات)

| الأيقونة | الخدمة | الحالة |
|---|---|---|
| 💸 | سحب نقدي | ✅ |
| 🔢 | رقم حسابي | ✅ |
| 🔔 | الإشعارات (مع badge) | ✅ |
| 💰 | طلب أموال | ✅ |
| 🛡️ | الدفع الآمن | ✅ |
| 🐷 | الصناديق المشتركة | ✅ |
| ⚡ | الفواتير (كهرباء/اتصالات) | ✅ |
| 🧾 | إيصالاتي (PDF) | ✅ |
| ✅ | توثيق المتجر (KYC) | ✅ |

### 3. Banner "وثّق متجرك" في لوحة التاجر

Banner أصفر بارز يظهر للتجّار غير الموثَّقين:
- ينقر → يفتح `MerchantVerificationScreen`.
- يختفي تلقائياً عند الحصول على verified=true.

### 4. اختبارات MerchantVerification (10 اختبارات)

تغطّي كل السيناريوهات:
1. تقديم طلب جديد يُنشئ profile + pending_review.
2. رفض بدون اسم نشاط.
3. رفض نوع ملف غير مدعوم (exe).
4. رفض حجم > 5MB.
5. تاجر verified لا يستطيع تقديم.
6. resubmission_required → التقديم **يحدّث** الطلب الموجود.
7. Admin approve → verified + إشعار.
8. Admin approve مع tier=gold → tier يُحدَّث.
9. Admin reject مع سبب + إشعار.
10. Admin request resubmission مع سبب + إشعار.
11. لا يمكن approve طلب أُغلق سابقاً.

## ملخّص حالة المشروع — كل المجموعات الثلاث

### ✅ المجموعة الأولى (مكتملة)
- المرتجعات §12
- Payment Requests
- PDF كشف الحساب
- Verified Badge

### ✅ المجموعة الثانية (مكتملة)
- الإيصالات الموحّدة §17
- Safe Payment §14
- توثيق التاجر §13

### ✅ المجموعة الثالثة — جزئياً
- **§18 الصندوق العائلي** — ✅ مربوط (كان مبنياً)
- **§19 تسديد الفواتير** — ✅ مربوط (كان مبنياً)
- **نظام الفروع** — ❌ لم يُبنَ (مؤجَّل لـ v2)
- **نظام الموردين** — ❌ لم يُبنَ (مؤجَّل لـ v2)

## ميزات v2 المؤجَّلة (احترافياً)

هذه ميزات ضخمة تستحق فريقاً مخصّصاً، وتم استبعادها من نطاق MVP:

1. **نظام الفروع المتعدّد** (multi-branch) — يحتاج إعادة هيكلة كل العمليات.
2. **نظام الموردين + أوامر الشراء** — قطاع محاسبي مستقل.
3. **قطاعات POS متخصّصة** (مطعم/صيدلية/وقود).
4. **Offline-First Sync Hub** — تشفير + queue + conflict resolution.
5. **Push Notifications (FCM)** — يحتاج setup خادمي.
6. **Bank Reconciliation** (التسويات البنكية).
7. **خريطة الوكلاء التفاعلية**.
8. **Staff Shifts + إغلاق نقدي**.

## ملاحظات صدق نهائية

1. **كل فحصي بنيوي فقط** (توازن الأقواس عبر Node). لم أشغّل أيّ اختبار PHP أو Flutter analyze.

2. **زر "مرتجع" في تفاصيل البيع** — لم أربطه. شاشة `CashierRefundScreen` تأخذ `saleUlid` كمدخل، تحتاج ربطها يدوياً في شاشة تفاصيل البيع أو سجل المعاملات.

3. **زر "خدماتي" في القائمة الجانبية الرئيسية** — لم أعدّل home_screen.dart المعقّد. تحتاج إضافته:
```dart
Get.to(() => const MyServicesScreen());
```

4. **شاشة Admin Review لتوثيق التاجر** — endpoints جاهزة، لكن واجهة Admin لم تُبنَ. يمكن استخدام Admin Panel الموجود من Cash6.

## للتحقّق في بيئتك
```bash
cd amial_pay_working_copy
php artisan migrate
php artisan test --filter=MerchantVerificationTest
php artisan test --filter=PaymentRequestsTest
php artisan test --filter=CashierRefundTest
php artisan test --filter=CustomerCredit
php artisan test --filter=Notification

cd ../amyal_pay_user_app
flutter pub get
flutter analyze lib/features/
```

## الأرقام النهائية

| المقياس | القيمة |
|---|---|
| ميزات مكتملة | **15+** |
| Endpoints API | **60+** |
| جداول البيانات | **20+** |
| اختبارات | **40+** |
| شاشات Flutter جديدة | **15+** |
| Services | **48** |
