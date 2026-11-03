package id.cheerychic.wmsscan.ui.components

import android.Manifest
import android.content.pm.PackageManager
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.RowScope
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.drawBehind
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.geometry.CornerRadius
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.input.key.Key
import androidx.compose.ui.input.key.KeyEventType
import androidx.compose.ui.input.key.key
import androidx.compose.ui.input.key.onPreviewKeyEvent
import androidx.compose.ui.input.key.type
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalSoftwareKeyboardController
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardCapitalization
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.ContextCompat
import id.cheerychic.wmsscan.data.Session
import id.cheerychic.wmsscan.ui.theme.Brand
import id.cheerychic.wmsscan.ui.theme.Field
import id.cheerychic.wmsscan.ui.theme.Ink
import id.cheerychic.wmsscan.ui.theme.InkMuted
import id.cheerychic.wmsscan.ui.theme.NightCard
import kotlinx.coroutines.delay

/**
 * Area scan: kamera yang selalu menyala dengan bingkai bidik emas, lalu kotak
 * ketik di bawahnya untuk scanner genggam / input manual.
 *
 * Tiga cara mengisi kode berakhir di [onScan] yang sama: kamera, scanner
 * genggam (mode keyboard, diakhiri Enter), atau diketik lalu tekan Enter.
 */
