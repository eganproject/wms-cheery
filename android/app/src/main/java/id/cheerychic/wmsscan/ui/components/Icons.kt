package id.cheerychic.wmsscan.ui.components

import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.StrokeJoin
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.graphics.vector.PathParser
import androidx.compose.ui.unit.dp

/**
 * Ikon garis (gaya Lucide) yang dipakai aplikasi. Digambar dari path SVG
 * supaya tidak perlu pustaka ikon besar.
 */
object Ic {
    private fun line(name: String, vararg paths: String, width: Float = 2f): ImageVector =
        ImageVector.Builder(name, 24.dp, 24.dp, 24f, 24f).apply {
            paths.forEach { d ->
                addPath(
                    pathData = PathParser().parsePathString(d).toNodes(),
                    fill = null,
                    stroke = SolidColor(Color.Black),
                    strokeLineWidth = width,
                    strokeLineCap = StrokeCap.Round,
                    strokeLineJoin = StrokeJoin.Round,
                )
            }
        }.build()

    val Home = line("home", "M3 10L12 3L21 10V20A1 1 0 0 1 20 21H15V15H9V21H4A1 1 0 0 1 3 20Z")
    val Box = line("box", "M21 8L12 3L3 8V16L12 21L21 16Z", "M3 8L12 13L21 8", "M12 13V21")
    val Truck = line(
        "truck", "M1 3H16V16H1Z", "M16 8H20L23 11V16H16",
        "M8 18.5A2.5 2.5 0 1 1 3 18.5A2.5 2.5 0 1 1 8 18.5Z",
        "M21 18.5A2.5 2.5 0 1 1 16 18.5A2.5 2.5 0 1 1 21 18.5Z",
    )
    val List = line("list", "M8 6H21", "M8 12H21", "M8 18H21", "M3 6H3.01", "M3 12H3.01", "M3 18H3.01")
    val Scan = line(
        "scan", "M3 7V5A2 2 0 0 1 5 3H7", "M17 3H19A2 2 0 0 1 21 5V7",
        "M21 17V19A2 2 0 0 1 19 21H17", "M7 21H5A2 2 0 0 1 3 19V17", "M7 12H17",
        width = 2.4f,
    )
    val Zap = line("zap", "M13 2L3 14H12L11 22L21 10H12Z")
    val Back = line("back", "M15 18L9 12L15 6")
    val Next = line("next", "M9 18L15 12L9 6")
    val Search = line("search", "M19 11A8 8 0 1 1 3 11A8 8 0 1 1 19 11Z", "M21 21L16.65 16.65")
    val Logout = line("logout", "M9 21H5A2 2 0 0 1 3 19V5A2 2 0 0 1 5 3H9", "M16 17L21 12L16 7", "M21 12H9")
    val Check = line("check", "M20 6L9 17L4 12", width = 3f)
    val Refresh = line(
        "refresh", "M3 12A9 9 0 0 1 12 3A9.75 9.75 0 0 1 18.74 5.74L21 8", "M21 3V8H16",
        "M21 12A9 9 0 0 1 12 21A9.75 9.75 0 0 1 5.26 18.26L3 16", "M8 16H3V21",
    )
    val Reset = line("reset", "M3 12A9 9 0 1 0 12 3A9.75 9.75 0 0 0 5.26 5.74L3 8", "M3 3V8H8")
    val Camera = line(
        "camera", "M14.5 4H9.5L7 7H4A2 2 0 0 0 2 9V18A2 2 0 0 0 4 20H20A2 2 0 0 0 22 18V9A2 2 0 0 0 20 7H17Z",
        "M15 13A3 3 0 1 1 9 13A3 3 0 1 1 15 13Z",
    )
    val CameraOff = line(
        "camera-off", "M2 2L22 22", "M7 7H4A2 2 0 0 0 2 9V18A2 2 0 0 0 4 20H20",
        "M9.5 4H14.5L17 7H20A2 2 0 0 1 22 9V16.5", "M14.12 15.12A3 3 0 1 1 9.88 10.88",
    )
    val Keyboard = line(
        "keyboard", "M4 6H20A2 2 0 0 1 22 8V16A2 2 0 0 1 20 18H4A2 2 0 0 1 2 16V8A2 2 0 0 1 4 6Z",
        "M6 10H6.01", "M10 10H10.01", "M14 10H14.01", "M18 10H18.01", "M7 14H17",
    )
    val Alert = line("alert", "M12 8V12", "M12 16H12.01", width = 2.6f)
    val Close = line("close", "M18 6L6 18", "M6 6L18 18")
    val Clock = line("clock", "M22 12A10 10 0 1 1 2 12A10 10 0 1 1 22 12Z", "M12 6V12L16 14")
}
