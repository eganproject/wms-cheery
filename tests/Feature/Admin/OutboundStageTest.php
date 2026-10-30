<?php

namespace Tests\Feature\Admin;

use App\Models\Inbound;
use App\Models\Outbound;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tahap dokumen pengiriman, dan daftar Penjualan Manual.
 *
 * Kolom status di basis data hanya mengenal draft, diajukan, ditolak, dan
 * diposting. Bagi paket marketplace itu terlalu kasar: satu kata "draft"
 * dipakai bersama oleh paket yang belum disentuh sama sekali dan paket yang
 * sudah selesai QC serta sedang mengantre di Serah Terima Paket — dua keadaan yang
 * menuntut tindakan sama sekali berbeda.
 */
class OutboundStageTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@cheery.test')->firstOrFail();

        $this->product = Product::create([
            'sku' => 'FLT-OLI-STD', 'name' => 'Filter Oli Standar',
            'unit' => 'pcs', 'min_stock' => 0,
        ]);

        $inbound = Inbound::create([
            'code' => Inbound::nextCode(), 'date' => now(), 'status' => Inbound::STATUS_DRAFT,
        ]);
        $inbound->items()->create(['product_id' => $this->product->id, 'quantity' => 100]);
        $this->actingAs($this->admin)->post(route('admin.inbounds.submit', $inbound));
    }

    /* ---------------------------------------------------- tiap tahap ----- */

    public function test_every_marketplace_stage_has_its_own_name(): void
    {
        $this->assertSame(Outbound::STAGE_NEED_RESI, $this->package(verified: false)->stage());
        $this->assertSame(Outbound::STAGE_SCANNING, $this->package(scanned: 1)->stage());
        $this->assertSame(Outbound::STAGE_HANDOVER, $this->package(scanned: 2)->stage());
        $this->assertSame(Outbound::STAGE_NEED_ITEMS, $this->package(quantity: 0)->stage());
    }

    /**
     * Inilah keadaan yang paling menyesatkan sebelumnya: paket yang sudah
     * lengkap dipindai dan mengantre di Serah Terima Paket tetap berlabel "Draft",
     * sama persis dengan dokumen yang belum disentuh siapa pun.
     */
    public function test_a_packed_marketplace_order_is_not_called_a_draft(): void
    {
        $ready = $this->package(scanned: 2);

        $this->assertSame(1, Outbound::awaitingHandover()->count());
        $this->assertSame('Menunggu serah terima', $ready->stageLabel());
        $this->assertNotSame('Draft', $ready->stageLabel());
    }

    /** Dokumen reguler tetap sederhana: draft ya draft. */
    public function test_a_regular_document_keeps_the_plain_label(): void
    {
        $regular = Outbound::create([
            'code' => Outbound::nextCode(), 'date' => now(),
            'type' => Outbound::TYPE_REGULAR, 'recipient' => 'Cabang Bandung',
            'status' => Outbound::STATUS_DRAFT,
        ]);

        $this->assertSame('Draft', $regular->stageLabel());
    }

    /** Keputusan penyetuju tidak boleh tertutup oleh tahap pemindaian. */
    public function test_a_rejected_package_still_reads_as_rejected(): void
    {
        $package = $this->package(scanned: 0, verified: false);
        $package->forceFill(['status' => Outbound::STATUS_REJECTED])->save();

        $this->assertSame('Ditolak', $package->refresh()->stageLabel());
    }

    /* ---------------------------------------------------- halaman -------- */

    /**
     * Penjualan Manual hanya memuat dokumen reguler. Paket marketplace dibuka
     * dari Pesanan, tidak ikut di daftar ini maupun export-nya.
     */
    public function test_the_manual_sales_list_leaves_marketplace_packages_out(): void
    {
        $package = $this->package(scanned: 2);
        $regular = $this->regular();

        $this->actingAs($this->admin)
            ->get(route('admin.outbounds.index'))
            ->assertOk()
            ->assertSee('Penjualan Manual')
            ->assertSee($regular->code)
            ->assertDontSee($package->code);
    }

    public function test_the_list_shows_the_stage_on_both_layouts(): void
    {
        $regular = $this->regular();
        $regular->forceFill(['status' => Outbound::STATUS_REJECTED])->save();

        $html = $this->actingAs($this->admin)
            ->get(route('admin.outbounds.index'))
            ->assertOk()
            ->getContent();

        // Tiga kali: badge di tabel, badge di kartu ponsel, dan satu pilihan di
        // saringan.
        $this->assertSame(3, substr_count($html, 'Ditolak'));
    }

    /* ---------------------------------------------------- saringan ------- */

    public function test_the_filter_offers_only_manual_sales_stages(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('admin.outbounds.index'))
            ->assertOk()
            ->getContent();

        foreach (['Draft', 'Menunggu persetujuan', 'Ditolak', 'Terkirim'] as $label) {
            $this->assertStringContainsString($label, $html, "Saringan kehilangan pilihan {$label}.");
        }

        foreach (['Perlu scan resi', 'Menunggu serah terima', 'Batal setelah dikirim'] as $label) {
            $this->assertStringNotContainsString($label, $html, "Tahap marketplace {$label} tidak relevan untuk penjualan manual.");
        }
    }

    public function test_the_draft_filter_returns_manual_drafts(): void
    {
        $regular = $this->regular();
        $packed = $this->package(scanned: 2);

        $this->actingAs($this->admin)
            ->get(route('admin.outbounds.index', ['status' => Outbound::STATUS_DRAFT]))
            ->assertOk()
            ->assertSee($regular->code)
            ->assertDontSee($packed->code);
    }

    /** Detail paket marketplace berada di bawah Pesanan, bukan Penjualan Manual. */
    public function test_a_marketplace_package_is_shown_under_orders(): void
    {
        $package = $this->package(scanned: 2);

        $this->actingAs($this->admin)
            ->get(route('admin.outbounds.show', $package))
            ->assertOk()
            ->assertSee('Pengiriman Pesanan')
            ->assertSee(route('admin.orders.index'));

        $regular = $this->regular();

        $this->actingAs($this->admin)
            ->get(route('admin.outbounds.show', $regular))
            ->assertOk()
            ->assertSee('Detail Penjualan Manual');
    }

    protected function regular(): Outbound
    {
        return Outbound::create([
            'code' => Outbound::nextCode(), 'date' => now(),
            'type' => Outbound::TYPE_REGULAR, 'recipient' => 'Toko Sebelah',
            'status' => Outbound::STATUS_DRAFT,
        ]);
    }

    /* ---------------------------------------------------- helpers -------- */

    protected function package(int $scanned = 0, bool $verified = true, int $quantity = 2): Outbound
    {
        $outbound = Outbound::create([
            'code' => Outbound::nextCode(), 'date' => now(),
            'type' => Outbound::TYPE_MARKETPLACE, 'recipient' => 'Andi Pratama',
            'marketplace' => 'Shopee',
            'tracking_number' => 'SPXID'.str_pad((string) (Outbound::count() + 1), 11, '0', STR_PAD_LEFT),
            'status' => Outbound::STATUS_DRAFT,
            'resi_verified_at' => $verified ? now() : null,
        ]);

        if ($quantity > 0) {
            $outbound->items()->create([
                'product_id' => $this->product->id,
                'quantity' => $quantity,
                'scanned_quantity' => $scanned,
            ]);
        }

        return $outbound->load('items');
    }
}
