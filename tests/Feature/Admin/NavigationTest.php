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
 * Struktur navigasi: menu samping dikelompokkan menurut pekerjaan, dan halaman
 * yang berkaitan digabung di bawah satu menu lalu dibuka lewat tab.
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

        // Menu samping dibuat pendek: halaman berkaitan menjadi tab.
        foreach (['Beranda', 'Persetujuan', 'Pesanan', 'Pre Order', 'Stasiun Gudang', 'Penerimaan',
            'Penjualan Manual', 'Koreksi Stok', 'Barang', 'Pemasok', 'Absensi', 'Karyawan',
            'Laporan Stok', 'Laporan Penjualan', 'Pengguna &amp; Akses', 'Aplikasi &amp; Integrasi'] as $entry) {
            $this->assertStringContainsString('>'.$entry, preg_replace('/>\s+/', '>', $sidebar));
        }

        // Halaman yang sudah menjadi tab tidak lagi punya menu sendiri.
        foreach (['Packing List', 'Import Resi', 'QC Kemas', 'Serah Terima Paket', 'Lama PO', 'Stok Opname',
            'Barang Rusak', 'Mutasi Stok', 'Kebutuhan Restock', 'Resi per Ekspedisi', 'Hak Akses',
            'Akses API Stok', 'Jam Kerja', 'Rekap'] as $entry) {
            $this->assertStringNotContainsString($entry, $sidebar);
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

    public function test_related_pages_open_as_tabs_under_one_menu(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.movements.index'))->assertOk()->getContent();
        $tabs = $this->tabs($html);

        foreach (['reports.stock', 'movements.index', 'reports.restock'] as $route) {
            $this->assertStringContainsString('href="'.route('admin.'.$route).'"', $tabs);
        }
        $this->assertMatchesRegularExpression(
            '/href="'.preg_quote(route('admin.movements.index'), '/').'"[^>]*aria-current="page"/', $tabs);

        // Menu samping menunjuk ke tab pertama dan tetap aktif di tab mana pun.
        $this->assertMatchesRegularExpression(
            '/href="'.preg_quote(route('admin.reports.stock'), '/').'"[^>]*aria-current="page"/', $this->sidebarOf($html));

        // Breadcrumb: kelompok › menu › tab.
        $this->assertStringContainsString('Laporan Stok', $html);
        $this->assertMatchesRegularExpression('/id="page-heading"[^>]*>Mutasi Stok</', $html);
    }

    public function test_tabs_the_user_may_not_open_are_hidden(): void
    {
        $role = Role::create(['name' => 'Laporan', 'slug' => 'laporan']);
        $role->permissions()->sync(Permission::whereIn('slug', ['dashboard.view', 'movements.view', 'reports.view'])->pluck('id'));
        $user = User::factory()->create(['role_id' => $role->id]);

        $tabs = $this->tabs($this->actingAs($user)->get(route('admin.movements.index'))->assertOk()->getContent());
        $this->assertStringContainsString(route('admin.reports.stock'), $tabs);

        // Hanya satu tab yang boleh dibuka: bilah tab tidak perlu tampil.
        $role->permissions()->sync(Permission::whereIn('slug', ['dashboard.view', 'movements.view'])->pluck('id'));
        $html = $this->actingAs($user->fresh())->get(route('admin.movements.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-page-tabs', $html);
        $this->assertStringContainsString('href="'.route('admin.movements.index').'"', $this->sidebarOf($html));
    }

    public function test_detail_and_standalone_pages_show_no_tab_bar(): void
    {
        foreach ([route('admin.products.index'), route('admin.suppliers.index'), route('admin.imports.create')] as $url) {
            $this->actingAs($this->admin)->get($url)->assertOk()->assertDontSee('data-page-tabs', false);
        }
    }

    public function test_every_tab_page_renders_its_tab_bar(): void
    {
        foreach (['orders.index', 'orders.packing', 'imports.index', 'orders.deadlines', 'po-periods.index',
            'outbounds.marketplace', 'outbounds.handover', 'returns.marketplace', 'inbounds.index', 'returns.index',
            'opnames.index', 'adjustments.index', 'disposals.index', 'attendance.index', 'attendance-leaves.index',
            'attendance.report', 'employees.index', 'work-shifts.index', 'attendance-settings.index',
            'reports.stock', 'movements.index', 'reports.restock', 'reports.owner', 'imports.daily', 'imports.couriers',
            'users.index', 'roles.index', 'permissions.index', 'mobile-app.index', 'stock-api-access.index'] as $route) {
            $html = $this->actingAs($this->admin)->get(route('admin.'.$route))->assertOk()->getContent();

            $this->assertStringContainsString('data-page-tabs', $html, $route);
            $this->assertMatchesRegularExpression(
                '/href="'.preg_quote(route('admin.'.$route), '/').'"[^>]*aria-current="page"/', $this->tabs($html), $route);
        }
    }

    public function test_the_command_palette_still_finds_pages_inside_tabs(): void
    {
        $this->actingAs($this->admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Mutasi Stok')
            ->assertSee('Rekap Absensi')
            ->assertSee('Buat Penerimaan Retur Baru');
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

        $html = $this->actingAs($this->admin)->get(route('admin.outbounds.marketplace'))->assertOk()->getContent();

        // Tab Serah Terima Paket membawa jumlah antrean, dan menu Stasiun
        // Gudang di samping ikut menampilkannya.
        $this->assertMatchesRegularExpression(
            '/href="'.preg_quote(route('admin.outbounds.handover'), '/').'".*?Serah Terima Paket\s*<span[^>]*>1</s', $this->tabs($html));
        $this->assertMatchesRegularExpression('/Stasiun Gudang<\/span>\s*<span[^>]*>1</', $this->sidebarOf($html));
    }

    /* --------------------------------------------------- helpers --------- */

    /**
     * Potongan HTML menu samping saja — label seperti "Serah Terima Paket" juga
     * muncul sebagai isi halaman, dan itu bukan yang sedang diuji di sini.
     */
    protected function sidebar(?User $user = null): string
    {
        $html = $this->actingAs($user ?? $this->admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->getContent();

        return $this->sidebarOf($html);
    }

    protected function sidebarOf(string $html): string
    {
        $start = strpos($html, 'data-sidebar-nav');
        $end = strpos($html, '</nav>', $start);

        return substr($html, $start, $end - $start);
    }

    protected function tabs(string $html): string
    {
        $start = strpos($html, 'data-page-tabs');
        $this->assertNotFalse($start, 'Bilah tab tidak ditemukan.');

        return substr($html, $start, strpos($html, '</nav>', $start) - $start);
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
        foreach (range(1, 30) as $i) {
            Product::create(['sku' => "FLT-{$i}", 'name' => "Filter {$i}", 'unit' => 'pcs', 'min_stock' => 0]);
        }

        $this->actingAs($this->admin)->get(route('admin.products.index'))
            ->assertOk()
            ->assertSee('role="navigation"', false);
    }
}
