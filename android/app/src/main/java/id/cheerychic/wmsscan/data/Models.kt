package id.cheerychic.wmsscan.data

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

@Serializable
data class Can(
    val kemas: Boolean = false,
    @SerialName("serah_terima") val serahTerima: Boolean = false,
    val pesanan: Boolean = false,
)

@Serializable
data class User(
    val id: Int,
    val name: String,
    val email: String,
    val role: String? = null,
    val can: Can = Can(),
)

@Serializable
data class LoginResponse(val token: String, val user: User)

@Serializable
data class MeResponse(val user: User)

@Serializable
data class MessageResponse(val message: String = "")

/* ------------------------------------------------------------ kemas -- */

@Serializable
data class PackOutbound(
    val id: Int,
    val code: String,
    @SerialName("tracking_number") val trackingNumber: String? = null,
    val marketplace: String? = null,
    val recipient: String? = null,
    @SerialName("order_number") val orderNumber: String? = null,
)

@Serializable
data class PackItem(
    val id: Int,
    val sku: String,
    val name: String = "",
    val barcode: String? = null,
    val unit: String? = null,
    val stock: Int = 0,
    val quantity: Int,
)

@Serializable
data class PackBundle(
    val sku: String? = null,
    val name: String? = null,
    val quantity: Int = 0,
)

@Serializable
data class ProgressItem(
    val id: Int,
    val scanned: Int = 0,
    val quantity: Int = 0,
    val completed: Boolean = false,
)

@Serializable
data class Progress(
    @SerialName("resi_verified") val resiVerified: Boolean = false,
    val total: Int = 0,
    val scanned: Int = 0,
    val ready: Boolean = false,
    val items: List<ProgressItem> = emptyList(),
)

@Serializable
data class ResiResponse(
    val outbound: PackOutbound,
    val items: List<PackItem> = emptyList(),
    val bundles: List<PackBundle> = emptyList(),
    val progress: Progress = Progress(),
)

@Serializable
data class ItemResponse(
    val message: String = "",
    val sku: String? = null,
    @SerialName("item_id") val itemId: Int? = null,
    val completed: Boolean = false,
    val progress: Progress = Progress(),
)

/* ----------------------------------------------------- serah terima -- */

@Serializable
data class HandoverOutbound(
    val id: Int,
    val code: String,
    @SerialName("tracking_number") val trackingNumber: String? = null,
    val marketplace: String? = null,
    val recipient: String? = null,
    val units: Int = 0,
)

@Serializable
data class HandoverResponse(
    val outbound: HandoverOutbound,
    val shipped: Boolean = false,
    val message: String = "",
    val remaining: Int = 0,
)

/* ---------------------------------------------------------- pesanan -- */

@Serializable
data class Summary(
    val date: String = "",
    @SerialName("belum_dikemas") val belumDikemas: Int = 0,
    @SerialName("siap_dikemas") val siapDikemas: Int = 0,
    @SerialName("sudah_dikemas") val sudahDikemas: Int = 0,
    val dikirim: Int = 0,
)

@Serializable
data class OrderItem(
    val sku: String? = null,
    val name: String? = null,
    val quantity: Int = 0,
)

@Serializable
data class PackedItem(
    val sku: String? = null,
    val name: String? = null,
    val barcode: String? = null,
    val quantity: Int = 0,
    val scanned: Int = 0,
)

@Serializable
data class Order(
    val id: Int,
    @SerialName("tracking_number") val trackingNumber: String? = null,
    @SerialName("order_number") val orderNumber: String? = null,
    val marketplace: String? = null,
    @SerialName("store_name") val storeName: String? = null,
    @SerialName("buyer_name") val buyerName: String? = null,
    val courier: String? = null,
    val stage: String = "",
    @SerialName("stage_label") val stageLabel: String = "",
    @SerialName("stage_detail") val stageDetail: String = "",
    @SerialName("ready_label") val readyLabel: String = "",
    val kind: String = "",
    @SerialName("is_priority") val isPriority: Boolean = false,
    @SerialName("ship_by") val shipBy: String? = null,
    @SerialName("deadline_label") val deadlineLabel: String? = null,
    @SerialName("deadline_tone") val deadlineTone: String? = null,
    @SerialName("total_quantity") val totalQuantity: Int = 0,
    @SerialName("scanned_quantity") val scannedQuantity: Int = 0,
    @SerialName("shipped_at") val shippedAt: String? = null,
    val items: List<OrderItem> = emptyList(),
    @SerialName("buyer_note") val buyerNote: String? = null,
    @SerialName("outbound_code") val outboundCode: String? = null,
    @SerialName("packed_items") val packedItems: List<PackedItem> = emptyList(),
)

@Serializable
data class PageMeta(
    @SerialName("current_page") val currentPage: Int = 1,
    @SerialName("last_page") val lastPage: Int = 1,
    val total: Int = 0,
)

@Serializable
data class OrdersResponse(
    val stage: String = "",
    val date: String = "",
    val data: List<Order> = emptyList(),
    val meta: PageMeta = PageMeta(),
)

@Serializable
data class OrderDetailResponse(val order: Order)

object Stages {
    const val BELUM = "belum-qc"
    const val SUDAH = "serah-terima"
    const val DIKIRIM = "dikirim"
}
