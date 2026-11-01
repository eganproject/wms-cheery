package id.cheerychic.wmsscan.data

import android.content.Context
import android.content.SharedPreferences
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.serialization.decodeFromString
import kotlinx.serialization.encodeToString

/**
 * Alamat server, token login, dan data pengguna — disimpan di HP supaya
 * operator tidak perlu login ulang setiap membuka aplikasi.
 */
object Session {
    private lateinit var prefs: SharedPreferences

    private val _user = MutableStateFlow<User?>(null)
    val user: StateFlow<User?> = _user

    fun init(context: Context) {
        prefs = context.getSharedPreferences("wms-scan", Context.MODE_PRIVATE)
        _user.value = prefs.getString("user", null)?.let {
            runCatching { Api.json.decodeFromString<User>(it) }.getOrNull()
        }
        if (token.isNullOrBlank()) _user.value = null
    }

    var serverUrl: String
        get() = prefs.getString("server", "") ?: ""
        set(value) = prefs.edit().putString("server", value).apply()

    var lastEmail: String
        get() = prefs.getString("email", "") ?: ""
        set(value) = prefs.edit().putString("email", value).apply()

    val token: String?
        get() = prefs.getString("token", null)

    fun signIn(token: String, user: User) {
        prefs.edit()
            .putString("token", token)
            .putString("user", Api.json.encodeToString(user))
            .apply()
        _user.value = user
    }

    fun updateUser(user: User) {
        prefs.edit().putString("user", Api.json.encodeToString(user)).apply()
        _user.value = user
    }

    fun signOut() {
        prefs.edit().remove("token").remove("user").apply()
        _user.value = null
    }

    /**
     * Ubah isian alamat menjadi alamat dasar yang rapi:
     * "wms.domain.com/" -> "https://wms.domain.com".
     */
    fun normalizeUrl(raw: String): String {
        var url = raw.trim().trimEnd('/')
        if (url.isEmpty()) return url
        if (!url.startsWith("http://", ignoreCase = true) && !url.startsWith("https://", ignoreCase = true)) {
            url = "https://$url"
        }
        // Bila yang ditempel alamat halaman admin, ambil akarnya saja.
        listOf("/admin", "/login", "/api/mobile").forEach { suffix ->
            val index = url.indexOf(suffix, ignoreCase = true)
            if (index > 0) url = url.substring(0, index)
        }
        return url.trimEnd('/')
    }
}
