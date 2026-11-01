package id.cheerychic.wmsscan.ui.theme

import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Typography
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color

// Palet desain baru: emas CheeryChic + hitam, latar krem lembut.
val Ink = Color(0xFF16171B)
val Ink2 = Color(0xFF3A3D45)
val InkMuted = Color(0xFF8A8F99)
val InkLine = Color(0xFFECEAE4)
val Bg = Color(0xFFF6F4EF)
val Field = Color(0xFFF4F2EC)
val Surface2 = Color(0xFFF1EFEA)
val Night = Color(0xFF0E0F12)
val NightCard = Color(0xFF1A1B20)
val Brand = Color(0xFFD6AE32)
val Brand2 = Color(0xFFB8901C)
val BrandBg = Color(0xFFFBF5E3)
val OnBrand = Color(0xFF1A1405)
val Success = Color(0xFF10A36B)
val SuccessDark = Color(0xFF0B7F54)
val SuccessBg = Color(0xFFE7F7EF)
val Danger = Color(0xFFE5484D)
val DangerBg = Color(0xFFFDECEC)
val Warning = Color(0xFFE38A13)
val WarningBg = Color(0xFFFFF4E2)
val Info = Color(0xFF2F7FD8)
val InfoBg = Color(0xFFEAF2FC)

private val colors = lightColorScheme(
    primary = Ink,
    onPrimary = Color.White,
    secondary = Brand,
    onSecondary = OnBrand,
    background = Bg,
    onBackground = Ink,
    surface = Color.White,
    onSurface = Ink,
    surfaceVariant = Field,
    onSurfaceVariant = InkMuted,
    outline = InkLine,
    error = Danger,
)

@Composable
fun WmsTheme(content: @Composable () -> Unit) {
    MaterialTheme(colorScheme = colors, typography = Typography(), content = content)
}
