<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Models\Product;
use App\Models\ShipmentImport;
use App\Models\ShipmentOrder;
use App\Services\OrderPlanner;
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
    /** Jenis packing list. */
    public const DAILY = 'harian';

    public const ALL = 'semua';

    public const BY_UPLOAD = 'unggahan';

    public const TYPES = [
        self::DAILY => 'Harian (resi siap)',
        self::ALL => 'Keseluruhan (belum diproses)',
        self::BY_UPLOAD => 'Per unggahan',
    ];

    /** Batas kirim pada packing list harian. */
    public const DUE = [
        '' => 'Semua yang siap',
        'today' => 'Batas sampai hari ini',
        'tomorrow' => 'Sampai besok',
        'week' => 'Sampai 7 hari',
    ];

    /** Banyaknya resi yang ditampilkan di layar; Excel memuat semuanya. */
    public const RESI_ON_SCREEN = 300;

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

    public function index(Request $request, OrderPlanner $planner): View
    {
        $planner->refresh();
        $data = $this->build($request);
        $data['resi'] = $data['type'] === self::BY_UPLOAD ? collect() : $this->resiQuery($request, $data)->limit(self::RESI_ON_SCREEN)->get();

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
    public function excel(Request $request, OrderPlanner $planner): StreamedResponse
    {
        $planner->refresh();

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

        if ($data['type'] !== self::BY_UPLOAD) {
            $export->sheet('Daftar Resi', [
                ['No', 'number'],
                ['No. Resi', 'text'],
                ['No. Pesanan', 'text'],
                ['Batas Kirim', 'datetime'],
                ['Keterangan Batas', 'text'],
                ['Prioritas', 'text'],
                ['Jenis', 'text'],
                ['Tanggal Pesanan', 'datetime'],
                ['Kurir', 'text'],
                ['Isi Pesanan', 'text'],
                ['Kesiapan Stok', 'text'],
            ], (function () use ($request, $data) {
                $number = 0;

                foreach ($this->resiQuery($request, $data)->lazy(500) as $order) {
                    yield $this->resiRow(++$number, $order);
                }
            })());
        }

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
    public function pdf(Request $request, OrderPlanner $planner): Response
    {
        $planner->refresh();
        $data = $this->build($request);

        // Daftar resi (label yang diambil dari rak) hanya untuk packing list
        // harian; daftar keseluruhan bisa berisi puluhan ribu resi.
        $resi = $data['type'] === self::DAILY ? $this->resiQuery($request, $data)->limit(2000)->get() : collect();

        $pdf = Pdf::loadView('admin.orders.packing-pdf', array_merge($data, [
            'resi' => $resi,
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

        $type = $this->type($request);
        $import = $type === self::BY_UPLOAD ? $import : null;
        $due = array_key_exists((string) $request->input('batas'), self::DUE) ? (string) $request->input('batas') : '';

        $mode = $request->input('per') === PackingList::BY_PRODUCT ? PackingList::BY_PRODUCT : PackingList::BY_SKU;
        $list = PackingList::for($this->filtered($request, $import, $type, $due), $mode);

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
            'type' => $type,
            'due' => $due,
        ];
    }

    /**
     * Harian bila tidak dipilih; "per unggahan" bila halaman dibuka dengan
     * batch atau tanggal unggah (tautan lama dari Import Resi dan Pesanan).
     */
    protected function type(Request $request): string
    {
        $type = (string) $request->input('jenis');

        if (array_key_exists($type, self::TYPES)) {
            return $type;
        }

        return $request->hasAny(['import', 'from', 'to', 'range']) ? self::BY_UPLOAD : self::DAILY;
    }

    /**
     * Resi pada packing list, paling mendesak lebih dulu.
     *
     * @param  array<string, mixed>  $data
     */
    protected function resiQuery(Request $request, array $data): Builder
    {
        return $this->filtered($request, null, $data['type'], $data['due'])
            ->with(['items:id,shipment_order_id,sku,quantity'])
            ->mostUrgentFirst();
    }

    /**
     * @return array<int, mixed>
     */
    protected function resiRow(int $number, ShipmentOrder $order): array
    {
        return [
            $number,
            $order->tracking_number,
            $order->order_number,
            $order->ship_by,
            $order->deadlineState()[0] ?? 'Belum ada batas',
            $order->is_priority ? 'Ya' : '',
            OrderController::orderKind($order),
            $order->order_date,
            $order->courier,
            $order->items->map(fn ($item) => $item->sku.' x'.$item->quantity)->implode(', '),
            match ($order->stock_status) {
                OrderPlanner::READY => 'Siap dikemas',
                OrderPlanner::WAITING => 'Menunggu stok'.($order->stock_note ? ' ('.$order->stock_note.')' : ''),
                OrderPlanner::UNKNOWN_SKU => 'SKU tidak dikenal',
                default => '',
            },
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
        $parts = ['Jenis: '.self::TYPES[$data['type']]];

        if ($data['type'] === self::DAILY) {
            $parts[] = self::DUE[$data['due']].', urut batas kirim (prioritas lebih dulu)';
        } elseif ($data['type'] === self::ALL) {
            $parts[] = 'Semua resi yang belum mulai discan di QC Kemas, tanpa melihat stok';
        } elseif ($data['import']) {
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
        $type = '-'.$this->type($request);

        return 'packing-list'.$type.$mode.'-'.now()->format('Y-m-d-Hi').'.'.$extension;
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
    protected function filtered(Request $request, ?ShipmentImport $import, string $type = self::BY_UPLOAD, string $due = ''): Builder
    {
        return ShipmentOrder::query()
            ->when($type === self::DAILY, fn (Builder $query) => $query
                ->stockStatus(OrderPlanner::READY)
                ->when($due !== '', fn (Builder $query) => $query->deadline($due)))
            ->when($type === self::ALL, fn (Builder $query) => $query->notStarted())
            ->when($type === self::BY_UPLOAD && $import, fn (Builder $query) => $query->where('shipment_import_id', $import->id))
            ->when($type === self::BY_UPLOAD && ! $import, fn (Builder $query) => $query->dateBetween(DateRange::fromRequest($request)))
            ->when($request->filled('marketplace'), fn (Builder $query) => $query->where('marketplace', $request->string('marketplace')))
            ->when($request->filled('store'), fn (Builder $query) => $query->where('store_name', $request->string('store')))
            ->when($request->filled('courier'), fn (Builder $query) => $query->where('courier', $request->string('courier')));
    }
}
