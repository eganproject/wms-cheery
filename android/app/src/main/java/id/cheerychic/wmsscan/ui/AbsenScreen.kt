package id.cheerychic.wmsscan.ui

import android.Manifest
import android.content.pm.PackageManager
import android.location.Location
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.ContextCompat
import id.cheerychic.wmsscan.data.AbsenResult
import id.cheerychic.wmsscan.data.AbsenToday
import id.cheerychic.wmsscan.data.Api
import id.cheerychic.wmsscan.data.ApiException
import id.cheerychic.wmsscan.data.Fix
import id.cheerychic.wmsscan.data.Locator
import id.cheerychic.wmsscan.ui.components.BigButton
import id.cheerychic.wmsscan.ui.components.Feedback
import id.cheerychic.wmsscan.ui.components.Header
import id.cheerychic.wmsscan.ui.components.Ic
import id.cheerychic.wmsscan.ui.components.SelfieCamera
import id.cheerychic.wmsscan.ui.components.SelfieCapture
import id.cheerychic.wmsscan.ui.components.StatusBarIcons
import id.cheerychic.wmsscan.ui.theme.Bg
import id.cheerychic.wmsscan.ui.theme.Brand
import id.cheerychic.wmsscan.ui.theme.Danger
import id.cheerychic.wmsscan.ui.theme.DangerBg
import id.cheerychic.wmsscan.ui.theme.Ink
import id.cheerychic.wmsscan.ui.theme.InkMuted
import id.cheerychic.wmsscan.ui.theme.Night
import id.cheerychic.wmsscan.ui.theme.OnBrand
import id.cheerychic.wmsscan.ui.theme.Success
import id.cheerychic.wmsscan.ui.theme.Warning
import kotlinx.coroutines.launch
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

private sealed interface AbsenState {
    data object Preparing : AbsenState
    data class Ready(val fix: Fix?, val locating: Boolean, val problem: String? = null) : AbsenState
    data object Sending : AbsenState
    data class Done(val result: AbsenResult) : AbsenState
    data class Refused(val message: String, val location: Boolean) : AbsenState
}

/**
 * Absen masuk / pulang: baca lokasi, ambil selfie, kirim.
 *
 * [type] "in" atau "out".
 */
