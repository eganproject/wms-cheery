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
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import androidx.lifecycle.viewmodel.compose.viewModel
import id.cheerychic.wmsscan.data.Api
import id.cheerychic.wmsscan.data.ApiException
import id.cheerychic.wmsscan.data.PackBundle
import id.cheerychic.wmsscan.data.PackItem
import id.cheerychic.wmsscan.data.PackOutbound
import id.cheerychic.wmsscan.data.ResiResponse
import id.cheerychic.wmsscan.ui.components.AppBar
import id.cheerychic.wmsscan.ui.components.Banner
import id.cheerychic.wmsscan.ui.components.BannerView
import id.cheerychic.wmsscan.ui.components.Feedback
import id.cheerychic.wmsscan.ui.components.ScanPanel
import id.cheerychic.wmsscan.ui.components.Tone
import id.cheerychic.wmsscan.ui.theme.Brand
import id.cheerychic.wmsscan.ui.theme.InkLine
import id.cheerychic.wmsscan.ui.theme.InkMuted
import id.cheerychic.wmsscan.ui.theme.Success
import id.cheerychic.wmsscan.ui.theme.SuccessBg
import id.cheerychic.wmsscan.ui.theme.Surface2
import kotlinx.coroutines.launch
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock

data class PackLine(val item: PackItem, val scanned: Int) {
    val complete get() = scanned >= item.quantity
}

data class Packet(
    val outbound: PackOutbound,
    val lines: List<PackLine>,
    val bundles: List<PackBundle>,
) {
    val total get() = lines.sumOf { it.item.quantity }
    val scanned get() = lines.sumOf { minOf(it.scanned, it.item.quantity) }
    val complete get() = lines.isNotEmpty() && lines.all { it.complete }
}

/**
 * Alur Scan Kemas, sama dengan halaman QC Kemas di web:
 * scan resi -> scan barang sampai lengkap -> otomatis siap untuk resi berikutnya.
 *
 * Scan diproses berurutan (tidak ada yang hilang walau scanner ditekan cepat).
 */
class KemasViewModel : ViewModel() {
    var packet by mutableStateOf<Packet?>(null)
        private set
    var banner by mutableStateOf<Banner?>(Banner("Scan resi paket untuk mulai.", Tone.Info))
        private set
    var busy by mutableStateOf(false)
        private set
    var quantity by mutableStateOf("1")
    var finishedToday by mutableStateOf(0)
        private set

    private val lock = Mutex()

    /** Paket yang sudah lengkap: scan berikutnya dianggap resi baru. */
    private val waitingForResi get() = packet?.let { it.complete } ?: true

    fun onScan(context: Context, code: String) {
        viewModelScope.launch {
            lock.withLock {
                busy = true
                try {
                    if (waitingForResi) scanResi(context, code) else scanItem(context, code)
                } catch (e: ApiException) {
                    banner = Banner(e.message ?: "Gagal", Tone.Error)
                    Feedback.error(context)
                } catch (e: Exception) {
                    banner = Banner("Jawaban server tidak dikenali.", Tone.Error)
                    Feedback.error(context)
                } finally {
                    busy = false
                }
            }
        }
    }

    private suspend fun scanResi(context: Context, code: String) {
        val result: ResiResponse = Api.scanResi(code)
        val scannedById = result.progress.items.associate { it.id to it.scanned }
        val next = Packet(
            outbound = result.outbound,
            lines = result.items.map { PackLine(it, scannedById[it.id] ?: 0) },
            bundles = result.bundles,
        )
        packet = next
        quantity = "1"
        banner = if (next.scanned > 0) {
            Banner("Lanjut scan · ${next.scanned}/${next.total} unit sudah discan sebelumnya", Tone.Info)
        } else {
            Banner("Resi OK · scan ${next.total} unit barang", Tone.Success)
        }
        Feedback.ok()
    }

    private suspend fun scanItem(context: Context, code: String) {
        val current = packet ?: return
        val qty = quantity.toIntOrNull()?.coerceAtLeast(1) ?: 1
        val result = Api.scanItem(current.outbound.id, code, qty)
        val scannedById = result.progress.items.associate { it.id to it.scanned }
        val next = current.copy(lines = current.lines.map { line ->
            line.copy(scanned = scannedById[line.item.id] ?: line.scanned)
        })
        packet = next
        quantity = "1"

        if (next.complete || result.progress.ready) {
            finishedToday++
            banner = Banner("Paket lengkap ✓ ${current.outbound.trackingNumber ?: current.outbound.code} · scan resi berikutnya", Tone.Success)
            Feedback.done(context)
        } else {
            banner = Banner(result.message, Tone.Success)
            Feedback.ok()
        }
    }

    /** Lepas paket dari layar tanpa menghapus hasil scan di server. */
    fun release() {
        packet = null
        banner = Banner("Scan resi paket untuk mulai. Paket tadi bisa dilanjutkan dengan scan resinya lagi.", Tone.Info)
    }

