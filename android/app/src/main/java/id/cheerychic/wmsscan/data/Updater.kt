package id.cheerychic.wmsscan.data

import android.content.Context
import android.content.Intent
import android.content.pm.PackageInfo
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.provider.Settings
import androidx.core.content.FileProvider
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import okhttp3.OkHttpClient
import okhttp3.Request
import java.io.File
import java.io.IOException
import java.util.concurrent.TimeUnit

/**
 * Pembaruan aplikasi dari dalam aplikasi (APK tidak lewat Play Store).
 *
 * Alurnya: tanya server WMS versi terbaru -> bila lebih baru, unduh APK ke
 * cache aplikasi -> buka penginstal Android. Android tetap meminta operator
 * menekan "Perbarui", dan sekali saja meminta izin "Instal aplikasi tidak
 * dikenal" untuk aplikasi ini.
 */
object Updater {
    sealed interface State {
        data object Idle : State
        data object Checking : State
        data object UpToDate : State
        data class Available(val release: AppRelease, val forced: Boolean) : State
        data class Downloading(val release: AppRelease, val forced: Boolean, val progress: Float?) : State
        data class NeedPermission(val release: AppRelease, val forced: Boolean, val file: File) : State
        data class ReadyToInstall(val release: AppRelease, val forced: Boolean, val file: File) : State
        data class Failed(val message: String, val release: AppRelease?, val forced: Boolean) : State
    }

