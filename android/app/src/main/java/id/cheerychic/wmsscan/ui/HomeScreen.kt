package id.cheerychic.wmsscan.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ExitToApp
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
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
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.compose.LocalLifecycleOwner
import androidx.lifecycle.repeatOnLifecycle
import id.cheerychic.wmsscan.data.Api
import id.cheerychic.wmsscan.data.ApiException
import id.cheerychic.wmsscan.data.Session
import id.cheerychic.wmsscan.data.Stages
import id.cheerychic.wmsscan.data.Summary
import id.cheerychic.wmsscan.data.User
import id.cheerychic.wmsscan.ui.components.AppBar
import id.cheerychic.wmsscan.ui.components.Banner
import id.cheerychic.wmsscan.ui.components.BannerView
import id.cheerychic.wmsscan.ui.components.Tone
import id.cheerychic.wmsscan.ui.theme.Brand
import id.cheerychic.wmsscan.ui.theme.Ink
import id.cheerychic.wmsscan.ui.theme.InkLine
import id.cheerychic.wmsscan.ui.theme.InkMuted
import id.cheerychic.wmsscan.ui.theme.Success
import id.cheerychic.wmsscan.ui.theme.Warning
import kotlinx.coroutines.launch

@Composable
fun HomeScreen(user: User, onOpen: (String) -> Unit) {
    val scope = rememberCoroutineScope()
    val lifecycle = LocalLifecycleOwner.current
    var summary by remember { mutableStateOf<Summary?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var confirmLogout by remember { mutableStateOf(false) }

    suspend fun load() {
        // Hak akses bisa diubah admin kapan saja; ambil ulang setiap kembali.
        runCatching { Session.updateUser(Api.me().user) }
        if (!Session.user.value!!.can.pesanan) return
        try {
            summary = Api.summary()
            error = null
        } catch (e: ApiException) {
            error = e.message
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
                }) { Text("Keluar") }
            },
            dismissButton = { TextButton(onClick = { confirmLogout = false }) { Text("Batal") } },
        )
    }

    Scaffold(
        topBar = {
            AppBar("WMS Cheery Scan", actions = {
                IconButton(onClick = { scope.launch { load() } }) {
                    Icon(Icons.Filled.Refresh, contentDescription = "Muat ulang")
                }
                IconButton(onClick = { confirmLogout = true }) {
                    Icon(Icons.AutoMirrored.Filled.ExitToApp, contentDescription = "Keluar")
                }
            })
        },
        containerColor = Color.White,
    ) { padding ->
        Column(
            Modifier
                .fillMaxSize()
                .padding(padding)
                .verticalScroll(rememberScrollState())
                .padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Text("Halo, ${user.name}", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
            Text(
                listOfNotNull(user.role, Session.serverUrl.removePrefix("https://").removePrefix("http://")).joinToString(" · "),
                color = InkMuted,
                fontSize = 13.sp,
            )

            BannerView(error?.let { Banner(it, Tone.Error) })

            if (user.can.pesanan) {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    StatTile("Belum dikemas", summary?.belumDikemas, Warning, Modifier.weight(1f)) {
                        onOpen("orders/${Stages.BELUM}")
                    }
                    StatTile("Sudah dikemas", summary?.sudahDikemas, Brand, Modifier.weight(1f)) {
                        onOpen("orders/${Stages.SUDAH}")
                    }
                    StatTile("Dikirim hari ini", summary?.dikirim, Success, Modifier.weight(1f)) {
                        onOpen("orders/${Stages.DIKIRIM}")
                    }
                }
                summary?.let {
                    if (it.siapDikemas > 0) {
                        Text("${it.siapDikemas} resi stoknya siap dikemas.", color = InkMuted, fontSize = 13.sp)
                    }
                }
            }

            MenuCard(
                title = "Scan Kemas",
                subtitle = "Scan resi, lalu scan barangnya satu per satu (QC Kemas).",
                enabled = user.can.kemas,
                onClick = { onOpen("kemas") },
            )
            MenuCard(
                title = "Scan Serah Terima",
                subtitle = "Scan resi paket yang diserahkan ke kurir. Stok langsung berkurang.",
                enabled = user.can.serahTerima,
                onClick = { onOpen("serah") },
            )
            MenuCard(
                title = "Daftar Pesanan",
                subtitle = "Lihat yang belum dikemas, sudah dikemas, dan sudah dikirim.",
                enabled = user.can.pesanan,
                onClick = { onOpen("orders/${Stages.BELUM}") },
            )
        }
    }
}

@Composable
private fun StatTile(label: String, value: Int?, accent: Color, modifier: Modifier, onClick: () -> Unit) {
    Column(
        modifier
            .clip(RoundedCornerShape(12.dp))
            .border(1.dp, InkLine, RoundedCornerShape(12.dp))
            .clickable(onClick = onClick)
            .padding(12.dp),
    ) {
        Text(value?.toString() ?: "–", fontSize = 26.sp, fontWeight = FontWeight.Bold, color = Ink)
        Row(verticalAlignment = Alignment.CenterVertically) {
            androidx.compose.foundation.layout.Box(
                Modifier.padding(end = 6.dp).clip(RoundedCornerShape(50)).background(accent).padding(4.dp)
            )
            Text(label, fontSize = 12.sp, color = InkMuted)
        }
    }
}

@Composable
private fun MenuCard(title: String, subtitle: String, enabled: Boolean, onClick: () -> Unit) {
    Column(
        Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(14.dp))
            .background(if (enabled) Ink else Color(0xFFF3F4F6))
            .clickable(enabled = enabled, onClick = onClick)
            .padding(18.dp),
    ) {
        Text(
            title,
            fontSize = 20.sp,
            fontWeight = FontWeight.Bold,
            color = if (enabled) Color.White else InkMuted,
        )
        Text(
            if (enabled) subtitle else "Akun Anda belum punya akses ke menu ini.",
            fontSize = 13.sp,
            color = if (enabled) Color(0xFFD1D5DB) else InkMuted,
        )
    }
}
