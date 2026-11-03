package id.cheerychic.wmsscan.ui.components

import android.content.Context
import android.media.AudioManager
import android.media.ToneGenerator
import android.os.Build
import android.os.VibrationEffect
import android.os.Vibrator
import android.os.VibratorManager

/**
 * Bunyi dan getar setelah scan, supaya operator tidak perlu melihat layar
 * untuk tahu scannya berhasil atau ditolak.
 */
object Feedback {
    private val tone: ToneGenerator? by lazy {
        runCatching { ToneGenerator(AudioManager.STREAM_MUSIC, 100) }.getOrNull()
    }

    fun ok() {
        tone?.startTone(ToneGenerator.TONE_PROP_BEEP, 120)
    }

    fun done(context: Context) {
        tone?.startTone(ToneGenerator.TONE_PROP_ACK, 300)
        vibrate(context, 80)
    }

    fun error(context: Context) {
        tone?.startTone(ToneGenerator.TONE_SUP_ERROR, 500)
        vibrate(context, 400)
    }

    private fun vibrate(context: Context, millis: Long) {
        val vibrator: Vibrator? = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            (context.getSystemService(Context.VIBRATOR_MANAGER_SERVICE) as? VibratorManager)?.defaultVibrator
        } else {
            @Suppress("DEPRECATION")
            context.getSystemService(Context.VIBRATOR_SERVICE) as? Vibrator
        }
        runCatching {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                vibrator?.vibrate(VibrationEffect.createOneShot(millis, VibrationEffect.DEFAULT_AMPLITUDE))
            } else {
                @Suppress("DEPRECATION")
                vibrator?.vibrate(millis)
            }
        }
    }
}
