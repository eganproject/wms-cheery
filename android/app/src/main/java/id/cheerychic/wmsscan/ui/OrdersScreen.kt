package id.cheerychic.wmsscan.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
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
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.derivedStateOf
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.drawBehind
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.platform.LocalFocusManager
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import androidx.lifecycle.viewmodel.compose.viewModel
import id.cheerychic.wmsscan.data.Api
import id.cheerychic.wmsscan.data.ApiException
import id.cheerychic.wmsscan.data.Order
import id.cheerychic.wmsscan.data.Stages
import id.cheerychic.wmsscan.data.Summary
import id.cheerychic.wmsscan.ui.components.AppCard
import id.cheerychic.wmsscan.ui.components.Banner
import id.cheerychic.wmsscan.ui.components.BannerView
import id.cheerychic.wmsscan.ui.components.BottomNav
import id.cheerychic.wmsscan.ui.components.Chip
import id.cheerychic.wmsscan.ui.components.FilterChip
import id.cheerychic.wmsscan.ui.components.Ic
import id.cheerychic.wmsscan.ui.components.ProductThumb
import id.cheerychic.wmsscan.ui.components.RoundButton
import id.cheerychic.wmsscan.ui.components.StatusBarIcons
import id.cheerychic.wmsscan.ui.components.Tab
import id.cheerychic.wmsscan.ui.components.Tone
import id.cheerychic.wmsscan.ui.theme.Bg
import id.cheerychic.wmsscan.ui.theme.Brand
import id.cheerychic.wmsscan.ui.theme.Brand2
import id.cheerychic.wmsscan.ui.theme.BrandBg
import id.cheerychic.wmsscan.ui.theme.Danger
import id.cheerychic.wmsscan.ui.theme.DangerBg
import id.cheerychic.wmsscan.ui.theme.Info
import id.cheerychic.wmsscan.ui.theme.InfoBg
import id.cheerychic.wmsscan.ui.theme.Ink
import id.cheerychic.wmsscan.ui.theme.InkMuted
import id.cheerychic.wmsscan.ui.theme.Success
import id.cheerychic.wmsscan.ui.theme.SuccessBg
import id.cheerychic.wmsscan.ui.theme.Surface2
import id.cheerychic.wmsscan.ui.theme.Warning
import id.cheerychic.wmsscan.ui.theme.WarningBg
import kotlinx.coroutines.Job
import kotlinx.coroutines.launch
import java.text.SimpleDateFormat
import java.util.Calendar
import java.util.Locale

private val TABS = listOf(
    Stages.BELUM to "Belum",
    Stages.SUDAH to "Dikemas",
    Stages.DIKIRIM to "Dikirim",
)

/** Saringan cepat tab Belum. */
enum class QuickFilter { Priority, Ready, Waiting }

class OrdersViewModel : ViewModel() {
    var stage by mutableStateOf(Stages.BELUM)
        private set
    var search by mutableStateOf("")
    var filter by mutableStateOf<QuickFilter?>(null)
        private set
    var courier by mutableStateOf<String?>(null)
        private set
    var date by mutableStateOf(Calendar.getInstance())
        private set
    val orders = mutableStateListOf<Order>()
    var total by mutableStateOf<Int?>(null)
        private set
    var summary by mutableStateOf<Summary?>(null)
        private set
    var loading by mutableStateOf(false)
        private set
    var error by mutableStateOf<String?>(null)
        private set

    private var page = 0
    private var lastPage = 1
    private var job: Job? = null
    private var generation = 0
    private var started = false
    private val iso = SimpleDateFormat("yyyy-MM-dd", Locale.US)
    private val pretty = SimpleDateFormat("EEE, d MMM yyyy", Locale("id"))

    val dateText: String get() = iso.format(date.time)
    val datePretty: String get() = pretty.format(date.time)
    val isToday: Boolean get() = dateText == iso.format(Calendar.getInstance().time)

    fun start(initial: String) {
        if (started) return
        started = true
        stage = initial
        reload()
    }

    fun select(newStage: String) {
        if (newStage == stage) return
        stage = newStage
        filter = null
        courier = null
        reload()
    }

    fun toggle(value: QuickFilter?) {
        filter = if (filter == value) null else value
        reload(withSummary = false)
    }

