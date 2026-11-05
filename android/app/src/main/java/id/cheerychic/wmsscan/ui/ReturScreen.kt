package id.cheerychic.wmsscan.ui

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.Matrix
import android.media.ExifInterface
import android.net.Uri
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Icon
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.ContextCompat
import androidx.core.content.FileProvider
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import androidx.lifecycle.viewmodel.compose.viewModel
import id.cheerychic.wmsscan.data.Api
import id.cheerychic.wmsscan.data.ApiException
import id.cheerychic.wmsscan.data.ForeignLine
import id.cheerychic.wmsscan.data.NameCandidate
import id.cheerychic.wmsscan.data.ReturnDoc
import id.cheerychic.wmsscan.data.ReturnLine
import id.cheerychic.wmsscan.data.ReturnPayload
import id.cheerychic.wmsscan.data.Session
import id.cheerychic.wmsscan.ui.components.AppCard
import id.cheerychic.wmsscan.ui.components.Banner
import id.cheerychic.wmsscan.ui.components.BannerView
import id.cheerychic.wmsscan.ui.components.BigButton
import id.cheerychic.wmsscan.ui.components.Chip
import id.cheerychic.wmsscan.ui.components.Feedback
import id.cheerychic.wmsscan.ui.components.Header
import id.cheerychic.wmsscan.ui.components.Ic
import id.cheerychic.wmsscan.ui.components.RoundButton
import id.cheerychic.wmsscan.ui.components.ScanArea
import id.cheerychic.wmsscan.ui.components.StatusBarIcons
import id.cheerychic.wmsscan.ui.components.Tone
import id.cheerychic.wmsscan.ui.theme.Bg
import id.cheerychic.wmsscan.ui.theme.Brand
import id.cheerychic.wmsscan.ui.theme.BrandBg
import id.cheerychic.wmsscan.ui.theme.Danger
import id.cheerychic.wmsscan.ui.theme.Field
import id.cheerychic.wmsscan.ui.theme.Ink
import id.cheerychic.wmsscan.ui.theme.InkLine
import id.cheerychic.wmsscan.ui.theme.InkMuted
import id.cheerychic.wmsscan.ui.theme.Night
import id.cheerychic.wmsscan.ui.theme.Success
import id.cheerychic.wmsscan.ui.theme.SuccessBg
import id.cheerychic.wmsscan.ui.theme.Warning
import id.cheerychic.wmsscan.ui.theme.WarningBg
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import java.io.ByteArrayOutputStream
import java.io.File

/** Satu baris yang sedang diperiksa. Layak jual = seharusnya - rusak - hilang. */
data class ReturnCheck(val line: ReturnLine, val expected: Int, val damaged: Int, val missing: Int) {
    val good get() = (expected - damaged - missing).coerceAtLeast(0)
    val overChecked get() = damaged + missing > expected
}

/** Alasan yang sama dengan stasiun retur di web. */
private val REASONS = listOf(
    "Barang rusak", "Salah kirim", "Tidak sesuai pesanan", "Pesanan dibatalkan", "Pembeli berubah pikiran", "Lainnya",
)

/**
 * Terima Retur, sama dengan stasiun Retur Marketplace di web:
 *
 *   scan resi -> isi paket muncul dari pesanan aslinya -> tandai rusak/hilang -> terima
 *
 * Resi yang belum ada di data import (pesanan dari sebelum memakai sistem)
 * berpindah ke input manual: barang discan, diketik SKU-nya, atau dicari
 * lewat nama. Barang bukan milik kita dicatat terpisah dengan foto dan tidak
 * pernah masuk stok.
 */
class ReturViewModel : ViewModel() {
    var doc by mutableStateOf<ReturnDoc?>(null)
        private set
    val checks = mutableStateListOf<ReturnCheck>()
    val foreign = mutableStateListOf<ForeignLine>()

    /** Resi sudah discan tetapi belum ada di data import. */
    var manual by mutableStateOf(false)
        private set
    var pendingTracking by mutableStateOf<String?>(null)
        private set
    var active by mutableStateOf(false)
        private set

