package id.cheerychic.wmsscan.ui

import android.content.Context
import androidx.compose.foundation.background
import androidx.compose.foundation.border
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
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
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
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import androidx.lifecycle.viewmodel.compose.viewModel
import id.cheerychic.wmsscan.data.Api
import id.cheerychic.wmsscan.data.ApiException
import id.cheerychic.wmsscan.data.FabricCutter
import id.cheerychic.wmsscan.data.FabricIssue
import id.cheerychic.wmsscan.data.FabricProduct
import id.cheerychic.wmsscan.data.FabricRoll
import id.cheerychic.wmsscan.data.Session
import id.cheerychic.wmsscan.ui.components.AppCard
import id.cheerychic.wmsscan.ui.components.Banner
import id.cheerychic.wmsscan.ui.components.BannerView
import id.cheerychic.wmsscan.ui.components.BigButton
import id.cheerychic.wmsscan.ui.components.Feedback
import id.cheerychic.wmsscan.ui.components.Header
import id.cheerychic.wmsscan.ui.components.ProductThumb
import id.cheerychic.wmsscan.ui.components.ScanArea
import id.cheerychic.wmsscan.ui.components.StatusBarIcons
import id.cheerychic.wmsscan.ui.components.Tone
import id.cheerychic.wmsscan.ui.theme.Bg
import id.cheerychic.wmsscan.ui.theme.Brand
import id.cheerychic.wmsscan.ui.theme.BrandBg
import id.cheerychic.wmsscan.ui.theme.Danger
import id.cheerychic.wmsscan.ui.theme.Ink
import id.cheerychic.wmsscan.ui.theme.Success
import id.cheerychic.wmsscan.ui.theme.InkMuted
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.launch
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock

/**
 * Scan Kain: kain hanya keluar lewat scan QR roll.
 *
 * Scan QR roll → SKU kain dan sisa roll langsung tampil → pilih barang yang
 * dibuat dari kain itu → isi jumlah atau Ambil Semua → simpan. Mode Cek: isi sisa hasil ukur ulang roll; selisihnya
 * dicatat sebagai koreksi. Aturan dan pesannya sama dengan halaman web.
 */
class KainViewModel : ViewModel() {
    var mode by mutableStateOf(if (Session.user.value?.can?.kainKeluar == true) MODE_OUT else MODE_CHECK)
    var roll by mutableStateOf<FabricRoll?>(null)
        private set
    var quantity by mutableStateOf("")
    val cutters = mutableStateListOf<FabricCutter>()
    var cutter by mutableStateOf<FabricCutter?>(null)
        private set
    var cutterPicking by mutableStateOf(false)
    var product by mutableStateOf<FabricProduct?>(null)
    var productQuery by mutableStateOf("")
        private set
    var productPicking by mutableStateOf(false)
    val productOptions = mutableStateListOf<FabricProduct>()
    private var productJob: kotlinx.coroutines.Job? = null
    var note by mutableStateOf("")
    var banner by mutableStateOf<Banner?>(null)
        private set
    var busy by mutableStateOf(false)
        private set
    val recent = mutableStateListOf<FabricIssue>()

    private val lock = Mutex()

    fun loadRecent() {
        if (Session.user.value?.can?.kainKeluar != true) return
        viewModelScope.launch {
            runCatching { Api.kainRecent() }.getOrNull()?.recent?.let { replaceRecent(it) }
        }
        viewModelScope.launch {
            val list = runCatching { Api.kainPemotong() }.getOrNull()?.cutters ?: return@launch
            cutters.clear()
            cutters.addAll(list)
            cutter = list.firstOrNull { it.id == Session.kainPemotong }
            cutterPicking = cutter == null && list.isNotEmpty()
        }
    }

    fun pickCutter(item: FabricCutter) {
        cutter = item
        cutterPicking = false
        Session.kainPemotong = item.id
    }

    fun switchMode(next: String) {
        mode = next
        cancel()
    }

