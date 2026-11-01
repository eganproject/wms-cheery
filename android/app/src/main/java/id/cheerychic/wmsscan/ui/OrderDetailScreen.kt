package id.cheerychic.wmsscan.ui

import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import id.cheerychic.wmsscan.data.Api
import id.cheerychic.wmsscan.data.ApiException
import id.cheerychic.wmsscan.data.Order
import id.cheerychic.wmsscan.ui.components.AppBar
import id.cheerychic.wmsscan.ui.components.Banner
import id.cheerychic.wmsscan.ui.components.BannerView
import id.cheerychic.wmsscan.ui.components.SectionTitle
import id.cheerychic.wmsscan.ui.components.Tone
import id.cheerychic.wmsscan.ui.theme.Brand
import id.cheerychic.wmsscan.ui.theme.InkLine
import id.cheerychic.wmsscan.ui.theme.InkMuted
import id.cheerychic.wmsscan.ui.theme.Success

@Composable
fun OrderDetailScreen(id: Int, onBack: () -> Unit) {
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

    Scaffold(topBar = { AppBar("Rincian Resi", onBack = onBack) }, containerColor = Color.White) { padding ->
        Column(
            Modifier
                .fillMaxSize()
                .padding(padding)
                .verticalScroll(rememberScrollState())
                .padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            BannerView(error?.let { Banner(it, Tone.Error) })
            val o = order
            if (o == null) {
                if (error == null) CircularProgressIndicator(color = Brand)
                return@Column
            }

            OrderCard(o) {}

            Field("No. pesanan", o.orderNumber)
            Field("Toko", o.storeName)
            Field("Pembeli", o.buyerName)
            Field("Kurir", o.courier)
            Field("Dokumen gudang", o.outboundCode)
            Field("Catatan pembeli", o.buyerNote)

            SectionTitle("Isi pesanan")
            o.items.forEach { item ->
                Row(
                    Modifier
                        .fillMaxWidth()
                        .border(1.dp, InkLine, RoundedCornerShape(8.dp))
                        .padding(10.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Column(Modifier.weight(1f)) {
                        Text(item.sku ?: "?", fontWeight = FontWeight.SemiBold)
                        item.name?.let { Text(it, fontSize = 12.sp, color = InkMuted) }
                    }
                    Text("×${item.quantity}", fontWeight = FontWeight.Bold)
                }
            }

            if (o.packedItems.isNotEmpty()) {
                SectionTitle("Hasil scan kemas")
                o.packedItems.forEach { item ->
                    val done = item.scanned >= item.quantity
                    Row(
                        Modifier
                            .fillMaxWidth()
                            .border(1.dp, if (done) Success.copy(alpha = 0.4f) else InkLine, RoundedCornerShape(8.dp))
                            .padding(10.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Column(Modifier.weight(1f)) {
                            Text(item.sku ?: "?", fontWeight = FontWeight.SemiBold)
                            item.name?.let { Text(it, fontSize = 12.sp, color = InkMuted) }
                        }
                        Text("${item.scanned}/${item.quantity}", fontWeight = FontWeight.Bold, color = if (done) Success else Color.Unspecified)
                    }
                }
            }
        }
    }
}

@Composable
private fun Field(label: String, value: String?) {
    if (value.isNullOrBlank()) return
    Row {
        Text(label, color = InkMuted, fontSize = 13.sp, modifier = Modifier.weight(0.4f))
        Text(value, fontSize = 13.sp, modifier = Modifier.weight(0.6f))
    }
}
