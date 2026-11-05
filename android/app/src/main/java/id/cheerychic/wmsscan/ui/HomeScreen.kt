package id.cheerychic.wmsscan.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
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
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.compose.LocalLifecycleOwner
import androidx.lifecycle.repeatOnLifecycle
import id.cheerychic.wmsscan.data.AbsenToday
import id.cheerychic.wmsscan.data.Api
import id.cheerychic.wmsscan.data.ApiException
import id.cheerychic.wmsscan.data.Session
import id.cheerychic.wmsscan.data.Summary
import id.cheerychic.wmsscan.data.Updater
import id.cheerychic.wmsscan.data.User
import id.cheerychic.wmsscan.ui.components.AppCard
import id.cheerychic.wmsscan.ui.components.Banner
import id.cheerychic.wmsscan.ui.components.BannerView
import id.cheerychic.wmsscan.ui.components.BottomNav
import id.cheerychic.wmsscan.ui.components.Chip
import id.cheerychic.wmsscan.ui.components.GoldOn
import id.cheerychic.wmsscan.ui.components.Ic
import id.cheerychic.wmsscan.ui.components.ProgressLine
import id.cheerychic.wmsscan.ui.components.RoundButton
import id.cheerychic.wmsscan.ui.components.StatusBarIcons
import id.cheerychic.wmsscan.ui.components.Tab
import id.cheerychic.wmsscan.ui.components.Tone
import id.cheerychic.wmsscan.ui.theme.Bg
import id.cheerychic.wmsscan.ui.theme.Brand
import id.cheerychic.wmsscan.ui.theme.Brand2
import id.cheerychic.wmsscan.ui.theme.BrandBg
import id.cheerychic.wmsscan.ui.theme.Info
import id.cheerychic.wmsscan.ui.theme.InfoBg
import id.cheerychic.wmsscan.ui.theme.Ink
import id.cheerychic.wmsscan.ui.theme.InkMuted
import id.cheerychic.wmsscan.ui.theme.Night
import id.cheerychic.wmsscan.ui.theme.NightCard
import id.cheerychic.wmsscan.ui.theme.OnBrand
import id.cheerychic.wmsscan.ui.theme.Success
import id.cheerychic.wmsscan.ui.theme.SuccessBg
import id.cheerychic.wmsscan.ui.theme.Warning
import id.cheerychic.wmsscan.ui.theme.WarningBg
import kotlinx.coroutines.launch
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

