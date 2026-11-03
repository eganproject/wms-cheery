package id.cheerychic.wmsscan.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
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
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.sp
import id.cheerychic.wmsscan.data.AbsenDay
import id.cheerychic.wmsscan.data.AbsenHistory
import id.cheerychic.wmsscan.data.Api
import id.cheerychic.wmsscan.data.ApiException
import id.cheerychic.wmsscan.ui.components.AppCard
import id.cheerychic.wmsscan.ui.components.Banner
import id.cheerychic.wmsscan.ui.components.BannerView
import id.cheerychic.wmsscan.ui.components.Chip
import id.cheerychic.wmsscan.ui.components.Header
import id.cheerychic.wmsscan.ui.components.RoundButton
import id.cheerychic.wmsscan.ui.components.StatusBarIcons
import id.cheerychic.wmsscan.ui.components.Tone
import id.cheerychic.wmsscan.ui.components.Ic
import id.cheerychic.wmsscan.ui.theme.Bg
import id.cheerychic.wmsscan.ui.theme.Brand
import id.cheerychic.wmsscan.ui.theme.Danger
import id.cheerychic.wmsscan.ui.theme.DangerBg
import id.cheerychic.wmsscan.ui.theme.Info
import id.cheerychic.wmsscan.ui.theme.InfoBg
import id.cheerychic.wmsscan.ui.theme.Ink
import id.cheerychic.wmsscan.ui.theme.InkMuted
import id.cheerychic.wmsscan.ui.theme.Success
import id.cheerychic.wmsscan.ui.theme.SuccessBg
import id.cheerychic.wmsscan.ui.theme.Surface2
import id.cheerychic.wmsscan.ui.theme.Warning
import id.cheerychic.wmsscan.ui.theme.WarningBg
import java.util.Calendar

/** Bulan sederhana (minSdk 24 belum punya java.time). */
private data class Month(val year: Int, val month: Int) : Comparable<Month> {
    fun plus(n: Int): Month {
        val index = year * 12 + (month - 1) + n
        return Month(index / 12, index % 12 + 1)
    }
    override fun compareTo(other: Month) = compareValuesBy(this, other, { it.year }, { it.month })
    override fun toString() = String.format(java.util.Locale.US, "%04d-%02d", year, month)

    companion object {
        fun now(): Month = Calendar.getInstance().let { Month(it.get(Calendar.YEAR), it.get(Calendar.MONTH) + 1) }
    }
}

/** Warna status absensi, sama dengan badge di WMS. */
internal fun variantColors(variant: String): Pair<Color, Color> = when (variant) {
    "success" -> Success to SuccessBg
    "warning" -> Warning to WarningBg
    "danger" -> Danger to DangerBg
    "info" -> Info to InfoBg
    "purple" -> Color(0xFF7C4DDB) to Color(0xFFF1EBFD)
    else -> InkMuted to Surface2
}

