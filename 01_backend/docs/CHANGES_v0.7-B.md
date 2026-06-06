# سجل التغييرات — Amyal Pay v0.7-B (Flutter Branding)

**التاريخ:** 2026-05-16
**النطاق:** AMYAL-BRANDING-001
**نوع التسليم:** Flutter app كامل (drop-in replacement)

---

## ملخص الأرقام

- **1187** import statement محدّث
- **261** ملف dart فُحص (يبقى عملياً، التغييرات في imports فقط)
- **67** branding asset (Android + iOS)
- **15** ملف تكوين معدّل/جديد
- **0** تغيير في logic — فقط branding/security/structure

---

## التغييرات الجوهرية

### 1. Dart Package Rename

| قبل | بعد |
|---|---|
| `name: six_cash` | `name: amyal_pay` |
| `import 'package:six_cash/...'` (1187 موضع) | `import 'package:amyal_pay/...'` |

### 2. Android Identifiers

| قبل | بعد |
|---|---|
| `namespace: com.sixamtech.sixcash_user` | `com.amyalpay.app` |
| `applicationId: com.sixamtech.sixcash_user` | `com.amyalpay.app` |
| `MainActivity.kt` في `com/u6amtech/efood_multivendor/` | `com/amyalpay/app/` |
| `package com.sixamtech.sixcash_user` (داخل MainActivity) | `package com.amyalpay.app` |
| `android:label="6Cash"` | `android:label="Amyal Pay"` |

### 3. iOS Identifiers

| قبل | بعد |
|---|---|
| `PRODUCT_BUNDLE_IDENTIFIER = com.sixamtech.cash` | `com.amyalpay.app` |
| `CFBundleDisplayName: 6Cash` | `Amyal Pay` |
| `CFBundleName: 6Cash` | `Amyal Pay` |
| permissions strings بالإنجليزية فقط | مترجمة للعربية |

### 4. Security Hardening (preview لـ v0.7-C)

| قبل | بعد |
|---|---|
| `android:usesCleartextTraffic="true"` | `="false"` |
| لا يوجد network_security_config | `network_security_config.xml` يمنع HTTP |
| min SDK = `flutter.minSdkVersion` (متغير) | `21` (ثابت — لـ secure_storage + adaptive icons) |

### 5. Bug Fixes

#### AndroidManifest XML Bug
الـ manifest الأصلي كان به bug XML — السطر 48 ينتهي بـ `>`:
```xml
android:icon="@mipmap/ic_launcher">    ← يغلق <application>!
android:allowBackup="false"            ← خارج الـ tag!
```
الـ XML parser قد يتسامح، لكن السلوك غير معرّف. **صُحّح في النسخة الجديدة.**

#### MainActivity Path Mismatch
المسار: `kotlin/com/u6amtech/efood_multivendor/MainActivity.kt`
المحتوى: `package com.sixamtech.sixcash_user`

تعارض واضح (إرث من food-delivery app fork). الكود يعمل صدفة لأن Flutter يستخدم namespace من build.gradle. **تم النقل للمسار الصحيح.**

### 6. Branding Assets (67 ملف)

| الموقع | المحتوى |
|---|---|
| `assets/branding/` | 4 مصادر (master + foreground + splash + wordmark) |
| `android/app/src/main/res/mipmap-*/` | 5 densities × 2 (legacy + round) = 10 |
| `android/app/src/main/res/mipmap-*/` adaptive | 5 densities × 2 (foreground + background) = 10 |
| `android/app/src/main/res/drawable-*/ic_notification.png` | 5 densities |
| `android/app/src/main/res/mipmap-anydpi-v26/` | 2 XML (adaptive icon definitions) |
| `ios/Runner/Assets.xcassets/AppIcon.appiconset/` | 15 PNG + Contents.json |
| `android/playstore-icon-512.png` | 1 (Play Store listing) |
| splash files | 4 (splash_icon + iOS LaunchImage 3 scales) |

### 7. ملفات جديدة

| الملف | الغرض |
|---|---|
| `lib/theme/amyal_colors.dart` | مرجع مركزي لـ AmyalColors |
| `android/app/src/main/res/values/colors.xml` | brand colors لـ native Android |
| `android/app/src/main/res/xml/network_security_config.xml` | منع cleartext |
| `assets/branding/` | كل المصادر |
| `generate_native_assets.sh` | script لتوليد native splash |
| `README.md` | تعليمات البناء (القديم محفوظ كـ `README_legacy_6Cash.md`) |

---

## كيف تشغّل المشروع

### المتطلبات
- Flutter SDK 3.8.1+
- Android SDK API 33+
- JDK 11+
- (للـ iOS) macOS + Xcode 16.2+

