# Amial Pay — User App (Flutter)

> Branded version of the original 6Cash user app.
> Version: 0.7.0+70
> Branch: AMIAL-BRANDING-001
> Flutter SDK: 3.8.1+

---

## ما تغيّر في v0.7-B

### Branding
- ✅ `pubspec.yaml` name: `six_cash` → `amial_pay`
- ✅ 1187 import تم تحديثه: `package:six_cash/` → `package:amial_pay/`
- ✅ App display name: `6Cash` → `Amial Pay`
- ✅ Android `applicationId`: `com.sixamtech.sixcash_user` → `amialpay.com`
- ✅ iOS `PRODUCT_BUNDLE_IDENTIFIER`: `com.sixamtech.cash` → `amialpay.com`
- ✅ MainActivity.kt نُقل من `com/u6amtech/efood_multivendor/` (leftover من food delivery app!) إلى `com/amialpay/app/`
- ✅ Launcher icons: Android 5 densities × 2 + Adaptive + iOS 15 sizes = 67 ملف
- ✅ Splash screen يستخدم الشعار + خلفية صفراء `#FECA1E`
- ✅ Notification icon أبيض شفاف + tint color أزرق
- ✅ Permission descriptions في Info.plist مترجمة للعربية

### الأمن
- ✅ `usesCleartextTraffic="false"` في AndroidManifest
- ✅ `network_security_config.xml` يمنع HTTP plaintext
- ✅ `tools:replace="android:resource"` على notification color

### Bug Fix
- ✅ AndroidManifest الأصلي كان فيه bug XML (السطر 48 يقفل `<application>` tag مبكراً) — صُحّح

### Endpoints الجديدة
في `lib/util/app_constants.dart` (للجاهزية مع backend v0.7-A):
- `amialPolicySession`
- `amialLegalStatus`, `amialLegalCurrent`, `amialLegalAccept`
- `amialRecoveryInitiateSelf`, `amialRecoveryInitiateLost`, etc.

### Colors API
استخدم `AmialColors` من `lib/theme/amial_colors.dart`:
```dart
import 'package:amial_pay/theme/amial_colors.dart';

Container(
  color: AmialColors.yellow,
  child: Text('مرحبا', style: TextStyle(color: AmialColors.primary)),
)
```

---

## الألوان الرسمية

| الاسم | الكود | استخدام |
|---|---|---|
| Primary Blue | `#053391` | نصوص، أزرار |
| Yellow | `#FECA1E` | خلفية البراند |
| Red | `#DC0A0B` | تحذيرات فقط |
| Background | `#FFF8E1` | خلفية الشاشات |

---

## بناء APK debug

⚠️ **توقيع release غير مُهيّأ** — release build يستخدم debug keystore (قرار المستخدم).

### المتطلبات
- Flutter SDK 3.8.1+
- Android SDK 33+ (compileSdk من Flutter framework)
- JDK 11+
- (iOS) macOS + Xcode 16.2+

### بناء سريع (Android Debug)

```bash
cd amial_pay_user_app
flutter pub get
./generate_native_assets.sh
flutter build apk --debug
```

الـ APK يُنتَج في:
```
build/app/outputs/flutter-apk/app-debug.apk
```

### بناء release (موقّع بـ debug keystore — للاختبار فقط)

```bash
flutter build apk --release
```

⚠️ هذا الـ APK **لا يصلح للنشر على Google Play** لأنه موقّع بـ debug keystore.

### إعداد keystore حقيقي (مستقبلاً)

```bash
keytool -genkey -v -keystore amial-release.jks \
  -keyalg RSA -keysize 2048 -validity 10000 -alias amialpay

# ضع المسار في android/key.properties:
cat > android/key.properties << EOF
storeFile=../amial-release.jks
storePassword=YOUR_PASSWORD
keyAlias=amialpay
keyPassword=YOUR_PASSWORD
EOF

# في build.gradle.kts غيّر:
#   signingConfig = signingConfigs.getByName("debug")
# إلى:
#   signingConfig = signingConfigs.getByName("release")
```

---

## الإعداد قبل التشغيل

### 1) baseUrl

في `lib/util/app_constants.dart`:
```dart
static const String baseUrl = 'https://your-amial-pay-backend.com';
```

### 2) Firebase project

الـ `main.dart` يستخدم Firebase project قديم (`gem-b5006`).
لـ Amial Pay الإنتاجي، أنشئ Firebase project جديد بـ:
- Package: `amialpay.com` (Android)
- Bundle ID: `amialpay.com` (iOS)

ثم استبدل القيم في `main.dart` و ضع:
- `android/app/google-services.json`
- `ios/Runner/GoogleService-Info.plist`

### 3) شغّل

```bash
flutter run
```

---

## ما لم يتم بعد (v0.7-C, v0.7-D)

### v0.7-C — Flutter Security
- [ ] استبدال SharedPreferences بـ flutter_secure_storage للـ token
- [ ] إضافة `Idempotency-Key` header في ApiClient
- [ ] حذف debug logs الحساسة من release build
- [ ] App Transport Security لـ iOS

### v0.7-D — Flutter UI الجديد
- [ ] شاشة Terms acceptance (مطلوبة بعد login)
- [ ] read-only banner لمستخدمي خارج SOUTH
- [ ] شاشة "أمان الحساب"
- [ ] PIN setup screen (منفصل عن password)
- [ ] شاشات Account Recovery
