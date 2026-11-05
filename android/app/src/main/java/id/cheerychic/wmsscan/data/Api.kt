package id.cheerychic.wmsscan.data

import android.os.Build
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.serialization.decodeFromString
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import kotlinx.serialization.json.put
import okhttp3.HttpUrl.Companion.toHttpUrlOrNull
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.MultipartBody
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody
import okhttp3.RequestBody.Companion.toRequestBody
import java.io.IOException
import java.util.concurrent.TimeUnit

/** Galat yang pesannya siap ditampilkan ke operator. */
class ApiException(message: String, val status: Int = 0) : Exception(message)

/**
 * Pemanggil API WMS (/api/mobile/...).
 *
 * Pesan galat dari server (mis. "Resi tidak ditemukan di data import")
 * diteruskan apa adanya, karena kalimatnya sudah ditulis untuk operator.
 */
object Api {
    val json = Json {
        ignoreUnknownKeys = true
        coerceInputValues = true
        explicitNulls = false
    }

    private val client = OkHttpClient.Builder()
        .connectTimeout(15, TimeUnit.SECONDS)
        .readTimeout(30, TimeUnit.SECONDS)
        .build()

    private val jsonType = "application/json; charset=utf-8".toMediaType()

    /* ------------------------------------------------------- auth ---- */

    suspend fun login(server: String, email: String, password: String): LoginResponse {
        val body = buildJsonObject {
            put("email", email)
            put("password", password)
            put("device_name", "${Build.MANUFACTURER} ${Build.MODEL}".trim())
        }
        return json.decodeFromString(call("POST", "/login", body, base = server, authed = false))
    }

    suspend fun me(): MeResponse = json.decodeFromString(call("GET", "/me"))

    suspend fun logout() {
        runCatching { call("POST", "/logout") }
    }

    /* ---------------------------------------------- pembaruan app ---- */

    /** Versi terbaru aplikasi (tanpa login, supaya HP yang sesinya habis tetap bisa update). */
    suspend fun appVersion(server: String = Session.serverUrl): AppVersionResponse =
        json.decodeFromString(call("GET", "/app-version", base = server, authed = false))

    /* ------------------------------------------------------ kemas ---- */

    suspend fun scanResi(code: String): ResiResponse =
        json.decodeFromString(call("POST", "/kemas/resi", buildJsonObject { put("code", code) }))

    suspend fun scanItem(outboundId: Int, code: String, quantity: Int): ItemResponse =
        json.decodeFromString(
            call("POST", "/kemas/$outboundId/item", buildJsonObject {
                put("code", code)
                if (quantity > 1) put("quantity", quantity)
            })
        )

    suspend fun resetPacking(outboundId: Int): MessageResponse =
        json.decodeFromString(call("POST", "/kemas/$outboundId/reset"))

    /* ----------------------------------------------- serah terima ---- */

    suspend fun handover(code: String): HandoverResponse =
        json.decodeFromString(call("POST", "/serah-terima/scan", buildJsonObject { put("code", code) }))

    /* ---------------------------------------------------- pesanan ---- */

    suspend fun summary(date: String? = null): Summary =
        json.decodeFromString(call("GET", "/summary", query = mapOf("date" to date)))

    suspend fun orders(
        stage: String,
        page: Int,
        search: String,
        date: String?,
        ready: String? = null,
        priority: Boolean = false,
        courier: String? = null,
    ): OrdersResponse =
        json.decodeFromString(
            call(
                "GET", "/orders",
                query = mapOf(
                    "stage" to stage, "page" to page.toString(), "search" to search, "date" to date,
                    "ready" to ready, "priority" to if (priority) "1" else null, "courier" to courier,
                ),
            )
        )

    suspend fun order(id: Int): OrderDetailResponse = json.decodeFromString(call("GET", "/orders/$id"))

    /* ------------------------------------------------------ absen ---- */

    suspend fun absenToday(): AbsenToday = json.decodeFromString(call("GET", "/absen"))

    suspend fun absenHistory(month: String?): AbsenHistory =
        json.decodeFromString(call("GET", "/absen/riwayat", query = mapOf("month" to month)))

