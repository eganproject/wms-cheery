package id.cheerychic.wmsscan.ui.components

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.SubcomposeAsyncImage
import id.cheerychic.wmsscan.data.Session
import id.cheerychic.wmsscan.ui.theme.Brand
import id.cheerychic.wmsscan.ui.theme.Brand2
import id.cheerychic.wmsscan.ui.theme.BrandBg
import id.cheerychic.wmsscan.ui.theme.Danger
import id.cheerychic.wmsscan.ui.theme.DangerBg
import id.cheerychic.wmsscan.ui.theme.Info
import id.cheerychic.wmsscan.ui.theme.InfoBg
import id.cheerychic.wmsscan.ui.theme.Ink
import id.cheerychic.wmsscan.ui.theme.InkLine
import id.cheerychic.wmsscan.ui.theme.InkMuted
import id.cheerychic.wmsscan.ui.theme.OnBrand
import id.cheerychic.wmsscan.ui.theme.Success
import id.cheerychic.wmsscan.ui.theme.SuccessBg
import id.cheerychic.wmsscan.ui.theme.Surface2
import id.cheerychic.wmsscan.ui.theme.Warning
import id.cheerychic.wmsscan.ui.theme.WarningBg

enum class Tone { Info, Success, Error, Warning, Neutral }

data class Banner(val text: String, val tone: Tone)

fun toneColors(tone: Tone): Pair<Color, Color> = when (tone) {
    Tone.Success -> Success to SuccessBg
    Tone.Error -> Danger to DangerBg
    Tone.Warning -> Warning to WarningBg
    Tone.Info -> Info to InfoBg
    Tone.Neutral -> Ink to Surface2
}

/** Kartu putih membulat dengan bayangan lembut. */
@Composable
fun AppCard(
    modifier: Modifier = Modifier,
    radius: Dp = 22.dp,
    onClick: (() -> Unit)? = null,
    content: @Composable ColumnScope.() -> Unit,
) {
    val shape = RoundedCornerShape(radius)
    Column(
        modifier
            .shadow(10.dp, shape, ambientColor = Color(0x223C2D0A), spotColor = Color(0x333C2D0A))
            .clip(shape)
            .background(Color.White)
            .then(if (onClick != null) Modifier.clickable(onClick = onClick) else Modifier),
        content = content,
    )
}

/** Tombol ikon kotak membulat, terang atau gelap (untuk layar hitam). */
@Composable
fun RoundButton(icon: ImageVector, description: String, dark: Boolean = false, tint: Color? = null, onClick: () -> Unit) {
    Box(
        Modifier
            .size(42.dp)
            .then(
                if (dark) Modifier.clip(RoundedCornerShape(14.dp)).background(Color(0x1AFFFFFF))
                else Modifier.shadow(6.dp, RoundedCornerShape(14.dp), ambientColor = Color(0x22000000)).clip(RoundedCornerShape(14.dp)).background(Color.White)
            )
            .clickable(onClick = onClick),
        contentAlignment = Alignment.Center,
    ) {
        Icon(icon, contentDescription = description, tint = tint ?: if (dark) Color.White else Ink, modifier = Modifier.size(20.dp))
    }
}

