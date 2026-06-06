# سجل التغييرات — Amyal Pay v0.7-C + v0.7-D

**التاريخ:** 2026-05-16
**النطاق:** AMYAL-SECURITY-002 (v0.7-C) + UI screens for v0.7-A endpoints (v0.7-D)

---

## v0.7-C — Flutter Security

### الملفات الجديدة

| الملف | الغرض |
|---|---|
| `lib/data/api/secure_storage_helper.dart` | wrapper آمن مع migration تلقائي من SharedPreferences |
| `lib/data/api/idempotency_key_generator.dart` | UUID v4 generator لـ `Idempotency-Key` |

### الملفات المعدّلة

| الملف | التغيير |
|---|---|
| `lib/data/api/api_client.dart` | + `idempotencyKey` parameter في `postData`<br>+ `X-Amial-Zone` و `X-Amyal-Client-Version` headers<br>+ تنظيف debug logs (لا token/body كاملاً، فقط في kDebugMode) |
| `lib/features/auth/domain/reposotories/auth_repo.dart` | token الآن في secure storage<br>+ `primeTokenCache()` async للـ splash<br>+ `getUserTokenAsync()` للقراءة المباشرة |
| `ios/Runner/Info.plist` | + NSAppTransportSecurity (HTTPS only, no exceptions) |

### Migration للـ token (شفافة للمستخدم)

أول مرة يستخدم المستخدم النسخة الجديدة:
1. `primeTokenCache()` يُستدعى في splash
2. يجد token في SharedPreferences القديم
3. ينقله لـ secure storage
4. يحذفه من SharedPreferences

النتيجة: المستخدم لا يحتاج إعادة login.

---

## v0.7-D — Flutter UI Screens

### Feature Module جديد: `lib/features/amyal/`

```
features/amyal/
├── controllers/
│   └── amyal_controller.dart       (Reactive state via GetX Rx)
├── domain/
│   ├── models/
│   │   └── amyal_models.dart        (4 models: SessionPolicy, LegalStatus, LegalTerm, RecoveryRequest)
│   └── repositories/
│       └── amyal_repo.dart          (يلتف على ApiClient، يستخدم Idempotency-Key)
├── screens/
│   ├── terms_acceptance_screen.dart (إلزامية بعد login)
│   └── account_recovery_screen.dart (wizard 3 خطوات)
└── widgets/
    └── zone_banner_widget.dart      (read-only banner)
```

### الشاشات المُسلَّمة

**1. TermsAcceptanceScreen**
- Scroll مع تتبع 80%+ قبل تفعيل القبول
- Checkbox "قرأت ووافقت"
- زر "موافق ومتابعة" disabled حتى scroll + checkbox
- يظهر changelog إن وُجد
- لو mandatory=true، لا يمكن للـ user الـ back

**2. ZoneBannerWidget**
- Banner أصفر فوق الشاشة لو read_only_mode
- يخفي نفسه لو can_transact = true
- استخدام: `const ZoneBannerWidget()` فوق `body`

**3. AccountRecoveryScreen**
- Wizard 3 خطوات (رقم → OTPs → PIN)
- Step indicator مرئي
- يستخدم `pin_code_fields` المتوفر
- يعرض success dialog عند اكتمال (يشرح 24h security hold)
- يعرض رسائل الخطأ من backend

### DI Registration

`lib/helper/get_di.dart` الآن يسجل:
- `AmyalRepo` (lazy)
- `AmyalController` (lazy, fenix:true لاحتفاظ بالـ state)

---

## كيف تربط الشاشات الجديدة بـ flow التطبيق

### 1. التحقق من Terms بعد Login

في `AuthController` بعد نجاح login، أضف:

```dart
Future<void> _afterLoginSuccess() async {
  // ... existing code

  // AMYAL-LEGAL-001: تحقق من القبول
  final amyal = Get.find<AmyalController>();
  final ok = await amyal.refreshLegalStatus();

  if (ok && amyal.legalStatus.value?.needsAcceptance == true) {
    Get.offAll(() => const TermsAcceptanceScreen(
      mandatory: true,
      onAccepted: _goToHome,  // method موجود
    ));
  } else {
    _goToHome();
  }
}
```

### 2. تحديث Zone Policy في Splash + Resume

في `SplashController`:

```dart
@override
void onInit() {
  super.onInit();
  Get.find<AmyalController>().refreshSessionPolicy();
}
```

في `main.dart` (للـ resume):

