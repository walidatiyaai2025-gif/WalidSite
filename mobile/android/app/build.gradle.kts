plugins {
    id("com.android.application")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}


// Signing is configured only from CI-provided ephemeral environment values.
// Debug QA is unaffected; an unsigned release must never silently build.
val signingEnvironment = listOf(
    "WALIDSITE_ANDROID_KEYSTORE_PATH",
    "WALIDSITE_ANDROID_KEY_ALIAS",
    "WALIDSITE_ANDROID_STORE_PASSWORD",
    "WALIDSITE_ANDROID_KEY_PASSWORD",
).associateWith { System.getenv(it) }
val hasReleaseSigning = signingEnvironment.values.all { !it.isNullOrBlank() }
val requestedReleaseTask = gradle.startParameter.taskNames.any {
    it.contains("release", ignoreCase = true)
}
if (requestedReleaseTask) {
    require(hasReleaseSigning) {
        "WalidSite release builds require a configured private signing identity"
    }
    require(file(signingEnvironment.getValue("WALIDSITE_ANDROID_KEYSTORE_PATH")!!).isFile) {
        "WalidSite release keystore file is not available"
    }
}

android {
    namespace = "com.walidatiya.walidsite"
    compileSdk = flutter.compileSdkVersion
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    defaultConfig {
        // Canonical pre-distribution package identity (not proof of store ownership).
        applicationId = "com.walidatiya.walidsite"
        // You can update the following values to match your application needs.
        // For more information, see: https://flutter.dev/to/review-gradle-config.
        minSdk = flutter.minSdkVersion
        targetSdk = flutter.targetSdkVersion
        versionCode = flutter.versionCode
        versionName = flutter.versionName
    }

    signingConfigs {
        if (hasReleaseSigning) {
            create("walidsiteProduction") {
                storeFile = file(signingEnvironment.getValue("WALIDSITE_ANDROID_KEYSTORE_PATH")!!)
                storePassword = signingEnvironment.getValue("WALIDSITE_ANDROID_STORE_PASSWORD")!!
                keyAlias = signingEnvironment.getValue("WALIDSITE_ANDROID_KEY_ALIAS")!!
                keyPassword = signingEnvironment.getValue("WALIDSITE_ANDROID_KEY_PASSWORD")!!
            }
        }
    }

    buildTypes {
        release {
            // Never default to the Flutter/Android debug signing identity.
            // Build-time presence is necessary but not sufficient: release CI
            // additionally verifies the actual APK cert against a pinned SHA.
            if (hasReleaseSigning) {
                signingConfig = signingConfigs.getByName("walidsiteProduction")
            }
        }
    }
}

kotlin {
    compilerOptions {
        jvmTarget = org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17
    }
}

flutter {
    source = "../.."
}
