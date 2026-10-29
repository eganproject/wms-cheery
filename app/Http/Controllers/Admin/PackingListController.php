<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Models\Product;
use App\Models\ShipmentImport;
use App\Models\ShipmentOrder;
use App\Services\TableExport;
use App\Support\DateRange;
use App\Support\PackingList;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Packing list: total barang yang harus dikemas dari resi yang diimport,
 * dan sisanya yang belum lolos QC Kemas.
 *
 * Halaman ini hanya membaca. Resi yang sudah dikirim atau dibatalkan tidak
 * dihitung — barangnya tidak lagi perlu dikemas.
 */
class PackingListController extends Controller implements HasMiddleware
{
    public const SHOW = [
        '' => 'Semua barang',
        'sisa' => 'Masih ada sisa',
        'selesai' => 'Sudah lengkap',
    ];

    public static function middleware(): array
    {
        return [
            new Middleware('can:imports.view'),
        ];
    }

    public function index(Request $request): View
    {
        $data = $this->build($request);

        $distinct = fn (string $column) => ShipmentOrder::filterOptions($column);

        return view('admin.orders.packing', array_merge($data, [
            'imports' => ShipmentImport::query()->latest('id')->limit(30)->get(['id', 'filename', 'created_at', 'order_count']),
            'marketplaces' => $distinct('marketplace'),
            'stores' => $distinct('store_name'),
            'couriers' => $distinct('courier'),
        ]));
    }

    /**
     * Excel dengan saringan dan mode ringkasan yang sama dengan layar.
     * Lembar kedua memuat resi yang belum tuntas per barang.
     */
    public function excel(Request $request): StreamedResponse
    {
        ['rows' => $rows, 'list' => $list, 'mode' => $mode] = $data = $this->build($request);
        $bySku = $mode === PackingList::BY_SKU;

        $export = TableExport::make('Packing List');

        foreach ($this->describe($request, $data) as $line) {
            $export->note($line);
        }

        $export->note(sprintf(
            '%s resi · %s unit harus dikemas · %s sudah QC · %s sisa',
            $list->totals['orders'], $list->totals['ordered'], $list->totals['scanned'], $list->totals['remaining'],
        ));

        $export->sheet('Packing List', [
            ['No', 'number'],
            ['SKU', 'text'],
            ['Nama Barang', 'text'],
            ['Keterangan', 'text'],
            ['Jumlah Resi', 'number'],
            ['Total Qty', 'sum'],
            ['Sudah QC', 'sum'],
            ['Sisa', 'sum'],
            ['Resi Belum Tuntas', 'number'],
        ], $rows->values()->map(fn (array $row, int $index) => [
            $index + 1,
            $row['sku'],
            $row['name'],
            $this->remark($row, $bySku),
            $row['orders'],
            $row['ordered'],
            $row['scanned'],
            $row['remaining'],
            $row['open_orders'],
        ]));

        $export->sheet('Resi Belum Tuntas', [
            ['SKU', 'text'],
            ['Nama Barang', 'text'],
            ['No. Resi', 'text'],
        ], (function () use ($rows) {
            foreach ($rows as $row) {
                foreach ($row['open_resi'] as $resi) {
                    yield [$row['sku'], $row['name'], $resi];
                }

                if ($row['open_orders'] > count($row['open_resi'])) {
                    yield [$row['sku'], $row['name'], '… dan '.($row['open_orders'] - count($row['open_resi'])).' resi lainnya'];
                }
            }
        })());

        return $export->download($this->filename($request, 'xlsx'));
    }

    /**
     * PDF untuk dicetak dan dibawa packer: daftar barang dengan kotak centang.
     */
    public function pdf(Request $request): Response
    {
        $data = $this->build($request);

        $pdf = Pdf::loadView('admin.orders.packing-pdf', array_merge($data, [
            'filters' => $this->describe($request, $data),
            'images' => $request->boolean('gambar', true) ? $this->thumbnails($data['rows']) : [],
        ]))->setPaper('a4', 'portrait');

        return $pdf->download($this->filename($request, 'pdf'));
    }

    /**
     * Daftar dan saringan yang sama untuk layar, Excel, dan PDF.
     *
     * @return array<string, mixed>
     */
    protected function build(Request $request): array
    {
        $import = $request->filled('import')
            ? ShipmentImport::find($request->integer('import'))
            : null;

        $mode = $request->input('per') === PackingList::BY_PRODUCT ? PackingList::BY_PRODUCT : PackingList::BY_SKU;
        $list = PackingList::for($this->filtered($request, $import), $mode);

        $show = array_key_exists((string) $request->input('show'), self::SHOW) ? (string) $request->input('show') : '';
        $search = trim((string) $request->input('search'));

        $rows = $list->rows
            ->when($show === 'sisa', fn ($rows) => $rows->where('remaining', '>', 0))
            ->when($show === 'selesai', fn ($rows) => $rows->where('remaining', 0))
            ->when($search !== '', fn ($rows) => $rows->filter(fn (array $row) => str_contains(mb_strtolower($row['sku'].' '.$row['name']), mb_strtolower($search))))
            ->values();

        return [
            'list' => $list,
            'rows' => $rows,
            'show' => $show,
            'mode' => $mode,
            'import' => $import,
        ];
    }