    var banner by mutableStateOf<Banner?>(null)
    var busy by mutableStateOf(false)
        private set
    var quantity by mutableStateOf(1)
    var reason by mutableStateOf<String?>(null)
    var candidates by mutableStateOf<List<NameCandidate>>(emptyList())
    var finishedCount by mutableStateOf(0)
        private set

    /** Pesan hijau setelah paket diterima; layar kembali menunggu resi. */
    var finished by mutableStateOf<String?>(null)

    private val lock = Mutex()

    val waitingForResi get() = !active
    val goodUnits get() = checks.sumOf { it.good }
    val damagedUnits get() = checks.sumOf { it.damaged }
    val missingUnits get() = checks.sumOf { it.missing }
    val foreignUnits get() = foreign.sumOf { it.quantity }
    val hasProblem get() = checks.any { it.overChecked }

    private suspend fun guarded(context: Context, block: suspend () -> Unit) {
        lock.withLock {
            busy = true
            try {
                block()
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

    fun onScan(context: Context, code: String) {
        viewModelScope.launch {
            guarded(context) {
                when {
                    waitingForResi -> scanResi(code)
                    manual -> scanItem(code)
                    else -> {
                        banner = Banner("Isi paket sudah ditentukan resi. Tandai rusak/hilang lalu tekan Terima.", Tone.Warning)
                        Feedback.error(context)
                    }
                }
            }
        }
    }

    private suspend fun scanResi(code: String) {
        val result = Api.returResi(code)
        finished = null
        candidates = emptyList()
        reason = null
        quantity = 1
        active = true

        if (!result.found) {
            manual = true
            pendingTracking = result.trackingNumber ?: code
            doc = null
            checks.clear()
            foreign.clear()
            banner = Banner(
                "Resi belum ada di data. Scan barcode, ketik SKU, atau ketik nama barangnya.",
                Tone.Info,
            )
            Feedback.ok()
            return
        }

        manual = false
        pendingTracking = null
        adopt(result, preserve = false)
        banner = result.warning?.let { Banner(it, Tone.Warning) }
            ?: Banner("Paket dibuka · ${result.items.size} baris · tandai yang rusak/hilang", Tone.Success)
        Feedback.ok()
    }

    private suspend fun scanItem(code: String) {
        val current = doc
        val result = if (current == null) {
            Api.returManual(pendingTracking, code, quantity.coerceAtLeast(1))
        } else {
            Api.returItem(current.id, code, quantity.coerceAtLeast(1))
        }

        // Kata yang diketik cocok dengan beberapa nama barang: pilih dulu.
        if (result.candidates.isNotEmpty()) {
            candidates = result.candidates
            banner = Banner(result.message ?: "Pilih barang yang dimaksud.", Tone.Warning)
            return
        }

        candidates = emptyList()
        quantity = 1
        adopt(result, preserve = true)
        result.scanned?.let { banner = Banner("${it.sku} · ${it.quantity} ${it.unit}", Tone.Success) }
        Feedback.ok()
    }

    fun pick(context: Context, candidate: NameCandidate) {
        candidates = emptyList()
        onScan(context, candidate.sku)
    }

    /** Ambil isi dari server; hasil periksa yang sudah diisi tidak tertimpa. */
    private fun adopt(payload: ReturnPayload, preserve: Boolean) {
        val previous = if (preserve) checks.associateBy { it.line.id } else emptyMap()
        val next = payload.items.map { line ->
            val old = previous[line.id]
            if (old != null) {
                // Retur manual: scan ulang barang yang sama menambah jumlahnya,
                // jadi "seharusnya" minimal sebanyak yang sudah discan.
                old.copy(line = line, expected = if (manual) maxOf(old.expected, line.quantity) else line.quantity)
            } else {
                ReturnCheck(
                    line = line,
                    expected = line.quantity,
                    damaged = line.damaged,
                    missing = (line.quantity - line.good - line.damaged).coerceAtLeast(0),
                )
            }
        }
        checks.clear()
        checks.addAll(next)
        foreign.clear()
        foreign.addAll(payload.foreign)
        payload.doc?.let { doc = it }
        pendingTracking = if (doc != null) null else pendingTracking
    }

    fun update(index: Int, check: ReturnCheck) {
        if (index in checks.indices) checks[index] = check
    }

    fun allIntact() {
        for (index in checks.indices) checks[index] = checks[index].copy(damaged = 0, missing = 0)
    }

    fun removeLine(context: Context, check: ReturnCheck) {
        val current = doc ?: return
        viewModelScope.launch {
            guarded(context) {
                adopt(Api.returRemoveItem(current.id, check.line.id), preserve = true)
                banner = Banner("Baris ${check.line.sku} dihapus.", Tone.Info)
            }
        }
    }

    fun addForeign(context: Context, description: String, qty: Int, photo: ByteArray?, onSaved: () -> Unit) {
        viewModelScope.launch {
            guarded(context) {
                val result = Api.returForeign(doc?.id, pendingTracking, description.trim(), qty, photo)
                adopt(result, preserve = true)
                banner = Banner("Bukan milik kita · ${description.trim()} · tidak masuk stok", Tone.Warning)
                Feedback.ok()
                onSaved()
            }
        }
    }

    fun removeForeign(context: Context, line: ForeignLine) {
        val current = doc ?: return
        viewModelScope.launch {
            guarded(context) {
                adopt(Api.returRemoveForeign(current.id, line.id), preserve = true)
            }
        }
    }

    fun finish(context: Context) {
        val current = doc ?: return
        if (checks.isEmpty() && foreign.isEmpty()) {
            banner = Banner("Belum ada barang pada paket ini.", Tone.Error)
            Feedback.error(context)
            return
        }
        if (hasProblem) {
            banner = Banner("Rusak + hilang melebihi jumlah. Perbaiki dulu.", Tone.Error)
            Feedback.error(context)
            return
        }
        viewModelScope.launch {
            var message: String? = null
            guarded(context) {
                val result = Api.returFinish(
                    current.id,
                    checks.associate { it.line.id to Triple(it.good, it.damaged, it.expected) },
                    reason,
                )
                finishedCount++
                message = result.message
                finished = result.message
                Feedback.done(context)
                clear()
                banner = null
            }
            // Pesan sukses hilang sendiri, di luar kunci supaya scan berikutnya tidak tertahan.
            if (message != null) {
                delay(4000)
                if (finished == message) finished = null
            }
        }
    }

    /** Lepas paket dari layar. Draftnya tetap ada di web dan bisa dilanjutkan. */
    fun release() {
        val code = doc?.code
        clear()
        banner = if (code != null) Banner("Draft $code tersimpan. Scan resinya lagi untuk melanjutkan.", Tone.Info) else null
    }

    private fun clear() {
        doc = null
        checks.clear()
        foreign.clear()
        manual = false
        pendingTracking = null
        active = false
        candidates = emptyList()
        reason = null
        quantity = 1
    }
}

@Composable
fun ReturScreen(onBack: () -> Unit, vm: ReturViewModel = viewModel()) {
    StatusBarIcons(darkScreen = true)
    val context = LocalContext.current
    var torch by remember { mutableStateOf(false) }
    var editQty by remember { mutableStateOf(false) }
    var foreignOpen by remember { mutableStateOf(false) }
    val canFinish = Session.user.value?.can?.returTerima ?: false

    if (editQty) {
        NumberDialog("Jumlah per scan", "Satu scan dihitung sebanyak angka ini. Kembali ke 1 setelah scan.", vm.quantity,
            onDismiss = { editQty = false }) { vm.quantity = it; editQty = false }
    }
    if (foreignOpen) {
        ForeignDialog(
            busy = vm.busy,
            onDismiss = { foreignOpen = false },
            onSave = { description, qty, photo -> vm.addForeign(context, description, qty, photo) { foreignOpen = false } },
        )
    }
    if (vm.candidates.isNotEmpty()) {
        AlertDialog(
            onDismissRequest = { vm.candidates = emptyList() },
            title = { Text("Pilih barang") },
            text = {
                LazyColumn(Modifier.heightIn(max = 380.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    items(vm.candidates, key = { it.sku }) { candidate ->
                        Column(
                            Modifier
                                .fillMaxWidth()
                                .clip(RoundedCornerShape(14.dp))
                                .background(Field)
                                .clickable { vm.pick(context, candidate) }
                                .padding(12.dp),
                        ) {
                            Text(candidate.name, color = Ink, fontWeight = FontWeight.SemiBold, fontSize = 14.sp)
                            Text("SKU ${candidate.sku}", color = InkMuted, fontSize = 12.sp)
                        }
                    }
                }
            },
            confirmButton = { TextButton(onClick = { vm.candidates = emptyList() }) { Text("Tutup", color = InkMuted) } },
            containerColor = Color.White,
        )
    }

    Box(Modifier.fillMaxSize().background(Night)) {
        Column(Modifier.fillMaxSize()) {
            Header("Terima Retur", onBack = onBack, dark = true) {
                RoundButton(Ic.Zap, "Senter", dark = true, tint = if (torch) Brand else Color.White) { torch = !torch }
            }
            ScanArea(
                hint = when {
                    vm.waitingForResi -> "Scan / ketik resi retur"
                    vm.manual -> "Barcode, SKU, atau nama barang"
                    else -> "Resi sudah dibuka"
                },
                onScan = { code -> vm.onScan(context, code) },
                modifier = Modifier.padding(horizontal = 20.dp, vertical = 8.dp),
                dark = true,
                cameraHeight = 150.dp,
                torch = torch,
                trailing = {
                    if (vm.manual) {
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
                    LinearProgressIndicator(
                        Modifier.fillMaxWidth().padding(horizontal = 20.dp, vertical = 4.dp).height(2.dp),
                        color = Brand, trackColor = Color.Transparent,
                    )
                } else {
                    Spacer(Modifier.height(10.dp))
                }

                if (vm.waitingForResi) {
                    IdleSheet(vm)
                } else {
                    PackageSheet(vm, canFinish, onForeign = { foreignOpen = true })
                }
            }
        }
    }
}

@Composable
private fun IdleSheet(vm: ReturViewModel) {
    Column(
        Modifier.fillMaxWidth().padding(horizontal = 20.dp, vertical = 12.dp),
        verticalArrangement = Arrangement.spacedBy(14.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        vm.finished?.let { BannerView(Banner(it, Tone.Success)) } ?: BannerView(vm.banner)
        Spacer(Modifier.height(8.dp))
        Box(
            Modifier.size(64.dp).clip(RoundedCornerShape(22.dp)).background(Color.White),
            contentAlignment = Alignment.Center,
        ) {
            Icon(Ic.Reset, null, tint = Brand, modifier = Modifier.size(30.dp))
        }
        Text("Scan resi paket retur", color = Ink, fontSize = 18.sp, fontWeight = FontWeight.Bold)
        Text(
            "Resi yang ada di sistem langsung menampilkan isi paketnya.\nResi lama yang belum ada bisa diisi manual.",
            color = InkMuted,
            fontSize = 13.sp,
            textAlign = TextAlign.Center,
        )
        if (vm.finishedCount > 0) {
            Text("${vm.finishedCount} retur diterima sesi ini", color = Success, fontWeight = FontWeight.SemiBold, fontSize = 13.sp)
        }
    }
}

@Composable
private fun PackageSheet(vm: ReturViewModel, canFinish: Boolean, onForeign: () -> Unit) {
    val context = LocalContext.current
    val doc = vm.doc

    Column(Modifier.fillMaxSize()) {
        LazyColumn(
            Modifier.weight(1f),
            verticalArrangement = Arrangement.spacedBy(10.dp),
            contentPadding = PaddingValues(start = 20.dp, end = 20.dp, bottom = 12.dp),
        ) {
            item {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text(
                            listOfNotNull(if (vm.manual) "Input manual" else doc?.marketplace, doc?.code)
                                .filter { it.isNotBlank() }.joinToString(" · "),
                            color = InkMuted, fontSize = 12.5.sp, maxLines = 1,
                        )
                        Text(
                            doc?.trackingNumber ?: vm.pendingTracking ?: "Tanpa resi",
                            color = Ink, fontSize = 19.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1,
                        )
                        doc?.orderNumber?.let { Text("Pesanan $it", color = InkMuted, fontSize = 12.sp, maxLines = 1) }
                    }
                    Text(
                        "Ganti resi",
                        color = Ink, fontSize = 13.sp, fontWeight = FontWeight.Bold,
                        modifier = Modifier.clip(RoundedCornerShape(10.dp)).clickable { vm.release() }.padding(6.dp),
                    )
                }
            }
            item { BannerView(vm.banner) }

            if (vm.checks.isEmpty() && vm.foreign.isEmpty()) {
                item {
                    Text(
                        "Scan barang pertama di dalam paket. Dokumen retur langsung dibuat begitu barang pertama masuk.",
                        color = InkMuted, fontSize = 13.sp, textAlign = TextAlign.Center,
                        modifier = Modifier.fillMaxWidth().padding(vertical = 16.dp),
                    )
                }
            }

            items(vm.checks.size, key = { vm.checks[it].line.id }) { index ->
                val check = vm.checks[index]
                CheckCard(
                    check = check,
                    manual = vm.manual,
                    onChange = { vm.update(index, it) },
                    onRemove = { vm.removeLine(context, check) },
                )
            }

            if (vm.foreign.isNotEmpty()) {
                item {
                    Text(
                        "Bukan milik kita · ${vm.foreignUnits} unit · tidak masuk stok",
                        color = Warning, fontWeight = FontWeight.Bold, fontSize = 13.sp,
                        modifier = Modifier.padding(top = 4.dp),
                    )
                }
                items(vm.foreign, key = { "f${it.id}" }) { line ->
                    Row(
                        Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(WarningBg).padding(12.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Icon(Ic.Camera, null, tint = if (line.photoUrl != null) Warning else InkMuted, modifier = Modifier.size(18.dp))
                        Spacer(Modifier.width(10.dp))
                        Column(Modifier.weight(1f)) {
                            Text(line.description, color = Ink, fontSize = 14.sp, fontWeight = FontWeight.SemiBold, maxLines = 2)
                            Text(if (line.photoUrl != null) "Ada foto" else "Tanpa foto", color = InkMuted, fontSize = 11.5.sp)
                        }
                        Text("${line.quantity} unit", color = Ink, fontWeight = FontWeight.Bold, fontSize = 14.sp)
                        Spacer(Modifier.width(6.dp))
                        Icon(
                            Ic.Close, "Hapus", tint = InkMuted,
                            modifier = Modifier.size(30.dp).clip(RoundedCornerShape(10.dp))
                                .clickable { vm.removeForeign(context, line) }.padding(6.dp),
                        )
                    }
                }
            }

            item {
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Chip("⚠ Bukan barang kita", Warning, WarningBg, onClick = onForeign)
                    if (vm.checks.isNotEmpty()) Chip("✓ Semua utuh", Success, SuccessBg, onClick = { vm.allIntact() })
                }
            }

            if (doc != null) {
                item {
                    Text("Alasan retur (opsional)", color = InkMuted, fontSize = 12.sp, modifier = Modifier.padding(top = 4.dp))
                    Spacer(Modifier.height(6.dp))
                    LazyRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        items(REASONS) { reason ->
                            val selected = vm.reason == reason
                            Chip(
                                reason,
                                if (selected) Ink else InkMuted,
                                if (selected) BrandBg else Color.White,
                                onClick = { vm.reason = if (selected) null else reason },
                            )
                        }
                    }
                }
            }
        }

        // Kaki: ringkasan + tombol terima, posisinya tetap.
        if (doc != null) {
            Column(
                Modifier.fillMaxWidth().background(Color.White).padding(horizontal = 20.dp, vertical = 12.dp),
                verticalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                    Text("${vm.goodUnits} layak", color = Success, fontWeight = FontWeight.Bold, fontSize = 13.sp)
                    if (vm.damagedUnits > 0) Text("${vm.damagedUnits} rusak", color = Danger, fontWeight = FontWeight.Bold, fontSize = 13.sp)
                    if (vm.missingUnits > 0) Text("${vm.missingUnits} hilang", color = Warning, fontWeight = FontWeight.Bold, fontSize = 13.sp)
                    if (vm.foreignUnits > 0) Text("${vm.foreignUnits} bukan milik kita", color = Warning, fontWeight = FontWeight.Bold, fontSize = 13.sp)
                }
                if (canFinish) {
                    BigButton("Terima Retur", enabled = !vm.busy && !vm.hasProblem) { vm.finish(context) }
                } else {
                    Text(
                        "Isi paket sudah tercatat sebagai draft. Minta petugas berwenang menerimanya di web.",
                        color = InkMuted, fontSize = 12.5.sp,
                    )
                }
            }
        }
    }
}

@Composable
private fun CheckCard(check: ReturnCheck, manual: Boolean, onChange: (ReturnCheck) -> Unit, onRemove: () -> Unit) {
    val line = check.line
    AppCard(Modifier.fillMaxWidth(), radius = 20.dp) {
        Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text(line.sku, color = Ink, fontWeight = FontWeight.Bold, fontSize = 15.sp, maxLines = 1)
                    Text(line.name, color = InkMuted, fontSize = 12.5.sp, maxLines = 1)
                }
                if (manual) {
                    Icon(
                        Ic.Close, "Hapus baris", tint = InkMuted,
                        modifier = Modifier.size(32.dp).clip(RoundedCornerShape(10.dp)).clickable(onClick = onRemove).padding(7.dp),
                    )
                } else {
                    Chip("${line.quantity} ${line.unit} di resi", Ink, Field)
                }
            }
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                Column(
                    Modifier.width(58.dp).clip(RoundedCornerShape(12.dp)).background(SuccessBg).padding(vertical = 6.dp),
                    horizontalAlignment = Alignment.CenterHorizontally,
                ) {
                    Text("${check.good}", color = Success, fontWeight = FontWeight.ExtraBold, fontSize = 17.sp)
                    Text("layak", color = Success, fontSize = 10.5.sp)
                }
                if (manual) {
                    Stepper("seharusnya", check.expected, Ink, min = 1) { onChange(check.copy(expected = it)) }
                }
                Stepper("rusak", check.damaged, Danger) { onChange(check.copy(damaged = it)) }
                Stepper("hilang", check.missing, Warning) { onChange(check.copy(missing = it)) }
            }
            if (check.overChecked) {
                Text("Rusak + hilang melebihi ${check.expected} ${line.unit}.", color = Danger, fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
            }
        }
    }
}

