package id.cheerychic.wmsscan.ui.components

import androidx.compose.foundation.layout.Box
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
 * Kode yang sama tidak dikirim dua kali dalam 2,5 detik, supaya label yang
 * masih di depan kamera tidak terhitung berulang.
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
        var lastAt = 0L

        providerFuture.addListener({
            // HP tanpa kamera / kamera dipakai aplikasi lain: jangan sampai
            // aplikasi tertutup, cukup beri tahu dan pakai scanner genggam.
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
                scanner.process(input)
                    .addOnSuccessListener { barcodes ->
                        val value = barcodes.firstOrNull { !it.rawValue.isNullOrBlank() }?.rawValue?.trim()
                        if (!value.isNullOrEmpty()) {
                            val now = SystemClock.elapsedRealtime()
                            if (value != lastCode || now - lastAt > 2500) {
                                lastCode = value
                                lastAt = now
                                currentOnCode(value)
                            }
                        }
                    }
                    .addOnCompleteListener { proxy.close() }
            }

            runCatching {
                provider.unbindAll()
                camera = provider.bindToLifecycle(lifecycleOwner, CameraSelector.DEFAULT_BACK_CAMERA, preview, analysis)
            }.onFailure { unavailable = true }
        }, ContextCompat.getMainExecutor(context))

        onDispose {
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
                modifier = Modifier.align(Alignment.TopStart).padding(14.dp),
            )
        }
    }
}