```dart
class _MyAppState extends State<MyApp> with WidgetsBindingObserver {
  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      Get.find<AmyalController>().refreshSessionPolicy();
    }
  }
}
```

### 3. عرض Banner في الشاشات المالية

في أي شاشة عمليات مالية (مثل HomeScreen):

```dart
Scaffold(
  body: Column(
    children: [
      const ZoneBannerWidget(),  // ← يظهر تلقائياً لو read-only
      Expanded(child: existingContent),
    ],
  ),
)
```

### 4. التعامل مع 403 TERMS_ACCEPTANCE_REQUIRED

في `ApiChecker.checkApi` أو wherever الـ response يُعالَج:

```dart
if (response.statusCode == 403 && response.body['code'] == 'TERMS_ACCEPTANCE_REQUIRED') {
  Get.offAll(() => const TermsAcceptanceScreen(mandatory: true));
  return;
}
```

### 5. إرسال Idempotency-Key في الـ Repos الموجودة

عدّل `TransactionRepo.sendMoney`:

```dart
Future<Response> sendMoney({required Map<String,dynamic> body, String? idempotencyKey}) {
  return apiClient.postData(
    AppConstants.customerSendMoney,
    body,
    idempotencyKey: idempotencyKey ?? IdempotencyKeyGenerator.forFinancialAction('send_money'),
  );
}
```

وفي الـ Controller، احفظ الـ key للـ retry:

```dart
class TransactionMoneyController {
  String? _currentIdempotencyKey;

  Future<void> sendMoney(...) async {
    _currentIdempotencyKey ??= IdempotencyKeyGenerator.forFinancialAction('send_money');
    final res = await repo.sendMoney(body: body, idempotencyKey: _currentIdempotencyKey);

    if (res.statusCode == 200 && res.body['success'] == true) {
      _currentIdempotencyKey = null; // reset لعملية جديدة
    }
    // لو فشل: نحتفظ بالـ key للـ retry
  }
}
```

---

## مَن لا يزال يحتاج عمل (v0.7-E ربما)

| البند | السبب |
|---|---|
| ربط Controllers الموجودة بـ Idempotency-Key | يحتاج تعديل ~15 controller (TransactionMoney, AddMoney, RequestedMoney, ...) |
| شاشة PIN setup (منفصل عن password) | يحتاج backend endpoints جديدة لـ PIN setup (موجودة في v0.6 service لكن endpoint لم يُضَف) |
| شاشة Account Security (سجل الأحداث) | يحتاج backend endpoint `GET /api/v1/amial/security/events` (لم يُبنَ) |
| Lost-phone Recovery Screen | يحتاج file upload لـ identification docs (موجود في pubspec — `image_picker` و `permission_handler`) |
| Markdown rendering للـ Terms content | اختياري — لو الـ backend يرسل HTML، نحتاج `flutter_html` (موجود) |

---

## الـ TODO الأساسية للمستخدم

### إلزامية قبل البناء

1. **تطبيق الـ migration** على `AuthController` (راجع قسم "1. التحقق من Terms بعد Login" أعلاه)
2. **إضافة ZoneBannerWidget** في `HomeScreen` على الأقل
3. **`primeTokenCache()`** في `SplashController`:
   ```dart
   await Get.find<AuthRepo>().primeTokenCache();
   ```
4. **معالجة `TERMS_ACCEPTANCE_REQUIRED`** في `ApiChecker`

### اختيارية لكن موصى بها

1. ربط Idempotency-Key بـ TransactionMoneyController
2. زر "تغيير رقم الهاتف" في profile screen → فتح `AccountRecoveryScreen`
3. تعطيل screenshot في شاشات حساسة (نستخدم `flutter_windowmanager` لاحقاً)

---

## الاختبار اليدوي

```bash
flutter pub get
flutter clean
flutter build apk --debug
```

تحقق على الجهاز:
1. ✅ Login يعمل (token محفوظ في secure storage)
2. ✅ TermsAcceptanceScreen تظهر إذا لم تكن مقبولة
3. ✅ ZoneBannerWidget يظهر إذا zone != SOUTH
4. ✅ AccountRecoveryScreen 3 خطوات تعمل
5. ✅ debug logs لا تحتوي token أو body كاملاً

---

## التحقق من الـ Security

```bash
# Android: استخراج APK و فحص أنه لا يحتوي على secrets
unzip -p app-debug.apk classes.dex | strings | grep -i "Bearer\|token\|password" | head

# iOS: NSAppTransportSecurity مُفعَّل
plutil -p ios/Runner/Info.plist | grep -A 5 NSAppTransportSecurity
```