@Composable
private fun Stepper(label: String, value: Int, color: Color, min: Int = 0, onChange: (Int) -> Unit) {
    Column(horizontalAlignment = Alignment.CenterHorizontally) {
        Text(label, color = InkMuted, fontSize = 10.5.sp)
        Row(
            Modifier.clip(RoundedCornerShape(12.dp)).background(Field),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text(
                "−", color = Ink, fontSize = 18.sp, fontWeight = FontWeight.Bold,
                modifier = Modifier.clickable { if (value > min) onChange(value - 1) }.padding(horizontal = 10.dp, vertical = 4.dp),
            )
            Text("$value", color = color, fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
            Text(
                "+", color = Ink, fontSize = 18.sp, fontWeight = FontWeight.Bold,
                modifier = Modifier.clickable { if (value < 9999) onChange(value + 1) }.padding(horizontal = 10.dp, vertical = 4.dp),
            )
        }
    }
}

/** Catat barang bukan milik kita: keterangan, jumlah, dan foto dari kamera. */
@Composable
private fun ForeignDialog(busy: Boolean, onDismiss: () -> Unit, onSave: (String, Int, ByteArray?) -> Unit) {
    val context = LocalContext.current
    var description by remember { mutableStateOf("") }
    var qty by remember { mutableStateOf("1") }
    var photo by remember { mutableStateOf<ByteArray?>(null) }
    var preview by remember { mutableStateOf<Bitmap?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    val photoUri = remember { photoTarget(context) }

    val take = rememberLauncherForActivityResult(ActivityResultContracts.TakePicture()) { saved ->
        if (!saved) return@rememberLauncherForActivityResult
        val bitmap = runCatching { loadPhoto(context, photoUri) }.getOrNull()
        if (bitmap == null) {
            error = "Foto gagal dibaca. Coba ambil lagi."
        } else {
            preview = bitmap
            photo = ByteArrayOutputStream().also { bitmap.compress(Bitmap.CompressFormat.JPEG, 82, it) }.toByteArray()
            error = null
        }
    }
    val permission = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
        if (granted) take.launch(photoUri) else error = "Izinkan kamera untuk mengambil foto."
    }

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Barang bukan milik kita") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                Text("Dicatat sebagai bukti klaim. Tidak menambah stok dan tidak dihitung hilang.", color = InkMuted, fontSize = 13.sp)
                OutlinedTextField(
                    value = description,
                    onValueChange = { description = it.take(255) },
                    label = { Text("Keterangan, mis. kaos hitam merek lain") },
                    singleLine = false,
                    maxLines = 3,
                )
                OutlinedTextField(
                    value = qty,
                    onValueChange = { value -> qty = value.filter { it.isDigit() }.take(4) },
                    label = { Text("Jumlah") },
                    singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
                )
                Row(verticalAlignment = Alignment.CenterVertically) {
                    preview?.let {
                        Image(
                            it.asImageBitmap(), null, contentScale = ContentScale.Crop,
                            modifier = Modifier.size(56.dp).clip(RoundedCornerShape(12.dp)),
                        )
                        Spacer(Modifier.width(10.dp))
                    }
                    Chip(if (preview == null) "📷 Ambil foto" else "📷 Ganti foto", Ink, BrandBg, onClick = {
                        if (ContextCompat.checkSelfPermission(context, Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED) {
                            take.launch(photoUri)
                        } else {
                            permission.launch(Manifest.permission.CAMERA)
                        }
                    })
                }
                error?.let { Text(it, color = Danger, fontSize = 12.5.sp) }
            }
        },
        confirmButton = {
            TextButton(enabled = !busy, onClick = {
                if (description.isBlank()) {
                    error = "Tulis keterangan barangnya."
                } else {
                    onSave(description, qty.toIntOrNull()?.coerceAtLeast(1) ?: 1, photo)
                }
            }) { Text("Simpan", color = Ink) }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Batal", color = InkMuted) } },
        containerColor = Color.White,
    )
}