@Composable
fun HomeScreen(user: User, onTab: (Tab) -> Unit, onAbsen: (String) -> Unit = {}, onAbsenHistory: () -> Unit = {}) {
    StatusBarIcons(darkScreen = false)
    val scope = rememberCoroutineScope()
    val lifecycle = LocalLifecycleOwner.current
    var summary by remember { mutableStateOf<Summary?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var confirmLogout by remember { mutableStateOf(false) }
    var absen by remember { mutableStateOf<AbsenToday?>(null) }
    var absenError by remember { mutableStateOf<String?>(null) }

    suspend fun load() {
        // Hak akses bisa diubah admin kapan saja; ambil ulang setiap kembali.
        runCatching { Session.updateUser(Api.me().user) }
        if (Session.user.value?.can?.absen == true) {
            try {
                absen = Api.absenToday()
                absenError = null
            } catch (e: ApiException) {
                absen = null
                absenError = e.message
            } catch (e: Exception) {
                absenError = "Data absen belum bisa dimuat."
            }
        }
        if (Session.user.value?.can?.pesanan != true) return
        try {
            summary = Api.summary()
            error = null
        } catch (e: ApiException) {
            error = e.message
        } catch (e: Exception) {
            error = "Jawaban server tidak dikenali."
        }
    }

    LaunchedEffect(Unit) {
        lifecycle.lifecycle.repeatOnLifecycle(Lifecycle.State.RESUMED) { load() }
    }

    if (confirmLogout) {
        AlertDialog(
            onDismissRequest = { confirmLogout = false },
            title = { Text("Keluar dari aplikasi?") },
            text = { Text("Anda perlu login lagi untuk scan.") },
            confirmButton = {
                TextButton(onClick = {
                    confirmLogout = false
                    scope.launch {
                        Api.logout()
                        Session.signOut()
                    }
                }) { Text("Keluar", color = Ink) }
            },
            dismissButton = { TextButton(onClick = { confirmLogout = false }) { Text("Batal", color = InkMuted) } },
            containerColor = Color.White,
        )
    }

    Box(Modifier.fillMaxSize().background(Bg)) {
        Column(
            Modifier
                .fillMaxSize()
                .verticalScroll(rememberScrollState())
                .statusBarsPadding()
                .padding(horizontal = 20.dp),
            verticalArrangement = Arrangement.spacedBy(16.dp),
        ) {
            Spacer(Modifier.height(4.dp))
            // Sapaan
            Row(verticalAlignment = Alignment.CenterVertically) {
                Box(
                    Modifier.size(44.dp).clip(RoundedCornerShape(15.dp)).background(BrandBg),
                    contentAlignment = Alignment.Center,
                ) {
                    Text(user.name.take(1).uppercase(), color = Brand2, fontWeight = FontWeight.ExtraBold, fontSize = 18.sp)
                }
                Spacer(Modifier.width(12.dp))
                Column(Modifier.weight(1f)) {
                    Text(
                        SimpleDateFormat("EEEE, d MMM", Locale("id")).format(Date()) + (user.role?.let { " · $it" } ?: ""),
                        color = InkMuted,
                        fontSize = 12.5.sp,
                        maxLines = 1,
                    )
                    Text("Halo, ${user.name.substringBefore(' ')} 👋", color = Ink, fontSize = 20.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1)
                }
                RoundButton(Ic.Refresh, "Muat ulang") { scope.launch { load() } }
                Spacer(Modifier.width(8.dp))
                RoundButton(Ic.Logout, "Keluar") { confirmLogout = true }
            }

            BannerView(error?.let { Banner(it, Tone.Error) })

            if (user.can.absen) {
                AbsenCard(absen, absenError, onAbsen = onAbsen, onHistory = onAbsenHistory)
            }

            if (user.can.pesanan) {
                TargetCard(summary) { onTab(Tab.Pesanan) }
                Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    StatCard(
                        summary?.belumDikemas, "Belum\ndikemas",
                        summary?.let { "${it.siapDikemas} siap" }, Warning, WarningBg, Modifier.weight(1f),
                    ) { onTab(Tab.Pesanan) }
                    StatCard(
                        summary?.sudahDikemas, "Sudah\ndikemas",
                        "antre kurir", Info, InfoBg, Modifier.weight(1f),
                    ) { onTab(Tab.Serah) }
                    StatCard(
                        summary?.dikirim, "Dikirim\nhari ini",
                        summary?.let { "+${it.dikirimSejamTerakhir} /jam" }, Success, SuccessBg, Modifier.weight(1f),
                    ) { onTab(Tab.Pesanan) }
                }
            }

            Text("Mulai kerja", color = Ink, fontWeight = FontWeight.Bold, fontSize = 17.sp)
            Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                WorkTile(
                    "Scan Kemas", if (user.can.kemas) "QC isi paket" else "Belum ada akses",
                    Ic.Box, gold = true, enabled = user.can.kemas, modifier = Modifier.weight(1f),
                ) { onTab(Tab.Kemas) }
                WorkTile(
                    "Serah Terima", if (user.can.serahTerima) "Serahkan ke kurir" else "Belum ada akses",
                    Ic.Truck, gold = false, enabled = user.can.serahTerima, modifier = Modifier.weight(1f),
                ) { onTab(Tab.Serah) }
            }

            // Versi aplikasi + cek pembaruan manual.
            Row(
                Modifier.fillMaxWidth().padding(top = 4.dp),
                horizontalArrangement = Arrangement.Center,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text("Versi ${Updater.versionName} · ", color = InkMuted, fontSize = 12.5.sp)
                Text(
                    "Periksa pembaruan",
                    color = Brand2,
                    fontSize = 12.5.sp,
                    fontWeight = FontWeight.SemiBold,
                    modifier = Modifier.clip(RoundedCornerShape(8.dp)).clickable { Updater.check(manual = true) }.padding(4.dp),
                )
            }
            Spacer(Modifier.height(120.dp))
        }

        BottomNav(Tab.Home, onTab, Modifier.align(Alignment.BottomCenter))
    }
}