/** Riwayat absen bulanan milik karyawan yang sedang login. */
@Composable
fun AbsenHistoryScreen(onBack: () -> Unit) {
    StatusBarIcons(darkScreen = false)
    var month by remember { mutableStateOf(Month.now()) }
    var data by remember { mutableStateOf<AbsenHistory?>(null) }
    var loading by remember { mutableStateOf(true) }
    var error by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(month) {
        loading = true
        try {
            data = Api.absenHistory(month.toString())
            error = null
        } catch (e: ApiException) {
            error = e.message
        } catch (e: Exception) {
            error = "Jawaban server tidak dikenali."
        }
        loading = false
    }

    Column(Modifier.fillMaxSize().background(Bg).statusBarsPadding()) {
        Header("Riwayat Absen", onBack = onBack)
        Row(
            Modifier.fillMaxWidth().padding(horizontal = 20.dp, vertical = 4.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            RoundButton(Ic.Back, "Bulan sebelumnya") { month = month.plus(-1) }
            Text(
                data?.monthLabel?.takeIf { it.isNotBlank() && data?.month == month.toString() } ?: month.toString(),
                color = Ink, fontWeight = FontWeight.Bold, fontSize = 16.sp,
                modifier = Modifier.weight(1f), textAlign = TextAlign.Center,
            )
            val canNext = month < Month.now()
            Box(Modifier.size(42.dp)) {
                if (canNext) {
                    RoundButton(Ic.Next, "Bulan berikutnya") { month = month.plus(1) }
                }
            }
        }

        BannerView(error?.let { Banner(it, Tone.Error) }, Modifier.padding(horizontal = 20.dp))

        LazyColumn(
            contentPadding = PaddingValues(start = 20.dp, end = 20.dp, top = 8.dp, bottom = 40.dp),
            verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            item { SummaryCard(data?.takeIf { !loading }) }
            if (loading) {
                item {
                    Box(Modifier.fillMaxWidth().padding(32.dp), contentAlignment = Alignment.Center) {
                        CircularProgressIndicator(color = Brand, strokeWidth = 3.dp, modifier = Modifier.size(28.dp))
                    }
                }
            } else if (data?.days.isNullOrEmpty()) {
                item {
                    Text(
                        "Belum ada data absen di bulan ini.",
                        color = InkMuted, fontSize = 13.5.sp,
                        modifier = Modifier.fillMaxWidth().padding(24.dp),
                        textAlign = TextAlign.Center,
                    )
                }
            } else {
                items(data!!.days, key = { it.date }) { day -> DayRow(day) }
            }
        }
    }
}

@Composable
private fun SummaryCard(data: AbsenHistory?) {
    val s = data?.summary
    AppCard(radius = 22.dp) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Row(verticalAlignment = Alignment.Bottom) {
                Text(s?.rate?.let { "$it%" } ?: "–", color = Ink, fontSize = 30.sp, fontWeight = FontWeight.ExtraBold)
                Spacer(Modifier.width(8.dp))
                Text("kehadiran", color = InkMuted, fontSize = 13.sp, modifier = Modifier.padding(bottom = 6.dp))
            }
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Stat("Hadir", s?.let { "${it.present}/${it.workdays}" }, Modifier.weight(1f))
                Stat("Telat", s?.late?.toString(), Modifier.weight(1f))
                Stat("Izin/Sakit", s?.let { "${it.izin + it.sakit}" }, Modifier.weight(1f))
                Stat("Cuti", s?.cuti?.toString(), Modifier.weight(1f))
                Stat("Alpa", s?.absent?.toString(), Modifier.weight(1f))
            }
            if (s != null && s.lateMinutes > 0) {
                Text("Total telat ${s.lateMinutes} menit", color = Warning, fontSize = 12.5.sp, fontWeight = FontWeight.SemiBold)
            }
        }
    }
}

@Composable
private fun Stat(label: String, value: String?, modifier: Modifier) {
    Column(modifier.clip(RoundedCornerShape(14.dp)).background(Surface2).padding(vertical = 10.dp), horizontalAlignment = Alignment.CenterHorizontally) {
        Text(value ?: "–", color = Ink, fontSize = 16.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1)
        Text(label, color = InkMuted, fontSize = 10.5.sp, maxLines = 1)
    }
}

@Composable
private fun DayRow(day: AbsenDay) {
    val (fg, bg) = variantColors(day.variant)
    AppCard(radius = 18.dp) {
        Row(Modifier.padding(horizontal = 14.dp, vertical = 12.dp), verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Text(day.dateLabel ?: day.date, color = Ink, fontWeight = FontWeight.Bold, fontSize = 14.sp)
                val times = listOfNotNull(day.checkIn?.let { "Masuk $it" }, day.checkOut?.let { "Pulang $it" })
                if (times.isNotEmpty()) {
                    Text(times.joinToString(" · "), color = InkMuted, fontSize = 12.5.sp)
                }
                if (!day.note.isNullOrBlank()) {
                    Text(day.note, color = if (day.lateMinutes > 0 || day.earlyMinutes > 0) Warning else InkMuted, fontSize = 12.sp)
                }
            }
            Spacer(Modifier.width(8.dp))
            Chip(day.label, fg, bg)
        }
    }
}
