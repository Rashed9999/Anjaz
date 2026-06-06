# CHANGELOG — جولة الإكمال الاحترافية

**تاريخ:** 2026-05-29  
**الإصدار:** Backend v2.24 + Flutter v2.16

## الهدف
سدّ فجوات التكامل: ميزات backend مكتملة بلا واجهة، وميزات Flutter بلا backend.

## الإضافات

### Backend

#### 1. AMIAL-ME-001 — بيانات المستخدم
- **`MeController`** في `app/Http/Controllers/Api/V1/Amial/`.
- **Endpoints:**
  - `GET /api/v1/amial/me` → profile كامل + balance + roles.
  - `GET /api/v1/amial/me/account-number` → رقم الحساب فقط (سريع).
- **`roles`** يكشف ما إذا كان المستخدم: customer / merchant / pos_user / agent.

#### 2. تكامل الإشعارات مع نظام السحب (AMIAL-CUSTOMER-WITHDRAW × NOTIFICATIONS)
- عند `CustomerWithdrawService::request()` → إشعار `withdraw_pending` للعميل.
- عند `CustomerWithdrawService::execute()` (الوكيل ينفّذ) → إشعار `withdrawal_completed` للعميل.
- الإشعارات best-effort — أيّ فشل لا يكسر السحب.
- 3 أنواع جديدة في `NotificationService::TYPES`: `withdraw_pending`، `withdraw_cancelled`، `withdrawal_failed`.

#### 3. اختبارات تكامل API
- `MeAndWithdrawApiTest` — 5 اختبارات تستخدم `Passport::actingAs`:
  - استرجاع بيانات المستخدم.
  - استرجاع رقم الحساب.
  - طلب سحب يُنشئ Notification تلقائياً.
  - قائمة طلبات السحب.
  - إلغاء يفكّ الحجز.

### Flutter

#### 1. AMIAL-CUSTOMER-WITHDRAW-001 — واجهات السحب
- `lib/features/withdraw/domain/repositories/customer_withdraw_repo.dart`.
- `lib/features/withdraw/controllers/customer_withdraw_controller.dart`.
- **شاشتان:**
  - `WithdrawRequestScreen` — إدخال المبلغ + مبالغ سريعة (5K/10K/20K/50K/100K) + تأكيد.
  - `WithdrawPendingScreen` — يعرض `op_code` بخط كبير + **عدّ تنازلي حيّ** + نسخ + إلغاء.
- توجيه تلقائي: إن وُجد طلب نشط، يُفتح `WithdrawPendingScreen` مباشرةً.

#### 2. AMIAL-ME-001 — بيانات المستخدم
- `lib/features/me/domain/me_repo.dart` (يحتوي `MeRepo` + `MeController` معاً).
- **شاشة `MyAccountNumberScreen`** — بطاقة عرض رقم الحساب بتنسيق `XX XX XXXX` + نسخ + مشاركة.

#### 3. شاشة Hub الموحّدة
- **`MyServicesScreen`** — نقطة وصول واحدة لكل ميزات أميال باي:
  - بطاقة رقم الحساب (مختصرة).
  - شبكة 6 خدمات: سحب نقدي، رقم حسابي، الإشعارات (مع badge)، السجل، جهات الاتصال، المساعدة.

#### 4. تسجيل DI
- 3 إضافات في `helper/get_di.dart`:
  - `CustomerWithdrawRepo` + `CustomerWithdrawController` (fenix).
  - `MeRepo` + `MeController` (fenix).

## تحديث جدول الميزات

| الميزة | Backend | Flutter | ربط |
|---|---|---|---|
| المحفظة + التحويل | ✅ | ✅ | ✅ |
| الكاشير | ✅ | ✅ | ✅ |
| الفاتورة المقسّمة | ✅ | ✅ | ✅ |
| **السحب من العميل** | ✅ | ✅ ⬆️ (كان ⏸️) | ✅ ⬆️ |
| نظام الديون | ✅ | ✅ | ✅ |
| الإشعارات | ✅ | ✅ | ✅ |
| **رقم الحساب** | ✅ | ✅ ⬆️ (كان ⏸️) | ✅ ⬆️ |
| **Me / Profile** | ✅ 🆕 | ✅ 🆕 | ✅ 🆕 |

## ما يبقى عليك
**فحصي بنيوي فقط** (توازن الأقواس). للتحقّق الفعلي:
```bash
# Backend
cd amial_pay_working_copy
php artisan migrate
php artisan test --filter=MeAndWithdrawApiTest
php artisan test --filter=NotificationTest

# Flutter
cd amyal_pay_user_app
flutter analyze lib/features/withdraw/ lib/features/me/
```

## نقاط ربط نهائية تحتاجك (لم أعدّل home_screen)
1. أضف زر "خدماتي" في **شاشة Profile أو القائمة الجانبية**:
```dart
ListTile(
  leading: Icon(Icons.apps),
  title: Text('خدماتي'),
  onTap: () => Get.to(() => const MyServicesScreen()),
);
```

2. كذلك يمكنك ربط زر السحب من theme widgets إن أردت ربطه مباشرةً:
```dart
onTap: () => Get.to(() => const WithdrawRequestScreen());
```

## القرارات التصميمية في هذه الجولة

- **تنسيق رقم الحساب `XX XX XXXX`**: يسهّل القراءة بصوت عال (شائع في فاكسات/مكالمات).
- **عدّ تنازلي حيّ في WithdrawPendingScreen**: Timer كل ثانية، يتعطّل تلقائياً عند 0.
- **توجيه تلقائي عند وجود طلب نشط**: إن دخل المستخدم `WithdrawRequestScreen` وله طلب pending، يُحوَّل لـ `WithdrawPendingScreen` مباشرةً (تجنّب إنشاء طلبين).
- **MeRepo + MeController في ملف واحد**: صغير جداً (15 سطر كل واحد)، فلا داعي لملفين.
- **MyServicesScreen كـ hub**: يحلّ مشكلة "كيف يصل المستخدم لهذه الميزات؟" دون إعادة كتابة home_screen المعقّد من Cash6.
