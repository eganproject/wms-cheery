package id.cheerychic.wmsscan.ui

import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import id.cheerychic.wmsscan.R
import id.cheerychic.wmsscan.data.Api
import id.cheerychic.wmsscan.data.ApiException
import id.cheerychic.wmsscan.data.Session
import id.cheerychic.wmsscan.ui.components.AppCard
import id.cheerychic.wmsscan.ui.components.Banner
import id.cheerychic.wmsscan.ui.components.BannerView
import id.cheerychic.wmsscan.ui.components.StatusBarIcons
import id.cheerychic.wmsscan.ui.components.Tone
import id.cheerychic.wmsscan.ui.theme.Bg
import id.cheerychic.wmsscan.ui.theme.Brand
import id.cheerychic.wmsscan.ui.theme.Field
import id.cheerychic.wmsscan.ui.theme.Ink
import id.cheerychic.wmsscan.ui.theme.InkMuted
import id.cheerychic.wmsscan.ui.theme.Night
import kotlinx.coroutines.launch

@Composable
fun LoginScreen() {
    StatusBarIcons(darkScreen = true)
    val scope = rememberCoroutineScope()
    var server by remember { mutableStateOf(Session.serverUrl) }
    var email by remember { mutableStateOf(Session.lastEmail) }
    var password by remember { mutableStateOf("") }
    var showPassword by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    fun login() {
        if (busy) return
        val base = Session.normalizeUrl(server)
        if (base.isEmpty() || email.isBlank() || password.isBlank()) {
            error = "Isi alamat server, email, dan sandi."
            return
        }
        busy = true
        error = null
        scope.launch {
            try {
                val result = Api.login(base, email.trim(), password)
                Session.serverUrl = base
                Session.lastEmail = email.trim()
                Session.signIn(result.token, result.user)
            } catch (e: ApiException) {
                error = e.message
            } catch (e: Exception) {
                error = "Jawaban server tidak dikenali. Pastikan alamat server benar."
            } finally {
                busy = false
            }
        }
    }

    Box(Modifier.fillMaxSize().background(Bg)) {
        // Bagian atas gelap dengan sentuhan emas.
        Box(
            Modifier
                .fillMaxWidth()
                .height(380.dp)
                .background(Brush.verticalGradient(listOf(Color(0xFF2A2414), Night)))
        )
        Column(
            Modifier
                .fillMaxSize()
                .verticalScroll(rememberScrollState())
                .statusBarsPadding()
                .navigationBarsPadding()
                .imePadding()
                .padding(horizontal = 20.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Spacer(Modifier.height(44.dp))
            Box(
                Modifier
                    .size(88.dp)
                    .shadow(18.dp, RoundedCornerShape(26.dp), spotColor = Brand)
                    .clip(RoundedCornerShape(26.dp))
                    .background(Color.White),
                contentAlignment = Alignment.Center,
            ) {
                Image(painterResource(R.mipmap.ic_launcher), contentDescription = null, modifier = Modifier.size(80.dp))
            }
            Spacer(Modifier.height(20.dp))
            Text("WMS Cheery Scan", color = Color.White, fontSize = 26.sp, fontWeight = FontWeight.ExtraBold)
            Spacer(Modifier.height(4.dp))
            Text("Kemas cepat, kirim tepat.", color = Color(0xFFBDB6A3), fontSize = 14.sp)
            Spacer(Modifier.height(36.dp))

            AppCard(Modifier.fillMaxWidth(), radius = 26.dp) {
                Column(Modifier.padding(20.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
                    Text("Masuk ke gudang", fontSize = 19.sp, fontWeight = FontWeight.Bold, color = Ink)
                    FilledField("SERVER", server, { server = it }, "contoh: wms.cheerychic.id", KeyboardType.Uri)
                    FilledField("EMAIL", email, { email = it }, "nama@cheerychic.id", KeyboardType.Email)
                    FilledField(
                        "SANDI", password, { password = it }, "Sandi akun WMS", KeyboardType.Password,
                        secret = !showPassword,
                        trailing = {
                            Text(
                                if (showPassword) "Sembunyi" else "Lihat",
                                color = InkMuted,
                                fontSize = 12.sp,
                                modifier = Modifier.clickable { showPassword = !showPassword },
                            )
                        },
                        onDone = { login() },
                    )
                    BannerView(error?.let { Banner(it, Tone.Error) })
                    Box(
                        Modifier
                            .fillMaxWidth()
                            .clip(RoundedCornerShape(18.dp))
                            .background(Ink)
                            .clickable(enabled = !busy) { login() }
                            .padding(vertical = 17.dp),
                        contentAlignment = Alignment.Center,
                    ) {
                        if (busy) {
                            CircularProgressIndicator(Modifier.size(20.dp), color = Brand, strokeWidth = 2.dp)
                        } else {
                            Text("Masuk", color = Color.White, fontWeight = FontWeight.Bold, fontSize = 16.sp)
                        }
                    }
                }
            }
            Spacer(Modifier.height(18.dp))
            Text(
                "Pakai akun yang sama dengan web WMS.\nAlamat server = alamat yang dibuka di browser.",
                color = InkMuted,
                fontSize = 12.5.sp,
                textAlign = TextAlign.Center,
            )
            Spacer(Modifier.height(24.dp))
        }
    }
}

@Composable
private fun FilledField(
    label: String,
    value: String,
    onChange: (String) -> Unit,
    placeholder: String,
    type: KeyboardType,
    secret: Boolean = false,
    trailing: (@Composable () -> Unit)? = null,
    onDone: (() -> Unit)? = null,
) {
    Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
        Text(label, color = InkMuted, fontSize = 11.sp, fontWeight = FontWeight.Bold, letterSpacing = 0.6.sp)
        Row(
            Modifier
                .fillMaxWidth()
                .clip(RoundedCornerShape(14.dp))
                .background(Field)
                .padding(horizontal = 14.dp, vertical = 14.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Box(Modifier.weight(1f)) {
                if (value.isEmpty()) Text(placeholder, color = InkMuted, fontSize = 15.sp)
                BasicTextField(
                    value = value,
                    onValueChange = onChange,
                    singleLine = true,
                    textStyle = TextStyle(color = Ink, fontSize = 15.sp, fontWeight = FontWeight.Medium),
                    cursorBrush = SolidColor(Brand),
                    visualTransformation = if (secret) PasswordVisualTransformation() else VisualTransformation.None,
                    keyboardOptions = KeyboardOptions(
                        keyboardType = type,
                        imeAction = if (onDone != null) ImeAction.Done else ImeAction.Next,
                    ),
                    keyboardActions = KeyboardActions(onDone = { onDone?.invoke() }),
                    modifier = Modifier.fillMaxWidth(),
                )
            }
            if (trailing != null) {
                Spacer(Modifier.width(8.dp))
                trailing()
            }
        }
    }
}
