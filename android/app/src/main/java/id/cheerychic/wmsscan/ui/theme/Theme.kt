package id.cheerychic.wmsscan.ui.theme

import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color

// Warna mengikuti tampilan web WMS Cheery: abu-abu "ink" dan emas CheeryChic.
val Ink = Color(0xFF1F2328)
val InkMuted = Color(0xFF6B7280)
val InkLine = Color(0xFFE5E7EB)
val Surface2 = Color(0xFFF6F7F8)
val Brand = Color(0xFFD6AE32)
val Success = Color(0xFF059669)
val SuccessBg = Color(0xFFECFDF5)
val Danger = Color(0xFFE11D48)
val DangerBg = Color(0xFFFFF1F2)
val Warning = Color(0xFFD97706)
val WarningBg = Color(0xFFFFFBEB)
val Info = Color(0xFF0284C7)
val InfoBg = Color(0xFFF0F9FF)

private val colors = lightColorScheme(
    primary = Ink,
    onPrimary = Color.White,
    secondary = Brand,
    onSecondary = Ink,
    background = Color.White,
    onBackground = Ink,
    surface = Color.White,
    onSurface = Ink,
    surfaceVariant = Surface2,
    onSurfaceVariant = InkMuted,
    outline = InkLine,
    error = Danger,
)

@Composable
fun WmsTheme(content: @Composable () -> Unit) {
    MaterialTheme(colorScheme = colors, content = content)
}
