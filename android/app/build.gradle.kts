plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
    id("org.jetbrains.kotlin.plugin.compose")
    id("org.jetbrains.kotlin.plugin.serialization")
}

// Nomor versi naik otomatis di GitHub Actions (nomor run), supaya APK baru
// selalu bisa dipasang menimpa yang lama.
val buildNumber = (System.getenv("GITHUB_RUN_NUMBER") ?: "1").toInt()

android {
    namespace = "id.cheerychic.wmsscan"
    compileSdk = 35

    defaultConfig {
        applicationId = "id.cheerychic.wmsscan"
        minSdk = 24
        targetSdk = 35
        versionCode = buildNumber
        versionName = "1.0.$buildNumber"
    }

    // Kunci tanda tangan diambil dari GitHub Secrets (lihat README). Dengan
    // kunci tetap, APK baru bisa dipasang menimpa yang lama. Tanpa secret,
    // APK ditandatangani kunci debug sementara (versi baru harus uninstall
    // dulu yang lama).
    val keystoreFile = file("release.keystore")
    val hasReleaseKey = keystoreFile.exists() && !System.getenv("WMS_KEYSTORE_PASSWORD").isNullOrEmpty()

    signingConfigs {
        if (hasReleaseKey) {
            create("shared") {
                storeFile = keystoreFile
                storePassword = System.getenv("WMS_KEYSTORE_PASSWORD")
                keyAlias = System.getenv("WMS_KEY_ALIAS")?.takeIf { it.isNotEmpty() } ?: "wmsscan"
                keyPassword = System.getenv("WMS_KEY_PASSWORD")?.takeIf { it.isNotEmpty() }
                    ?: System.getenv("WMS_KEYSTORE_PASSWORD")
            }
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = false
            signingConfig = signingConfigs.findByName("shared") ?: signingConfigs.getByName("debug")
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions {
        jvmTarget = "17"
    }
    buildFeatures {
        compose = true
    }
    lint {
        abortOnError = false
        checkReleaseBuilds = false
    }
}

dependencies {
    val composeBom = platform("androidx.compose:compose-bom:2024.12.01")
    implementation(composeBom)
    implementation("androidx.compose.ui:ui")
    implementation("androidx.compose.ui:ui-tooling-preview")
    implementation("androidx.compose.material3:material3")
    implementation("androidx.compose.material:material-icons-core")
    implementation("androidx.activity:activity-compose:1.9.3")
    implementation("androidx.core:core-ktx:1.15.0")
    implementation("androidx.lifecycle:lifecycle-runtime-ktx:2.8.7")
    implementation("androidx.lifecycle:lifecycle-runtime-compose:2.8.7")
    implementation("androidx.lifecycle:lifecycle-viewmodel-compose:2.8.7")
    implementation("androidx.navigation:navigation-compose:2.8.5")

    implementation("com.squareup.okhttp3:okhttp:4.12.0")
    implementation("org.jetbrains.kotlinx:kotlinx-serialization-json:1.7.3")
    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-android:1.9.0")

    // Kamera + pembaca barcode/QR (model ikut di APK, tanpa Google Play).
    val camerax = "1.4.1"
    implementation("androidx.camera:camera-core:$camerax")
    implementation("androidx.camera:camera-camera2:$camerax")
    implementation("androidx.camera:camera-lifecycle:$camerax")
    implementation("androidx.camera:camera-view:$camerax")
    implementation("com.google.mlkit:barcode-scanning:17.3.0")

    // Foto produk.
    implementation("io.coil-kt:coil-compose:2.7.0")

    debugImplementation("androidx.compose.ui:ui-tooling")
}