    fun cancel() {
        roll = null
        product = null
        productPicking = false
        quantity = ""
        note = ""
    }

    /** Satu-satunya barang pemakai kain, atau barang terakhir bila cocok. */
    private fun preselectProduct(found: FabricRoll) {
        val options = found.products
        product = if (options.size == 1) options.first() else options.firstOrNull { it.id == Session.kainBarang }
        productQuery = ""
        productOptions.clear()
        productOptions.addAll(options)
        productPicking = product == null
        if (options.isEmpty()) searchProducts("")
    }

    fun searchProducts(value: String) {
        productQuery = value.take(60)
        val current = roll
        productJob?.cancel()
        if (productQuery.isBlank() && current != null && current.products.isNotEmpty()) {
            productOptions.clear()
            productOptions.addAll(current.products)
            return
        }
        productJob = viewModelScope.launch {
            kotlinx.coroutines.delay(250)
            val list = runCatching { Api.kainBarang(productQuery.trim(), current?.code) }.getOrNull()?.products ?: return@launch
            productOptions.clear()
            productOptions.addAll(list)
        }
    }

    fun pickProduct(item: FabricProduct) {
        product = item
        productPicking = false
        Session.kainBarang = item.id
    }

    fun isUsage(item: FabricProduct): Boolean = roll?.products?.any { it.id == item.id } == true

    fun onScan(context: Context, code: String) {
        val raw = code.trim()
        if (raw.isEmpty()) return
        // Roll terbuka dan yang diketik angka: itu jumlahnya.
        if (roll != null && NUMBER.matches(raw)) {
            quantity = raw
            save(context, all = false)
            return
        }
        viewModelScope.launch {
            lock.withLock {
                busy = true
                try {
                    val result = Api.kainLookup(raw)
                    val found = result.roll
                    if (found == null || (found.empty && mode == MODE_OUT)) {
                        roll = null
                        banner = Banner(result.message.ifBlank { "Roll sudah habis." }, Tone.Error)
                        Feedback.error(context)
                    } else {
                        roll = found
                        quantity = ""
                        if (mode == MODE_OUT) preselectProduct(found)
                        banner = Banner(result.message, Tone.Success)
                        Feedback.ok()
                    }
                } catch (e: ApiException) {
                    roll = null
                    banner = Banner(e.message ?: "Gagal", Tone.Error)
                    Feedback.error(context)
                } catch (e: CancellationException) {
                    throw e
                } catch (e: Exception) {
                    banner = Banner("Jawaban server tidak dikenali", Tone.Error)
                    Feedback.error(context)
                } finally {
                    busy = false
                }
            }
        }
    }

    fun save(context: Context, all: Boolean) {
        val current = roll ?: return
        val value = quantity.trim().replace(',', '.').toDoubleOrNull()
        val chosen = product
        if (mode == MODE_OUT && cutters.isNotEmpty() && cutter == null) {
            banner = Banner("Pilih pemotong yang menerima kain ini.", Tone.Error)
            cutterPicking = true
            Feedback.error(context)
            return
        }
        if (mode == MODE_OUT && chosen == null) {
            banner = Banner("Pilih barang yang dibuat dari kain ini.", Tone.Error)
            productPicking = true
            Feedback.error(context)
            return
        }
        if (!all && value == null) {
            banner = Banner(if (mode == MODE_CHECK) "Isi sisa hasil ukur roll ini." else "Isi jumlah kain keluar, atau tekan Ambil Semua.", Tone.Error)
            Feedback.error(context)
            return
        }
        viewModelScope.launch {
            lock.withLock {
                busy = true
                try {
                    val result = if (mode == MODE_CHECK) {
                        Api.kainCek(current.code, value ?: 0.0, note)
                    } else {
                        Api.kainKeluar(current.code, if (all) null else value, chosen!!.id, cutter?.id, note)
                    }
                    banner = Banner(result.message, Tone.Success)
                    result.recent?.let { replaceRecent(it) }
                    cancel()
                    Feedback.done(context)
                } catch (e: ApiException) {
                    banner = Banner(e.message ?: "Gagal", Tone.Error)
                    Feedback.error(context)
                } catch (e: CancellationException) {
                    throw e
                } catch (e: Exception) {
                    banner = Banner("Jawaban server tidak dikenali", Tone.Error)
                    Feedback.error(context)
                } finally {
                    busy = false
                }
            }
        }
    }

