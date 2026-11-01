package id.cheerychic.wmsscan.ui.components

import android.Manifest
import android.content.pm.PackageManager
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.Check
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.input.key.Key
import androidx.compose.ui.input.key.KeyEventType
import androidx.compose.ui.input.key.key
import androidx.compose.ui.input.key.onPreviewKeyEvent
import androidx.compose.ui.input.key.type
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalSoftwareKeyboardController
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardCapitalization
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.ContextCompat
import id.cheerychic.wmsscan.ui.theme.Danger
import id.cheerychic.wmsscan.ui.theme.DangerBg
import id.cheerychic.wmsscan.ui.theme.Info
import id.cheerychic.wmsscan.ui.theme.InfoBg
import id.cheerychic.wmsscan.ui.theme.InkLine
import id.cheerychic.wmsscan.ui.theme.Success
import id.cheerychic.wmsscan.ui.theme.SuccessBg
import id.cheerychic.wmsscan.ui.theme.Warning
import id.cheerychic.wmsscan.ui.theme.WarningBg

enum class Tone { Info, Success, Error, Warning }

data class Banner(val text: String, val tone: Tone)

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun AppBar(title: String, onBack: (() -> Unit)? = null, actions: @Composable () -> Unit = {}) {
    TopAppBar(
        title = { Text(title, fontWeight = FontWeight.SemiBold, fontSize = 18.sp) },
        navigationIcon = {
            if (onBack != null) {
                IconButton(onClick = onBack) {
                    Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Kembali")
                }
            }
        },
        actions = { actions() },
        colors = TopAppBarDefaults.topAppBarColors(containerColor = Color.White),
    )
}

/**
 * Kotak input scan.
 *
 * Bisa diisi tiga cara: scanner genggam / PDA yang mengetik lalu menekan
 * Enter, kamera HP (tombol Kamera), atau diketik manual.
 */
@Composable
fun ScanPanel(
    label: String,
    onScan: (String) -> Unit,
    modifier: Modifier = Modifier,
    extra: @Composable () -> Unit = {},
) {
    val context = LocalContext.current
    var text by remember { mutableStateOf("") }
    var cameraOn by rememberSaveable { mutableStateOf(false) }
    val focus = remember { FocusRequester() }
    val keyboard = LocalSoftwareKeyboardController.current

    val permission = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
        cameraOn = granted
    }

    fun submit() {
        val code = text.trim()
        text = ""
        if (code.isNotEmpty()) onScan(code)
        // Setelah scan keyboard disembunyikan lagi supaya daftar barang terlihat.
        keyboard?.hide()
    }

    // Kotak scan langsung aktif untuk scanner genggam, tetapi keyboard layar
    // tidak dimunculkan: operator memakai scanner atau kamera, dan keyboard
    // hanya menutupi daftar barang. Ketuk kotaknya untuk mengetik manual.
    LaunchedEffect(Unit) {
        runCatching { focus.requestFocus() }
        // Keyboard bisa muncul sedikit setelah fokus didapat, jadi disembunyikan
        // beberapa kali selama layar baru dibuka.
        repeat(3) {
            kotlinx.coroutines.delay(200)
            keyboard?.hide()
        }
    }

    Column(modifier, verticalArrangement = Arrangement.spacedBy(8.dp)) {
        if (cameraOn) {
            Box(
                Modifier
                    .fillMaxWidth()
                    .height(200.dp)
                    .clip(RoundedCornerShape(12.dp))
                    .background(Color.Black)
            ) {
                CameraScanner(Modifier.fillMaxWidth().height(200.dp)) { code -> onScan(code) }
                // Garis bidik di tengah.
                Box(
                    Modifier
                        .align(Alignment.Center)
                        .fillMaxWidth(0.8f)
                        .height(2.dp)
                        .background(Danger.copy(alpha = 0.8f))
                )
            }
        }

        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            OutlinedTextField(
                value = text,
                onValueChange = { value ->
                    // Sebagian scanner mengirim Enter sebagai baris baru.
                    if (value.contains('\n') || value.contains('\r')) {
                        text = value.replace("\n", "").replace("\r", "")
                        submit()
                    } else {
                        text = value
                    }
                },
                label = { Text(label) },
                singleLine = true,
                keyboardOptions = KeyboardOptions(
                    capitalization = KeyboardCapitalization.Characters,
                    autoCorrectEnabled = false,
                    keyboardType = KeyboardType.Ascii,
                    imeAction = ImeAction.Done,
                ),
                keyboardActions = KeyboardActions(onDone = { submit() }),
                trailingIcon = {
                    IconButton(onClick = { submit() }) {
                        Icon(Icons.Filled.Check, contentDescription = "Kirim")
                    }
                },
                modifier = Modifier
                    .weight(1f)
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
            OutlinedButton(onClick = {
                if (cameraOn) {
                    cameraOn = false
                } else if (ContextCompat.checkSelfPermission(context, Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED) {
                    cameraOn = true
                } else {
                    permission.launch(Manifest.permission.CAMERA)
                }
            }) {
                Text(if (cameraOn) "Tutup" else "Kamera")
            }
        }
        extra()
    }
}

@Composable
fun BannerView(banner: Banner?, modifier: Modifier = Modifier) {
    if (banner == null) return
    val (fg, bg) = when (banner.tone) {
        Tone.Success -> Success to SuccessBg
        Tone.Error -> Danger to DangerBg
        Tone.Warning -> Warning to WarningBg
        Tone.Info -> Info to InfoBg
    }
    Box(
        modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(10.dp))
            .background(bg)
            .border(1.dp, fg.copy(alpha = 0.35f), RoundedCornerShape(10.dp))
            .padding(horizontal = 14.dp, vertical = 12.dp)
    ) {
        Text(banner.text, color = fg, fontWeight = FontWeight.SemiBold, fontSize = 16.sp)
    }
}

@Composable
fun Pill(text: String, fg: Color, bg: Color) {
    Box(
        Modifier
            .clip(RoundedCornerShape(50))
            .background(bg)
            .padding(horizontal = 8.dp, vertical = 2.dp)
    ) {
        Text(text, color = fg, fontSize = 12.sp, fontWeight = FontWeight.Medium)
    }
}

@Composable
fun Line() {
    Box(Modifier.fillMaxWidth().height(1.dp).background(InkLine))
}

@Composable
fun Gap(height: Int) {
    Box(Modifier.height(height.dp).width(1.dp))
}

@Composable
fun SectionTitle(text: String) {
    Text(text, style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.SemiBold)
}
