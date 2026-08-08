# AMIAL PAY — Branding Assets

## الألوان الرسمية (مأخوذة من الشعار مباشرة)

```dart
class AmialColors {
  static const Color primary = Color(0xFF053391);   // أزرق ملكي عميق
  static const Color yellow = Color(0xFFFECA1E);    // ذهبي ساطع
  static const Color red = Color(0xFFDC0A0B);       // أحمر للتحذير
  static const Color background = Color(0xFFFFF8E1); // كريم فاتح (مكمّل للأصفر)
}
```

| الاستخدام | الكود | المعاينة |
|---|---|---|
| Primary (نصوص، أزرار) | `#053391` | أزرق ملكي |
| Yellow (background رئيسي) | `#FECA1E` | ذهبي |
| Red (تحذير، خطأ) | `#DC0A0B` | أحمر |
| Background الشاشات | `#FFF8E1` | كريم فاتح |

## بنية المجلدات

```
branding/
├── source/
│   ├── icon_square_master.png   ← المصدر المربع (632×632)
│   └── wordmark.png              ← المصدر بالنسبة الأصلية (562×384)
│
├── android/
│   ├── res/
│   │   ├── mipmap-mdpi/          (48×48)
│   │   ├── mipmap-hdpi/          (72×72)
│   │   ├── mipmap-xhdpi/         (96×96)
│   │   ├── mipmap-xxhdpi/        (144×144)
│   │   └── mipmap-xxxhdpi/       (192×192)
│   │       ↳ ic_launcher.png
│   │       ↳ ic_launcher_round.png
│   │       ↳ ic_launcher_foreground.png  (Adaptive icon)
│   │       ↳ ic_launcher_background.png  (Adaptive icon)
│   │
│   ├── notification/
│   │   └── drawable-*/ic_notification.png  (silhouette أبيض)
│   │
│   └── playstore-icon-512.png    (للنشر على Google Play)
│
├── ios/
│   └── AppIcon.appiconset/
│       ├── Contents.json
│       └── Icon-App-*.png         (15 حجم)
│
└── splash/
    ├── splash_icon.png            (1200×1200، لـ flutter_native_splash)
    ├── LaunchImage.png            (iOS launch screen)
    ├── LaunchImage@2x.png
    └── LaunchImage@3x.png
```

## التطبيق على Flutter App

### 1) نسخ الـ Android icons

```bash
# من الـ branding zip إلى مشروع Flutter
cp -r branding/android/res/* User_app/android/app/src/main/res/

# notification (للإشعارات)
cp -r branding/android/notification/drawable-* User_app/android/app/src/main/res/
```

### 2) نسخ iOS icons

```bash
rm -rf User_app/ios/Runner/Assets.xcassets/AppIcon.appiconset
cp -r branding/ios/AppIcon.appiconset User_app/ios/Runner/Assets.xcassets/
```

### 3) Splash screen (يحتاج plugin)

أضف في `pubspec.yaml`:

```yaml
dev_dependencies:
  flutter_native_splash: ^2.4.0

flutter_native_splash:
  color: "#FECA1E"
  image: assets/branding/splash/splash_icon.png
  android_12:
    image: assets/branding/splash/splash_icon.png
    icon_background_color: "#FECA1E"
  ios: true
  android: true
  web: false
```

ثم:
```bash
flutter pub run flutter_native_splash:create
```

### 4) Adaptive Icon XML

أنشئ `android/app/src/main/res/mipmap-anydpi-v26/ic_launcher.xml`:

```xml
<?xml version="1.0" encoding="utf-8"?>
<adaptive-icon xmlns:android="http://schemas.android.com/apk/res/android">
    <background android:drawable="@mipmap/ic_launcher_background" />
    <foreground android:drawable="@mipmap/ic_launcher_foreground" />
</adaptive-icon>
```

وكذلك `ic_launcher_round.xml` في نفس المجلد.

### 5) AndroidManifest.xml

عدّل `<application>` ليستخدم الأيقونة الجديدة (يجب أن تكون موجودة بالفعل، فقط تأكد):

```xml
<application
    android:label="Amial Pay"
    android:icon="@mipmap/ic_launcher"
    android:roundIcon="@mipmap/ic_launcher_round"
    ...>
```

### 6) Notification icon

في `android/app/src/main/AndroidManifest.xml` داخل `<application>`:

```xml
<meta-data
    android:name="com.google.firebase.messaging.default_notification_icon"
    android:resource="@drawable/ic_notification" />
<meta-data
    android:name="com.google.firebase.messaging.default_notification_color"
    android:resource="@color/notification_color" />
```

ثم أضف اللون في `android/app/src/main/res/values/colors.xml`:

```xml
<resources>
    <color name="notification_color">#053391</color>
</resources>
```

## ملاحظات مهمة

### الأيقونة الصغيرة (48×48 mdpi)

النص "أميال" + "AMIAL PAY" + "دفع سريع وآمن" مكدّس عمودياً → في 48×48 يبدو مزدحماً. على شاشات mdpi (نادرة الآن، معظم الأجهزة hdpi+)، النص يصبح غير قابل للقراءة. لكن:
- معظم الأجهزة الحديثة hdpi+ (72px+) → النص واضح
- الـ Adaptive Icon (Android 8+) يعرض الـ foreground في 67% من المساحة → الأيقونة الكبيرة الكاملة تظهر
- Adaptive Icon هو الذي يُعرض في معظم launchers الحديثة

إن أردت لاحقاً أيقونة symbol-only للأحجام الصغيرة (مثلاً فقط "أ" بحجم كبير على خلفية صفراء)، أعطني الكلمة وأولّدها.

### iOS لا يدعم شفافية

iOS app icons لا يدعم شفافية — لذا كل iOS icons لها خلفية صفراء صلبة (`#FECA1E`).

### الـ Splash

flutter_native_splash يولّد splash screens تلقائياً لكل densities ومقاسات أجهزة iOS من ملف واحد. لا تضع splash يدوياً.

## التحقق بعد التطبيق

```bash
cd User_app
flutter clean
flutter pub get
flutter pub run flutter_native_splash:create
flutter build apk --debug

# تحقق من الأيقونة في:
# User_app/build/app/outputs/flutter-apk/app-debug.apk
# (افتح بـ apktool أو فقط نصّبه على جهاز)
```
