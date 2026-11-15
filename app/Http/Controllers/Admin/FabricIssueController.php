<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Fabric;
use App\Models\FabricMovement;
use App\Services\FabricStockService;
use App\Services\TableExport;
use App\Support\DateRange;
use App\Support\Listing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Kain Keluar: riwayat kain yang keluar lewat scan, beserta pembatalannya.
 * Kain tidak bisa dikeluarkan dari halaman ini, hanya dari Scan Kain.
 */
class FabricIssueController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:fabrics.view', only: ['index', 'export']),
            new Middleware('can:fabrics.adjust', only: ['cancel']),
        ];
    }

    public function index(Request $request): View
    {
        $base = $this->filtered($request);

        $movements = (clone $base)
            ->with(['roll:id,code', 'fabric:id,sku,name,color,unit', 'user:id,name'])
            ->tap(fn ($query) => Listing::sort($query, $request, [
                'time' => fn ($query, $dir) => $query->orderBy('moved_at', $dir)->orderBy('id', $dir),
                'quantity' => 'quantity',
            ], ['time', 'desc']))
            ->paginate(Listing::perPage($request))
            ->withQueryString();

        $perFabric = (clone $base)
            ->whereNull('cancelled_at')
            ->selectRaw('fabric_id, SUM(quantity) AS total, COUNT(*) AS entries')
            ->groupBy('fabric_id')
            ->get()
            ->map(fn ($row) => ['fabric' => Fabric::find($row->fabric_id), 'total' => -(float) $row->total, 'entries' => (int) $row->entries])
            ->filter(fn ($row) => $row['fabric'])
            ->sortByDesc('total')
            ->values();

        return view('admin.fabric-issues.index', [
            'movements' => $movements,
            'perFabric' => $perFabric,
            'fabrics' => Fabric::query()->orderBy('sku')->get(['id', 'sku', 'name', 'color']),
            'destinations' => FabricMovement::query()->issues()->whereNotNull('destination')->distinct()->orderBy('destination')->pluck('destination'),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $range = DateRange::fromRequest($request);
        $rows = $this->filtered($request)
            ->with(['roll:id,code', 'fabric', 'user:id,name'])
            ->orderBy('moved_at')->orderBy('id')
            ->get();

        return TableExport::make('Kain Keluar')
            ->note('Tanggal: '.$range->label())
            ->sheet('Kain Keluar', [
                ['Waktu', 'datetime'], ['Kode Roll', 'text'], ['SKU Kain', 'text'], ['Nama Kain', 'text'], ['Satuan', 'text'],
                ['Jumlah Keluar', 'sum'], ['Sisa Roll', 'number'], ['Tujuan', 'text'], ['Catatan', 'text'], ['Discan oleh', 'text'], ['Status', 'text'],
            ], $rows->map(fn (FabricMovement $movement) => [
                $movement->moved_at, $movement->roll->code, $movement->fabric->sku, $movement->fabric->displayName(), $movement->fabric->unit,
                $movement->isCancelled() ? 0 : -$movement->quantity, $movement->balance_after, $movement->destination, $movement->note,
                $movement->user?->name, $movement->isCancelled() ? 'Dibatalkan' : 'Keluar',
            ]))
            ->download('kain-keluar-'.now()->format('Y-m-d').'.xlsx');
    }

    public function cancel(Request $request, FabricMovement $movement, FabricStockService $stock): RedirectResponse
    {
        $stock->cancel($movement, $request->user());
        $movement->load('roll');

        return back()->with('success', 'Kain keluar dibatalkan; jumlahnya kembali ke roll '.$movement->roll->code.'.');
    }

    protected function filtered(Request $request): Builder
    {
        $range = DateRange::fromRequest($request);
        $term = $request->string('search')->trim()->value();

        return FabricMovement::query()
            ->issues()
            ->when($term, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereHas('roll', fn (Builder $query) => $query->where('code', 'like', "%{$term}%"))
                ->orWhere('destination', 'like', "%{$term}%")
                ->orWhere('note', 'like', "%{$term}%")))
            ->when($request->filled('fabric_id'), fn (Builder $query) => $query->where('fabric_id', $request->integer('fabric_id')))
            ->when($request->filled('destination'), fn (Builder $query) => $query->where('destination', $request->string('destination')->value()))
            ->when($request->input('status') === 'batal', fn (Builder $query) => $query->whereNotNull('cancelled_at'))
            ->when($request->input('status') === 'keluar', fn (Builder $query) => $query->whereNull('cancelled_at'))
            ->tap(fn ($query) => DateRange::constrain($query, 'moved_at', $range->from, $range->to));
    }
}