/** Baris judul: tombol kembali, judul di tengah, aksi di kanan. */
@Composable
fun Header(
    title: String,
    onBack: (() -> Unit)? = null,
    dark: Boolean = false,
    action: @Composable () -> Unit = { Spacer(Modifier.size(42.dp)) },
) {
    Row(
        Modifier
            .fillMaxWidth()
            .statusBarsPadding()
            .padding(horizontal = 20.dp, vertical = 8.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        if (onBack != null) RoundButton(Ic.Back, "Kembali", dark = dark, onClick = onBack) else Spacer(Modifier.size(42.dp))
        Text(
            title,
            modifier = Modifier.weight(1f),
            textAlign = TextAlign.Center,
            fontWeight = FontWeight.Bold,
            fontSize = 17.sp,
            color = if (dark) Color.White else Ink,
        )
        action()
    }
}

@Composable
fun Chip(text: String, fg: Color, bg: Color, modifier: Modifier = Modifier, onClick: (() -> Unit)? = null) {
    Box(
        modifier
            .clip(RoundedCornerShape(50))
            .background(bg)
            .then(if (onClick != null) Modifier.clickable(onClick = onClick) else Modifier)
            .padding(horizontal = 10.dp, vertical = 5.dp)
    ) {
        Text(text, color = fg, fontSize = 12.sp, fontWeight = FontWeight.SemiBold, maxLines = 1)
    }
}

/** Chip saringan: hitam saat aktif, putih saat tidak. */
@Composable
fun FilterChip(text: String, selected: Boolean, onClick: () -> Unit) {
    Chip(
        text,
        fg = if (selected) Color.White else Ink,
        bg = if (selected) Ink else Color.White,
        modifier = if (selected) Modifier else Modifier.border(1.dp, InkLine, RoundedCornerShape(50)),
        onClick = onClick,
    )
}

@Composable
fun BannerView(banner: Banner?, modifier: Modifier = Modifier) {
    if (banner == null) return
    val (fg, bg) = toneColors(banner.tone)
    Row(
        modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(14.dp))
            .background(bg)
            .padding(horizontal = 14.dp, vertical = 11.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(
            when (banner.tone) {
                Tone.Success -> Ic.Check
                Tone.Error, Tone.Warning -> Ic.Alert
                else -> Ic.Scan
            },
            contentDescription = null,
            tint = fg,
            modifier = Modifier.size(18.dp),
        )
        Spacer(Modifier.width(8.dp))
        Text(banner.text, color = fg, fontWeight = FontWeight.SemiBold, fontSize = 14.sp)
    }
}

/** Bilah progres emas yang membulat. */
@Composable
fun ProgressLine(fraction: Float, modifier: Modifier = Modifier, height: Dp = 8.dp, track: Color = Color(0xFFEFEBE1), color: Color? = null) {
    Box(
        modifier
            .fillMaxWidth()
            .height(height)
            .clip(RoundedCornerShape(50))
            .background(track)
    ) {
        Box(
            Modifier
                .fillMaxHeight()
                .fillMaxWidth(fraction.coerceIn(0f, 1f))
                .clip(RoundedCornerShape(50))
                .background(
                    if (color != null) Brush.horizontalGradient(listOf(color, color))
                    else Brush.horizontalGradient(listOf(Brand, Color(0xFFE8C75A)))
                )
        )
    }
}

/**
 * Foto produk. Bila belum ada foto, kotak berwarna dengan dua huruf SKU.
 */
@Composable
fun ProductThumb(url: String?, sku: String?, size: Dp = 52.dp, radius: Dp = 14.dp) {
    val shape = RoundedCornerShape(radius)
    val placeholder = @Composable {
        Box(
            Modifier
                .size(size)
                .clip(shape)
                .background(Brush.linearGradient(listOf(BrandBg, Color(0xFFF1E3BC)))),
            contentAlignment = Alignment.Center,
        ) {
            Text(
                (sku ?: "?").filter { it.isLetterOrDigit() }.take(2).uppercase(),
                color = Brand2,
                fontWeight = FontWeight.ExtraBold,
                fontSize = (size.value / 3.4f).sp,
            )
        }
    }
    val full = absoluteUrl(url)
    if (full == null) {
        placeholder()
        return
    }
    SubcomposeAsyncImage(
        model = full,
        contentDescription = sku,
        contentScale = ContentScale.Crop,
        modifier = Modifier.size(size).clip(shape),
        loading = { placeholder() },
        error = { placeholder() },
    )
}

/** Alamat gambar dari server bisa relatif ("/storage/..."). */
fun absoluteUrl(url: String?): String? {
    if (url.isNullOrBlank()) return null
    if (url.startsWith("http://") || url.startsWith("https://")) return url
    return Session.serverUrl.trimEnd('/') + "/" + url.trimStart('/')
}

@Composable
fun IconBadge(icon: ImageVector, fg: Color, bg: Color, size: Dp = 40.dp) {
    Box(
        Modifier.size(size).clip(RoundedCornerShape(size / 3)).background(bg),
        contentAlignment = Alignment.Center,
    ) {
        Icon(icon, contentDescription = null, tint = fg, modifier = Modifier.size(size / 2))
    }
}

@Composable
fun Dot(color: Color, size: Dp = 8.dp) {
    Box(Modifier.size(size).clip(CircleShape).background(color))
}

/** Tombol utama besar. */
@Composable
fun BigButton(text: String, modifier: Modifier = Modifier, bg: Color = Ink, fg: Color = Color.White, enabled: Boolean = true, onClick: () -> Unit) {
    Box(
        modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(18.dp))
            .background(if (enabled) bg else bg.copy(alpha = 0.5f))
            .clickable(enabled = enabled, onClick = onClick)
            .padding(vertical = 17.dp),
        contentAlignment = Alignment.Center,
    ) {
        Text(text, color = fg, fontWeight = FontWeight.Bold, fontSize = 16.sp)
    }
}

val GoldOn = OnBrand
