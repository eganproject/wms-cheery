<?php

namespace Tests\Feature\Admin;

use App\Models\Product;
use App\Models\ShipmentImport;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kaki tabel bersama: jumlah baris per halaman, urut kolom, navigasi halaman.
 */
class ListingTableTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@cheery.test')->firstOrFail();
    }

    protected function products(int $count): void
    {
        foreach (range(1, $count) as $i) {
            $product = Product::create([
                'sku' => sprintf('SKU-%03d', $i),
                'name' => sprintf('Barang %03d', $i),
                'category' => $i % 2 ? 'Ganjil' : 'Genap',
                'unit' => 'pcs',
                'min_stock' => 0,
            ]);
            $product->forceFill(['stock' => $i * 3])->saveQuietly();
        }
    }

    public function test_rows_per_page_defaults_to_25_and_accepts_listed_options_only(): void
    {
        $this->products(60);

        $this->actingAs($this->admin)->get(route('admin.products.index'))
            ->assertOk()
            ->assertViewHas('products', fn ($p) => $p->perPage() === 25 && $p->total() === 60)
            ->assertSee('Baris per halaman')
            ->assertSee('Menampilkan');

        $this->get(route('admin.products.index', ['per_page' => 50]))
            ->assertViewHas('products', fn ($p) => $p->perPage() === 50);

        // Angka di luar pilihan (mis. 100000) tidak boleh membuat halaman berat.
        $this->get(route('admin.products.index', ['per_page' => 100000]))
            ->assertViewHas('products', fn ($p) => $p->perPage() === 25);
    }

    public function test_clicking_a_column_sorts_both_directions(): void
    {
        $this->products(30);

        $this->actingAs($this->admin)
            ->get(route('admin.products.index', ['sort' => 'stock', 'dir' => 'desc']))
            ->assertOk()
            ->assertViewHas('products', fn ($p) => $p->first()->sku === 'SKU-030');

        $this->get(route('admin.products.index', ['sort' => 'stock', 'dir' => 'asc']))
            ->assertViewHas('products', fn ($p) => $p->first()->sku === 'SKU-001');

        // Kolom yang tidak diizinkan diabaikan, bukan dipakai mentah ke SQL.
        $this->get(route('admin.products.index', ['sort' => 'password', 'dir' => 'asc']))
            ->assertOk();
    }

    public function test_sortable_header_links_keep_filters_and_reset_the_page(): void
    {
        $this->products(30);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.products.index', ['search' => 'Barang', 'page' => 2, 'sort' => 'stock', 'dir' => 'desc']))
            ->assertOk();

        // Klik lagi kolom yang aktif membalik arah; filter tetap, halaman kembali ke 1.
        $html = $response->getContent();
        $this->assertMatchesRegularExpression('/href="[^"]*search=Barang[^"]*sort=stock[^"]*dir=asc"/', html_entity_decode($html));
        $this->assertDoesNotMatchRegularExpression('/href="[^"]*page=2[^"]*sort=stock[^"]*dir=asc"/', html_entity_decode($html));
    }

    public function test_page_jump_and_numbers_render_when_there_are_many_pages(): void
    {
        $this->products(70);

        $this->actingAs($this->admin)
            ->get(route('admin.products.index', ['per_page' => 10]))
            ->assertOk()
            ->assertSee('data-page-jump', false)
            ->assertSee('aria-current="page"', false);
    }

    public function test_every_list_page_accepts_sort_and_page_size(): void
    {
        $this->products(3);

        $pages = [
            'admin.products.index' => ['name', 'category', 'location', 'stock'],
            'admin.movements.index' => ['time', 'product', 'type', 'quantity', 'balance'],
            'admin.inbounds.index' => ['code', 'date', 'supplier', 'items', 'quantity', 'status'],
            'admin.outbounds.index' => ['date', 'recipient', 'quantity', 'status'],
            'admin.outbounds.handover' => [],
            'admin.returns.index' => ['date', 'sender', 'type', 'reason', 'quantity', 'status'],
            'admin.adjustments.index' => ['date', 'reason', 'items', 'status'],
            'admin.opnames.index' => ['date', 'scope', 'progress', 'status'],
            'admin.disposals.index' => [],
            'admin.suppliers.index' => ['name', 'contact', 'inbounds', 'status'],
            'admin.users.index' => ['name', 'role', 'phone', 'status', 'last_login'],
            'admin.roles.index' => [],
            'admin.employees.index' => ['name', 'method', 'shift', 'pin', 'status'],
            'admin.attendance-leaves.index' => ['employee', 'type', 'date'],
            'admin.attendance.index' => ['name', 'shift', 'in', 'out', 'status'],
            'admin.attendance.report' => ['name', 'workdays', 'present', 'late', 'rate'],
            'admin.stock-api-access.index' => ['ip', 'label', 'status'],
            'admin.imports.batches' => ['file', 'orders', 'items', 'unmatched', 'date'],
            'admin.imports.daily' => ['date', 'total', 'readiness'],
            'admin.imports.couriers' => ['courier', 'total', 'readiness'],
            'admin.orders.index' => [],
            'admin.reports.stock' => ['nama', 'keluar', 'frekuensi', 'sisa'],
            'admin.reports.restock' => ['nama', 'laku', 'jumlah'],
        ];

        $this->actingAs($this->admin);

        foreach ($pages as $route => $keys) {
            $this->get(route($route, ['per_page' => 50]))->assertOk();

            foreach ($keys as $key) {
                foreach (['asc', 'desc'] as $dir) {
                    $this->get(route($route, ['sort' => $key, 'dir' => $dir]))
                        ->assertOk();
                }
            }
        }

        foreach (['name', 'category', 'damaged', 'stock'] as $key) {
            $this->get(route('admin.disposals.index', ['tab' => 'saldo', 'stok_sort' => $key, 'stok_dir' => 'asc', 'stok_per_page' => 50]))->assertOk();
        }
        foreach (['date', 'bucket', 'action', 'items', 'quantity', 'status'] as $key) {
            $this->get(route('admin.disposals.index', ['tab' => 'riwayat', 'dokumen_sort' => $key, 'dokumen_dir' => 'desc']))->assertOk();
        }
    }

    public function test_report_tables_built_in_memory_sort_and_paginate(): void
    {
        $this->products(1);
        $product = Product::first();

        foreach (['JNE' => 3, 'J&T' => 1, 'SiCepat' => 2] as $courier => $count) {
            foreach (range(1, $count) as $i) {
                $import = ShipmentImport::create([
                    'filename' => 'tiktok.xlsx', 'source' => 'tiktok', 'row_count' => 1,
                    'detected_columns' => ['tracking_number', 'sku'],
                ]);
                $order = $import->orders()->create([
                    'tracking_number' => "{$courier}-{$i}",
                    'order_number' => "INV-{$courier}-{$i}",
                    'marketplace' => 'TikTok',
                    'courier' => $courier,
                    'order_date' => now(),
                ]);
                $order->items()->create([
                    'sku' => $product->sku, 'product_id' => $product->id,
                    'product_name' => $product->name, 'quantity' => 1,
                ]);
            }
        }

        $this->actingAs($this->admin)
            ->get(route('admin.imports.couriers', ['sort' => 'total', 'dir' => 'asc']))
            ->assertOk()
            ->assertSee('Belum QC')
            ->assertViewHas('couriers', fn ($p) => $p->first()->courier === 'J&T' && $p->total() === 3);

        $this->get(route('admin.imports.couriers', ['sort' => 'courier', 'dir' => 'desc']))
            ->assertViewHas('couriers', fn ($p) => $p->first()->courier === 'SiCepat');

        $this->get(route('admin.imports.couriers', ['sort' => 'awaiting', 'dir' => 'desc']))
            ->assertOk()
            ->assertSee('sort=awaiting', false);

        $this->get(route('admin.imports.daily', ['sort' => 'total', 'dir' => 'desc']))
            ->assertOk()
            ->assertViewHas('rows', fn ($p) => $p->total() === 1);
    }

    public function test_packing_list_and_settings_lists_are_paginated_and_sortable(): void
    {
        $this->products(3);
        $this->actingAs($this->admin);

        foreach (['sku', 'orders', 'ordered', 'scanned', 'remaining', 'progress'] as $key) {
            $this->get(route('admin.orders.packing', ['sku_sort' => $key, 'sku_dir' => 'desc', 'sku_per_page' => 100]))->assertOk();
        }
        foreach (['resi', 'deadline', 'order_date'] as $key) {
            foreach (['harian', 'semua'] as $type) {
                $this->get(route('admin.orders.packing', ['jenis' => $type, 'resi_sort' => $key, 'resi_dir' => 'asc', 'resi_per_page' => 25]))->assertOk();
            }
        }

        $this->get(route('admin.po-periods.index', ['sort' => 'current', 'dir' => 'desc']))
            ->assertOk()
            ->assertViewHas('owners', fn ($p) => $p->total() === 3);

        foreach (['name', 'start', 'end', 'tolerance', 'employees', 'status'] as $key) {
            $this->get(route('admin.work-shifts.index', ['sort' => $key, 'dir' => 'asc']))->assertOk();
        }

        $this->get(route('admin.approvals.index', ['inbound' => 2, 'inbound_per_page' => 25]))->assertOk();
    }
}