    private fun replaceRecent(list: List<FabricIssue>) {
        recent.clear()
        recent.addAll(list.distinctBy { it.id })
    }

    companion object {
        const val MODE_OUT = "keluar"
        const val MODE_CHECK = "cek"
        private val NUMBER = Regex("^\\d+([.,]\\d{1,3})?$")
    }
}

@Composable
fun KainScreen(onBack: () -> Unit, vm: KainViewModel = viewModel()) {
    StatusBarIcons(darkScreen = false)
    val context = LocalContext.current
    val can = Session.user.value?.can
    val canOut = can?.kainKeluar == true
    val canCheck = can?.kainCek == true

    LaunchedEffect(Unit) { vm.loadRecent() }

    Column(Modifier.fillMaxSize().background(Bg)) {
        Header("Scan Kain", onBack = onBack)
        LazyColumn(
            Modifier.fillMaxSize(),
            verticalArrangement = Arrangement.spacedBy(12.dp),
            contentPadding = PaddingValues(start = 20.dp, end = 20.dp, top = 8.dp, bottom = 24.dp),
        ) {
            if (canOut && canCheck) {
                item {
                    Row(
                        Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(Color.White).padding(4.dp),
                        horizontalArrangement = Arrangement.spacedBy(4.dp),
                    ) {
                        ModeButton("Kain Keluar", vm.mode == KainViewModel.MODE_OUT, Modifier.weight(1f)) { vm.switchMode(KainViewModel.MODE_OUT) }
                        ModeButton("Cek Roll", vm.mode == KainViewModel.MODE_CHECK, Modifier.weight(1f)) { vm.switchMode(KainViewModel.MODE_CHECK) }
                    }
                }
            }
            if (vm.mode == KainViewModel.MODE_OUT && vm.cutters.isNotEmpty()) {
                item { CutterPicker(vm) }
            }
            item {
                ScanArea(
                    hint = if (vm.roll == null) "Scan / ketik kode roll" else "Scan roll lain atau ketik jumlah",
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
            vm.roll?.let { roll ->
                item { RollCard(roll, vm, context) }
            }
            if (vm.mode == KainViewModel.MODE_OUT) {
                item {
                    Text("Kain keluar saya hari ini", color = Ink, fontWeight = FontWeight.Bold, fontSize = 16.sp)
                }
                items(vm.recent, key = { it.id }) { entry -> IssueCard(entry) }
                if (vm.recent.isEmpty()) {
                    item {
                        Text("Belum ada kain keluar hari ini.", color = InkMuted, fontSize = 13.sp)
                    }
                }
            }
            item { Spacer(Modifier.navigationBarsPadding()) }
        }
    }
}

@Composable
private fun ModeButton(text: String, selected: Boolean, modifier: Modifier, onClick: () -> Unit) {
    Box(
        modifier
            .clip(RoundedCornerShape(11.dp))
            .background(if (selected) Ink else Color.Transparent)
            .clickable(onClick = onClick)
            .padding(vertical = 11.dp),
        contentAlignment = Alignment.Center,
    ) {
        Text(text, color = if (selected) Color.White else InkMuted, fontWeight = FontWeight.Bold, fontSize = 14.sp)
    }
}

@Composable
private fun RollCard(roll: FabricRoll, vm: KainViewModel, context: Context) {
    val check = vm.mode == KainViewModel.MODE_CHECK
    AppCard(Modifier.fillMaxWidth().border(1.5.dp, Brand, RoundedCornerShape(22.dp)), radius = 22.dp) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Row(verticalAlignment = Alignment.Top, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                ProductThumb(roll.imageUrl, roll.sku, size = 56.dp)
                Column(Modifier.weight(1f)) {
                    Text(roll.code, color = Ink, fontFamily = FontFamily.Monospace, fontWeight = FontWeight.Bold, fontSize = 16.sp)
                    Text(
                        listOfNotNull(roll.name, roll.color).joinToString(" · "),
                        color = Ink, fontSize = 14.sp,
                    )
                    Text(
                        listOfNotNull("SKU ${roll.sku}", "awal ${roll.initialLabel}", roll.lot?.let { "lot $it" }, roll.location).joinToString(" · "),
                        color = InkMuted, fontSize = 12.sp,
                    )
                }
                Column(horizontalAlignment = Alignment.End) {
                    Text("Sisa", color = InkMuted, fontSize = 12.sp)
                    Text(roll.quantityLabel, color = Ink, fontWeight = FontWeight.ExtraBold, fontSize = 26.sp)
                }
            }
            if (!check) ProductPicker(vm)
            OutlinedTextField(
                value = vm.quantity,
                onValueChange = { value -> vm.quantity = value.filter { it.isDigit() || it == '.' || it == ',' }.take(10) },
                label = { Text(if (check) "Sisa hasil ukur (${roll.unit})" else "Jumlah keluar (${roll.unit})") },
                singleLine = true,
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal, imeAction = ImeAction.Done),
                keyboardActions = KeyboardActions(onDone = { vm.save(context, all = false) }),
                modifier = Modifier.fillMaxWidth(),
            )
            OutlinedTextField(
                value = vm.note,
                onValueChange = { vm.note = it.take(255) },
                label = { Text("Catatan (opsional)") },
                singleLine = true,
                modifier = Modifier.fillMaxWidth(),
            )
            if (!check) {
                BigButton(
                    "Ambil Semua (${roll.quantityLabel})",
                    bg = BrandBg, fg = Ink, enabled = !vm.busy,
                ) { vm.save(context, all = true) }
            }
            BigButton(if (check) "Simpan Koreksi" else "Simpan", enabled = !vm.busy) { vm.save(context, all = false) }
            Text(
                "Batal",
                color = InkMuted,
                fontSize = 14.sp,
                modifier = Modifier.align(Alignment.CenterHorizontally).clickable { vm.cancel() }.padding(6.dp),
            )
        }
    }
}