@Composable
private fun NumberDialog(title: String, note: String, current: Int, onDismiss: () -> Unit, onSave: (Int) -> Unit) {
    var text by remember { mutableStateOf(current.toString()) }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(title) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(note, color = InkMuted, fontSize = 13.sp)
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

/** Berkas sementara untuk kamera bawaan HP, dibagikan lewat FileProvider. */
private fun photoTarget(context: Context): Uri {
    val dir = File(context.cacheDir, "retur").apply { mkdirs() }
    val file = File(dir, "barang-asing.jpg")
    return FileProvider.getUriForFile(context, "${context.packageName}.updates", file)
}

/** Baca foto, kecilkan sampai sisi terpanjang ±1600 px, dan luruskan orientasinya. */
private fun loadPhoto(context: Context, uri: Uri): Bitmap? {
    val resolver = context.contentResolver
    val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
    resolver.openInputStream(uri)?.use { BitmapFactory.decodeStream(it, null, bounds) }
    var sample = 1
    while (maxOf(bounds.outWidth, bounds.outHeight) / (sample * 2) >= 1600) sample *= 2
    val bitmap = resolver.openInputStream(uri)?.use {
        BitmapFactory.decodeStream(it, null, BitmapFactory.Options().apply { inSampleSize = sample })
    } ?: return null

    val rotation = resolver.openInputStream(uri)?.use {
        when (ExifInterface(it).getAttributeInt(ExifInterface.TAG_ORIENTATION, ExifInterface.ORIENTATION_NORMAL)) {
            ExifInterface.ORIENTATION_ROTATE_90 -> 90f
            ExifInterface.ORIENTATION_ROTATE_180 -> 180f
            ExifInterface.ORIENTATION_ROTATE_270 -> 270f
            else -> 0f
        }
    } ?: 0f

    if (rotation == 0f) return bitmap
    return Bitmap.createBitmap(bitmap, 0, 0, bitmap.width, bitmap.height, Matrix().apply { postRotate(rotation) }, true)
}
