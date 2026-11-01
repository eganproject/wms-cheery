package id.cheerychic.wmsscan.ui

import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.KeyboardArrowLeft
import androidx.compose.material.icons.filled.KeyboardArrowRight
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material.icons.filled.Search
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Tab
import androidx.compose.material3.TabRow
import androidx.compose.material3.TabRowDefaults
import androidx.compose.material3.TabRowDefaults.tabIndicatorOffset
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
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
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
import id.cheerychic.wmsscan.ui.components.AppBar
import id.cheerychic.wmsscan.ui.components.Banner
import id.cheerychic.wmsscan.ui.components.BannerView
import id.cheerychic.wmsscan.ui.components.Pill
import id.cheerychic.wmsscan.ui.components.Tone
import id.cheerychic.wmsscan.ui.theme.Brand
import id.cheerychic.wmsscan.ui.theme.Danger
import id.cheerychic.wmsscan.ui.theme.DangerBg
import id.cheerychic.wmsscan.ui.theme.Info
import id.cheerychic.wmsscan.ui.theme.InfoBg
import id.cheerychic.wmsscan.ui.theme.Ink
import id.cheerychic.wmsscan.ui.theme.InkLine
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
    Stages.BELUM to "Belum Dikemas",
    Stages.SUDAH to "Sudah Dikemas",
    Stages.DIKIRIM to "Dikirim",
)

class OrdersViewModel : ViewModel() {
    var stage by mutableStateOf(Stages.BELUM)
        private set
    var search by mutableStateOf("")
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
        reload()
    }

    fun shiftDay(days: Int) {
        date = (date.clone() as Calendar).apply { add(Calendar.DAY_OF_MONTH, days) }
        reload()
    }

    fun reload() {
        job?.cancel()
        orders.clear()
        page = 0
        lastPage = 1
        total = null
        loadMore()
        viewModelScope.launch {
            runCatching { summary = Api.summary(dateText) }
        }
    }

    fun loadMore() {
        if (loading || page >= lastPage) return
        loading = true
        job = viewModelScope.launch {
            try {
                val result = Api.orders(stage, page + 1, search.trim(), if (stage == Stages.DIKIRIM) dateText else null)
                page = result.meta.currentPage
                lastPage = result.meta.lastPage
                total = result.meta.total
                orders.addAll(result.data)
                error = null
            } catch (e: ApiException) {
                error = e.message
            } catch (e: Exception) {
                error = "Jawaban server tidak dikenali."
            } finally {
                loading = false
            }
        }
    }
}