/** Kartu hitam: resi yang batas kirimnya hari ini, sudah berapa yang terkirim. */
@Composable
private fun TargetCard(summary: Summary?, onClick: () -> Unit) {
    val done = summary?.dikirim ?: 0
    val target = done + (summary?.batasHariIniTerbuka ?: 0)
    val fraction = if (target == 0) 0f else done.toFloat() / target
    Box(
        Modifier
            .fillMaxWidth()
            .shadow(14.dp, RoundedCornerShape(26.dp), spotColor = Color(0x66000000))
            .clip(RoundedCornerShape(26.dp))
            .background(Brush.linearGradient(listOf(NightCard, Night)))
            .clickable(onClick = onClick)
    ) {
        // Lingkaran emas samar di pojok kanan atas.
        Box(
            Modifier
                .size(170.dp)
                .align(Alignment.TopEnd)
                .offset(x = 50.dp, y = (-50).dp)
                .clip(CircleShape)
                .background(Brush.radialGradient(listOf(Brand.copy(alpha = 0.28f), Color.Transparent)))
        )
        Column(Modifier.padding(20.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Text("Batas kirim hari ini", color = Color(0xFFBDB6A3), fontSize = 13.sp)
            Row(verticalAlignment = Alignment.Bottom) {
                Text(if (summary == null) "–" else "$done", color = Color.White, fontSize = 38.sp, fontWeight = FontWeight.ExtraBold)
                Spacer(Modifier.width(6.dp))
                Text("/ $target paket", color = Color(0xFFBDB6A3), fontSize = 15.sp, modifier = Modifier.padding(bottom = 7.dp))
            }
            ProgressLine(fraction, track = Color(0x22FFFFFF))
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text("${(fraction * 100).toInt()}% selesai", color = Color(0xFFBDB6A3), fontSize = 12.5.sp, modifier = Modifier.weight(1f))
                val late = summary?.lewatBatas ?: 0
                if (late > 0) {
                    Icon(Ic.Clock, null, tint = Color(0xFFFF8A8E), modifier = Modifier.size(14.dp))
                    Spacer(Modifier.width(4.dp))
                    Text("$late lewat batas", color = Color(0xFFFF8A8E), fontSize = 12.5.sp, fontWeight = FontWeight.SemiBold)
                } else if (summary != null) {
                    Text("Tidak ada yang telat", color = Color(0xFF7FD9B0), fontSize = 12.5.sp)
                }
            }
        }
    }
}

@Composable
private fun StatCard(
    value: Int?,
    label: String,
    chip: String?,
    fg: Color,
    bg: Color,
    modifier: Modifier,
    onClick: () -> Unit,
) {
    AppCard(modifier, radius = 20.dp, onClick = onClick) {
        Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Text(value?.toString() ?: "–", color = Ink, fontSize = 24.sp, fontWeight = FontWeight.ExtraBold)
            Text(label, color = InkMuted, fontSize = 11.5.sp, lineHeight = 14.sp)
            Spacer(Modifier.height(2.dp))
            if (chip != null) Chip(chip, fg, bg)
        }
    }
}

