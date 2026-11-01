package id.cheerychic.wmsscan.ui

import android.content.Context
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import androidx.lifecycle.viewmodel.compose.viewModel
import id.cheerychic.wmsscan.data.Api
import id.cheerychic.wmsscan.data.ApiException
import id.cheerychic.wmsscan.data.Session
import id.cheerychic.wmsscan.ui.components.AppBar
import id.cheerychic.wmsscan.ui.components.Banner
import id.cheerychic.wmsscan.ui.components.BannerView
import id.cheerychic.wmsscan.ui.components.Feedback
import id.cheerychic.wmsscan.ui.components.ScanPanel
import id.cheerychic.wmsscan.ui.components.Tone
import id.cheerychic.wmsscan.ui.theme.Brand
import id.cheerychic.wmsscan.ui.theme.Danger
import id.cheerychic.wmsscan.ui.theme.DangerBg
import id.cheerychic.wmsscan.ui.theme.InkLine
import id.cheerychic.wmsscan.ui.theme.InkMuted
import id.cheerychic.wmsscan.ui.theme.Success
import id.cheerychic.wmsscan.ui.theme.SuccessBg
import kotlinx.coroutines.launch
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

data class HandoverLog(
    val code: String,
    val ok: Boolean,
    val title: String,
    val detail: String,
    val time: String,
)

/**
 * Serah Terima Paket: setiap resi yang discan langsung dikirim (stok
 * berkurang), atau diajukan bila akun tidak punya hak menyetujui.
 */
class SerahTerimaViewModel : ViewModel() {
    val log = mutableStateListOf<HandoverLog>()
    var banner by mutableStateOf<Banner?>(Banner("Scan resi paket yang diserahkan ke kurir.", Tone.Info))
        private set
    var remaining by mutableStateOf<Int?>(null)
        private set
    var busy by mutableStateOf(false)
        private set
    var sentCount by mutableStateOf(0)
        private set

    private val lock = Mutex()
    private val clock = SimpleDateFormat("HH:mm:ss", Locale("id"))

    fun loadRemaining() {
        if (Session.user.value?.can?.pesanan != true) return
        viewModelScope.launch {
            runCatching { remaining = Api.summary().sudahDikemas }
        }
    }

    fun onScan(context: Context, code: String) {
        viewModelScope.launch {
            lock.withLock {
                busy = true
                val now = clock.format(Date())
                try {
                    val result = Api.handover(code)
                    val o = result.outbound
                    remaining = result.remaining
                    sentCount++
                    banner = Banner("${o.trackingNumber ?: o.code} · ${result.message}", Tone.Success)
                    log.add(0, HandoverLog(code, true, o.trackingNumber ?: o.code,
                        listOfNotNull(o.marketplace, o.recipient, result.message).joinToString(" · "), now))
                    Feedback.done(context)
                } catch (e: ApiException) {
                    banner = Banner("$code · ${e.message}", Tone.Error)
                    log.add(0, HandoverLog(code, false, code, e.message ?: "Gagal", now))
                    Feedback.error(context)
                } catch (e: Exception) {
                    banner = Banner("$code · jawaban server tidak dikenali", Tone.Error)
                    Feedback.error(context)
                } finally {
                    busy = false
                }
            }
        }
    }
}

@Composable
fun SerahTerimaScreen(onBack: () -> Unit, onQueue: () -> Unit, vm: SerahTerimaViewModel = viewModel()) {
    val context = LocalContext.current

    LaunchedEffect(Unit) { vm.loadRemaining() }

    Scaffold(
        topBar = {
            AppBar("Serah Terima Paket", onBack = onBack, actions = {
                if (Session.user.value?.can?.pesanan == true) {
                    TextButton(onClick = onQueue) { Text("Antrean") }
                }
            })
        },
        containerColor = Color.White,
    ) { padding ->
        Column(
            Modifier
                .fillMaxSize()
                .padding(padding)
                .padding(horizontal = 16.dp),
            verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            ScanPanel(label = "Scan resi paket", onScan = { code -> vm.onScan(context, code) })
            if (vm.busy) LinearProgressIndicator(Modifier.fillMaxWidth(), color = Brand)
            BannerView(vm.banner)

            Row(horizontalArrangement = Arrangement.spacedBy(16.dp)) {
                Text("Dikirim sesi ini: ${vm.sentCount}", fontWeight = FontWeight.SemiBold)
                vm.remaining?.let { Text("Sisa antrean: $it", color = InkMuted) }
            }

            LazyColumn(verticalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.weight(1f)) {
                items(vm.log) { entry ->
                    Row(
                        Modifier
                            .fillMaxWidth()
                            .clip(RoundedCornerShape(10.dp))
                            .background(if (entry.ok) SuccessBg else DangerBg)
                            .border(1.dp, if (entry.ok) Success.copy(alpha = 0.35f) else Danger.copy(alpha = 0.35f), RoundedCornerShape(10.dp))
                            .padding(12.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Column(Modifier.weight(1f)) {
                            Text(entry.title, fontWeight = FontWeight.Bold)
                            Text(entry.detail, fontSize = 13.sp, color = if (entry.ok) InkMuted else Danger)
                        }
                        Text(entry.time, fontSize = 12.sp, color = InkMuted)
                    }
                }
                if (vm.log.isEmpty()) {
                    item {
                        Text(
                            "Belum ada paket discan. Paket yang belum selesai QC Kemas, batal, atau sudah dikirim akan ditolak dengan alasannya.",
                            color = InkMuted,
                            fontSize = 13.sp,
                            modifier = Modifier
                                .fillMaxWidth()
                                .border(1.dp, InkLine, RoundedCornerShape(10.dp))
                                .padding(12.dp),
                        )
                    }
                }
            }
        }
    }
}
