package id.cheerychic.wmsscan.ui

import android.content.Context
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
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
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
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
import id.cheerychic.wmsscan.ui.components.AppCard
import id.cheerychic.wmsscan.ui.components.Banner
import id.cheerychic.wmsscan.ui.components.BannerView
import id.cheerychic.wmsscan.ui.components.Feedback
import id.cheerychic.wmsscan.ui.components.Header
import id.cheerychic.wmsscan.ui.components.Ic
import id.cheerychic.wmsscan.ui.components.ProductThumb
import id.cheerychic.wmsscan.ui.components.ProgressLine
import id.cheerychic.wmsscan.ui.components.RoundButton
import id.cheerychic.wmsscan.ui.components.ScanArea
import id.cheerychic.wmsscan.ui.components.StatusBarIcons
import id.cheerychic.wmsscan.ui.components.Tone
import id.cheerychic.wmsscan.ui.theme.Bg
import id.cheerychic.wmsscan.ui.theme.Brand
import id.cheerychic.wmsscan.ui.theme.Ink
import id.cheerychic.wmsscan.ui.theme.InkLine
import id.cheerychic.wmsscan.ui.theme.InkMuted
import id.cheerychic.wmsscan.ui.theme.Night
import id.cheerychic.wmsscan.ui.theme.Success
import id.cheerychic.wmsscan.ui.theme.SuccessDark
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.delay
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
    val label get() = outbound.trackingNumber ?: outbound.code
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
    var banner by mutableStateOf<Banner?>(null)
        private set
    var busy by mutableStateOf(false)
        private set
    var quantity by mutableStateOf(1)
    var finishedCount by mutableStateOf(0)
        private set

    /** Paket yang baru saja lengkap; ditampilkan layar hijau sebentar. */
    var celebrate by mutableStateOf<Packet?>(null)

    private val lock = Mutex()

    /** Paket yang sudah lengkap: scan berikutnya dianggap resi baru. */
    val waitingForResi get() = packet?.complete ?: true

    fun onScan(context: Context, code: String) {
        viewModelScope.launch {
            lock.withLock {
                busy = true
                try {
                    if (waitingForResi) scanResi(code) else scanItem(context, code)
                } catch (e: ApiException) {
                    banner = Banner(e.message ?: "Gagal", Tone.Error)
                    Feedback.error(context)
                } catch (e: CancellationException) {
                    throw e
                } catch (e: Exception) {
                    banner = Banner("Jawaban server tidak dikenali.", Tone.Error)
                    Feedback.error(context)
                } finally {
                    busy = false
                }
            }
        }
    }

    private suspend fun scanResi(code: String) {
        val result: ResiResponse = Api.scanResi(code)
        val scannedById = result.progress.items.associate { it.id to it.scanned }
        val next = Packet(
            outbound = result.outbound,
            lines = result.items.map { PackLine(it, scannedById[it.id] ?: 0) },
            bundles = result.bundles,
        )
        celebrate = null
        packet = next
        quantity = 1
        banner = if (next.scanned > 0) {
            Banner("Lanjut scan · ${next.scanned}/${next.total} unit sudah discan", Tone.Info)
        } else {
            Banner("Resi OK · scan ${next.total} unit barang", Tone.Success)
        }
        Feedback.ok()
    }

    private suspend fun scanItem(context: Context, code: String) {
        val current = packet ?: return
        val result = Api.scanItem(current.outbound.id, code, quantity.coerceAtLeast(1))
        val scannedById = result.progress.items.associate { it.id to it.scanned }
        val next = current.copy(lines = current.lines.map { line ->
            line.copy(scanned = scannedById[line.item.id] ?: line.scanned)
        })
        packet = next
        quantity = 1

        if (next.complete || result.progress.ready) {
            finishedCount++
            banner = null
            celebrate = next
            Feedback.done(context)
        } else {
            banner = Banner(result.message, Tone.Success)
            Feedback.ok()
        }
    }

    /** Lepas paket dari layar tanpa menghapus hasil scan di server. */
    fun release() {
        packet = null
        banner = Banner("Paket tadi bisa dilanjutkan dengan scan resinya lagi.", Tone.Info)
    }

    fun reset(context: Context) {
        val current = packet ?: return
        viewModelScope.launch {
            lock.withLock {
                busy = true
                try {
                    val result = Api.resetPacking(current.outbound.id)
                    packet = null
                    banner = Banner(result.message.ifBlank { "Progres scan direset." }, Tone.Info)
                } catch (e: ApiException) {
                    banner = Banner(e.message ?: "Gagal reset", Tone.Error)
                    Feedback.error(context)
                } catch (e: CancellationException) {
                    throw e
                } catch (e: Exception) {
                    // Galat lain tidak boleh menutup aplikasi.
                    banner = Banner("Gagal reset · jawaban server tidak dikenali.", Tone.Error)
                    Feedback.error(context)
                } finally {
                    busy = false
                }
            }
        }
    }
}

