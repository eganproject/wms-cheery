<?php

namespace App\Http\Controllers;

use App\Models\FabricCutting;
use App\Models\FabricMovement;
use App\Models\SewingBatch;
use App\Support\DeliveryTimeline;
use Illuminate\View\View;

/**
 * Halaman tujuan QR di surat jalan kain (ke Cutting) dan surat jalan ke
 * penjahit. Tanpa login supaya pemotong/penjahit bisa membukanya dari HP;
 * aman karena tautannya bertanda tangan (tidak bisa ditebak atau diubah)
 * dan hanya menampilkan isi surat jalan beserta statusnya, tanpa harga.
 */
class DeliveryNotePageController extends Controller
{
    protected const MOVEMENT_RELATIONS = ['roll:id,code,fabric_receipt_id', 'roll.receipt:id,code,date,supplier_id', 'roll.receipt.supplier:id,name', 'fabric', 'user:id,name'];

    public function cutting(FabricCutting $fabricCutting): View
    {
        $fabricCutting->load([
            'employee:id,name,position', 'product:id,sku,name', 'group:id,name',
            'movements' => fn ($query) => $query
                ->whereIn('type', [FabricMovement::TYPE_OUT, FabricMovement::TYPE_CANCEL, FabricMovement::TYPE_RETURN])
                ->with(self::MOVEMENT_RELATIONS)
                ->orderBy('moved_at')
                ->orderBy('id'),
            'batches' => fn ($query) => $query->with([
                'supplier:id,name', 'items:id,sewing_batch_id,product_id,quantity',
                'inbounds' => fn ($query) => $query->withSum('items', 'quantity')->orderBy('id'),
            ])->orderBy('id'),
        ]);

        $rolls = $fabricCutting->deliveryRolls();

        return view('delivery-notes.cutting', [
            'cutting' => $fabricCutting,
            'rolls' => $rolls,
            'totals' => FabricCutting::deliveryTotals($rolls),
            'received' => SewingBatch::receivedFor($fabricCutting->batches->pluck('id')),
            'timeline' => DeliveryTimeline::forCutting($fabricCutting),
        ]);
    }

    public function batch(SewingBatch $sewingBatch): View
    {
        $sewingBatch->load([
            'supplier:id,name', 'items.product:id,sku,name,unit',
            'cutting.employee:id,name', 'cutting.product:id,sku,name', 'cutting.group:id,name',
            'cutting.movements' => fn ($query) => $query
                ->whereIn('type', [FabricMovement::TYPE_OUT, FabricMovement::TYPE_CANCEL, FabricMovement::TYPE_RETURN])
                ->with(self::MOVEMENT_RELATIONS)
                ->orderBy('moved_at')
                ->orderBy('id'),
            'inbounds' => fn ($query) => $query->withSum('items', 'quantity')->orderBy('id'),
        ]);

        $received = $sewingBatch->receivedByProduct();

        return view('delivery-notes.batch', [
            'batch' => $sewingBatch,
            'received' => $received,
            'outstanding' => $sewingBatch->outstanding($received),
            'timeline' => DeliveryTimeline::forBatch($sewingBatch),
        ]);
    }
}
