package id.cheerychic.wmsscan.ui.components

import android.content.Context
import android.util.Size
import androidx.camera.core.CameraSelector
import androidx.camera.core.ImageCapture
import androidx.camera.core.ImageCaptureException
import androidx.camera.core.Preview
import androidx.camera.core.resolutionselector.ResolutionSelector
import androidx.camera.core.resolutionselector.ResolutionStrategy
import androidx.camera.lifecycle.ProcessCameraProvider
import androidx.camera.view.PreviewView
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.remember
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.viewinterop.AndroidView
import androidx.core.content.ContextCompat
import androidx.lifecycle.compose.LocalLifecycleOwner
import kotlinx.coroutines.suspendCancellableCoroutine
import java.io.File
import kotlin.coroutines.resume
import kotlin.coroutines.resumeWithException

/** Pengambil foto dari kamera depan untuk selfie absen. */
class SelfieCapture {
    internal var imageCapture: ImageCapture? = null
    internal var context: Context? = null

    val ready: Boolean get() = imageCapture != null

    /**
     * Ambil satu foto. Disimpan lewat berkas supaya orientasinya (EXIF) ikut
     * tertulis, lalu dibaca sebagai JPEG untuk dikirim.
     */
    suspend fun take(): ByteArray {
        val capture = imageCapture ?: throw IllegalStateException("Kamera belum siap.")
        val ctx = context ?: throw IllegalStateException("Kamera belum siap.")
        val file = File.createTempFile("selfie", ".jpg", ctx.cacheDir)

        return try {
            suspendCancellableCoroutine { cont ->
                val options = ImageCapture.OutputFileOptions.Builder(file)
                    .setMetadata(ImageCapture.Metadata().apply { isReversedHorizontal = true })
                    .build()
                capture.takePicture(options, ContextCompat.getMainExecutor(ctx), object : ImageCapture.OnImageSavedCallback {
                    override fun onImageSaved(output: ImageCapture.OutputFileResults) {
                        if (cont.isActive) cont.resume(Unit)
                    }

                    override fun onError(exception: ImageCaptureException) {
                        if (cont.isActive) cont.resumeWithException(exception)
                    }
                })
            }
            file.readBytes()
        } finally {
            file.delete()
        }
    }
}

/**
 * Pratinjau kamera depan. [onUnavailable] dipanggil bila HP tidak punya
 * kamera depan atau kamera sedang dipakai aplikasi lain.
 */
@Composable
fun SelfieCamera(capture: SelfieCapture, modifier: Modifier = Modifier, onUnavailable: () -> Unit = {}) {
    val context = LocalContext.current
    val lifecycleOwner = LocalLifecycleOwner.current
    val previewView = remember {
        PreviewView(context).apply { scaleType = PreviewView.ScaleType.FILL_CENTER }
    }

    DisposableEffect(lifecycleOwner) {
        val providerFuture = ProcessCameraProvider.getInstance(context)
        var disposed = false

        providerFuture.addListener({
            if (disposed) return@addListener
            val provider = runCatching { providerFuture.get() }.getOrElse {
                onUnavailable()
                return@addListener
            }
            val preview = Preview.Builder().build().also { it.setSurfaceProvider(previewView.surfaceProvider) }
            // Foto kecil sudah cukup untuk mengenali wajah, dan cepat dikirim.
            val image = ImageCapture.Builder()
                .setCaptureMode(ImageCapture.CAPTURE_MODE_MINIMIZE_LATENCY)
                .setJpegQuality(80)
                .setResolutionSelector(
                    ResolutionSelector.Builder()
                        .setResolutionStrategy(
                            ResolutionStrategy(Size(720, 960), ResolutionStrategy.FALLBACK_RULE_CLOSEST_LOWER_THEN_HIGHER)
                        )
                        .build()
                )
                .build()

            runCatching {
                provider.unbindAll()
                provider.bindToLifecycle(lifecycleOwner, CameraSelector.DEFAULT_FRONT_CAMERA, preview, image)
                capture.imageCapture = image
                capture.context = context
            }.onFailure { onUnavailable() }
        }, ContextCompat.getMainExecutor(context))

        onDispose {
            disposed = true
            capture.imageCapture = null
            runCatching { providerFuture.get().unbindAll() }
        }
    }

    Box(modifier) {
        AndroidView(factory = { previewView }, modifier = Modifier.fillMaxSize())
    }
}
