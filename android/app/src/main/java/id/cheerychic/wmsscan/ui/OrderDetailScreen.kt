package id.cheerychic.wmsscan.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import id.cheerychic.wmsscan.data.Api
import id.cheerychic.wmsscan.data.ApiException
import id.cheerychic.wmsscan.data.Order
import id.cheerychic.wmsscan.ui.components.AppCard
import id.cheerychic.wmsscan.ui.components.Banner
import id.cheerychic.wmsscan.ui.components.BannerView
import id.cheerychic.wmsscan.ui.components.Header
import id.cheerychic.wmsscan.ui.components.ProductThumb
import id.cheerychic.wmsscan.ui.components.ProgressLine
import id.cheerychic.wmsscan.ui.components.StatusBarIcons
import id.cheerychic.wmsscan.ui.components.Tone
import id.cheerychic.wmsscan.ui.theme.Bg
import id.cheerychic.wmsscan.ui.theme.Brand
import id.cheerychic.wmsscan.ui.theme.Ink
import id.cheerychic.wmsscan.ui.theme.InkMuted
import id.cheerychic.wmsscan.ui.theme.Success

@Composable
fun OrderDetailScreen(id: Int, onBack: () -> Unit) {
    StatusBarIcons(darkScreen = false)
    var order by remember { mutableStateOf<Order?>(null) }
    var error by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(id) {
        try {
            order = Api.order(id).order
        } catch (e: ApiException) {
            error = e.message
        } catch (e: Exception) {
            error = "Jawaban server tidak dikenali."
        }
    }

    Column(Modifier.fillMaxSize().background(Bg)) {
        Header("Rincian Resi", onBack = onBack)
        Column(
            Modifier
                .fillMaxSize()
                .verticalScroll(rememberScrollState())
                .padding(horizontal = 20.dp, vertical = 8.dp)
                .navigationBarsPadding(),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            BannerView(error?.let { Banner(it, Tone.Error) })
            val o = order
            if (o == null) {
                if (error == null) {
                    Box(Modifier.fillMaxWidth().padding(32.dp), contentAlignment = Alignment.Center) {
                        CircularProgressIndicator(color = Brand)
                    }
                }
                return@Column
            }

            OrderCard(o) {}

            if (o.stageDetail.isNotBlank()) {
                Text(o.stageDetail, color = InkMuted, fontSize = 13.sp)
            }

            AppCard(Modifier.fillMaxWidth(), radius = 20.dp) {
                Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    Field("No. pesanan", o.orderNumber)
                    Field("Toko", o.storeName)
                    Field("Pembeli", o.buyerName)
                    Field("Kurir", o.courier)
                    Field("Jenis", o.kind)
                    Field("Dokumen gudang", o.outboundCode)
                    Field("Catatan pembeli", o.buyerNote)
                }
            }

            Section("Isi pesanan")
            o.items.forEach { item ->
                ItemRow(item.imageUrl, item.sku, item.name) {
                    Text("×${item.quantity}", color = Ink, fontWeight = FontWeight.ExtraBold, fontSize = 17.sp)
                }
            }

            if (o.packedItems.isNotEmpty()) {
                Section("Hasil scan kemas")
                o.packedItems.forEach { item ->
                    val done = item.scanned >= item.quantity
                    ItemRow(item.imageUrl, item.sku, item.name, progress = if (item.quantity == 0) 0f else item.scanned.toFloat() / item.quantity, done = done) {
                        Text(
                            "${item.scanned}/${item.quantity}",
                            color = if (done) Success else Ink,
                            fontWeight = FontWeight.ExtraBold,
                            fontSize = 17.sp,
                        )
                    }
                }
            }
            Spacer(Modifier.height(16.dp))
        }
    }
}

@Composable
private fun Section(title: String) {
    Text(title, color = Ink, fontWeight = FontWeight.Bold, fontSize = 16.sp, modifier = Modifier.padding(top = 4.dp))
}

@Composable
private fun ItemRow(
    image: String?,
    sku: String?,
    name: String?,
    progress: Float? = null,
    done: Boolean = false,
    trailing: @Composable () -> Unit,
) {
    AppCard(Modifier.fillMaxWidth(), radius = 18.dp) {
        Row(Modifier.padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
            ProductThumb(image, sku, size = 46.dp, radius = 13.dp)
            Spacer(Modifier.width(12.dp))
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                Text(sku ?: "?", color = Ink, fontWeight = FontWeight.Bold, fontSize = 14.5.sp, maxLines = 1)
                if (!name.isNullOrBlank()) Text(name, color = InkMuted, fontSize = 12.5.sp, maxLines = 2)
                if (progress != null) ProgressLine(progress, height = 5.dp, color = if (done) Success else null)
            }
            Spacer(Modifier.width(10.dp))
            trailing()
        }
    }
}

@Composable
private fun Field(label: String, value: String?) {
    if (value.isNullOrBlank()) return
    Row {
        Text(label, color = InkMuted, fontSize = 13.sp, modifier = Modifier.weight(0.42f))
        Text(value, color = Ink, fontSize = 13.sp, fontWeight = FontWeight.Medium, textAlign = TextAlign.End, modifier = Modifier.weight(0.58f))
    }
}