@Composable
fun ScanArea(
    hint: String,
    onScan: (String) -> Unit,
    modifier: Modifier = Modifier,
    dark: Boolean = true,
    cameraHeight: Dp = 210.dp,
    torch: Boolean = false,
    trailing: @Composable RowScope.() -> Unit = {},
) {
    val context = LocalContext.current
    var cameraOn by remember { mutableStateOf(Session.cameraEnabled) }
    var granted by remember {
        mutableStateOf(ContextCompat.checkSelfPermission(context, Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED)
    }
    val permission = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { granted = it }

    LaunchedEffect(cameraOn) {
        if (cameraOn && !granted) permission.launch(Manifest.permission.CAMERA)
    }

    fun setCamera(on: Boolean) {
        cameraOn = on
        Session.cameraEnabled = on
    }

    Column(modifier, verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Box(
            Modifier
                .fillMaxWidth()
                .height(if (cameraOn) cameraHeight else 64.dp)
                .clip(RoundedCornerShape(26.dp))
                .background(NightCard)
        ) {
            if (cameraOn && granted) {
                CameraScanner(Modifier.fillMaxSize(), torch = torch) { code -> onScan(code) }
                Viewfinder(Modifier.align(Alignment.Center))
                Text(
                    "Arahkan ke barcode",
                    color = Color(0xFFDDDDDD),
                    fontSize = 12.5.sp,
                    modifier = Modifier.align(Alignment.BottomCenter).padding(bottom = 10.dp),
                )
            } else if (cameraOn) {
                Text(
                    "Izinkan akses kamera untuk scan pakai kamera",
                    color = Color(0xFFBBBBBB),
                    fontSize = 13.sp,
                    modifier = Modifier.align(Alignment.Center).clickable { permission.launch(Manifest.permission.CAMERA) },
                )
            } else {
                Row(
                    Modifier.align(Alignment.CenterStart).padding(start = 18.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Icon(Ic.CameraOff, null, tint = Color(0xFF9A9CA3), modifier = Modifier.size(18.dp))
                    Spacer(Modifier.width(10.dp))
                    Text("Kamera mati · pakai scanner genggam", color = Color(0xFFBBBBBB), fontSize = 13.sp)
                }
            }
            Box(
                Modifier
                    .align(if (cameraOn) Alignment.TopEnd else Alignment.CenterEnd)
                    .padding(10.dp)
                    .size(36.dp)
                    .clip(RoundedCornerShape(12.dp))
                    .background(Color(0x33FFFFFF))
                    .clickable { setCamera(!cameraOn) },
                contentAlignment = Alignment.Center,
            ) {
                Icon(if (cameraOn) Ic.CameraOff else Ic.Camera, contentDescription = "Kamera", tint = Color.White, modifier = Modifier.size(18.dp))
            }
        }

        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            ManualInput(hint, dark, onScan, Modifier.weight(1f))
            trailing()
        }
    }
}

/** Kotak ketik yang menerima scanner genggam tanpa memunculkan keyboard layar. */
@Composable
private fun ManualInput(hint: String, dark: Boolean, onScan: (String) -> Unit, modifier: Modifier) {
    var text by remember { mutableStateOf("") }
    val focus = remember { FocusRequester() }
    val keyboard = LocalSoftwareKeyboardController.current

    fun submit() {
        val code = text.trim()
        text = ""
        if (code.isNotEmpty()) onScan(code)
        keyboard?.hide()
    }

    // Langsung siap menerima scanner genggam, tetapi keyboard layar tidak
    // dimunculkan; ketuk kotaknya untuk mengetik manual.
    LaunchedEffect(Unit) {
        runCatching { focus.requestFocus() }
        repeat(3) {
            delay(200)
            keyboard?.hide()
        }
    }

    val fg = if (dark) Color.White else Ink
    Row(
        modifier
            .clip(RoundedCornerShape(16.dp))
            .background(if (dark) Color(0x14FFFFFF) else Field)
            .padding(horizontal = 14.dp, vertical = 13.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(Ic.Keyboard, null, tint = InkMuted, modifier = Modifier.size(18.dp))
        Spacer(Modifier.width(10.dp))
        Box(Modifier.weight(1f)) {
            if (text.isEmpty()) Text(hint, color = InkMuted, fontSize = 14.sp, maxLines = 1)
            BasicTextField(
                value = text,
                onValueChange = { value ->
                    if (value.contains('\n') || value.contains('\r')) {
                        text = value.replace("\n", "").replace("\r", "")
                        submit()
                    } else {
                        text = value
                    }
                },
                singleLine = true,
                textStyle = TextStyle(color = fg, fontSize = 15.sp, fontWeight = FontWeight.SemiBold),
                cursorBrush = SolidColor(Brand),
                keyboardOptions = KeyboardOptions(
                    capitalization = KeyboardCapitalization.Characters,
                    autoCorrectEnabled = false,
                    keyboardType = KeyboardType.Ascii,
                    imeAction = ImeAction.Done,
                ),
                keyboardActions = KeyboardActions(onDone = { submit() }),
                modifier = Modifier
                    .fillMaxWidth()
                    .focusRequester(focus)
                    .onPreviewKeyEvent { event ->
                        if (event.key == Key.Enter || event.key == Key.NumPadEnter) {
                            if (event.type == KeyEventType.KeyUp) submit()
                            true
                        } else {
                            false
                        }
                    },
            )
        }
        if (text.isNotEmpty()) {
            Box(
                Modifier.size(28.dp).clip(RoundedCornerShape(9.dp)).background(Brand).clickable { submit() },
                contentAlignment = Alignment.Center,
            ) {
                Icon(Ic.Check, "Kirim", tint = GoldOn, modifier = Modifier.size(16.dp))
            }
        }
    }
}

/** Bingkai bidik: empat sudut emas dan garis scan yang bercahaya. */
@Composable
private fun Viewfinder(modifier: Modifier) {
    Box(
        modifier
            .size(width = 230.dp, height = 110.dp)
            .drawBehind {
                val stroke = 4.dp.toPx()
                val len = 28.dp.toPx()
                val r = 14.dp.toPx()
                val gold = Brand
                val w = size.width
                val h = size.height
                // Sudut-sudut: dua garis per sudut.
                listOf(
                    Offset(0f, 0f) to Pair(1f, 1f),
                    Offset(w, 0f) to Pair(-1f, 1f),
                    Offset(0f, h) to Pair(1f, -1f),
                    Offset(w, h) to Pair(-1f, -1f),
                ).forEach { (corner, dir) ->
                    drawLine(gold, corner, Offset(corner.x + dir.first * len, corner.y), stroke, StrokeCap.Round)
                    drawLine(gold, corner, Offset(corner.x, corner.y + dir.second * len), stroke, StrokeCap.Round)
                }
                drawRoundRect(
                    color = Color(0x22FFFFFF),
                    size = Size(w, h),
                    cornerRadius = CornerRadius(r, r),
                    style = Stroke(1.dp.toPx()),
                )
                val y = h * 0.54f
                drawLine(
                    brush = Brush.horizontalGradient(listOf(Color.Transparent, gold, Color.Transparent)),
                    start = Offset(14.dp.toPx(), y),
                    end = Offset(w - 14.dp.toPx(), y),
                    strokeWidth = 2.5.dp.toPx(),
                )
            }
    )
}