/** Pemotong yang menerima kain; diingat di HP ini. */
@Composable
private fun CutterPicker(vm: KainViewModel) {
    val chosen = vm.cutter
    AppCard(Modifier.fillMaxWidth(), radius = 18.dp) {
        Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            Text("Pemotong (cutter)", color = InkMuted, fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
            if (chosen != null && !vm.cutterPicking) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text(chosen.name, color = Ink, fontWeight = FontWeight.Bold, fontSize = 15.sp)
                        chosen.position?.let { Text(it, color = InkMuted, fontSize = 12.sp) }
                    }
                    Text(
                        "Ganti",
                        color = Ink,
                        fontWeight = FontWeight.Bold,
                        fontSize = 13.sp,
                        modifier = Modifier.clip(RoundedCornerShape(8.dp)).clickable { vm.cutterPicking = true }.padding(8.dp),
                    )
                }
            } else {
                vm.cutters.forEach { item ->
                    Row(
                        Modifier
                            .fillMaxWidth()
                            .clip(RoundedCornerShape(10.dp))
                            .background(if (item.id == chosen?.id) BrandBg else Color.Transparent)
                            .clickable { vm.pickCutter(item) }
                            .padding(horizontal = 10.dp, vertical = 9.dp),
                    ) {
                        Text(item.name, color = Ink, fontSize = 14.sp, modifier = Modifier.weight(1f))
                        item.position?.let { Text(it, color = InkMuted, fontSize = 12.sp) }
                    }
                }
            }
        }
    }
}

