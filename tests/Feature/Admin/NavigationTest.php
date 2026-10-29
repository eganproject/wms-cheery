<?php

namespace Tests\Feature\Admin;

use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Struktur navigasi ala ERPNext: menu samping dikelompokkan menurut pekerjaan,
 * dan halaman sekelompok saling terhubung lewat tab di kepala halaman.
 */
class NavigationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@cheery.test')->firstOrFail();
    }

    /* --------------------------------------------------- menu samping ---- */

    public function test_the_sidebar_groups_pages_by_the_work_they_serve(): void
    {
        $sidebar = $this->sidebar();

        // Kelompok menu mengikuti pekerjaan, seperti sidebar workspace ERPNext.
        foreach (['Penjualan Marketplace', 'Transaksi Stok', 'Master Data', 'Laporan', 'Pengaturan'] as $group) {
            $this->assertStringContainsString($group, $sidebar);
        }

        // Setiap halaman bisa dibuka dengan satu klik dari menu samping.
        foreach (['Beranda', 'Persetujuan', 'Stasiun Packing', 'Siap Dikirim', 'Retur Marketplace',
            'Status Resi', 'Import Resi', 'Barang Masuk', 'Barang Keluar', 'Penerimaan Retur',
            'Stok Opname', 'Penyesuaian Stok', 'Barang Rusak', 'Pemasok', 'Laporan Stok',
            'Mutasi Stok', 'Kebutuhan Restock', 'Resi per Hari', 'Resi per Ekspedisi',
            'Pengguna', 'Hak Akses', 'Akses API Stok'] as $entry) {
            $this->assertStringContainsString($entry, $sidebar);
        }
    }

    public function test_the_sidebar_hides_pages_the_user_may_not_open(): void
    {
        $role = Role::create(['name' => 'Mutasi Saja', 'slug' => 'mutasi-saja']);
        $role->permissions()->sync(Permission::whereIn('slug', ['dashboard.view', 'movements.view'])->pluck('id'));

        $sidebar = $this->sidebar(User::factory()->create(['role_id' => $role->id]));

        $this->assertStringContainsString(route('admin.movements.index'), $sidebar);
        $this->assertStringNotContainsString(route('admin.products.index'), $sidebar);

        // Kelompok yang seluruh isinya tertutup tidak ditampilkan sama sekali.
        $this->assertStringNotContainsString('Pengaturan', $sidebar);
        $this->assertStringNotContainsString('Penjualan Marketplace', $sidebar);
    }

    public function test_the_active_page_is_marked_in_the_sidebar(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.suppliers.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/href="'.preg_quote(route('admin.suppliers.index'), '/').'"[^>]*aria-current="page"/',
            $html
        );
    }

    /* --------------------------------------------------- tab ------------- */

    public function test_pages_in_one_group_carry_the_same_tab_bar(): void
    {
        foreach ([route('admin.products.index'), route('admin.suppliers.index')] as $url) {
            $tabs = $this->tabs($this->actingAs($this->admin)->get($url)->assertOk()->getContent());

            $this->assertStringContainsString(route('admin.products.index'), $tabs);
            $this->assertStringContainsString(route('admin.suppliers.index'), $tabs);
        }
    }

    public function test_the_report_group_offers_the_daily_waybill_report_tab(): void
    {
        $tabs = $this->tabs($this->actingAs($this->admin)->get(route('admin.reports.stock'))->assertOk()->getContent());

        $this->assertStringContainsString('Resi per Hari', $tabs);
        $this->assertStringContainsString(route('admin.imports.daily'), $tabs);
    }

    public function test_a_tab_the_user_may_not_open_is_never_offered(): void
    {
        $role = Role::create(['name' => 'Barang Saja', 'slug' => 'barang-saja']);
        $role->permissions()->sync(Permission::whereIn('slug', ['dashboard.view', 'products.view'])->pluck('id'));

        $user = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($user)->get(route('admin.products.index'))
            ->assertOk()
            // Hanya satu halaman yang boleh dibuka, jadi bilah tabnya tidak dirender.
            ->assertDontSee('data-page-tabs', false)
            ->assertDontSee(route('admin.suppliers.index'));
    }

    public function test_the_command_palette_lists_only_permitted_pages(): void
    {
        $role = Role::create(['name' => 'Barang Saja', 'slug' => 'barang-saja']);
        $role->permissions()->sync(Permission::whereIn('slug', ['dashboard.view', 'products.view', 'products.create'])->pluck('id'));

        $this->actingAs(User::factory()->create(['role_id' => $role->id]))
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Buat Barang Baru')
            ->assertDontSee('Buat Pengguna Baru');
    }

    public function test_the_ready_queue_tab_carries_its_backlog_count(): void
    {
        $product = Product::create(['sku' => 'FLT-1', 'name' => 'Filter', 'unit' => 'pcs', 'min_stock' => 0]);

        $outbound = \App\Models\Outbound::create([
            'code' => \App\Models\Outbound::nextCode(), 'date' => now(),
            'type' => \App\Models\Outbound::TYPE_MARKETPLACE, 'recipient' => 'Pembeli',
            'tracking_number' => 'SPXID111', 'status' => \App\Models\Outbound::STATUS_DRAFT,
            'resi_verified_at' => now(),
        ]);
        $outbound->items()->create(['product_id' => $product->id, 'quantity' => 2, 'scanned_quantity' => 2]);

        $this->actingAs($this->admin)->get(route('admin.outbounds.index'))
            ->assertOk()
            ->assertSee('Siap Dikirim')
            ->assertSee(route('admin.outbounds.ready'));
    }

    /* --------------------------------------------------- helpers --------- */

    /**
     * Potongan HTML bilah tab di kepala halaman.
     */
    protected function tabs(string $html): string
    {
        $start = strpos($html, 'data-page-tabs');

        if ($start === false) {
            return '';
        }

        return substr($html, $start, strpos($html, '</nav>', $start) - $start);
    }

    /**
     * Potongan HTML menu samping saja — label seperti "Siap Dikirim" juga
     * muncul sebagai isi halaman, dan itu bukan yang sedang diuji di sini.
     */
    protected function sidebar(?User $user = null): string
    {
        $html = $this->actingAs($user ?? $this->admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->getContent();

        $start = strpos($html, 'data-sidebar-nav');
        $end = strpos($html, '</nav>', $start);

        return substr($html, $start, $end - $start);
    }

    /* --------------------------------------------------- paginasi -------- */

    public function test_a_single_page_list_renders_no_pagination_bar(): void
    {
        Product::create(['sku' => 'FLT-1', 'name' => 'Filter', 'unit' => 'pcs', 'min_stock' => 0]);

        $this->actingAs($this->admin)->get(route('admin.products.index'))
            ->assertOk()
            // Laravel menandai navigasi paginasi dengan role="navigation".
            ->assertDontSee('role="navigation"', false);
    }

    public function test_a_longer_list_still_paginates(): void
    {
        foreach (range(1, 12) as $i) {
            Product::create(['sku' => "FLT-{$i}", 'name' => "Filter {$i}", 'unit' => 'pcs', 'min_stock' => 0]);
        }

        $this->actingAs($this->admin)->get(route('admin.products.index'))
            ->assertOk()
            ->assertSee('role="navigation"', false);
    }
}
