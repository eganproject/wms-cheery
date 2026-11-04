<?php

namespace Tests\Feature\Admin;

use App\Models\Product;
use App\Models\ProductPoPeriod;
use App\Models\ShipmentOrder;
use App\Models\User;
use App\Services\OrderPlanner;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Halaman Tenggat PO: jumlah resi belum dikirim per kelompok batas kirim.
 */
class PoDeadlineTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected const HEADER = ['Order ID', 'Tracking ID', 'Seller SKU', 'Quantity', 'Normal or Pre-order', 'Created Time', 'Paid Time', 'Delivery Option'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-06-12 09:00'));
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@cheery.test')->firstOrFail();

        $po7 = Product::create(['sku' => 'Helena 3', 'name' => 'Helena - 3', 'unit' => 'pcs', 'min_stock' => 0]);
        $po14 = Product::create(['sku' => 'Kalea 1', 'name' => 'Kalea - 1', 'unit' => 'pcs', 'min_stock' => 0]);
        ProductPoPeriod::create(['product_id' => $po7->id, 'starts_at' => '2026-01-01 00:00', 'days' => 7]);
        ProductPoPeriod::create(['product_id' => $po14->id, 'starts_at' => '2026-01-01 00:00', 'days' => 14]);
        Product::create(['sku' => 'Baru 1', 'name' => 'Baru - 1', 'unit' => 'pcs', 'min_stock' => 0]);

        $rows = [
            // PO 7 hari dibayar 1 Juni -> batas 8 Juni: lewat batas.
            ['1', 'JX01', 'Helena 3', '1', 'Pre-order', '01/06/2026 10:00:00', '01/06/2026 10:00:00', 'Standar'],
            // PO 7 hari dibayar 5 Juni 15:00 -> batas 12 Juni 15:00: hari ini.
            ['2', 'JX02', 'Helena 3', '1', 'Pre-order', '05/06/2026 15:00:00', '05/06/2026 15:00:00', 'Standar'],
            // PO 7 hari dibayar 6 Juni -> 13 Juni: besok.
            ['3', 'JX03', 'Helena 3', '1', 'Pre-order', '06/06/2026 08:00:00', '06/06/2026 08:00:00', 'Standar'],
            // PO 14 hari dibayar 10 Juni -> 24 Juni: lebih dari 7 hari.
            ['4', 'JX04', 'Kalea 1', '1', 'Pre-order', '10/06/2026 08:00:00', '10/06/2026 08:00:00', 'Standar'],
            // PO tanpa lama PO: belum ada batas.
            ['5', 'JX05', 'Baru 1', '1', 'Pre-order', '10/06/2026 08:00:00', '10/06/2026 08:00:00', 'Standar'],
            // Normal: tidak dihitung di jenis PO.
            ['6', 'JX06', 'Kalea 1', '1', 'Normal', '12/06/2026 08:00:00', '12/06/2026 08:00:00', 'Standar'],
        ];

        $csv = collect([self::HEADER, ...$rows])->map(fn (array $row) => implode(',', $row))->implode("\n");
        $path = tempnam(sys_get_temp_dir(), 'tiktok').'.csv';
        file_put_contents($path, $csv);

        $this->actingAs($this->admin)->post(route('admin.imports.store'), [
            'file' => new UploadedFile($path, 'Untuk Dikirim.csv', 'text/csv', null, true),
        ])->assertSessionHas('success');

        app(OrderPlanner::class)->refresh(force: true);
    }

    public function test_counts_po_orders_per_deadline_bucket(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.orders.deadlines'))->assertOk();

        $buckets = $response->viewData('buckets');
        $this->assertSame(1, $buckets['overdue']['total']);
        $this->assertSame(1, $buckets['today']['total']);
        $this->assertSame(1, $buckets['tomorrow']['total']);
        $this->assertSame(0, $buckets['soon']['total']);
        $this->assertSame(1, $buckets['later']['total']);
        $this->assertSame(1, $buckets['missing']['total']);
        $this->assertSame(5, $response->viewData('total'));

        $leadTimes = $response->viewData('leadTimes')->keyBy(fn ($row) => $row['po_days'] ?? 'x');
        $this->assertSame(3, $leadTimes[7]['total']);
        $this->assertSame(1, $leadTimes[14]['total']);

        $response->assertSee('Tenggat PO')->assertSee('JX01')->assertDontSee('JX06');
    }

    public function test_all_kind_includes_normal_orders_and_cancelled_are_left_out(): void
    {
        ShipmentOrder::where('tracking_number', 'JX02')->update(['cancelled_at' => now()]);

        $response = $this->actingAs($this->admin)->get(route('admin.orders.deadlines', ['jenis' => 'semua']))->assertOk();

        $this->assertSame(5, $response->viewData('total'));
        $this->assertSame(0, $response->viewData('buckets')['today']['total']);
    }

    public function test_list_follows_selected_bucket_date_and_lead_time(): void
    {
        $this->actingAs($this->admin)->get(route('admin.orders.deadlines', ['kelompok' => 'overdue']))
            ->assertOk()->assertSee('JX01')->assertDontSee('JX03');

        $this->actingAs($this->admin)->get(route('admin.orders.deadlines', ['tanggal' => '2026-06-13']))
            ->assertOk()->assertSee('JX03')->assertDontSee('JX01');

        $this->actingAs($this->admin)->get(route('admin.orders.deadlines', ['lama' => '14']))
            ->assertOk()->assertSee('JX04')->assertDontSee('JX02');

        $this->actingAs($this->admin)->get(route('admin.orders.deadlines', ['lama' => 'x']))
            ->assertOk()->assertSee('JX05')->assertDontSee('JX04');
    }

    public function test_calendar_shows_counts_per_day_and_list_view_still_works(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.orders.deadlines'))->assertOk();
        $response->assertSee('data-deadline-calendar', false)
            ->assertSee('data-day="2026-06-13"', false)
            ->assertSee('Juni 2026');
        $this->assertSame('2026-06', $response->viewData('month')->format('Y-m'));

        // Bulan berikutnya: JX04 (24 Juni) di bulan Juni, jadi Juli kosong dan menyebut resi sebelum kalender.
        $this->actingAs($this->admin)->get(route('admin.orders.deadlines', ['bulan' => '2026-07']))
            ->assertOk()->assertSee('Juli 2026')->assertSee('batasnya sebelum kalender ini');

        $this->actingAs($this->admin)->get(route('admin.orders.deadlines', ['tampilan' => 'daftar']))
            ->assertOk()->assertSee('data-deadline-days', false)->assertDontSee('data-deadline-calendar', false);
    }
}
