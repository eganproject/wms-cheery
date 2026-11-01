package id.cheerychic.wmsscan.ui

import android.content.Context
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
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
import id.cheerychic.wmsscan.data.Summary
import id.cheerychic.wmsscan.ui.components.AppCard
import id.cheerychic.wmsscan.ui.components.Banner
import id.cheerychic.wmsscan.ui.components.BannerView
import id.cheerychic.wmsscan.ui.components.Chip
import id.cheerychic.wmsscan.ui.components.Feedback
import id.cheerychic.wmsscan.ui.components.Header
import id.cheerychic.wmsscan.ui.components.Ic
import id.cheerychic.wmsscan.ui.components.IconBadge
import id.cheerychic.wmsscan.ui.components.RoundButton
import id.cheerychic.wmsscan.ui.components.ScanArea
import id.cheerychic.wmsscan.ui.components.StatusBarIcons
import id.cheerychic.wmsscan.ui.components.Tone
import id.cheerychic.wmsscan.ui.theme.Bg
import id.cheerychic.wmsscan.ui.theme.Brand
import id.cheerychic.wmsscan.ui.theme.Brand2
import id.cheerychic.wmsscan.ui.theme.Danger
import id.cheerychic.wmsscan.ui.theme.DangerBg
import id.cheerychic.wmsscan.ui.theme.Ink
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
    var banner by mutableStateOf<Banner?>(null)
        private set
    var remaining by mutableStateOf<Int?>(null)
        private set
    var summary by mutableStateOf<Summary?>(null)
        private set
    var busy by mutableStateOf(false)
        private set
    var sentCount by mutableStateOf(0)
        private set

    private val lock = Mutex()
    private val clock = SimpleDateFormat("HH:mm", Locale("id"))

    fun loadSummary() {
        if (Session.user.value?.can?.pesanan != true) return
        viewModelScope.launch {
            runCatching {
                val s = Api.summary()
                summary = s
                remaining = s.sudahDikemas
            }
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
                    log.add(0, HandoverLog(
                        code, true, o.trackingNumber ?: o.code,
                        listOfNotNull(o.marketplace, o.recipient, if (o.units > 0) "${o.units} unit" else null)
                            .filter { it.isNotBlank() }.joinToString(" · "),
                        now,
                    ))
                    Feedback.done(context)
                    loadSummary()
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
    StatusBarIcons(darkScreen = false)
    val context = LocalContext.current
    val canSeeOrders = Session.user.value?.can?.pesanan == true

    LaunchedEffect(Unit) { vm.loadSummary() }

    Column(Modifier.fillMaxSize().background(Bg)) {
        Header("Serah Terima Paket", onBack = onBack) {
            if (canSeeOrders) RoundButton(Ic.List, "Antrean", onClick = onQueue) else Spacer(Modifier.width(42.dp))
        }
        LazyColumn(
            Modifier.fillMaxSize(),
            verticalArrangement = Arrangement.spacedBy(12.dp),
            contentPadding = PaddingValues(start = 20.dp, end = 20.dp, top = 8.dp, bottom = 24.dp),
        ) {
            item {
                AppCard(Modifier.fillMaxWidth()) {
                    Row(Modifier.padding(vertical = 16.dp)) {
                        Column(Modifier.weight(1f).padding(horizontal = 18.dp)) {
                            Text("Diserahkan sesi ini", color = InkMuted, fontSize = 12.5.sp)
                            Text("${vm.sentCount}", color = Ink, fontSize = 32.sp, fontWeight = FontWeight.ExtraBold)
                        }
                        Box(Modifier.width(1.dp).height(54.dp).background(InkLine).align(Alignment.CenterVertically))
                        Column(Modifier.weight(1f).padding(horizontal = 18.dp)) {
                            Text("Sisa antrean", color = InkMuted, fontSize = 12.5.sp)
                            Text(vm.remaining?.toString() ?: "–", color = Brand2, fontSize = 32.sp, fontWeight = FontWeight.ExtraBold)
                        }
                    }
                }
            }
            val couriers = vm.summary?.antreanPerKurir.orEmpty()
            if (couriers.isNotEmpty()) {
                item {
                    Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        Chip("Semua · ${couriers.sumOf { it.total }}", Color.White, Ink)
                        couriers.forEach { c ->
                            Chip(
                                "${c.courier} · ${c.total}", Ink, Color.White,
                                modifier = Modifier.border(1.dp, InkLine, RoundedCornerShape(50)),
                            )
                        }
                    }
                }
            }
            item {
                ScanArea(
                    hint = "Scan / ketik resi paket",
                    onScan = { code -> vm.onScan(context, code) },
                    dark = false,
                    cameraHeight = 170.dp,
                )
            }
            item {
                Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    if (vm.busy) LinearProgressIndicator(Modifier.fillMaxWidth().height(2.dp), color = Brand, trackColor = Color.Transparent)
                    BannerView(vm.banner)
                }
            }
            item {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text("Riwayat", color = Ink, fontWeight = FontWeight.Bold, fontSize = 16.sp, modifier = Modifier.weight(1f))
                    Text("Sesi ini", color = InkMuted, fontSize = 12.5.sp)
                }
            }
            items(vm.log) { entry -> LogCard(entry) }
            if (vm.log.isEmpty()) {
                item {
                    Text(
                        "Belum ada paket discan. Paket yang belum selesai QC Kemas, batal, atau sudah dikirim akan ditolak dengan alasannya.",
                        color = InkMuted,
                        fontSize = 13.sp,
                        modifier = Modifier.padding(vertical = 4.dp),
                    )
                }
            }
            item { Spacer(Modifier.navigationBarsPadding()) }
        }
    }
}

@Composable
private fun LogCard(entry: HandoverLog) {
    AppCard(
        Modifier.fillMaxWidth().then(
            if (entry.ok) Modifier else Modifier.border(1.dp, Danger.copy(alpha = 0.35f), RoundedCornerShape(18.dp))
        ),
        radius = 18.dp,
    ) {
        Row(Modifier.padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
            if (entry.ok) IconBadge(Ic.Check, Success, SuccessBg) else IconBadge(Ic.Alert, Danger, DangerBg)
            Spacer(Modifier.width(12.dp))
            Column(Modifier.weight(1f)) {
                Text(entry.title, color = Ink, fontWeight = FontWeight.Bold, fontSize = 14.5.sp, maxLines = 1)
                Text(entry.detail, color = if (entry.ok) InkMuted else Danger, fontSize = 12.5.sp, maxLines = 2)
            }
            Spacer(Modifier.width(8.dp))
            Text(entry.time, color = InkMuted, fontSize = 12.sp)
        }
    }
}
