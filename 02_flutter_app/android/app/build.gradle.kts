import java.util.Properties
import java.io.FileInputStream

plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
    id("dev.flutter.flutter-gradle-plugin")
}

val keystoreProperties = Properties()
val keystorePropertiesFile = rootProject.file("key.properties")
if (keystorePropertiesFile.exists()) {
    keystoreProperties.load(FileInputStream(keystorePropertiesFile))
}

android {
    // AMYAL-BRANDING-001
    namespace = "com.amyalpay.app"
    compileSdk = flutter.compileSdkVersion

    defaultConfig {
        applicationId = "com.amyalpay.app"
        minSdk = 21  // AMYAL-BRANDING-001: ثابت 21 لـ secure storage + adaptive icons
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
            // AMYAL-BRANDING-001: release موقّع بـ debug keystore حتى يُولّد keystore حقيقي
            // (وفق قرار المستخدم: توقيع release ليس أولوية الآن)
            signingConfig = signingConfigs.getByName("debug")

            // تعطيل minify/shrink للتسليم السريع — أعِد تفعيلها للـ production الحقيقي
            isMinifyEnabled = false
            isShrinkResources = false
        }
        getByName("debug") {
            applicationIdSuffix = ".debug"
            versionNameSuffix = "-debug"
        }
    }
}

flutter {
    source = "../.."
}

dependencies {
    coreLibraryDesugaring("com.android.tools:desugar_jdk_libs:2.1.4")
    implementation("com.google.firebase:firebase-messaging:23.4.1")
    implementation("com.google.mlkit:face-detection:16.1.5")
    implementation("com.google.mlkit:barcode-scanning:17.0.2")
}