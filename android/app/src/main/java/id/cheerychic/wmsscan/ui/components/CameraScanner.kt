package id.cheerychic.wmsscan.ui.components

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.ui.draw.clip
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Text
import androidx.compose.ui.Alignment
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import android.os.SystemClock
import androidx.annotation.OptIn
import androidx.camera.core.Camera
import androidx.camera.core.CameraSelector
import androidx.camera.core.ExperimentalGetImage
import androidx.camera.core.ImageAnalysis
import androidx.camera.core.Preview
import androidx.camera.lifecycle.ProcessCameraProvider
import androidx.camera.view.PreviewView
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.viewinterop.AndroidView
import androidx.core.content.ContextCompat
import androidx.lifecycle.compose.LocalLifecycleOwner
import com.google.mlkit.vision.barcode.BarcodeScanning
import com.google.mlkit.vision.common.InputImage
import java.util.concurrent.Executors

/**
 * Pratinjau kamera yang terus membaca barcode / QR.
 *
 * Kode yang sama baru dikirim lagi setelah ia keluar dari bidikan kamera
 * (tidak terlihat sedikitnya [REARM_MS]). Dulu batasnya 2,5 detik sejak scan
 * terakhir, sehingga satu barang yang dibiarkan di depan kamera terhitung
 * berulang kali — QC Kemas menganggap 3 unit padahal hanya 1 yang dipegang —
 * dan resi yang masih terbidik di Serah Terima dikirim ulang lalu ditolak.
 */
@OptIn(ExperimentalGetImage::class)
@Composable
fun CameraScanner(
    modifier: Modifier = Modifier,
    torch: Boolean = false,
    onCode: (String) -> Unit,
) {
    var camera by remember { mutableStateOf<Camera?>(null) }
    var unavailable by remember { mutableStateOf(false) }
    LaunchedEffect(camera, torch) {
        camera?.let { cam -> if (cam.cameraInfo.hasFlashUnit()) cam.cameraControl.enableTorch(torch) }
    }

    val context = LocalContext.current
    val lifecycleOwner = LocalLifecycleOwner.current
    val currentOnCode by rememberUpdatedState(onCode)
    val previewView = remember {
        PreviewView(context).apply { scaleType = PreviewView.ScaleType.FILL_CENTER }
    }

    DisposableEffect(lifecycleOwner) {
        val providerFuture = ProcessCameraProvider.getInstance(context)
        val executor = Executors.newSingleThreadExecutor()
        val scanner = BarcodeScanning.getClient()
        var lastCode = ""
        var lastSeenAt = 0L
        var disposed = false

        providerFuture.addListener({
            // HP tanpa kamera / kamera dipakai aplikasi lain: jangan sampai
            // aplikasi tertutup, cukup beri tahu dan pakai scanner genggam.
            if (disposed) return@addListener
            val provider = runCatching { providerFuture.get() }.getOrElse {
                unavailable = true
                return@addListener
            }
            val preview = Preview.Builder().build()
            preview.setSurfaceProvider(previewView.surfaceProvider)

            val analysis = ImageAnalysis.Builder()
                .setBackpressureStrategy(ImageAnalysis.STRATEGY_KEEP_ONLY_LATEST)
                .build()

            analysis.setAnalyzer(executor) { proxy ->
                val media = proxy.image
                if (media == null) {
                    proxy.close()
                    return@setAnalyzer
                }
                val input = InputImage.fromMediaImage(media, proxy.imageInfo.rotationDegrees)
                val task = runCatching { scanner.process(input) }.getOrElse {
                    // Pemindai sudah ditutup (layar baru saja ditinggalkan).
                    proxy.close()
                    return@setAnalyzer
                }
                task
                    .addOnSuccessListener { barcodes ->
                        if (disposed) return@addOnSuccessListener
                        val now = SystemClock.elapsedRealtime()
                        val values = barcodes.mapNotNull { it.rawValue?.trim() }.filter { it.isNotEmpty() }
                        // Selama kode terakhir masih terlihat, ia tidak dikirim ulang.
                        if (lastCode.isNotEmpty() && lastCode in values) {
                            lastSeenAt = now
                            return@addOnSuccessListener
                        }
                        val value = values.firstOrNull() ?: return@addOnSuccessListener
                        if (value != lastCode || now - lastSeenAt > REARM_MS) {
                            lastCode = value
                            lastSeenAt = now
                            currentOnCode(value)
                        }
                    }
                    .addOnCompleteListener { proxy.close() }
            }

            if (disposed) return@addListener
            runCatching {
                provider.unbindAll()
                camera = provider.bindToLifecycle(lifecycleOwner, CameraSelector.DEFAULT_BACK_CAMERA, preview, analysis)
            }.onFailure { unavailable = true }
        }, ContextCompat.getMainExecutor(context))

        onDispose {
            disposed = true
            runCatching { providerFuture.get().unbindAll() }
            executor.shutdown()
            scanner.close()
        }
    }

    Box(modifier) {
        AndroidView(factory = { previewView }, modifier = Modifier.fillMaxSize())
        if (unavailable) {
            Text(
                "Kamera tidak tersedia · pakai scanner genggam",
                color = Color(0xFFBBBBBB),
                fontSize = 12.5.sp,
                modifier = Modifier
                    .align(Alignment.Center)
                    .clip(RoundedCornerShape(50))
                    .background(Color(0xE61A1B20))
                    .padding(horizontal = 14.dp, vertical = 8.dp),
            )
        }
    }
}

/** Lama kode harus hilang dari bidikan sebelum boleh terbaca lagi. */
private const val REARM_MS = 1000L