    fun pickCourier(value: String?) {
        courier = if (courier == value) null else value
        reload(withSummary = false)
    }

    fun shiftDay(days: Int) {
        date = (date.clone() as Calendar).apply { add(Calendar.DAY_OF_MONTH, days) }
        reload()
    }

    fun reload(withSummary: Boolean = true) {
        job?.cancel()
        generation++
        loading = false
        orders.clear()
        page = 0
        lastPage = 1
        total = null
        loadMore()
        if (withSummary) viewModelScope.launch {
            runCatching { summary = Api.summary(dateText) }
        }
    }

    fun loadMore() {
        if (loading || page >= lastPage) return
        loading = true
        val mine = generation
        job = viewModelScope.launch {
            try {
                val result = Api.orders(
                    stage = stage,
                    page = page + 1,
                    search = search.trim(),
                    date = if (stage == Stages.DIKIRIM) dateText else null,
                    ready = when (filter) {
                        QuickFilter.Ready -> "siap"
                        QuickFilter.Waiting -> "menunggu"
                        else -> null
                    },
                    priority = filter == QuickFilter.Priority,
                    courier = courier,
                )
                if (mine != generation) return@launch
                page = result.meta.currentPage
                lastPage = result.meta.lastPage
                total = result.meta.total
                orders.addAll(result.data)
                error = null
            } catch (e: ApiException) {
                error = e.message
            } catch (e: kotlinx.coroutines.CancellationException) {
                throw e
            } catch (e: Exception) {
                error = "Jawaban server tidak dikenali."
            } finally {
                // Muatan lama yang dibatalkan tidak boleh membuka kunci muatan baru.
                if (mine == generation) loading = false
            }
        }
    }
}

