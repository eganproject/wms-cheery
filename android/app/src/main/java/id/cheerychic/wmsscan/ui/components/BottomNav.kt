package id.cheerychic.wmsscan.ui.components

import android.app.Activity
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalView
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.view.WindowCompat
import id.cheerychic.wmsscan.ui.theme.Brand
import id.cheerychic.wmsscan.ui.theme.Ink

enum class Tab { Home, Kemas, Serah, Pesanan }

/**
 * Menu bawah gelap dengan tombol scan emas di tengah (langsung ke Scan Kemas).
 */
@Composable
fun BottomNav(current: Tab, onOpen: (Tab) -> Unit, modifier: Modifier = Modifier) {
    Box(modifier.fillMaxWidth().navigationBarsPadding().padding(horizontal = 14.dp, vertical = 12.dp)) {
        Row(
            Modifier
                .fillMaxWidth()
                .height(68.dp)
                .shadow(16.dp, RoundedCornerShape(24.dp))
                .clip(RoundedCornerShape(24.dp))
                .background(Ink.copy(alpha = 0.97f))
                .padding(horizontal = 8.dp),
            horizontalArrangement = Arrangement.SpaceAround,
            verticalAlignment = Alignment.CenterVertically,
        ) {
            NavItem(Ic.Home, "Beranda", current == Tab.Home) { onOpen(Tab.Home) }
            NavItem(Ic.Box, "Kemas", current == Tab.Kemas) { onOpen(Tab.Kemas) }
            Box(Modifier.width(64.dp))
            NavItem(Ic.Truck, "Serah", current == Tab.Serah) { onOpen(Tab.Serah) }
            NavItem(Ic.List, "Pesanan", current == Tab.Pesanan) { onOpen(Tab.Pesanan) }
        }
        Box(
            Modifier
                .align(Alignment.TopCenter)
                .offset(y = (-18).dp)
                .size(58.dp)
                .shadow(14.dp, RoundedCornerShape(19.dp), spotColor = Brand)
                .clip(RoundedCornerShape(19.dp))
                .background(Brand)
                .clickable { onOpen(Tab.Kemas) },
            contentAlignment = Alignment.Center,
        ) {
            Icon(Ic.Scan, contentDescription = "Scan Kemas", tint = GoldOn, modifier = Modifier.size(28.dp))
        }
    }
}

@Composable
private fun NavItem(icon: ImageVector, label: String, selected: Boolean, onClick: () -> Unit) {
    Column(
        Modifier
            .width(64.dp)
            .clip(RoundedCornerShape(14.dp))
            .clickable(onClick = onClick)
            .padding(vertical = 6.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Icon(icon, contentDescription = label, tint = if (selected) Brand else Color(0xFF9A9CA3), modifier = Modifier.size(22.dp))
        Text(
            label,
            fontSize = 10.5.sp,
            fontWeight = FontWeight.SemiBold,
            color = if (selected) Color.White else Color(0xFF9A9CA3),
        )
    }
}

/** Warna ikon status bar: putih untuk layar gelap, hitam untuk layar terang. */
@Composable
fun StatusBarIcons(darkScreen: Boolean) {
    val view = LocalView.current
    DisposableEffect(darkScreen) {
        val window = (view.context as? Activity)?.window
        if (window != null) {
            WindowCompat.getInsetsController(window, view).isAppearanceLightStatusBars = !darkScreen
        }
        // Tidak dikembalikan saat layar ditutup: layar berikutnya sudah
        // memasang warnanya sendiri, dan mengembalikan di sini menimpanya.
        onDispose { }
    }
}