    /** Absen masuk/pulang: lokasi GPS + selfie (JPEG) dalam satu kiriman multipart. */
    suspend fun absen(
        type: String,
        latitude: Double,
        longitude: Double,
        accuracy: Float?,
        isMock: Boolean,
        selfie: ByteArray?,
    ): AbsenResult {
        val form = MultipartBody.Builder().setType(MultipartBody.FORM)
            .addFormDataPart("type", type)
            .addFormDataPart("latitude", latitude.toString())
            .addFormDataPart("longitude", longitude.toString())
            .addFormDataPart("is_mock", if (isMock) "1" else "0")
            .apply {
                accuracy?.let { addFormDataPart("accuracy", it.toString()) }
                selfie?.let { addFormDataPart("selfie", "selfie.jpg", it.toRequestBody("image/jpeg".toMediaType())) }
            }
            .build()
        return json.decodeFromString(call("POST", "/absen", rawBody = form))
    }

    /* ----------------------------------------------------- inti ------ */

    private suspend fun call(
        verb: String,
        path: String,
        body: JsonObject? = null,
        query: Map<String, String?> = emptyMap(),
        rawBody: RequestBody? = null,
        base: String = Session.serverUrl,
        authed: Boolean = true,
    ): String = withContext(Dispatchers.IO) {
        val url = ("$base/api/mobile$path").toHttpUrlOrNull()?.newBuilder()?.apply {
            query.forEach { (key, value) -> if (!value.isNullOrBlank()) addQueryParameter(key, value) }
        }?.build() ?: throw ApiException("Alamat server tidak valid: $base")

        val request = Request.Builder()
            .url(url)
            .header("Accept", "application/json")
            .apply {
                if (authed) Session.token?.let { header("Authorization", "Bearer $it") }
                if (verb == "GET") {
                    get()
                } else {
                    method(verb, rawBody ?: (body ?: JsonObject(emptyMap())).toString().toRequestBody(jsonType))
                }
            }
            .build()

        val response = try {
            client.newCall(request).execute()
        } catch (e: IOException) {
            throw ApiException("Tidak bisa terhubung ke server. Periksa internet / Wi-Fi dan alamat server.")
        }

        response.use {
            val text = it.body?.string().orEmpty()
            if (it.isSuccessful) return@withContext text

            if (it.code == 401 && authed) {
                Session.signOut()
                throw ApiException("Sesi login habis. Silakan login lagi.", 401)
            }

            throw ApiException(errorMessage(it.code, text), it.code)
        }
    }

    private fun errorMessage(status: Int, text: String): String {
        val parsed = runCatching { json.parseToJsonElement(text).jsonObject }.getOrNull()

        // Galat validasi Laravel: {"errors": {"code": ["pesan"]}}
        val firstError = (parsed?.get("errors") as? JsonObject)?.values?.firstOrNull()?.let { value ->
            when (value) {
                is JsonArray -> value.firstOrNull()?.jsonPrimitive?.contentOrNull
                is JsonPrimitive -> value.contentOrNull
                else -> null
            }
        }
        if (!firstError.isNullOrBlank()) return firstError

        val message = (parsed?.get("message") as? JsonPrimitive)?.contentOrNull

        return when (status) {
            // Pesan bawaan Laravel berbahasa Inggris; pesan buatan WMS diteruskan.
            403 -> message?.takeIf { it.isNotBlank() && !it.startsWith("This action") } ?: "Akun Anda tidak punya akses untuk ini."
            404 -> "Data tidak ditemukan. Pastikan alamat server benar."
            // POST yang dialihkan (mis. http -> https) berubah menjadi GET.
            405 -> "Alamat server dialihkan. Coba tulis alamatnya diawali https://"
            429 -> "Terlalu banyak permintaan. Tunggu sebentar lalu coba lagi."
            in 500..599 -> "Server sedang bermasalah ($status). Coba lagi sebentar."
            else -> message?.takeIf { it.isNotBlank() } ?: "Gagal ($status)."
        }
    }
}
