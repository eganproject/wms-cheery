<?php

namespace Tests\Feature\Admin;

use App\Models\Inbound;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/**
 * Export barang masuk: satu berkas berisi ringkasan, rekap per barang, daftar
 * dokumen, dan detail baris — semuanya mengikuti filter yang aktif di halaman.
 */
class InboundExportTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Product $oli;

    protected Product $rem;

    protected Supplier $astra;

    protected Supplier $sinar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@cheery.test')->firstOrFail();

        $this->oli = Product::create(['sku' => '00123', 'name' => 'Filter Oli Standar', 'unit' => 'pcs', 'min_stock' => 0, 'category' => 'Filter']);
        $this->rem = Product::create(['sku' => 'KMP-REM-DPN', 'name' => 'Kampas Rem Depan', 'unit' => 'set', 'min_stock' => 0, 'category' => 'Rem']);

        $this->astra = Supplier::create(['code' => 'SUP-1', 'name' => 'PT Astra Parts', 'is_active' => true]);
        $this->sinar = Supplier::create(['code' => 'SUP-2', 'name' => 'CV Sinar Jaya', 'is_active' => true]);
    }

    public function test_the_workbook_has_a_summary_and_three_tables(): void
    {
        $this->inbound('2026-10-01', $this->astra, [[$this->oli, 10, 1]]);

        $book = $this->readExport(['range' => 'semua']);

        $this->assertSame(['Ringkasan', 'Rekap Barang', 'Dokumen', 'Detail Barang'], $book->getSheetNames());
        $this->assertSame('Ringkasan', $book->getActiveSheet()->getTitle());
    }

    public function test_the_summary_totals_and_groups_follow_the_data(): void
    {
        $this->inbound('2026-10-01', $this->astra, [[$this->oli, 10, 1], [$this->rem, 4, 0]], post: true);
        $this->inbound('2026-10-02', $this->sinar, [[$this->oli, 6, 2]]);

        $rows = $this->rowsOf($this->readExport(['range' => 'semua']), 'Ringkasan');
        $values = $this->labelled($rows);

        $this->assertEquals(2, $values['Total Dokumen']);
        $this->assertEquals(3, $values['Total Baris Barang']);
        $this->assertEquals(2, $values['Jumlah SKU Berbeda']);
        $this->assertEquals(2, $values['Jumlah Pemasok']);
        $this->assertEquals(20, $values['Total Qty Diterima']);
        $this->assertEquals(3, $values['Total Qty Rusak']);
        $this->assertEquals(17, $values['Total Qty Layak']);

        // Rekap per pemasok: Astra 14 unit, Sinar 6 unit.
        $this->assertEquals(14, $values['PT Astra Parts']);
        $this->assertEquals(6, $values['CV Sinar Jaya']);
        $this->assertArrayHasKey('Diposting', $values);
        $this->assertArrayHasKey('Draft', $values);
    }

    public function test_the_tables_carry_documents_lines_and_product_totals(): void
    {
        $this->inbound('2026-10-01', $this->astra, [[$this->oli, 10, 1], [$this->rem, 4, 0]], post: true);
        $this->inbound('2026-10-02', $this->sinar, [[$this->oli, 6, 2]]);

        $book = $this->readExport(['range' => 'semua']);

        $documents = array_slice($this->rowsOf($book, 'Dokumen'), 5);
        $this->assertCount(2, $documents);
        $this->assertSame('PT Astra Parts', $documents[0][3]);
        $this->assertSame('Diposting', $documents[0][5]);
        $this->assertEquals(14, $documents[0][7]);
        $this->assertEquals(13, $documents[0][9]);

        $details = array_slice($this->rowsOf($book, 'Detail Barang'), 5);
        $this->assertCount(3, $details);
        // SKU berawalan nol tetap teks, tidak berubah jadi angka 123.
        $this->assertSame('00123', $details[0][6]);

        $products = $this->rowsOf($book, 'Rekap Barang');
        $this->assertSame('No', $products[4][0]);
        $oli = $products[5];
        $this->assertSame('00123', $oli[1]);
        $this->assertEquals(2, $oli[5]);
        $this->assertEquals(16, $oli[6]);
        $this->assertEquals(3, $oli[7]);
        $this->assertEquals(13, $oli[8]);
        // Hanya dokumen yang sudah diposting yang menambah stok.
        $this->assertEquals(10, $oli[9]);
        $this->assertSame('TOTAL', $products[7][0]);
        $this->assertEquals(20, $products[7][6]);
    }

    public function test_the_export_follows_the_active_filters(): void
    {
        $this->inbound('2026-10-01', $this->astra, [[$this->oli, 10, 0]], post: true);
        $this->inbound('2026-10-05', $this->sinar, [[$this->rem, 3, 0]]);

        $book = $this->readExport(['from' => '2026-10-04', 'to' => '2026-10-06']);
        $values = $this->labelled($this->rowsOf($book, 'Ringkasan'));

        $this->assertEquals(1, $values['Total Dokumen']);
        $this->assertEquals(3, $values['Total Qty Diterima']);
        $this->assertSame('04 Oktober 2026 s/d 06 Oktober 2026', $values['Periode']);

        $values = $this->labelled($this->rowsOf($this->readExport(['range' => 'semua', 'status' => 'posted']), 'Ringkasan'));
        $this->assertEquals(10, $values['Total Qty Diterima']);
        $this->assertSame('Diposting', $values['Status']);

        $values = $this->labelled($this->rowsOf($this->readExport(['range' => 'semua', 'search' => 'Sinar']), 'Ringkasan'));
        $this->assertEquals(3, $values['Total Qty Diterima']);
        $this->assertSame('Sinar', $values['Pencarian']);
    }

    public function test_an_empty_result_still_downloads(): void
    {
        $book = $this->readExport(['from' => '2020-01-01', 'to' => '2020-01-02']);
        $values = $this->labelled($this->rowsOf($book, 'Ringkasan'));

        $this->assertEquals(0, $values['Total Dokumen']);
        $this->assertSame('Tidak ada data untuk filter ini.', $this->rowsOf($book, 'Dokumen')[5][0]);
    }

    public function test_exporting_needs_its_own_permission(): void
    {
        $role = Role::firstOrCreate(
            ['slug' => 'pengamat'],
            ['name' => 'Pengamat', 'description' => 'Hanya melihat.', 'is_super_admin' => false],
        );
        $role->permissions()->sync(Permission::whereIn('slug', ['dashboard.view', 'inbounds.view'])->pluck('id'));
        $viewer = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($viewer)->get(route('admin.inbounds.export'))->assertForbidden();
        $this->actingAs($viewer)->get(route('admin.inbounds.index'))
            ->assertOk()
            ->assertDontSee(route('admin.inbounds.export'));
    }

    public function test_the_index_button_carries_the_current_filters(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.inbounds.index', ['status' => 'posted', 'from' => '2026-10-01', 'to' => '2026-10-31']))
            ->assertOk()
            ->assertSee(route('admin.inbounds.export', ['status' => 'posted', 'from' => '2026-10-01', 'to' => '2026-10-31']));
    }

    /* --------------------------------------------------------- helpers --- */

    /**
     * @param  array<int, array{0: Product, 1: int, 2: int}>  $lines
     */
    protected function inbound(string $date, Supplier $supplier, array $lines, bool $post = false): Inbound
    {
        $inbound = Inbound::create([
            'code' => Inbound::nextCode(),
            'date' => $date,
            'supplier_id' => $supplier->id,
            'reference' => 'SJ-'.$date,
            'status' => Inbound::STATUS_DRAFT,
            'user_id' => $this->admin->id,
        ]);

        foreach ($lines as [$product, $quantity, $damaged]) {
            $inbound->items()->create(['product_id' => $product->id, 'quantity' => $quantity, 'damaged_quantity' => $damaged]);
        }

        if ($post) {
            $this->actingAs($this->admin)->post(route('admin.inbounds.submit', $inbound));
            $this->assertTrue($inbound->refresh()->isPosted());
        }

        return $inbound;
    }

    /**
     * @param  array<string, string>  $query
     */
    protected function readExport(array $query): Spreadsheet
    {
        $response = $this->actingAs($this->admin)->get(route('admin.inbounds.export', $query));

        $response->assertOk();
        $this->assertStringContainsString('barang-masuk-', $response->headers->get('content-disposition'));

        $path = tempnam(sys_get_temp_dir(), 'inbound').'.xlsx';
        file_put_contents($path, $response->streamedContent());

        return IOFactory::load($path);
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    protected function rowsOf(Spreadsheet $book, string $sheet): array
    {
        return $book->getSheetByName($sheet)->toArray(null, true, false, false);
    }

    /**
     * Label di kolom A dan nilai di kolom B. Untuk tabel rekap, kolom B adalah
     * jumlah dokumen, jadi baris pemasok dibaca dari kolom D (qty diterima).
     *
     * @param  array<int, array<int, mixed>>  $rows
     * @return array<string, mixed>
     */
    protected function labelled(array $rows): array
    {
        $values = [];
        $inBreakdown = false;

        foreach ($rows as $row) {
            $label = $row[0] ?? null;

            if (! is_string($label)) {
                continue;
            }

            if (str_starts_with($label, 'REKAP PER')) {
                $inBreakdown = true;

                continue;
            }

            if ($inBreakdown) {
                if (($row[3] ?? null) !== null) {
                    $values[$label] ??= $row[3];
                }
            } elseif (($row[1] ?? null) !== null) {
                $values[$label] = $row[1];
            }
        }

        return $values;
    }
}
