<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris berkas import yang ditolak, beserta alasannya.
 */
class ShipmentImportFailure extends Model
{
    public const REASON_NO_TRACKING = 'Tracking ID kosong';

    public const REASON_NO_SKU = 'Seller SKU kosong';

    public const REASON_TRACKING_IN_SYSTEM = 'Resi sudah ada di sistem';

    public const REASON_TRACKING_SHARED = 'Resi dipakai lebih dari satu pesanan';

    public const REASON_ORDER_IN_SYSTEM = 'Order ID sudah punya resi lain di sistem';

    public const REASON_ORDER_SPLIT = 'Order ID muncul dengan beberapa resi';

    protected $fillable = [
        'shipment_import_id',
        'row_number',
        'order_number',
        'tracking_number',
        'sku',
        'product_name',
        'quantity',
        'reason',
        'detail',
    ];

    public function import(): BelongsTo
    {
        return $this->belongsTo(ShipmentImport::class, 'shipment_import_id');
    }
}
