package id.cheerychic.wmsscan.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.window.DialogProperties
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.compose.LocalLifecycleOwner
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.repeatOnLifecycle
import id.cheerychic.wmsscan.data.AppRelease
import id.cheerychic.wmsscan.data.Updater
import id.cheerychic.wmsscan.data.Updater.State
import id.cheerychic.wmsscan.ui.components.ProgressLine
import id.cheerychic.wmsscan.ui.theme.Brand2
import id.cheerychic.wmsscan.ui.theme.Danger
import id.cheerychic.wmsscan.ui.theme.Ink
import id.cheerychic.wmsscan.ui.theme.InkMuted

/**
 * Jendela "Versi baru tersedia" — cek otomatis setiap aplikasi dibuka /
 * kembali tampil, unduh APK, lalu buka penginstal Android.
 */
@Composable
fun UpdatePrompt() {
    val context = LocalContext.current
    val lifecycle = LocalLifecycleOwner.current
    val state by Updater.state.collectAsStateWithLifecycle()

    LaunchedEffect(Unit) {
        lifecycle.lifecycle.repeatOnLifecycle(Lifecycle.State.RESUMED) {
            Updater.onResume()
            Updater.check()
        }
    }

    // Begitu APK siap (dan izin sudah ada), langsung buka penginstal.
    LaunchedEffect(state) {
        val ready = state as? State.ReadyToInstall ?: return@LaunchedEffect
        Updater.install(context, ready.file)
    }

    when (val s = state) {
        State.Idle -> Unit

        State.Checking -> UpdateDialog(
            title = "Memeriksa pembaruan…",
            body = { Text("Sebentar, sedang menanyakan versi terbaru ke server.", color = InkMuted) },
            confirm = null,
            dismiss = null,
            onDismissRequest = {},
        )

        State.UpToDate -> UpdateDialog(
            title = "Sudah versi terbaru",
            body = { Text("Aplikasi Anda versi ${Updater.versionName}. Belum ada pembaruan.", color = InkMuted) },
            confirm = "OK" to { Updater.dismiss() },
            dismiss = null,
            onDismissRequest = { Updater.dismiss() },
        )

        is State.Available -> UpdateDialog(
            title = if (s.forced) "Perlu pembaruan" else "Versi baru tersedia",
            body = { ReleaseInfo(s.release, s.forced) },
            confirm = "Perbarui" to { Updater.download(s.release, s.forced) },
            dismiss = if (s.forced) null else "Nanti" to { Updater.dismiss() },
            onDismissRequest = { if (!s.forced) Updater.dismiss() },
        )

        is State.Downloading -> UpdateDialog(
            title = "Mengunduh pembaruan…",
            body = {
                Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    Text(
                        "Versi ${s.release.versionName}" + (s.progress?.let { " · ${(it * 100).toInt()}%" } ?: ""),
                        color = InkMuted,
                    )
                    ProgressLine(s.progress ?: 0f)
                    Text("Jangan tutup aplikasi sampai unduhan selesai.", color = InkMuted, fontSize = 12.5.sp)
                }
            },
            confirm = null,
            dismiss = if (s.forced) null else "Batal" to { Updater.dismiss() },
            onDismissRequest = {},
        )

        is State.NeedPermission -> UpdateDialog(
            title = "Izinkan pemasangan",
            body = {
                Text(
                    "Android perlu izin \"Instal aplikasi tidak dikenal\" untuk WMS Cheery Scan (cukup sekali). " +
                        "Tekan Buka pengaturan, nyalakan izinnya, lalu kembali ke aplikasi ini.",
                    color = InkMuted,
                )
            },
            confirm = "Buka pengaturan" to { Updater.openInstallPermission(context) },
            dismiss = if (s.forced) null else "Nanti" to { Updater.dismiss() },
            onDismissRequest = {},
        )

        is State.ReadyToInstall -> UpdateDialog(
            title = "Siap dipasang",
            body = {
                Text(
                    "Tekan \"Perbarui\" / \"Instal\" di layar Android yang muncul. Data login tetap tersimpan.",
                    color = InkMuted,
                )
            },
            confirm = "Pasang" to { Updater.install(context, s.file) },
            dismiss = if (s.forced) null else "Nanti" to { Updater.dismiss() },
            onDismissRequest = {},
        )

        is State.Failed -> UpdateDialog(
            title = "Pembaruan gagal",
            body = { Text(s.message, color = Danger) },
            confirm = if (s.release != null) "Coba lagi" to { Updater.download(s.release, s.forced) } else "OK" to { Updater.dismiss() },
            dismiss = if (s.release != null && !s.forced) "Tutup" to { Updater.dismiss() } else null,
            onDismissRequest = { if (!s.forced) Updater.dismiss() },
        )
    }
}

@Composable
private fun ReleaseInfo(release: AppRelease, forced: Boolean) {
    Column(
        Modifier.heightIn(max = 280.dp).verticalScroll(rememberScrollState()),
        verticalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        Text(
            "Versi ${release.versionName} siap dipasang (sekarang ${Updater.versionName})." +
                (release.size?.let { " Ukuran %.1f MB.".format(it / 1_048_576.0) } ?: ""),
            color = Ink,
        )
        if (forced) {
            Text("Versi ini wajib dipasang sebelum aplikasi bisa dipakai lagi.", color = Brand2, fontWeight = FontWeight.SemiBold)
        }
        release.notes?.takeIf { it.isNotBlank() }?.let {
            Text("Yang baru:", color = Ink, fontWeight = FontWeight.SemiBold)
            Text(it, color = InkMuted, fontSize = 13.5.sp)
        }
    }
}

@Composable
private fun UpdateDialog(
    title: String,
    body: @Composable () -> Unit,
    confirm: Pair<String, () -> Unit>?,
    dismiss: Pair<String, () -> Unit>?,
    onDismissRequest: () -> Unit,
) {
    AlertDialog(
        onDismissRequest = onDismissRequest,
        title = { Text(title, fontWeight = FontWeight.Bold) },
        text = body,
        confirmButton = {
            confirm?.let { (label, action) ->
                TextButton(onClick = action) { Text(label, color = Brand2, fontWeight = FontWeight.Bold) }
            }
        },
        dismissButton = dismiss?.let { (label, action) ->
            { TextButton(onClick = action) { Text(label, color = InkMuted) } }
        },
        containerColor = Color.White,
        properties = DialogProperties(dismissOnBackPress = false, dismissOnClickOutside = false),
    )
}