@Composable
fun OrdersScreen(
    initialStage: String,
    onBack: () -> Unit,
    onOpen: (Int) -> Unit,
    vm: OrdersViewModel = viewModel(),
) {
    LaunchedEffect(initialStage) { vm.start(initialStage) }

    val listState = rememberLazyListState()
    val nearEnd by remember {
        derivedStateOf {
            val last = listState.layoutInfo.visibleItemsInfo.lastOrNull()?.index ?: 0
            last >= listState.layoutInfo.totalItemsCount - 5
        }
    }
    LaunchedEffect(nearEnd, vm.orders.size) { if (nearEnd && vm.orders.isNotEmpty()) vm.loadMore() }

    Scaffold(
        topBar = {
            AppBar("Daftar Pesanan", onBack = onBack, actions = {
                IconButton(onClick = { vm.reload() }) { Icon(Icons.Filled.Refresh, contentDescription = "Muat ulang") }
            })
        },
        containerColor = Color.White,
    ) { padding ->
        Column(Modifier.fillMaxSize().padding(padding)) {
            val selected = TABS.indexOfFirst { it.first == vm.stage }.coerceAtLeast(0)
            TabRow(
                selectedTabIndex = selected,
                containerColor = Color.White,
                indicator = { positions ->
                    TabRowDefaults.SecondaryIndicator(Modifier.tabIndicatorOffset(positions[selected]), color = Brand)
                },
            ) {
                TABS.forEachIndexed { index, (key, label) ->
                    val count = when (key) {
                        Stages.BELUM -> vm.summary?.belumDikemas
                        Stages.SUDAH -> vm.summary?.sudahDikemas
                        else -> vm.summary?.dikirim
                    }
                    Tab(
                        selected = index == selected,
                        onClick = { vm.select(key) },
                        text = {
                            Text(
                                if (count != null) "$label\n$count" else label,
                                fontSize = 13.sp,
                                fontWeight = if (index == selected) FontWeight.Bold else FontWeight.Normal,
                                textAlign = androidx.compose.ui.text.style.TextAlign.Center,
                            )
                        },
                    )
                }
            }

            Column(Modifier.padding(horizontal = 16.dp, vertical = 10.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedTextField(
                    value = vm.search,
                    onValueChange = { vm.search = it },
                    placeholder = { Text("Cari resi, no. pesanan, pembeli, SKU") },
                    singleLine = true,
                    leadingIcon = { Icon(Icons.Filled.Search, contentDescription = null) },
                    keyboardOptions = KeyboardOptions(imeAction = ImeAction.Search),
                    keyboardActions = KeyboardActions(onSearch = { vm.reload() }),
                    modifier = Modifier.fillMaxWidth(),
                )
                if (vm.stage == Stages.DIKIRIM) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        IconButton(onClick = { vm.shiftDay(-1) }) {
                            Icon(Icons.Filled.KeyboardArrowLeft, contentDescription = "Hari sebelumnya")
                        }
                        Text(
                            if (vm.isToday) "Hari ini · ${vm.datePretty}" else vm.datePretty,
                            fontWeight = FontWeight.SemiBold,
                            modifier = Modifier.weight(1f),
                            textAlign = androidx.compose.ui.text.style.TextAlign.Center,
                        )
                        IconButton(onClick = { vm.shiftDay(1) }, enabled = !vm.isToday) {
                            Icon(Icons.Filled.KeyboardArrowRight, contentDescription = "Hari berikutnya")
                        }
                    }
                }
                vm.total?.let { Text("$it resi", color = InkMuted, fontSize = 13.sp) }
                BannerView(vm.error?.let { Banner(it, Tone.Error) })
            }

            LazyColumn(
                state = listState,
                verticalArrangement = Arrangement.spacedBy(8.dp),
                modifier = Modifier.weight(1f).padding(horizontal = 16.dp),
            ) {
                items(vm.orders, key = { it.id }) { order -> OrderCard(order) { onOpen(order.id) } }
                item {
                    Box(Modifier.fillMaxWidth().padding(16.dp), contentAlignment = Alignment.Center) {
                        when {
                            vm.loading -> CircularProgressIndicator(color = Brand)
                            vm.orders.isEmpty() && vm.error == null && vm.total != null ->
                                Text("Tidak ada resi di sini.", color = InkMuted)
                        }
                    }
                }
            }
        }
    }
}

@Composable
fun OrderCard(order: Order, onClick: () -> Unit) {
    Column(
        Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(12.dp))
            .border(1.dp, InkLine, RoundedCornerShape(12.dp))
            .clickable(onClick = onClick)
            .padding(12.dp),
        verticalArrangement = Arrangement.spacedBy(4.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            Text(order.trackingNumber ?: "(tanpa resi)", fontWeight = FontWeight.Bold, fontSize = 15.sp, modifier = Modifier.weight(1f))
            if (order.isPriority) Pill("Prioritas", Danger, DangerBg)
            order.deadlineLabel?.let { label ->
                val (fg, bg) = when (order.deadlineTone) {
                    "danger" -> Danger to DangerBg
                    "warning" -> Warning to WarningBg
                    else -> InkMuted to Surface2
                }
                Pill(label, fg, bg)
            }
        }
        Text(
            listOfNotNull(order.marketplace, order.courier, order.buyerName).filter { it.isNotBlank() }.joinToString(" · "),
            fontSize = 13.sp,
            color = InkMuted,
        )
        Text(
            order.items.joinToString(", ") { "${it.sku ?: "?"} ×${it.quantity}" },
            fontSize = 13.sp,
            color = Ink,
            maxLines = 2,
        )
        Row(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.CenterVertically) {
            val (fg, bg) = when (order.stage) {
                Stages.DIKIRIM -> Success to SuccessBg
                Stages.SUDAH -> Info to InfoBg
                else -> Warning to WarningBg
            }
            Pill(order.stageLabel, fg, bg)
            if (order.readyLabel.isNotBlank()) {
                Pill(order.readyLabel, if (order.readyLabel.startsWith("Siap")) Success else Danger,
                    if (order.readyLabel.startsWith("Siap")) SuccessBg else DangerBg)
            }
            if (order.kind.isNotBlank()) Pill(order.kind, InkMuted, Surface2)
        }
        if (order.stageDetail.isNotBlank()) {
            Text(order.stageDetail, fontSize = 12.sp, color = InkMuted)
        }
    }
}
