import java.util.Properties
import java.io.FileInputStream

plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
    id("dev.flutter.flutter-gradle-plugin")
    // AMIAL-FCM-001: بدون تطبيق هذه الإضافة لا يُقرأ google-services.json إطلاقاً،
    // فلا يُولَّد values.xml ولا تُسجَّل الحزمة لدى Firebase — الإشعارات تصمت بلا خطأ.
    // كانت معرَّفة في settings.gradle.kts بـ `apply false` ولم تُطبَّق هنا قطّ.
    id("com.google.gms.google-services")
    // AMIAL-CRASH-001: تُطبَّق بعد google-services — تقرأ منها معرّف المشروع.
    id("com.google.firebase.crashlytics")
}

val keystoreProperties = Properties()
val keystorePropertiesFile = rootProject.file("key.properties")
if (keystorePropertiesFile.exists()) {
    keystoreProperties.load(FileInputStream(keystorePropertiesFile))
}

android {
    // AMIAL-BRANDING-001
    namespace = "com.amialpay.app"
    compileSdk = flutter.compileSdkVersion

    defaultConfig {
        applicationId = "com.amialpay.app"
        minSdk = 21  // AMIAL-BRANDING-001: ثابت 21 لـ secure storage + adaptive icons
        targetSdk = flutter.targetSdkVersion
        versionCode = flutter.versionCode
        versionName = flutter.versionName
        multiDexEnabled = true
    }

    compileOptions {
        isCoreLibraryDesugaringEnabled = true
        sourceCompatibility = JavaVersion.VERSION_11
        targetCompatibility = JavaVersion.VERSION_11
    }

    kotlinOptions {
        jvmTarget = JavaVersion.VERSION_11.toString()
    }


    signingConfigs {
        create("release") {
            keyAlias = keystoreProperties["keyAlias"] as String?
            keyPassword = keystoreProperties["keyPassword"] as String?
            storeFile = keystoreProperties["storeFile"]?.let { file(it as String) }
            storePassword = keystoreProperties["storePassword"] as String?
        }
    }

    buildTypes {
        getByName("release") {
            // AMIAL-BRANDING-001: release موقّع بـ debug keystore حتى يُولّد keystore حقيقي
            // (وفق قرار المستخدم: توقيع release ليس أولوية الآن)
            signingConfig = signingConfigs.getByName("debug")

            // تعطيل minify/shrink للتسليم السريع — أعِد تفعيلها للـ production الحقيقي
            isMinifyEnabled = false
            isShrinkResources = false
        }
        getByName("debug") {
            // AMIAL-FCM-001: أُزيل applicationIdSuffix = ".debug".
            // الحزمة المسجّلة في Firebase هي com.amialpay.app فقط؛ ومع اللاحقة
            // تصير حزمة نسخة التطوير com.amialpay.app.debug فيفشل بناء debug
            // بـ "No matching client found for package name" — ولو نجح لما وصلت
            // إشعارة واحدة أثناء الاختبار.
            versionNameSuffix = "-debug"
        }
    }
}

flutter {
    source = "../.."
}

dependencies {
    coreLibraryDesugaring("com.android.tools:desugar_jdk_libs:2.1.4")
    // AMIAL-FCM-001: أُزيل التثبيت اليدوي firebase-messaging:23.4.1 (بقايا القالب).
    // إضافة firebase_messaging لـ Flutter تجلب نسختها المتوافقة، والتثبيت اليدوي
    // على نسخة أقدم يخاطر بخلط إصدارات Firebase داخل البناء الواحد.
    implementation("com.google.mlkit:face-detection:16.1.5")
    implementation("com.google.mlkit:barcode-scanning:17.0.2")
}