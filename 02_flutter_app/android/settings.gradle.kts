pluginManagement {
    val flutterSdkPath = run {
        val properties = java.util.Properties()
        file("local.properties").inputStream().use { properties.load(it) }
        val flutterSdkPath = properties.getProperty("flutter.sdk")
        require(flutterSdkPath != null) { "flutter.sdk not set in local.properties" }
        flutterSdkPath
    }

    includeBuild("$flutterSdkPath/packages/flutter_tools/gradle")

    repositories {
        google()
        mavenCentral()
        gradlePluginPortal()
    }
}

plugins {
    id("dev.flutter.flutter-plugin-loader") version "1.0.0"
    // ══════════════════════════════════════════════════════════════════
    //  AMIAL-BUILD-AGP-001 — **أدنى ما يقبله Flutter، لا أحدثَ ما وُجد.**
    //
    //  سقط بناءُ Codemagic بعد ٦٤ ثانيةً من `assembleRelease`:
    //
    //      Your project's Android Gradle Plugin version (8.9.1) is lower
    //      than Flutter's minimum supported version of 8.11.1.
    //
    //  و`flutter: stable` في `codemagic.yaml` يتحرّك من تلقائه، فرفعت
    //  نسخةٌ جديدةٌ من Flutter حدَّها الأدنى — **والمشروعُ ثابتٌ فتخلّف**.
    //  (ولا يظهر محلّيّاً: `flutter analyze` لا يمسّ Gradle إطلاقاً.)
    //
    //  **ولا يُقفز إلى AGP 9:** رسالةُ البناء نفسُها تحذّر أنّ التاسع
    //  يقرأ `newDsl` وحدَه، فيحتاج إعادةَ كتابة ملفّات Gradle كلِّها.
    //  و8.11.1 هو المطلوبُ حرفاً بحرف، ويعمل مع Gradle 8.14.3 المثبّت.
    id("com.android.application") version "8.11.1" apply false
    id("org.jetbrains.kotlin.android") version "2.1.0" apply false
    // AMIAL-FCM-001: 4.3.15 يسبق AGP 8 ويتعثّر مع Gradle الحديث؛ 4.4.2 هو المتوافق.
    id("com.google.gms.google-services") version "4.4.2" apply false
    // AMIAL-CRASH-001: الحزمة وحدها ترفع أعطال Dart. أمّا انهيارات الطبقة
    // الأصلية (NDK/Java) ورموز الأسطر في بناء release — حيث يُشوَّه الكود —
    // فتحتاج هذه الإضافة لرفع خرائط الرموز، وإلّا وصل الأثر غير مقروء.
    id("com.google.firebase.crashlytics") version "3.0.2" apply false

}

include(":app")