@Composable
fun KemasScreen(onBack: () -> Unit, vm: KemasViewModel = viewModel()) {
    StatusBarIcons(darkScreen = true)
    val context = LocalContext.current
    var confirmReset by remember { mutableStateOf(false) }
    var editQty by remember { mutableStateOf(false) }
    var torch by remember { mutableStateOf(false) }
    val packet = vm.packet
    val waitingResi = vm.waitingForResi

    if (confirmReset) {
        AlertDialog(
            onDismissRequest = { confirmReset = false },
            title = { Text("Reset scan paket ini?") },
            text = { Text("Semua hitungan barang di paket ini kembali ke nol dan resinya harus discan ulang.") },
            confirmButton = {
                TextButton(onClick = { confirmReset = false; vm.reset(context) }) { Text("Reset", color = Ink) }
            },
            dismissButton = { TextButton(onClick = { confirmReset = false }) { Text("Batal", color = InkMuted) } },
            containerColor = Color.White,
        )
    }
    if (editQty) QtyDialog(vm.quantity, onDismiss = { editQty = false }) { vm.quantity = it; editQty = false }

    Box(Modifier.fillMaxSize().background(Night)) {
        Column(Modifier.fillMaxSize()) {
            Header("Scan Kemas", onBack = onBack, dark = true) {
                RoundButton(Ic.Zap, "Senter", dark = true, tint = if (torch) Brand else Color.White) { torch = !torch }
            }
            ScanArea(
                hint = if (waitingResi) "Scan / ketik resi" else "Ketik barcode / SKU",
                onScan = { code -> vm.onScan(context, code) },
                modifier = Modifier.padding(horizontal = 20.dp, vertical = 8.dp),
                dark = true,
                cameraHeight = 190.dp,
                torch = torch,
                trailing = {
                    if (!waitingResi) {
                        Box(
                            Modifier
                                .clip(RoundedCornerShape(16.dp))
                                .background(if (vm.quantity > 1) Brand else Color(0x14FFFFFF))
                                .clickable { editQty = true }
                                .padding(horizontal = 14.dp, vertical = 14.dp),
                        ) {
                            Text(
                                "Qty ×${vm.quantity}",
                                color = if (vm.quantity > 1) Ink else Color.White,
                                fontWeight = FontWeight.Bold,
                                fontSize = 14.sp,
                            )
                        }
                    }
                },
            )

            // Lembar bawah berisi paket yang sedang dikemas.
            Column(
                Modifier
                    .fillMaxWidth()
                    .weight(1f)
                    .padding(top = 6.dp)
                    .clip(RoundedCornerShape(topStart = 28.dp, topEnd = 28.dp))
                    .background(Bg),
            ) {
                Box(Modifier.fillMaxWidth().padding(top = 10.dp), contentAlignment = Alignment.Center) {
                    Box(Modifier.size(width = 40.dp, height = 4.dp).clip(RoundedCornerShape(50)).background(InkLine))
                }
                if (vm.busy) {
                    LinearProgressIndicator(Modifier.fillMaxWidth().padding(horizontal = 20.dp, vertical = 4.dp).height(2.dp), color = Brand, trackColor = Color.Transparent)
                } else {
                    Spacer(Modifier.height(10.dp))
                }
                if (packet == null || (packet.complete && vm.celebrate == null)) {
                    IdleSheet(vm)
                } else {
                    PacketSheet(packet, vm, onReset = { confirmReset = true })
                }
            }
        }

        AnimatedVisibility(vm.celebrate != null, enter = fadeIn(), exit = fadeOut()) {
            vm.celebrate?.let { done -> DoneOverlay(done, vm.finishedCount) { vm.celebrate = null } }
        }
    }
}