@Composable
fun AbsenScreen(type: String, onBack: () -> Unit, onDone: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val capture = remember { SelfieCapture() }
    var today by remember { mutableStateOf<AbsenToday?>(null) }
    var state by remember { mutableStateOf<AbsenState>(AbsenState.Preparing) }
    var cameraBroken by remember { mutableStateOf(false) }
    val title = if (type == "out") "Absen Pulang" else "Absen Masuk"

    fun has(permission: String) = ContextCompat.checkSelfPermission(context, permission) == PackageManager.PERMISSION_GRANTED
    var cameraGranted by remember { mutableStateOf(has(Manifest.permission.CAMERA)) }
    var locationGranted by remember { mutableStateOf(has(Manifest.permission.ACCESS_FINE_LOCATION) || has(Manifest.permission.ACCESS_COARSE_LOCATION)) }

    fun locate() {
        val current = state as? AbsenState.Ready
        state = AbsenState.Ready(current?.fix, locating = true)
        scope.launch {
            state = try {
                AbsenState.Ready(Locator.current(context), locating = false)
            } catch (e: Exception) {
                AbsenState.Ready(null, locating = false, problem = e.message ?: "Lokasi belum terbaca.")
            }
        }
    }

    val permissions = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { result ->
        cameraGranted = has(Manifest.permission.CAMERA)
        locationGranted = has(Manifest.permission.ACCESS_FINE_LOCATION) || has(Manifest.permission.ACCESS_COARSE_LOCATION)
        if (locationGranted) locate() else state = AbsenState.Ready(null, false, "Izin lokasi ditolak. Absen dari HP butuh lokasi untuk memastikan kamu di gudang.")
    }

    LaunchedEffect(Unit) {
        today = runCatching { Api.absenToday() }.getOrNull()
        val missing = listOf(Manifest.permission.CAMERA, Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION)
            .filterNot { has(it) }
        if (missing.isEmpty() || (locationGranted && cameraGranted)) locate() else permissions.launch(missing.toTypedArray())
    }

    fun send() {
        val ready = state as? AbsenState.Ready ?: return
        val fix = ready.fix ?: return
        val needSelfie = today?.requireSelfie ?: true
        state = AbsenState.Sending
        scope.launch {
            try {
                val selfie = if (needSelfie || (cameraGranted && !cameraBroken)) runCatching { capture.take() }.getOrNull() else null
                if (needSelfie && selfie == null) {
                    state = AbsenState.Ready(fix, false, "Foto selfie gagal diambil. Pastikan kamera depan tidak dipakai aplikasi lain.")
                    return@launch
                }
                val result = Api.absen(type, fix.latitude, fix.longitude, fix.accuracy, fix.isMock, selfie)
                Feedback.done(context)
                state = AbsenState.Done(result)
            } catch (e: ApiException) {
                Feedback.error(context)
                val message = e.message ?: "Absen gagal."
                state = AbsenState.Refused(message, location = message.contains("lokasi", ignoreCase = true))
            } catch (e: kotlinx.coroutines.CancellationException) {
                throw e
            } catch (e: Exception) {
                Feedback.error(context)
                state = AbsenState.Refused("Jawaban server tidak dikenali. Coba lagi.", location = false)
            }
        }
    }

    when (val current = state) {
        is AbsenState.Done -> DoneView(type, current.result, onDone)
        is AbsenState.Refused -> RefusedView(title, current, onBack) {
            state = AbsenState.Ready(null, false)
            locate()
        }
        else -> {
            StatusBarIcons(darkScreen = true)
            Column(Modifier.fillMaxSize().background(Night)) {
                Header(title, onBack = onBack, dark = true)
                Box(
                    Modifier
                        .padding(top = 18.dp)
                        .align(Alignment.CenterHorizontally)
                        .size(width = 270.dp, height = 340.dp)
                        .clip(RoundedCornerShape(140.dp))
                        .border(4.dp, Brand, RoundedCornerShape(140.dp))
                        .background(Color(0xFF2A2620)),
                    contentAlignment = Alignment.Center,
                ) {
                    if (cameraGranted && !cameraBroken) {
                        SelfieCamera(capture, Modifier.fillMaxSize(), onUnavailable = { cameraBroken = true })
                    } else {
                        Text(
                            if (cameraBroken) "Kamera depan tidak tersedia" else "Izin kamera belum diberikan",
                            color = Color(0xFFBDB6A3), fontSize = 13.sp, textAlign = TextAlign.Center,
                            modifier = Modifier.padding(24.dp),
                        )
                    }
                }
                Text(
                    "Posisikan wajah di dalam bingkai",
                    color = Color(0xFFC9C3B3), fontSize = 14.sp,
                    modifier = Modifier.align(Alignment.CenterHorizontally).padding(top = 14.dp),
                )

                Spacer(Modifier.height(18.dp))
                LocationCard(state, today, Modifier.padding(horizontal = 20.dp), onRetry = { locate() })

                Spacer(Modifier.weight(1f))
                val ready = state as? AbsenState.Ready
                val canSend = ready?.fix != null && !ready.locating
                Column(Modifier.padding(horizontal = 20.dp).navigationBarsPadding().padding(bottom = 16.dp)) {
                    if (state is AbsenState.Sending) {
                        Box(Modifier.fillMaxWidth().height(56.dp), contentAlignment = Alignment.Center) {
                            CircularProgressIndicator(color = Brand, strokeWidth = 3.dp, modifier = Modifier.size(28.dp))
                        }
                    } else {
                        BigButton(
                            if (today?.requireSelfie == false) "$title Sekarang" else "Ambil Foto & $title",
                            bg = Brand, fg = OnBrand, enabled = canSend,
                        ) { send() }
                    }
                }
            }
        }
    }
}