    private lateinit var app: Context
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Main)
    private var job: Job? = null
    private var lastCheck = 0L
    private var snoozed: Long? = null

    private val _state = MutableStateFlow<State>(State.Idle)
    val state: StateFlow<State> = _state

    private val client = OkHttpClient.Builder()
        .connectTimeout(20, TimeUnit.SECONDS)
        .readTimeout(60, TimeUnit.SECONDS)
        .build()

    fun init(context: Context) {
        app = context.applicationContext
        // APK lama sisa pembaruan sebelumnya tidak perlu disimpan.
        runCatching { updateDir().listFiles()?.forEach { it.delete() } }
    }

    val versionCode: Long
        get() = runCatching {
            val info = app.packageManager.getPackageInfo(app.packageName, 0)
            if (Build.VERSION.SDK_INT >= 28) info.longVersionCode else @Suppress("DEPRECATION") info.versionCode.toLong()
        }.getOrDefault(0L)

    val versionName: String
        get() = runCatching { app.packageManager.getPackageInfo(app.packageName, 0).versionName }.getOrNull() ?: "-"

    /**
     * Cek versi terbaru. Cek otomatis paling sering sekali per 30 menit dan
     * diam bila gagal; cek manual selalu jalan dan menampilkan hasilnya.
     */
    fun check(manual: Boolean = false) {
        val server = Session.serverUrl
        if (server.isBlank()) return
        if (job?.isActive == true) return
        when (_state.value) {
            is State.Downloading, is State.NeedPermission, is State.ReadyToInstall -> return
            else -> Unit
        }
        val now = System.currentTimeMillis()
        if (!manual && now - lastCheck < 30 * 60 * 1000L) return
        lastCheck = now

        job = scope.launch {
            if (manual) _state.value = State.Checking
            val response = try {
                Api.appVersion(server)
            } catch (e: Exception) {
                _state.value = if (manual) {
                    State.Failed(
                        (e as? ApiException)?.message ?: "Belum bisa memeriksa pembaruan.",
                        null,
                        false,
                    )
                } else {
                    State.Idle
                }
                return@launch
            }

            val latest = response.latest
            val current = versionCode
            if (latest == null || latest.versionCode <= current) {
                _state.value = if (manual) State.UpToDate else State.Idle
                return@launch
            }

            val forced = current < response.minVersionCode
            if (!manual && !forced && snoozed == latest.versionCode) {
                _state.value = State.Idle
                return@launch
            }
            _state.value = State.Available(latest, forced)
        }
    }

    /** "Nanti": tidak ditanya lagi untuk versi ini sampai aplikasi dibuka ulang. */
    fun dismiss() {
        val current = _state.value
        if (current is State.Available) snoozed = current.release.versionCode
        if (current is State.Downloading) job?.cancel()
        _state.value = State.Idle
    }

    fun download(release: AppRelease, forced: Boolean) {
        if (job?.isActive == true) return
        job = scope.launch {
            _state.value = State.Downloading(release, forced, null)
            try {
                val file = fetchApk(release) { progress ->
                    _state.value = State.Downloading(release, forced, progress)
                }
                _state.value = if (canInstall()) {
                    State.ReadyToInstall(release, forced, file)
                } else {
                    State.NeedPermission(release, forced, file)
                }
            } catch (e: kotlinx.coroutines.CancellationException) {
                throw e
            } catch (e: Exception) {
                _state.value = State.Failed(
                    e.message?.takeIf { e is ApiException } ?: "Unduhan gagal. Periksa internet lalu coba lagi.",
                    release,
                    forced,
                )
            }
        }
    }

    /** Dipanggil saat aplikasi kembali tampil (mis. selesai dari layar izin). */
    fun onResume() {
        val current = _state.value
        if (current is State.NeedPermission && canInstall()) {
            _state.value = State.ReadyToInstall(current.release, current.forced, current.file)
        }
    }

    fun canInstall(): Boolean =
        Build.VERSION.SDK_INT < 26 || app.packageManager.canRequestPackageInstalls()

    fun openInstallPermission(context: Context) {
        if (Build.VERSION.SDK_INT < 26) return
        val intent = Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES, Uri.parse("package:${app.packageName}"))
        runCatching { context.startActivity(intent) }
            .onFailure { context.startActivity(Intent(Settings.ACTION_SECURITY_SETTINGS)) }
    }

    fun install(context: Context, file: File) {
        val uri = FileProvider.getUriForFile(context, "${app.packageName}.updates", file)
        val intent = Intent(Intent.ACTION_VIEW)
            .setDataAndType(uri, "application/vnd.android.package-archive")
            .addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION or Intent.FLAG_ACTIVITY_NEW_TASK)
        runCatching { context.startActivity(intent) }.onFailure {
            val current = _state.value
            if (current is State.ReadyToInstall) {
                _state.value = State.Failed("Penginstal Android tidak bisa dibuka.", current.release, current.forced)
            }
        }
    }

    private fun updateDir() = File(app.cacheDir, "updates")

    @Suppress("DEPRECATION")
    private fun signatureFlags(): Int =
        if (Build.VERSION.SDK_INT >= 28) PackageManager.GET_SIGNING_CERTIFICATES else PackageManager.GET_SIGNATURES

    @Suppress("DEPRECATION")
    private fun signatures(info: PackageInfo?): Set<String>? {
        if (info == null) return null
        val list = if (Build.VERSION.SDK_INT >= 28) {
            info.signingInfo?.let { if (it.hasMultipleSigners()) it.apkContentsSigners else it.signingCertificateHistory }
        } else {
            info.signatures
        }
        return list?.map { it.toCharsString() }?.toSet()
    }

    private suspend fun fetchApk(release: AppRelease, onProgress: (Float?) -> Unit): File = withContext(Dispatchers.IO) {
        val url = if (release.downloadUrl.startsWith("/")) Session.serverUrl + release.downloadUrl else release.downloadUrl
        val request = try {
            Request.Builder().url(url).build()
        } catch (e: IllegalArgumentException) {
            throw ApiException("Alamat unduhan pembaruan tidak valid.")
        }

        val dir = updateDir().apply { mkdirs() }
        val part = File(dir, "wms-cheery-scan.apk.part")
        val target = File(dir, "wms-cheery-scan-${release.versionCode}.apk")

        try {
            client.newCall(request).execute().use { response ->
                if (!response.isSuccessful) throw ApiException("Unduhan gagal (${response.code}). Coba lagi sebentar.")
                val body = response.body ?: throw ApiException("Berkas pembaruan kosong.")
                val total = body.contentLength().takeIf { it > 0 } ?: release.size ?: -1L
                var lastReported = -1
                body.byteStream().use { input ->
                    part.outputStream().use { output ->
                        val buffer = ByteArray(64 * 1024)
                        var done = 0L
                        while (true) {
                            val read = input.read(buffer)
                            if (read < 0) break
                            output.write(buffer, 0, read)
                            done += read
                            if (total > 0) {
                                val percent = (done * 100 / total).toInt()
                                if (percent != lastReported) {
                                    lastReported = percent
                                    withContext(Dispatchers.Main) { onProgress((done.toFloat() / total).coerceIn(0f, 1f)) }
                                }
                            }
                        }
                    }
                }
            }
        } catch (e: IOException) {
            part.delete()
            throw ApiException("Unduhan terputus. Periksa internet lalu coba lagi.")
        }

        // Pastikan yang terunduh memang APK aplikasi ini dan lebih baru.
        val info = app.packageManager.getPackageArchiveInfo(part.path, signatureFlags())
        val code = info?.let {
            if (Build.VERSION.SDK_INT >= 28) it.longVersionCode else @Suppress("DEPRECATION") it.versionCode.toLong()
        }
        if (info == null || info.packageName != app.packageName || code == null || code <= versionCode) {
            part.delete()
            throw ApiException("Berkas pembaruan tidak cocok dengan aplikasi ini.")
        }

        // Kunci tanda tangan beda = Android pasti menolak menimpa. Beri tahu jelas.
        val installed = runCatching { signatures(app.packageManager.getPackageInfo(app.packageName, signatureFlags())) }.getOrNull()
        val incoming = signatures(info)
        if (!installed.isNullOrEmpty() && !incoming.isNullOrEmpty() && installed.intersect(incoming).isEmpty()) {
            part.delete()
            throw ApiException(
                "Versi baru ditandatangani kunci yang berbeda, jadi tidak bisa menimpa aplikasi ini. " +
                    "Hapus aplikasi ini sekali, lalu pasang APK terbaru dari halaman rilis."
            )
        }

        target.delete()
        if (!part.renameTo(target)) throw ApiException("Berkas pembaruan tidak bisa disimpan.")
        target
    }
}
