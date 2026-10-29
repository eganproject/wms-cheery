<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShipmentImport;
use App\Models\ShipmentOrder;
use App\Support\DateRange;
use App\Support\PackingList;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

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

    public function __invoke(Request $request): View
    {
        $import = $request->filled('import')
            ? ShipmentImport::find($request->integer('import'))
            : null;

        $list = PackingList::for($this->filtered($request, $import));

        $show = array_key_exists((string) $request->input('show'), self::SHOW) ? (string) $request->input('show') : '';
        $search = trim((string) $request->input('search'));

        $rows = $list->rows
            ->when($show === 'sisa', fn ($rows) => $rows->where('remaining', '>', 0))
            ->when($show === 'selesai', fn ($rows) => $rows->where('remaining', 0))
            ->when($search !== '', fn ($rows) => $rows->filter(fn (array $row) => str_contains(mb_strtolower($row['sku'].' '.$row['name']), mb_strtolower($search))))
            ->values();

        $distinct = fn (string $column) => ShipmentOrder::query()
            ->whereNotNull($column)->where($column, '!=', '')
            ->distinct()->orderBy($column)->pluck($column);

        return view('admin.orders.packing', [
            'list' => $list,
            'rows' => $rows,
            'show' => $show,
            'import' => $import,
            'imports' => ShipmentImport::query()->latest('id')->limit(30)->get(['id', 'filename', 'created_at', 'order_count']),
            'marketplaces' => $distinct('marketplace'),
            'stores' => $distinct('store_name'),
            'couriers' => $distinct('courier'),
        ]);
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