@Composable
private fun IdleSheet(vm: KemasViewModel) {
    Column(
        Modifier.fillMaxWidth().padding(horizontal = 20.dp, vertical = 12.dp),
        verticalArrangement = Arrangement.spacedBy(14.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        BannerView(vm.banner)
        Spacer(Modifier.height(8.dp))
        Box(
            Modifier.size(64.dp).clip(RoundedCornerShape(22.dp)).background(Color.White),
            contentAlignment = Alignment.Center,
        ) {
            Icon(Ic.Scan, null, tint = Brand, modifier = Modifier.size(30.dp))
        }
        Text("Scan resi untuk mulai", color = Ink, fontSize = 18.sp, fontWeight = FontWeight.Bold)
        Text(
            "Arahkan kamera ke resi, atau tembak dengan scanner genggam.\nSetelah itu scan barangnya satu per satu.",
            color = InkMuted,
            fontSize = 13.sp,
            textAlign = TextAlign.Center,
        )
        if (vm.finishedCount > 0) {
            Text("${vm.finishedCount} paket selesai sesi ini", color = Success, fontWeight = FontWeight.SemiBold, fontSize = 13.sp)
        }
    }
}

@Composable
private fun PacketSheet(packet: Packet, vm: KemasViewModel, onReset: () -> Unit) {
    val o = packet.outbound
    LazyColumn(
        Modifier.fillMaxSize(),
        verticalArrangement = Arrangement.spacedBy(10.dp),
        contentPadding = androidx.compose.foundation.layout.PaddingValues(start = 20.dp, end = 20.dp, bottom = 24.dp),
    ) {
        item {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text(
                        listOfNotNull(o.marketplace, o.recipient).filter { it.isNotBlank() }.joinToString(" · "),
                        color = InkMuted, fontSize = 12.5.sp, maxLines = 1,
                    )
                    Text(packet.label, color = Ink, fontSize = 19.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1)
                    o.orderNumber?.let { Text("Pesanan $it", color = InkMuted, fontSize = 12.sp, maxLines = 1) }
                }
                Box(Modifier.size(54.dp), contentAlignment = Alignment.Center) {
                    CircularProgressIndicator(
                        progress = { if (packet.total == 0) 0f else packet.scanned.toFloat() / packet.total },
                        modifier = Modifier.fillMaxSize(),
                        color = if (packet.complete) Success else Brand,
                        trackColor = InkLine,
                        strokeWidth = 5.dp,
                    )
                    Text("${packet.scanned}/${packet.total}", color = Ink, fontWeight = FontWeight.Bold, fontSize = 13.sp)
                }
            }
        }
        if (packet.bundles.isNotEmpty()) {
            item {
                Text(
                    "Paket bundel: " + packet.bundles.joinToString(", ") { "${it.sku ?: it.name} ×${it.quantity}" },
                    color = InkMuted, fontSize = 12.5.sp,
                )
            }
        }
        item { BannerView(vm.banner) }
        items(packet.lines, key = { it.item.id }) { line -> LineCard(line) }
        item {
            Row(Modifier.fillMaxWidth().padding(top = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                if (!packet.complete) {
                    Row(
                        Modifier.clip(RoundedCornerShape(10.dp)).clickable(onClick = onReset).padding(6.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Icon(Ic.Reset, null, tint = InkMuted, modifier = Modifier.size(15.dp))
                        Spacer(Modifier.width(6.dp))
                        Text("Reset paket", color = InkMuted, fontSize = 13.sp)
                    }
                }
                Spacer(Modifier.weight(1f))
                if (!packet.complete) {
                    Row(
                        Modifier.clip(RoundedCornerShape(10.dp)).clickable { vm.release() }.padding(6.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Text("Ganti resi", color = Ink, fontSize = 13.sp, fontWeight = FontWeight.Bold)
                        Icon(Ic.Next, null, tint = Ink, modifier = Modifier.size(16.dp))
                    }
                }
            }
        }
    }
}

@Composable
private fun LineCard(line: PackLine) {
    val done = line.complete
    AppCard(
        Modifier.fillMaxWidth().then(if (done) Modifier.border(1.5.dp, Success.copy(alpha = 0.5f), RoundedCornerShape(20.dp)) else Modifier),
        radius = 20.dp,
    ) {
        Row(Modifier.padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
            Box {
                ProductThumb(line.item.imageUrl, line.item.sku, size = 54.dp)
                if (done) {
                    Box(
                        Modifier.align(Alignment.TopEnd).padding(2.dp).size(18.dp).clip(CircleShape).background(Success),
                        contentAlignment = Alignment.Center,
                    ) { Icon(Ic.Check, null, tint = Color.White, modifier = Modifier.size(11.dp)) }
                }
            }
            Spacer(Modifier.width(12.dp))
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                Text(line.item.sku, color = Ink, fontWeight = FontWeight.Bold, fontSize = 15.sp, maxLines = 1)
                Text(
                    line.item.name.ifBlank { line.item.barcode ?: "" },
                    color = InkMuted, fontSize = 12.5.sp, maxLines = 1,
                )
                Spacer(Modifier.height(3.dp))
                ProgressLine(
                    if (line.item.quantity == 0) 0f else line.scanned.toFloat() / line.item.quantity,
                    height = 5.dp,
                    color = if (done) Success else null,
                )
            }
            Spacer(Modifier.width(12.dp))
            Row(verticalAlignment = Alignment.Bottom) {
                Text("${line.scanned}", color = if (done) Success else Ink, fontSize = 22.sp, fontWeight = FontWeight.ExtraBold)
                Text("/${line.item.quantity}", color = InkMuted, fontSize = 14.sp, modifier = Modifier.padding(bottom = 3.dp))
            }
        }
    }
}

/** Layar hijau penuh saat paket lengkap; lanjut otomatis setelah 2 detik. */
@Composable
private fun DoneOverlay(packet: Packet, sessionCount: Int, onNext: () -> Unit) {
    LaunchedEffect(packet) {
        delay(2200)
        onNext()
    }
    Column(
        Modifier
            .fillMaxSize()
            .background(Brush.verticalGradient(listOf(Success, SuccessDark)))
            .clickable(onClick = onNext)
            .statusBarsPadding()
            .navigationBarsPadding()
            .padding(24.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Spacer(Modifier.weight(0.8f))
        Box(
            Modifier.size(132.dp).clip(CircleShape).background(Color(0x26FFFFFF)),
            contentAlignment = Alignment.Center,
        ) {
            Box(Modifier.size(92.dp).clip(CircleShape).background(Color.White), contentAlignment = Alignment.Center) {
                Icon(Ic.Check, null, tint = Success, modifier = Modifier.size(46.dp))
            }
        }
        Spacer(Modifier.height(26.dp))
        Text("Paket lengkap!", color = Color.White, fontSize = 30.sp, fontWeight = FontWeight.ExtraBold)
        Spacer(Modifier.height(6.dp))
        Text("${packet.label} · ${packet.total} unit", color = Color(0xD9FFFFFF), fontSize = 14.sp)
        Spacer(Modifier.height(24.dp))
        Column(
            Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(Color(0x1FFFFFFF)).padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(6.dp),
        ) {
            packet.lines.take(5).forEach { line ->
                Row {
                    Text(line.item.sku, color = Color.White, fontSize = 13.sp, modifier = Modifier.weight(1f), maxLines = 1)
                    Text("${line.scanned}/${line.item.quantity}", color = Color.White, fontSize = 13.sp, fontWeight = FontWeight.Bold)
                }
            }
            if (packet.lines.size > 5) Text("+${packet.lines.size - 5} SKU lain", color = Color(0xB3FFFFFF), fontSize = 12.sp)
        }
        Spacer(Modifier.height(16.dp))
        Text("Paket masuk antrean serah terima", color = Color(0xD9FFFFFF), fontSize = 13.sp)
        Spacer(Modifier.weight(1f))
        Box(
            Modifier
                .fillMaxWidth()
                .clip(RoundedCornerShape(18.dp))
                .background(Color.White)
                .clickable(onClick = onNext)
                .padding(vertical = 17.dp),
            contentAlignment = Alignment.Center,
        ) {
            Text("Scan resi berikutnya", color = SuccessDark, fontWeight = FontWeight.Bold, fontSize = 16.sp)
        }
        Spacer(Modifier.height(12.dp))
        Text("Otomatis lanjut dalam 2 detik · $sessionCount paket sesi ini", color = Color(0xCCFFFFFF), fontSize = 12.sp)
    }
}

@Composable
private fun QtyDialog(current: Int, onDismiss: () -> Unit, onSave: (Int) -> Unit) {
    var text by remember { mutableStateOf(current.toString()) }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Jumlah per scan") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text("Untuk pesanan borongan: satu scan dihitung sebanyak angka ini. Kembali ke 1 setelah scan.", color = InkMuted, fontSize = 13.sp)
                OutlinedTextField(
                    value = text,
                    onValueChange = { value -> text = value.filter { it.isDigit() }.take(4) },
                    singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
                )
            }
        },
        confirmButton = {
            TextButton(onClick = { onSave(text.toIntOrNull()?.coerceAtLeast(1) ?: 1) }) { Text("Simpan", color = Ink) }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Batal", color = InkMuted) } },
        containerColor = Color.White,
    )
}