### خطوات البناء

```bash
# 1. ضع baseUrl في lib/util/app_constants.dart
# مثلاً: static const String baseUrl = 'https://api.amialpay.com';

# 2. ثبّت dependencies
flutter pub get

# 3. ولّد native splash
chmod +x generate_native_assets.sh
./generate_native_assets.sh

# 4. ابنِ APK debug
flutter build apk --debug

# الملف يُنتَج في: build/app/outputs/flutter-apk/app-debug.apk
```

---

## التحقق بعد البناء

```bash
# 1. تأكد أن الأيقونة صحيحة
aapt dump badging build/app/outputs/flutter-apk/app-debug.apk | grep -E "application-label|package"

# يتوقع:
# package: name='com.amyalpay.app.debug' versionCode='70' versionName='0.7.0-debug'
# application-label:'Amyal Pay'

# 2. تأكد أن APK يثبّت
adb install build/app/outputs/flutter-apk/app-debug.apk

# 3. تأكد أن Activity تفتح
adb shell am start -n com.amyalpay.app.debug/com.amyalpay.app.MainActivity
```

---

## ما لم يتم في v0.7-B (مؤجل صراحة لـ v0.7-C و v0.7-D)

### الـ Logic لم يتغير
- ApiClient لا يزال يرسل token من SharedPreferences (سيحول لـ secure_storage في v0.7-C)
- لا Idempotency-Key header بعد (v0.7-C)
- لا شاشة Terms acceptance بعد (v0.7-D)
- لا read-only banner لمستخدمي خارج SOUTH بعد (v0.7-D)
- لا شاشات Recovery بعد (v0.7-D)

هذا مقصود — v0.7-B هي **branding pure** كي تتمكن من بناء APK مرئياً واختباره قبل تغييرات الـ logic.

### Firebase project
ما زال يستخدم credentials project قديم (`gem-b5006`). لـ production:
1. أنشئ مشروع Firebase جديد
2. سجّل packages الجديدة
3. حدّث main.dart + ضع google-services.json + GoogleService-Info.plist

---

## بنية الـ ZIP المُسلَّم

```
amyal_pay_user_app/
├── README.md                          (← ابدأ من هنا)
├── README_legacy_6Cash.md             (الـ README الأصلي محفوظ)
├── pubspec.yaml                       (name=amyal_pay)
├── generate_native_assets.sh          (script للـ native splash)
│
├── android/
│   ├── build.gradle.kts
│   ├── settings.gradle.kts
│   ├── gradle.properties
│   └── app/
│       ├── build.gradle.kts          (applicationId=com.amyalpay.app)
│       └── src/main/
│           ├── AndroidManifest.xml   (إصلاح XML bug + cleartext=false)
│           ├── kotlin/com/amyalpay/app/MainActivity.kt
│           └── res/
│               ├── mipmap-{mdpi..xxxhdpi}/ (icons)
│               ├── mipmap-anydpi-v26/      (adaptive)
│               ├── drawable-{mdpi..xxxhdpi}/ ic_notification.png
│               ├── drawable*/launch_background.xml
│               ├── values/colors.xml
│               └── xml/network_security_config.xml
│
├── ios/
│   └── Runner/
│       ├── Info.plist                (CFBundleDisplayName=Amyal Pay)
│       └── Assets.xcassets/AppIcon.appiconset/
│       Runner.xcodeproj/project.pbxproj  (Bundle ID = com.amyalpay.app)
│
├── assets/
│   ├── branding/                     (مصادر الـ branding)
│   ├── image/, language/, animationFile/, payment/, svg/, font/
│
└── lib/
    ├── main.dart
    ├── theme/
    │   ├── amyal_colors.dart         (NEW)
    │   ├── custom_theme_colors.dart
    │   ├── light_theme.dart
    │   └── dark_theme.dart
    ├── util/
    │   └── app_constants.dart        (appName=Amyal Pay + amyal_* endpoints)
    └── features/, common/, data/, helper/  (1187 import محدّث)
```

---

## معلومة مهمة عن الـ APK

**لا أستطيع بناء الـ APK من هذه البيئة** (لا Flutter SDK + لا Android SDK + لا internet). الـ APK يجب أن يُبنى من جانبك.

عند بنائه، أرسل:
- نتائج `flutter build apk --debug --verbose` (آخر 30 سطر)
- لقطة شاشة لـ launcher بعد التثبيت (للتأكد من الأيقونة)
- بيانات `aapt dump badging` للـ APK

ثم نبدأ v0.7-C (Flutter Security).
