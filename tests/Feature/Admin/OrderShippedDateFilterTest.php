<?php

namespace Tests\Feature\Admin;

use App\Models\Outbound;
use App\Models\Product;
use App\Models\ShipmentImport;
use App\Models\ShipmentOrder;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tab Dikirim disaring menurut waktu serah terima, bukan waktu unggah, dan
 * riwayat serah terima bisa dilihat dari menu Serah Terima Paket.
 */
class OrderShippedDateFilterTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@cheery.test')->firstOrFail();
    }

    protected function order(string $resi, array $attributes = [], $uploadedAt = null): ShipmentOrder
    {
        $import = ShipmentImport::first() ?? ShipmentImport::create([
            'filename' => 'tiktok.xlsx', 'user_id' => $this->admin->id,
        ]);

        $order = ShipmentOrder::create(array_merge([
            'shipment_import_id' => $import->id,
            'tracking_number' => $resi,
            'order_number' => 'ORD-'.$resi,
            'marketplace' => 'TikTok',
            'store_name' => 'Cheery Official',
            'courier' => 'J&T',
        ], $attributes));

        if ($uploadedAt) {
            $order->forceFill(['created_at' => $uploadedAt])->saveQuietly();
        }

        return $order;
    }

    protected function ship(ShipmentOrder $order, $postedAt, int $qty = 1): Outbound
    {
        $product = Product::firstOrCreate(['sku' => 'SKU-1'], ['name' => 'Tas', 'unit' => 'pcs', 'min_stock' => 0]);
        $outbound = Outbound::create([
            'code' => Outbound::nextCode(), 'date' => now(), 'type' => Outbound::TYPE_MARKETPLACE,
            'recipient' => 'Pembeli', 'tracking_number' => $order->tracking_number, 'status' => Outbound::STATUS_POSTED,
            'shipment_order_id' => $order->id, 'resi_verified_at' => $postedAt, 'posted_at' => $postedAt,
            'submitted_by' => $this->admin->id,
        ]);
        $outbound->items()->create(['product_id' => $product->id, 'quantity' => $qty, 'scanned_quantity' => $qty]);
        ShipmentOrder::syncShipped([$order->id]);

        return $outbound;
    }

    public function test_uploaded_yesterday_and_handed_over_today_shows_in_shipped_tab_today(): void
    {
        $this->ship($this->order('RESIKEMARIN', [], now()->subDay()), now());
        $this->ship($this->order('RESILAMA', [], now()->subDays(3)), now()->subDays(2));

        $html = $this->actingAs($this->admin)
            ->get(route('admin.orders.index', ['stage' => ShipmentOrder::STAGE_SHIPPED]))
            ->assertOk()
            ->assertSee('RESIKEMARIN')
            ->assertDontSee('RESILAMA')
            ->getContent();

        $this->assertMatchesRegularExpression('/Dikirim <span[^>]*>1</', $html);
    }

    public function test_date_type_can_be_chosen_explicitly(): void
    {
        $this->ship($this->order('RESIKEMARIN', [], now()->subDay()), now());

        $this->actingAs($this->admin)
            ->get(route('admin.orders.index', ['stage' => ShipmentOrder::STAGE_SHIPPED, 'date_by' => 'created']))
            ->assertOk()
            ->assertDontSee('RESIKEMARIN');

        $yesterday = now()->subDay()->toDateString();
        $this->actingAs($this->admin)
            ->get(route('admin.orders.index', ['date_by' => 'created', 'from' => $yesterday, 'to' => $yesterday]))
            ->assertOk()
            ->assertSee('RESIKEMARIN');

        $this->actingAs($this->admin)
            ->get(route('admin.orders.index', ['date_by' => 'qc']))
            ->assertOk()
            ->assertSee('RESIKEMARIN');
    }

    public function test_extra_filters_narrow_the_list(): void
    {
        $this->order('RESIPO', ['is_preorder' => true, 'shipping_method' => 'Reguler', 'order_status' => 'Menunggu dikirim']);
        $this->order('RESINORMAL', ['is_preorder' => false, 'shipping_method' => 'Instan', 'order_status' => 'Menunggu dikirim']);

        $get = fn (array $query) => $this->actingAs($this->admin)->get(route('admin.orders.index', $query))->assertOk();

        $get(['kind' => 'po'])->assertSee('RESIPO')->assertDontSee('RESINORMAL');
        $get(['kind' => 'normal'])->assertSee('RESINORMAL')->assertDontSee('RESIPO');
        $get(['shipping_method' => 'Instan'])->assertSee('RESINORMAL')->assertDontSee('RESIPO');
        $get(['returned' => 'with'])->assertDontSee('RESIPO')->assertDontSee('RESINORMAL');
        $get(['content' => 'multi'])->assertDontSee('RESIPO');

        $this->actingAs($this->admin)
            ->get(route('admin.orders.export', ['kind' => 'po']))
            ->assertOk();
    }

    public function test_handover_history_lists_packages_by_handover_time(): void
    {
        $this->ship($this->order('RESIHARIINI', ['courier' => 'J&T'], now()->subDay()), now(), 2);
        $this->ship($this->order('RESISPX', ['courier' => 'SPX'], now()->subDay()), now());
        $this->ship($this->order('RESIKEMARIN', [], now()->subDays(2)), now()->subDay());

        $this->actingAs($this->admin)
            ->get(route('admin.outbounds.handover.history'))
            ->assertOk()
            ->assertSee('RESIHARIINI')
            ->assertSee('RESISPX')
            ->assertDontSee('RESIKEMARIN')
            ->assertSee('Riwayat Serah Terima');

        $this->actingAs($this->admin)
            ->get(route('admin.outbounds.handover.history', ['courier' => 'SPX', 'search' => 'RESI', 'by' => $this->admin->id, 'store' => 'Cheery Official']))
            ->assertOk()
            ->assertSee('RESISPX')
            ->assertDontSee('RESIHARIINI');

        $this->actingAs($this->admin)
            ->get(route('admin.outbounds.handover.history.export', ['courier' => 'J&T']))
            ->assertOk();

        $this->actingAs($this->admin)
            ->get(route('admin.outbounds.handover', ['courier' => 'SPX', 'store' => 'Cheery Official']))
            ->assertOk()
            ->assertSee('Riwayat Serah Terima');
    }
}