@Composable
private fun LocationCard(state: AbsenState, today: AbsenToday?, modifier: Modifier, onRetry: () -> Unit) {
    val ready = state as? AbsenState.Ready
    val fix = ready?.fix
    // Jarak ke lokasi gudang terdekat, dihitung di HP hanya sebagai petunjuk.
    val nearest = fix?.let { f ->
        today?.locations?.map { loc ->
            val out = FloatArray(1)
            Location.distanceBetween(f.latitude, f.longitude, loc.latitude, loc.longitude, out)
            loc to out[0].toInt()
        }?.minByOrNull { it.second }
    }
    val inside = nearest != null && nearest.second <= nearest.first.radiusMeters

    Column(
        modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(Color(0x12FFFFFF)).padding(horizontal = 16.dp, vertical = 14.dp),
        verticalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            val tint = when {
                ready?.problem != null -> Danger
                fix == null -> Color(0xFFBDB6A3)
                nearest == null || inside -> Color(0xFF5BE3A8)
                else -> Warning
            }
            Icon(Ic.Pin, null, tint = tint, modifier = Modifier.size(16.dp))
            Spacer(Modifier.width(10.dp))
            when {
                ready?.problem != null -> Text(ready.problem, color = Color.White, fontSize = 13.5.sp, modifier = Modifier.weight(1f))
                ready == null || ready.locating || fix == null -> {
                    Text("Membaca lokasi...", color = Color.White, fontSize = 14.sp, modifier = Modifier.weight(1f))
                    CircularProgressIndicator(color = Brand, strokeWidth = 2.dp, modifier = Modifier.size(16.dp))
                }
                nearest != null -> {
                    Text(nearest.first.name, color = Color.White, fontWeight = FontWeight.Bold, fontSize = 14.sp, modifier = Modifier.weight(1f))
                    Text(
                        "${distanceLabel(nearest.second)} " + if (inside) "✓" else "· di luar ${nearest.first.radiusMeters} m",
                        color = tint, fontWeight = FontWeight.Bold, fontSize = 12.5.sp,
                    )
                }
                else -> Text("Lokasi terbaca" + (fix.accuracy?.let { " (±${it.toInt()} m)" } ?: ""), color = Color.White, fontSize = 14.sp, modifier = Modifier.weight(1f))
            }
        }
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(Ic.Clock, null, tint = Color(0xFF9A9CA3), modifier = Modifier.size(14.dp))
            Spacer(Modifier.width(10.dp))
            Text(
                SimpleDateFormat("EEEE, d MMM yyyy · HH:mm", Locale("id")).format(Date()) + (today?.shift?.let { " · jam kerja ${it.start}–${it.end}" } ?: ""),
                color = Color(0xFF9A9CA3), fontSize = 12.5.sp, modifier = Modifier.weight(1f),
            )
        }
        if (ready?.problem != null || (fix != null && nearest != null && !inside)) {
            Text(
                "Baca ulang lokasi",
                color = Brand, fontWeight = FontWeight.Bold, fontSize = 13.sp,
                modifier = Modifier.clip(RoundedCornerShape(8.dp)).clickable(onClick = onRetry).padding(vertical = 2.dp),
            )
        }
    }
}