    fun reset(context: Context) {
        val current = packet ?: return
        viewModelScope.launch {
            lock.withLock {
                try {
                    val result = Api.resetPacking(current.outbound.id)
                    packet = null
                    banner = Banner(result.message, Tone.Info)
                } catch (e: ApiException) {
                    banner = Banner(e.message ?: "Gagal reset", Tone.Error)
                    Feedback.error(context)
                }
            }
        }
    }
}

@Composable
fun KemasScreen(onBack: () -> Unit, vm: KemasViewModel = viewModel()) {
    val context = LocalContext.current
    var confirmReset by remember { mutableStateOf(false) }
    val packet = vm.packet

    if (confirmReset) {
        AlertDialog(
            onDismissRequest = { confirmReset = false },
            title = { Text("Reset scan paket ini?") },
            text = { Text("Semua hitungan barang di paket ini kembali ke nol dan resinya harus discan ulang.") },
            confirmButton = {
                TextButton(onClick = { confirmReset = false; vm.reset(context) }) { Text("Reset") }
            },
            dismissButton = { TextButton(onClick = { confirmReset = false }) { Text("Batal") } },
        )
    }

    Scaffold(
        topBar = {
            AppBar("Scan Kemas", onBack = onBack, actions = {
                if (packet != null && !packet.complete) {
                    TextButton(onClick = { vm.release() }) { Text("Ganti resi") }
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
            val waitingResi = packet == null || packet.complete
            ScanPanel(
                label = if (waitingResi) "Scan resi" else "Scan barang (barcode / SKU)",
                onScan = { code -> vm.onScan(context, code) },
                extra = {
                    if (!waitingResi) {
                        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            Text("Jumlah per scan", color = InkMuted, fontSize = 13.sp)
                            OutlinedTextField(
                                value = vm.quantity,
                                onValueChange = { value -> vm.quantity = value.filter { it.isDigit() }.take(4) },
                                singleLine = true,
                                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
                                modifier = Modifier.width(90.dp),
                            )
                            Text("(untuk pesanan borongan)", color = InkMuted, fontSize = 12.sp)
                        }
                    }
                },
            )

            if (vm.busy) {
                LinearProgressIndicator(Modifier.fillMaxWidth(), color = Brand)
            }
            BannerView(vm.banner)

            if (packet == null) {
                if (vm.finishedToday > 0) {
                    Text("${vm.finishedToday} paket selesai dikemas di HP ini sejak dibuka.", color = InkMuted, fontSize = 13.sp)
                }
                return@Column
            }

            PacketHeader(packet)

            LazyColumn(verticalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.weight(1f)) {
                items(packet.lines, key = { it.item.id }) { line -> LineRow(line) }
                item {
                    if (!packet.complete) {
                        TextButton(onClick = { confirmReset = true }) { Text("Reset scan paket ini") }
                    }
                }
            }
        }
    }
}

@Composable
private fun PacketHeader(packet: Packet) {
    val o = packet.outbound
    Column(
        Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(12.dp))
            .background(Surface2)
            .padding(12.dp),
        verticalArrangement = Arrangement.spacedBy(4.dp),
    ) {
        Text(o.trackingNumber ?: o.code, fontWeight = FontWeight.Bold, fontSize = 18.sp)
        Text(
            listOfNotNull(o.marketplace, o.orderNumber, o.recipient).joinToString(" · "),
            color = InkMuted,
            fontSize = 13.sp,
        )
        if (packet.bundles.isNotEmpty()) {
            Text(
                "Paket: " + packet.bundles.joinToString(", ") { "${it.sku ?: it.name} ×${it.quantity}" },
                fontSize = 13.sp,
            )
        }
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            LinearProgressIndicator(
                progress = { if (packet.total == 0) 0f else packet.scanned.toFloat() / packet.total },
                modifier = Modifier.weight(1f),
                color = if (packet.complete) Success else Brand,
                trackColor = InkLine,
            )
            Text("${packet.scanned}/${packet.total} unit", fontWeight = FontWeight.SemiBold)
        }
    }
}

@Composable
private fun LineRow(line: PackLine) {
    val done = line.complete
    Row(
        Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(10.dp))
            .background(if (done) SuccessBg else Color.White)
            .border(1.dp, if (done) Success.copy(alpha = 0.4f) else InkLine, RoundedCornerShape(10.dp))
            .padding(12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Column(Modifier.weight(1f)) {
            Text(line.item.sku, fontWeight = FontWeight.Bold, fontSize = 16.sp)
            Text(line.item.name, fontSize = 13.sp, color = InkMuted, maxLines = 2)
            line.item.barcode?.takeIf { it.isNotBlank() }?.let {
                Text("Barcode $it", fontSize = 12.sp, color = InkMuted)
            }
        }
        Text(
            "${line.scanned}/${line.item.quantity}",
            fontSize = 22.sp,
            fontWeight = FontWeight.Bold,
            color = if (done) Success else Color(0xFF1F2328),
        )
        Text(" ${line.item.unit ?: ""}", fontSize = 12.sp, color = InkMuted)
    }
}