@Composable
private fun WorkTile(
    title: String,
    subtitle: String,
    icon: ImageVector,
    gold: Boolean,
    enabled: Boolean,
    modifier: Modifier,
    onClick: () -> Unit,
) {
    val bg = if (gold) Brush.linearGradient(listOf(Color(0xFFE2BE4C), Brand2)) else Brush.linearGradient(listOf(NightCard, Night))
    val fg = if (gold) OnBrand else Color.White
    Column(
        modifier
            .height(150.dp)
            .shadow(12.dp, RoundedCornerShape(24.dp), spotColor = if (gold) Brand else Color.Black)
            .clip(RoundedCornerShape(24.dp))
            .background(bg)
            .clickable(enabled = enabled, onClick = onClick)
            .padding(16.dp),
        verticalArrangement = Arrangement.SpaceBetween,
    ) {
        Box(
            Modifier.size(42.dp).clip(RoundedCornerShape(14.dp)).background(if (gold) Color(0x33FFFFFF) else Color(0x1AFFFFFF)),
            contentAlignment = Alignment.Center,
        ) {
            Icon(icon, null, tint = if (gold) GoldOn else Brand, modifier = Modifier.size(22.dp))
        }
        Column {
            Text(title, color = fg.copy(alpha = if (enabled) 1f else 0.5f), fontSize = 17.sp, fontWeight = FontWeight.ExtraBold)
            Text(subtitle, color = fg.copy(alpha = 0.7f), fontSize = 12.5.sp)
        }
    }
}

/** Kartu absen hari ini: status, jam masuk/pulang, dan tombol absen dari HP. */
@Composable
private fun AbsenCard(today: AbsenToday?, error: String?, onAbsen: (String) -> Unit, onHistory: () -> Unit) {
    AppCard(radius = 22.dp, onClick = if (today != null) onHistory else null) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Box(
                    Modifier.size(40.dp).clip(RoundedCornerShape(13.dp)).background(BrandBg),
                    contentAlignment = Alignment.Center,
                ) {
                    Icon(Ic.Fingerprint, null, tint = Brand2, modifier = Modifier.size(22.dp))
                }
                Spacer(Modifier.width(12.dp))
                Column(Modifier.weight(1f)) {
                    Text("Absensi hari ini", color = Ink, fontWeight = FontWeight.Bold, fontSize = 15.sp)
                    Text(
                        today?.shift?.label ?: if (today != null) "Belum ada jam kerja" else "Memuat…",
                        color = InkMuted, fontSize = 12.5.sp, maxLines = 1,
                    )
                }
                if (today != null) {
                    val (fg, bg) = variantColors(today.day.variant)
                    Chip(today.day.label, fg, bg)
                }
            }

            if (error != null && today == null) {
                Text(error, color = Warning, fontSize = 13.sp, lineHeight = 18.sp)
                return@Column
            }
            if (today == null) return@Column

            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                TimeBox("Masuk", today.day.checkIn, Modifier.weight(1f))
                TimeBox("Pulang", today.day.checkOut, Modifier.weight(1f))
            }
            if (!today.day.note.isNullOrBlank()) {
                Text(today.day.note, color = if (today.day.lateMinutes > 0) Warning else InkMuted, fontSize = 12.5.sp)
            }

            val leave = today.leave
            when {
                leave != null -> Text("${leave.label} · ${leave.period}", color = InkMuted, fontSize = 13.sp)
                today.nextAction == null -> Text(
                    if (today.employee.method == "machine") "Absen lewat mesin fingerprint di gudang." else "Absen dari HP belum diaktifkan untukmu.",
                    color = InkMuted, fontSize = 13.sp,
                )
                else -> {
                    val out = today.nextAction == "out"
                    Box(
                        Modifier
                            .fillMaxWidth()
                            .clip(RoundedCornerShape(16.dp))
                            .background(if (out) Ink else Brand)
                            .clickable { onAbsen(today.nextAction ?: "in") }
                            .padding(vertical = 14.dp),
                        contentAlignment = Alignment.Center,
                    ) {
                        Text(
                            when {
                                !out -> "Absen Masuk"
                                today.day.checkOut != null -> "Perbarui Absen Pulang"
                                else -> "Absen Pulang"
                            },
                            color = if (out) Color.White else OnBrand, fontWeight = FontWeight.ExtraBold, fontSize = 15.sp,
                        )
                    }
                }
            }
        }
    }
}

@Composable
private fun TimeBox(label: String, time: String?, modifier: Modifier) {
    Column(modifier.clip(RoundedCornerShape(14.dp)).background(Bg).padding(horizontal = 12.dp, vertical = 10.dp)) {
        Text(label, color = InkMuted, fontSize = 11.5.sp)
        Text(time ?: "–", color = Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
    }
}