/** Pilih barang yang dibuat dari kain roll ini. */
@Composable
private fun ProductPicker(vm: KainViewModel) {
    val chosen = vm.product
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Text("Untuk barang", color = InkMuted, fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
        if (chosen != null && !vm.productPicking) {
            Row(
                Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(BrandBg).padding(10.dp),
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.spacedBy(10.dp),
            ) {
                ProductThumb(chosen.imageUrl, chosen.sku, size = 40.dp, radius = 10.dp)
                Column(Modifier.weight(1f)) {
                    Text(chosen.sku, color = Ink, fontFamily = FontFamily.Monospace, fontWeight = FontWeight.Bold, fontSize = 13.sp, maxLines = 1)
                    Text(chosen.name, color = Ink, fontSize = 13.sp, maxLines = 1)
                }
                Text(
                    "Ganti",
                    color = Ink,
                    fontWeight = FontWeight.Bold,
                    fontSize = 13.sp,
                    modifier = Modifier.clip(RoundedCornerShape(8.dp)).clickable { vm.productPicking = true }.padding(8.dp),
                )
            }
        } else {
            OutlinedTextField(
                value = vm.productQuery,
                onValueChange = { vm.searchProducts(it) },
                label = { Text("Cari SKU / nama barang") },
                singleLine = true,
                modifier = Modifier.fillMaxWidth(),
            )
            if (vm.productOptions.isEmpty()) {
                Text(
                    if (vm.productQuery.isBlank()) "Ketik SKU atau nama barang." else "Barang tidak ditemukan.",
                    color = InkMuted, fontSize = 12.sp,
                )
            }
            Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(Color.White)) {
                vm.productOptions.take(12).forEach { item ->
                    Row(
                        Modifier.fillMaxWidth().clickable { vm.pickProduct(item) }.padding(horizontal = 10.dp, vertical = 8.dp),
                        verticalAlignment = Alignment.CenterVertically,
                        horizontalArrangement = Arrangement.spacedBy(10.dp),
                    ) {
                        ProductThumb(item.imageUrl, item.sku, size = 36.dp, radius = 9.dp)
                        Column(Modifier.weight(1f)) {
                            Text(item.sku, color = Ink, fontFamily = FontFamily.Monospace, fontWeight = FontWeight.Bold, fontSize = 13.sp, maxLines = 1)
                            Text(item.name, color = InkMuted, fontSize = 12.5.sp, maxLines = 1)
                        }
                        if (vm.isUsage(item)) {
                            Text("pakai kain ini", color = Success, fontSize = 11.sp, fontWeight = FontWeight.SemiBold)
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun IssueCard(entry: FabricIssue) {
    AppCard(Modifier.fillMaxWidth(), radius = 18.dp) {
        Row(Modifier.padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
            Text(entry.time, color = InkMuted, fontSize = 12.sp, modifier = Modifier.width(44.dp))
            Column(Modifier.weight(1f)) {
                Text(entry.code, color = Ink, fontFamily = FontFamily.Monospace, fontWeight = FontWeight.Bold, fontSize = 14.sp, maxLines = 1)
                Text(
                    listOfNotNull(entry.name, entry.productSku?.let { "untuk $it" } ?: entry.destination?.let { "ke $it" }, entry.cuttingCode).joinToString(" · "),
                    color = InkMuted, fontSize = 12.5.sp, maxLines = 1,
                )
            }
            Column(horizontalAlignment = Alignment.End) {
                Text("-${entry.quantityLabel}", color = if (entry.cancelled) InkMuted else Danger, fontWeight = FontWeight.Bold, fontSize = 14.sp)
                Text("sisa ${entry.balanceLabel}", color = InkMuted, fontSize = 11.5.sp)
            }
        }
    }
}