@Composable
fun OrdersScreen(
    initialStage: String,
    onTab: (Tab) -> Unit,
    onOpen: (Int) -> Unit,
    vm: OrdersViewModel = viewModel(),
) {
    StatusBarIcons(darkScreen = false)
    LaunchedEffect(initialStage) { vm.start(initialStage) }
    val focus = LocalFocusManager.current

    val listState = rememberLazyListState()
    val nearEnd by remember {
        derivedStateOf {
            val last = listState.layoutInfo.visibleItemsInfo.lastOrNull()?.index ?: 0
            last >= listState.layoutInfo.totalItemsCount - 5
        }
    }
    LaunchedEffect(nearEnd, vm.orders.size) { if (nearEnd && vm.orders.isNotEmpty()) vm.loadMore() }

    Box(Modifier.fillMaxSize().background(Bg)) {
        Column(Modifier.fillMaxSize().statusBarsPadding()) {
            Column(Modifier.padding(horizontal = 20.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                Spacer(Modifier.height(2.dp))
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text("Pesanan", color = Ink, fontSize = 28.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
                    RoundButton(Ic.Refresh, "Muat ulang") { vm.reload() }
                }
                SearchBox(vm.search, { vm.search = it }) {
                    focus.clearFocus()
                    vm.reload()
                }
                Segments(vm)
            }
            Row(
                Modifier
                    .fillMaxWidth()
                    .horizontalScroll(rememberScrollState())
                    .padding(horizontal = 20.dp, vertical = 12.dp),
                horizontalArrangement = Arrangement.spacedBy(6.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                QuickFilters(vm)
            }

            LazyColumn(
                state = listState,
                verticalArrangement = Arrangement.spacedBy(10.dp),
                contentPadding = PaddingValues(start = 20.dp, end = 20.dp, bottom = 120.dp),
                modifier = Modifier.weight(1f),
            ) {
                vm.error?.let { message -> item { BannerView(Banner(message, Tone.Error)) } }
                vm.total?.let { count -> item { Text("$count resi", color = InkMuted, fontSize = 12.5.sp) } }
                items(vm.orders, key = { it.id }) { order -> OrderCard(order) { onOpen(order.id) } }
                item {
                    Box(Modifier.fillMaxWidth().padding(16.dp), contentAlignment = Alignment.Center) {
                        when {
                            vm.loading -> CircularProgressIndicator(color = Brand, strokeWidth = 3.dp, modifier = Modifier.size(28.dp))
                            vm.orders.isEmpty() && vm.error == null && vm.total != null ->
                                Text("Tidak ada resi di sini.", color = InkMuted)
                        }
                    }
                }
            }
        }
        BottomNav(Tab.Pesanan, onTab, Modifier.align(Alignment.BottomCenter))
    }
}

@Composable
private fun SearchBox(value: String, onChange: (String) -> Unit, onSearch: () -> Unit) {
    AppCard(Modifier.fillMaxWidth(), radius = 16.dp) {
        Row(Modifier.padding(horizontal = 14.dp, vertical = 13.dp), verticalAlignment = Alignment.CenterVertically) {
            Icon(Ic.Search, null, tint = InkMuted, modifier = Modifier.size(18.dp))
            Spacer(Modifier.width(10.dp))
            Box(Modifier.weight(1f)) {
                if (value.isEmpty()) Text("Cari resi, pembeli, SKU", color = InkMuted, fontSize = 14.5.sp)
                BasicTextField(
                    value = value,
                    onValueChange = onChange,
                    singleLine = true,
                    textStyle = TextStyle(color = Ink, fontSize = 14.5.sp),
                    cursorBrush = SolidColor(Brand),
                    keyboardOptions = KeyboardOptions(imeAction = ImeAction.Search),
                    keyboardActions = KeyboardActions(onSearch = { onSearch() }),
                    modifier = Modifier.fillMaxWidth(),
                )
            }
            if (value.isNotEmpty()) {
                Icon(
                    Ic.Close, "Hapus", tint = InkMuted,
                    modifier = Modifier.size(18.dp).clickable { onChange(""); onSearch() },
                )
            }
        }
    }
}

@Composable
private fun Segments(vm: OrdersViewModel) {
    Row(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(Surface2).padding(4.dp),
    ) {
        TABS.forEach { (key, label) ->
            val selected = vm.stage == key
            val count = when (key) {
                Stages.BELUM -> vm.summary?.belumDikemas
                Stages.SUDAH -> vm.summary?.sudahDikemas
                else -> vm.summary?.dikirim
            }
            Row(
                Modifier
                    .weight(1f)
                    .clip(RoundedCornerShape(12.dp))
                    .background(if (selected) Color.White else Color.Transparent)
                    .clickable { vm.select(key) }
                    .padding(vertical = 10.dp),
                horizontalArrangement = Arrangement.Center,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text(label, color = if (selected) Ink else InkMuted, fontWeight = if (selected) FontWeight.Bold else FontWeight.Medium, fontSize = 13.5.sp)
                if (count != null) {
                    Spacer(Modifier.width(6.dp))
                    Text(
                        "$count",
                        color = if (selected) Brand2 else InkMuted,
                        fontWeight = FontWeight.Bold,
                        fontSize = 11.5.sp,
                        modifier = Modifier
                            .clip(RoundedCornerShape(50))
                            .background(if (selected) BrandBg else Color.Transparent)
                            .padding(horizontal = 6.dp, vertical = 1.dp),
                    )
                }
            }
        }
    }
}

@Composable
private fun QuickFilters(vm: OrdersViewModel) {
    val s = vm.summary
    when (vm.stage) {
        Stages.BELUM -> {
            FilterChip("Semua", vm.filter == null) { vm.toggle(null) }
            FilterChip("🔥 Prioritas${s?.let { " ${it.prioritas}" } ?: ""}", vm.filter == QuickFilter.Priority) { vm.toggle(QuickFilter.Priority) }
            FilterChip("Siap dikemas${s?.let { " ${it.siapDikemas}" } ?: ""}", vm.filter == QuickFilter.Ready) { vm.toggle(QuickFilter.Ready) }
            FilterChip("Tunggu stok${s?.let { " ${it.menungguStok}" } ?: ""}", vm.filter == QuickFilter.Waiting) { vm.toggle(QuickFilter.Waiting) }
        }
        Stages.SUDAH -> {
            FilterChip("Semua kurir", vm.courier == null) { vm.pickCourier(null) }
            s?.antreanPerKurir.orEmpty().forEach { c ->
                FilterChip("${c.courier} · ${c.total}", vm.courier == c.courier) { vm.pickCourier(c.courier) }
            }
        }
        else -> {
            RoundButton(Ic.Back, "Hari sebelumnya") { vm.shiftDay(-1) }
            Text(
                if (vm.isToday) "Hari ini · ${vm.datePretty}" else vm.datePretty,
                color = Ink,
                fontWeight = FontWeight.SemiBold,
                fontSize = 14.sp,
                textAlign = TextAlign.Center,
                modifier = Modifier.padding(horizontal = 10.dp),
            )
            if (!vm.isToday) RoundButton(Ic.Next, "Hari berikutnya") { vm.shiftDay(1) }
        }
    }
}

/** Warna garis kiri kartu: merah = telat/hari ini, oranye = besok, hijau = terkirim. */
private fun stripeColor(order: Order): Color = when {
    order.stage == Stages.DIKIRIM -> Success
    order.deadlineTone == "danger" -> Danger
    order.deadlineTone == "warning" -> Warning
    order.stage == Stages.SUDAH -> Info
    else -> Brand
}

@Composable
fun OrderCard(order: Order, onClick: () -> Unit) {
    val stripe = stripeColor(order)
    AppCard(Modifier.fillMaxWidth(), radius = 20.dp, onClick = onClick) {
        Column(
            Modifier
                .drawBehind { drawRect(stripe, size = Size(4.dp.toPx(), size.height)) }
                .padding(start = 16.dp, end = 14.dp, top = 14.dp, bottom = 14.dp),
            verticalArrangement = Arrangement.spacedBy(6.dp),
        ) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(
                    order.trackingNumber ?: "(tanpa resi)",
                    color = Ink, fontWeight = FontWeight.ExtraBold, fontSize = 15.sp, maxLines = 1,
                    modifier = Modifier.weight(1f),
                )
                if (order.isPriority) {
                    Chip("🔥", Danger, DangerBg)
                    Spacer(Modifier.width(4.dp))
                }
                val deadline = order.deadlineLabel
                if (deadline != null) {
                    val (fg, bg) = when (order.deadlineTone) {
                        "danger" -> Danger to DangerBg
                        "warning" -> Warning to WarningBg
                        else -> InkMuted to Surface2
                    }
                    Chip(deadline, fg, bg)
                } else if (order.kind.startsWith("PO")) {
                    Chip(order.kind, InkMuted, Surface2)
                }
            }
            Text(
                listOfNotNull(order.courier, order.buyerName, order.marketplace)
                    .filter { it.isNotBlank() }.joinToString(" · "),
                color = InkMuted, fontSize = 12.5.sp, maxLines = 1,
            )
            Spacer(Modifier.height(2.dp))
            Row(verticalAlignment = Alignment.CenterVertically) {
                order.items.take(3).forEach { item ->
                    ProductThumb(item.imageUrl, item.sku, size = 32.dp, radius = 9.dp)
                    Spacer(Modifier.width(5.dp))
                }
                Spacer(Modifier.width(4.dp))
                Text(
                    "${order.totalQuantity} unit · ${order.items.size} SKU",
                    color = Ink, fontSize = 12.5.sp, fontWeight = FontWeight.Medium,
                    modifier = Modifier.weight(1f), maxLines = 1,
                )
                StatusChip(order)
            }
        }
    }
}

@Composable
private fun StatusChip(order: Order) {
    when {
        order.readyLabel.isNotBlank() -> {
            val ok = order.readyLabel.startsWith("Siap")
            Chip(
                order.readyLabel.substringBefore(" (").let { if (ok) it else it.replace("Menunggu", "Tunggu") },
                if (ok) Success else Danger,
                if (ok) SuccessBg else DangerBg,
            )
        }
        order.stage == Stages.SUDAH -> Chip(
            if (order.scannedQuantity > 0) "${order.scannedQuantity}/${order.totalQuantity} discan" else order.stageLabel,
            Info, InfoBg,
        )
        order.stage == Stages.DIKIRIM -> Chip(order.shippedAt?.takeIf { it.length >= 16 }?.let { "Kirim " + it.substring(11, 16) } ?: order.stageLabel, Success, SuccessBg)
        else -> Chip(order.stageLabel, Warning, WarningBg)
    }
}