@Composable
private fun DoneView(type: String, result: AbsenResult, onDone: () -> Unit) {
    StatusBarIcons(darkScreen = true)
    val label = result.today.day.label
    val subtitle = when {
        type == "in" && result.today.day.lateMinutes > 0 -> "Telat ${result.today.day.lateMinutes} menit · jam kerja ${result.today.shift?.start ?: ""}"
        type == "in" -> "Tepat waktu" + (result.today.shift?.let { " · jam kerja ${it.start}" } ?: "")
        result.today.day.earlyMinutes > 0 -> "Pulang ${result.today.day.earlyMinutes} menit lebih awal"
        else -> "Terima kasih, hati-hati di jalan"
    }
    val bg = if (type == "in" && result.today.day.lateMinutes > 0) Warning else Success
    Column(
        Modifier.fillMaxSize().background(bg).padding(horizontal = 20.dp).navigationBarsPadding(),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Spacer(Modifier.weight(0.8f))
        Box(Modifier.size(116.dp).clip(CircleShape).background(Color.White), contentAlignment = Alignment.Center) {
            Icon(Ic.Check, null, tint = bg, modifier = Modifier.size(60.dp))
        }
        Spacer(Modifier.height(26.dp))
        Text(
            if (type == "out") "Absen pulang tercatat" else "Absen masuk tercatat",
            color = Color.White, fontSize = 26.sp, fontWeight = FontWeight.ExtraBold, textAlign = TextAlign.Center,
        )
        Text(result.punch.time, color = Color.White, fontSize = 56.sp, fontWeight = FontWeight.ExtraBold)
        Text(subtitle, color = Color.White.copy(alpha = 0.92f), fontSize = 16.sp, textAlign = TextAlign.Center)
        Spacer(Modifier.height(34.dp))
        Column(
            Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(Color(0x29FFFFFF)).padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            InfoRow("Lokasi", (result.punch.location ?: "—") + (result.punch.distanceMeters?.let { " · $it m" } ?: ""))
            InfoRow("Selfie", if (result.punch.hasSelfie) "Tersimpan" else "Tidak diambil")
            InfoRow("Status hari ini", label)
            if (result.punch.outsideRadius) InfoRow("Catatan", "Di luar lokasi, akan dicek admin")
        }
        Spacer(Modifier.weight(1f))
        BigButton("Kembali ke Beranda", bg = Color.White, fg = bg, modifier = Modifier.padding(bottom = 16.dp)) { onDone() }
    }
}

@Composable
private fun InfoRow(label: String, value: String) {
    Row {
        Text(label, color = Color.White.copy(alpha = 0.85f), fontSize = 14.sp, modifier = Modifier.weight(1f))
        Text(value, color = Color.White, fontWeight = FontWeight.Bold, fontSize = 14.sp, textAlign = TextAlign.End)
    }
}

@Composable
private fun RefusedView(title: String, state: AbsenState.Refused, onBack: () -> Unit, onRetry: () -> Unit) {
    StatusBarIcons(darkScreen = false)
    Column(Modifier.fillMaxSize().background(Bg).navigationBarsPadding()) {
        Header(title, onBack = onBack)
        Column(Modifier.padding(horizontal = 20.dp).weight(1f), horizontalAlignment = Alignment.CenterHorizontally) {
            Spacer(Modifier.height(60.dp))
            Box(Modifier.size(108.dp).clip(CircleShape).background(DangerBg), contentAlignment = Alignment.Center) {
                Icon(if (state.location) Ic.Pin else Ic.Alert, null, tint = Danger, modifier = Modifier.size(52.dp))
            }
            Spacer(Modifier.height(22.dp))
            Text(
                if (state.location) "Belum bisa absen di sini" else "Absen belum tercatat",
                color = Ink, fontSize = 23.sp, fontWeight = FontWeight.ExtraBold, textAlign = TextAlign.Center,
            )
            Spacer(Modifier.height(10.dp))
            Text(state.message, color = InkMuted, fontSize = 15.sp, lineHeight = 22.sp, textAlign = TextAlign.Center)
            if (state.location) {
                Spacer(Modifier.height(26.dp))
                Column(
                    Modifier.fillMaxWidth().clip(RoundedCornerShape(20.dp)).background(Color.White).padding(16.dp),
                ) {
                    Text("Sedang tugas luar?", color = Ink, fontWeight = FontWeight.Bold, fontSize = 14.sp)
                    Text(
                        "Minta admin gudang mencatat izin di WMS, atau nyalakan GPS dan coba lagi saat sudah di gudang.",
                        color = InkMuted, fontSize = 13.5.sp, lineHeight = 19.sp, modifier = Modifier.padding(top = 4.dp),
                    )
                }
            }
        }
        BigButton(if (state.location) "Cek Lokasi Lagi" else "Coba Lagi", modifier = Modifier.padding(horizontal = 20.dp, vertical = 16.dp)) { onRetry() }
    }
}

private fun distanceLabel(meters: Int): String =
    if (meters >= 1000) String.format(Locale("id"), "%.1f km", meters / 1000f) else "$meters m"