    /**
     * Saringan yang berlaku, dalam kalimat pendek untuk kepala berkas.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    protected function describe(Request $request, array $data): array
    {
        $parts = [];

        if ($data['import']) {
            $parts[] = 'Batch: '.$data['import']->filename.' ('.$data['import']->created_at->format('d/m/Y H:i').')';
        } else {
            $parts[] = 'Tanggal unggah: '.DateRange::fromRequest($request)->label();
        }

        foreach (['marketplace' => 'Marketplace', 'store' => 'Toko', 'courier' => 'Kurir'] as $key => $label) {
            if ($request->filled($key)) {
                $parts[] = $label.': '.$request->string($key);
            }
        }

        $parts[] = 'Ringkas per: '.($data['mode'] === PackingList::BY_PRODUCT ? 'barang di rak (paket dipecah)' : 'SKU di resi');

        if ($data['show'] !== '') {
            $parts[] = 'Tampilkan: '.mb_strtolower(self::SHOW[$data['show']]);
        }

        if ($request->filled('search')) {
            $parts[] = 'Cari: '.trim((string) $request->input('search'));
        }

        return [implode(' · ', $parts)];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function remark(array $row, bool $bySku): string
    {
        return collect([
            $row['matched'] ? null : 'SKU belum terdaftar',
            $row['from_bundle'] ? ($bySku ? 'Paket bundling' : 'Termasuk isi paket') : null,
        ])->filter()->implode(', ');
    }

    protected function filename(Request $request, string $extension): string
    {
        $mode = $request->input('per') === PackingList::BY_PRODUCT ? '-per-barang' : '';

        return 'packing-list'.$mode.'-'.now()->format('Y-m-d-Hi').'.'.$extension;
    }

    /**
     * Gambar kecil tiap barang sebagai data URI, supaya PDF tidak perlu
     * membaca berkas asli yang bisa berukuran besar. Hasil kecilnya disimpan,
     * sehingga cetakan berikutnya cepat.
     *
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $rows
     * @return array<string, string> image_path => data URI
     */
    protected function thumbnails($rows): array
    {
        if (! function_exists('imagecreatefromstring')) {
            return [];
        }

        $disk = Storage::disk(Product::IMAGE_DISK);
        $images = [];

        foreach ($rows->pluck('image_path')->filter()->unique() as $path) {
            try {
                if (! $disk->exists($path)) {
                    continue;
                }

                $cache = 'pdf-thumbs/'.md5($path.'|'.$disk->lastModified($path)).'.jpg';

                if (! Storage::disk('local')->exists($cache)) {
                    $source = @imagecreatefromstring($disk->get($path));

                    if (! $source) {
                        continue;
                    }

                    $size = 120;
                    $width = imagesx($source);
                    $height = imagesy($source);
                    $side = min($width, $height);
                    $thumb = imagecreatetruecolor($size, $size);
                    imagefill($thumb, 0, 0, imagecolorallocate($thumb, 255, 255, 255));
                    imagecopyresampled($thumb, $source, 0, 0, intdiv($width - $side, 2), intdiv($height - $side, 2), $size, $size, $side, $side);

                    ob_start();
                    imagejpeg($thumb, null, 80);
                    Storage::disk('local')->put($cache, (string) ob_get_clean());
                    imagedestroy($thumb);
                    imagedestroy($source);
                }

                $images[$path] = 'data:image/jpeg;base64,'.base64_encode(Storage::disk('local')->get($cache));
            } catch (\Throwable) {
                // Gambar yang rusak tidak boleh menggagalkan cetakan.
                continue;
            }
        }

        return $images;
    }

    /**
     * Resi yang dihitung. Bila satu batch import dipilih, tanggal unggah
     * diabaikan: batch itu sendiri sudah menjadi batasnya.
     */
    protected function filtered(Request $request, ?ShipmentImport $import): Builder
    {
        return ShipmentOrder::query()
            ->when($import, fn (Builder $query) => $query->where('shipment_import_id', $import->id))
            ->when(! $import, fn (Builder $query) => $query->dateBetween(DateRange::fromRequest($request)))
            ->when($request->filled('marketplace'), fn (Builder $query) => $query->where('marketplace', $request->string('marketplace')))
            ->when($request->filled('store'), fn (Builder $query) => $query->where('store_name', $request->string('store')))
            ->when($request->filled('courier'), fn (Builder $query) => $query->where('courier', $request->string('courier')));
    }
}
