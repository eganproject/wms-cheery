package id.cheerychic.wmsscan.data

import android.annotation.SuppressLint
import android.content.Context
import android.location.Location
import android.location.LocationListener
import android.location.LocationManager
import android.os.Build
import android.os.Bundle
import android.os.Looper
import androidx.core.content.ContextCompat
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlinx.coroutines.withTimeoutOrNull
import kotlin.coroutines.resume

/** Posisi GPS untuk absen. */
data class Fix(val latitude: Double, val longitude: Double, val accuracy: Float?, val isMock: Boolean)

/**
 * Membaca lokasi sekali, tanpa Google Play Services.
 *
 * Coba GPS dulu (paling akurat), lalu jaringan. Posisi terakhir yang masih
 * baru (< 2 menit) dipakai bila pembacaan baru tidak selesai tepat waktu.
 */
object Locator {
    class LocationOff : Exception("GPS mati. Nyalakan Lokasi di HP lalu coba lagi.")
    class NoFix : Exception("Lokasi belum terbaca. Pindah ke tempat terbuka atau dekat jendela, lalu coba lagi.")

    @SuppressLint("MissingPermission")
    suspend fun current(context: Context, timeoutMs: Long = 20_000): Fix {
        val manager = ContextCompat.getSystemService(context, LocationManager::class.java) ?: throw NoFix()
        val providers = listOf(LocationManager.GPS_PROVIDER, LocationManager.NETWORK_PROVIDER)
            .filter { runCatching { manager.isProviderEnabled(it) }.getOrDefault(false) }

        if (providers.isEmpty()) throw LocationOff()

        val fresh = withTimeoutOrNull(timeoutMs) { single(manager, providers.first()) }
            ?: providers.drop(1).firstNotNullOfOrNull { withTimeoutOrNull(8_000) { single(manager, it) } }
            ?: providers.mapNotNull { runCatching { manager.getLastKnownLocation(it) }.getOrNull() }
                .filter { System.currentTimeMillis() - it.time < 120_000 }
                .minByOrNull { if (it.hasAccuracy()) it.accuracy else Float.MAX_VALUE }
            ?: throw NoFix()

        return Fix(
            latitude = fresh.latitude,
            longitude = fresh.longitude,
            accuracy = if (fresh.hasAccuracy()) fresh.accuracy else null,
            isMock = isMock(fresh),
        )
    }

    @Suppress("DEPRECATION")
    private fun isMock(location: Location): Boolean =
        if (Build.VERSION.SDK_INT >= 31) location.isMock else location.isFromMockProvider

    @SuppressLint("MissingPermission")
    @Suppress("DEPRECATION")
    private suspend fun single(manager: LocationManager, provider: String): Location? =
        suspendCancellableCoroutine { cont ->
            if (Build.VERSION.SDK_INT >= 30) {
                val signal = android.os.CancellationSignal()
                cont.invokeOnCancellation { signal.cancel() }
                runCatching {
                    manager.getCurrentLocation(provider, signal, { it.run() }) { location ->
                        if (cont.isActive) cont.resume(location)
                    }
                }.onFailure { if (cont.isActive) cont.resume(null) }
            } else {
                val listener = object : LocationListener {
                    override fun onLocationChanged(location: Location) {
                        if (cont.isActive) cont.resume(location)
                    }

                    override fun onStatusChanged(provider: String?, status: Int, extras: Bundle?) {}
                    override fun onProviderEnabled(provider: String) {}
                    override fun onProviderDisabled(provider: String) {
                        if (cont.isActive) cont.resume(null)
                    }
                }
                cont.invokeOnCancellation { manager.removeUpdates(listener) }
                runCatching { manager.requestSingleUpdate(provider, listener, Looper.getMainLooper()) }
                    .onFailure { if (cont.isActive) cont.resume(null) }
            }
        }
}
