<?php

namespace Tests\Feature\Admin;

use App\Models\Outbound;
use App\Models\Product;
use App\Models\ProductBundleItem;
use App\Models\ProductGroup;
use App\Models\ShipmentImport;
use App\Models\ShipmentOrder;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Packing list: barang yang harus dikemas dari resi import, dan sisanya
 * setelah QC Kemas.
 */
class PackingListTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected ShipmentImport $import;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@cheery.test')->firstOrFail();
        $this->import = ShipmentImport::create(['filename' => 'ginee.xlsx', 'user_id' => $this->admin->id]);
    }

    protected function order(string $resi, array $items, array $attributes = []): ShipmentOrder
    {
        $order = ShipmentOrder::create(array_merge([
            'shipment_import_id' => $this->import->id,
            'tracking_number' => $resi,
            'marketplace' => 'Shopee',
        ], $attributes));

        foreach ($items as [$sku, $quantity, $product]) {
            $order->items()->create(['sku' => $sku, 'quantity' => $quantity, 'product_id' => $product?->id]);
        }

        return $order;
    }

    protected function outbound(ShipmentOrder $order, Product $product, int $quantity, int $scanned, string $status = Outbound::STATUS_DRAFT): Outbound
    {
        $outbound = Outbound::create([
            'code' => Outbound::nextCode(), 'date' => now(), 'type' => Outbound::TYPE_MARKETPLACE,
            'recipient' => 'Pembeli', 'tracking_number' => $order->tracking_number, 'status' => $status,
            'shipment_order_id' => $order->id, 'resi_verified_at' => now(),
        ]);
        $outbound->items()->create(['product_id' => $product->id, 'quantity' => $quantity, 'scanned_quantity' => $scanned]);

        return $outbound;
    }

    public function test_it_totals_items_and_the_remaining_after_qc(): void
    {
        $tas = Product::create(['sku' => 'TAS-1', 'name' => 'Tas Mini', 'unit' => 'pcs', 'min_stock' => 0]);
        $dompet = Product::create(['sku' => 'DOMPET', 'name' => 'Dompet', 'unit' => 'pcs', 'min_stock' => 0]);
        $paket = Product::create(['sku' => 'PAKET', 'name' => 'Paket Hemat', 'unit' => 'pcs', 'min_stock' => 0, 'type' => Product::TYPE_BUNDLE]);
        ProductBundleItem::create(['bundle_id' => $paket->id, 'component_id' => $tas->id, 'quantity' => 2]);

        // Belum ada dokumen: 1 tas + paket (2 tas) = 3 tas.
        $this->order('R1', [['TAS-1', 1, $tas], ['PAKET', 1, $paket]]);
        // Sebagian sudah discan: 2 tas, 1 discan.
        $this->outbound($this->order('R2', [['TAS-1', 2, $tas]]), $tas, 2, 1);
        // Lolos QC: dompet lengkap.
        $this->outbound($this->order('R3', [['DOMPET', 1, $dompet]]), $dompet, 1, 1);
        // Tidak dihitung: sudah dikirim dan dibatalkan.
        $this->outbound($this->order('R4', [['TAS-1', 5, $tas]]), $tas, 5, 5, Outbound::STATUS_POSTED);
        $this->order('R5', [['TAS-1', 7, $tas]], ['cancelled_at' => now()]);
        // SKU yang belum terdaftar tetap tampil.
        $this->order('R6', [['ASING', 4, null]]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.orders.packing', ['import' => $this->import->id, 'per' => 'barang']))
            ->assertOk();

        $rows = $response->viewData('rows')->keyBy('sku');
        $this->assertSame([5, 1, 4], [$rows['TAS-1']['ordered'], $rows['TAS-1']['scanned'], $rows['TAS-1']['remaining']]);
        $this->assertSame(['R1', 'R2'], $rows['TAS-1']['open_resi']);
        $this->assertSame(0, $rows['DOMPET']['remaining']);
        $this->assertFalse($rows['ASING']['matched']);
        $this->assertArrayNotHasKey('PAKET', $rows->all());

        $totals = $response->viewData('list')->totals;
        $this->assertSame(4, $totals['orders']);
        $this->assertSame(1, $totals['orders_done']);
        $this->assertSame(8, $totals['remaining']);

        $response->assertSee('Packing List')->assertSee('Tas Mini');
    }

    public function test_it_sums_each_sku_across_waybills_by_default(): void
    {
        $trip = Product::create(['sku' => 'TRIP1', 'name' => 'Trip Bag', 'unit' => 'pcs', 'min_stock' => 0]);
        $tas = Product::create(['sku' => 'TAS-1', 'name' => 'Tas Mini', 'unit' => 'pcs', 'min_stock' => 0]);
        $paket = Product::create(['sku' => 'PAKET', 'name' => 'Paket Hemat', 'unit' => 'pcs', 'min_stock' => 0, 'type' => Product::TYPE_BUNDLE]);
        ProductBundleItem::create(['bundle_id' => $paket->id, 'component_id' => $tas->id, 'quantity' => 2]);

        // Dua resi, masing-masing 2 pcs TRIP1: ringkasannya TRIP1 4 pcs.
        $this->order('R1', [['TRIP1', 2, $trip], ['PAKET', 1, $paket]]);
        $second = $this->order('R2', [['TRIP1', 2, $trip]]);
        $this->outbound($second, $trip, 2, 2);

        $rows = $this->actingAs($this->admin)
            ->get(route('admin.orders.packing', ['import' => $this->import->id]))
            ->assertOk()
            ->assertSee('TRIP1')
            ->viewData('rows')
            ->keyBy('sku');

        $this->assertSame(['PAKET', 'TRIP1'], $rows->keys()->all());
        $this->assertSame([4, 2, 2, 2], [$rows['TRIP1']['ordered'], $rows['TRIP1']['scanned'], $rows['TRIP1']['remaining'], $rows['TRIP1']['orders']]);
        $this->assertSame(1, $rows['PAKET']['ordered']);
        $this->assertTrue($rows['PAKET']['from_bundle']);
    }

    public function test_a_bundle_counts_as_packed_once_all_its_contents_are_scanned(): void
    {
        $tas = Product::create(['sku' => 'TAS-1', 'name' => 'Tas Mini', 'unit' => 'pcs', 'min_stock' => 0]);
        $paket = Product::create(['sku' => 'PAKET', 'name' => 'Paket Hemat', 'unit' => 'pcs', 'min_stock' => 0, 'type' => Product::TYPE_BUNDLE]);
        ProductBundleItem::create(['bundle_id' => $paket->id, 'component_id' => $tas->id, 'quantity' => 2]);

        // 2 paket = 4 tas; 3 tas sudah discan -> baru 1 paket lengkap.
        $this->outbound($this->order('R1', [['PAKET', 2, $paket]]), $tas, 4, 3);

        $rows = $this->actingAs($this->admin)
            ->get(route('admin.orders.packing', ['import' => $this->import->id]))
            ->viewData('rows')
            ->keyBy('sku');

        $this->assertSame([2, 1, 1], [$rows['PAKET']['ordered'], $rows['PAKET']['scanned'], $rows['PAKET']['remaining']]);
    }

    public function test_it_can_show_only_rows_with_remaining_units(): void
    {
        $tas = Product::create(['sku' => 'TAS-1', 'name' => 'Tas Mini', 'unit' => 'pcs', 'min_stock' => 0]);
        $dompet = Product::create(['sku' => 'DOMPET', 'name' => 'Dompet', 'unit' => 'pcs', 'min_stock' => 0]);
        $this->order('R1', [['TAS-1', 1, $tas]]);
        $this->outbound($this->order('R2', [['DOMPET', 1, $dompet]]), $dompet, 1, 1);

        $rows = $this->actingAs($this->admin)
            ->get(route('admin.orders.packing', ['show' => 'sisa', 'jenis' => 'unggahan']))
            ->assertOk()
            ->viewData('rows');

        $this->assertSame(['TAS-1'], $rows->pluck('sku')->all());
    }

    public function test_rows_show_a_clickable_image_and_variants_fall_back_to_the_parent_image(): void
    {
        $group = ProductGroup::create(['name' => 'Gamis', 'unit' => 'pcs', 'image_path' => 'products/gamis.jpg']);
        $variant = Product::create(['sku' => 'GAMIS-M', 'name' => 'Gamis - M', 'unit' => 'pcs', 'min_stock' => 0, 'product_group_id' => $group->id]);
        $tas = Product::create(['sku' => 'TAS-1', 'name' => 'Tas Mini', 'unit' => 'pcs', 'min_stock' => 0]);
        $tas->forceFill(['image_path' => 'products/tas.jpg'])->save();
        $polos = Product::create(['sku' => 'POLOS', 'name' => 'Tanpa Gambar', 'unit' => 'pcs', 'min_stock' => 0]);

        $this->order('R1', [['GAMIS-M', 1, $variant], ['TAS-1', 2, $tas], ['POLOS', 1, $polos], ['ASING', 1, null]]);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.orders.packing', ['jenis' => 'semua']))
            ->assertOk()
            ->getContent();

        $gamis = $group->imageUrl();
        $this->assertStringContainsString('href="'.$gamis.'"', $html);
        $this->assertStringContainsString('href="'.$tas->image_url.'"', $html);
        // Tabel barang dan kolom Isi daftar resi sama-sama membuka pratinjau.
        $this->assertSame(2, substr_count($html, 'data-lightbox="packing"'));
        $this->assertSame(2, substr_count($html, 'data-lightbox="packing-resi"'));
        $this->assertStringContainsString('data-lightbox-caption="GAMIS-M"', $html);
        // Barang tanpa gambar dan SKU belum terdaftar memakai kotak kosong.
        $this->assertStringContainsString('title="Belum ada gambar"', $html);
        $this->assertStringContainsString('id="image-lightbox"', $html);
    }
}
