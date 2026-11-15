<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OutboundItem;
use App\Services\TableExport;
use App\Support\DateRange;
use App\Support\Listing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Rekap substitusi SKU di QC Kemas: barang pesanan mana yang dikirim dengan
 * barang lain, berapa unit, kenapa, dan oleh siapa.
 */
class OrderSubstitutionController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:outbounds.view'),
        ];
    }

    public function index(Request $request): View
    {
        $rows = $this->filtered($request)
            ->with(['outbound.shipmentOrder', 'product', 'substitutedFrom', 'substitutedBy'])
            ->tap(fn ($query) => Listing::sort($query, $request, [
                'date' => fn ($query, $dir) => $query->orderBy('substituted_at', $dir)->orderBy('id', $dir),
                'quantity' => 'quantity',
            ], ['date', 'desc']))
            ->paginate(Listing::perPage($request))
            ->withQueryString();

        return view('admin.orders.substitutions', [
            'rows' => $rows,
            'reasons' => OutboundItem::SUBSTITUTION_REASONS,
            'totalUnits' => (int) $this->filtered($request)->sum('quantity'),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $range = DateRange::fromRequest($request);

        $rows = $this->filtered($request)
            ->with(['outbound.shipmentOrder', 'product', 'substitutedFrom', 'substitutedBy'])
            ->orderBy('substituted_at')->orderBy('id')
            ->lazy(500)
            ->map(fn (OutboundItem $item) => [
                $item->substituted_at,
                $item->outbound?->tracking_number,
                $item->outbound?->shipmentOrder?->order_number,
                $item->outbound?->code,
                $item->substitutedFrom?->sku,
                $item->substitutedFrom?->name,
                $item->product?->sku,
                $item->product?->name,
                $item->quantity,
                $item->substitutionReasonLabel(),
                $item->substitutedBy?->name,
                $item->outbound?->isPosted() ? 'Dikirim' : 'Belum dikirim',
            ]);

        return TableExport::make('Substitusi SKU')
            ->note('Tanggal: '.$range->label())
            ->sheet('Substitusi', [
                ['Waktu', 'datetime'], ['Resi', 'text'], ['No. Pesanan', 'text'], ['Dokumen', 'text'],
                ['SKU Pesanan', 'text'], ['Nama Barang Pesanan', 'text'], ['SKU Pengganti', 'text'], ['Nama Pengganti', 'text'],
                ['Jumlah', 'sum'], ['Alasan', 'text'], ['Oleh', 'text'], ['Status', 'text'],
            ], $rows)
            ->download('substitusi-sku-'.now()->format('Y-m-d').'.xlsx');
    }

    protected function filtered(Request $request): Builder
    {
        $range = DateRange::fromRequest($request);

        return OutboundItem::query()
            ->whereNotNull('substituted_from_id')
            ->tap(fn (Builder $query) => DateRange::constrain($query, 'substituted_at', $range->from, $range->to))
            ->when($request->filled('reason'), fn (Builder $query) => $query->where('substitution_reason', $request->input('reason')))
            ->when($request->filled('search'), function (Builder $query) use ($request) {
                $term = '%'.trim((string) $request->input('search')).'%';

                $query->where(function (Builder $query) use ($term) {
                    $query->whereHas('product', fn (Builder $product) => $product->where('sku', 'like', $term)->orWhere('name', 'like', $term))
                        ->orWhereHas('substitutedFrom', fn (Builder $product) => $product->where('sku', 'like', $term)->orWhere('name', 'like', $term))
                        ->orWhereHas('outbound', fn (Builder $outbound) => $outbound->where('tracking_number', 'like', $term)->orWhere('code', 'like', $term));
                });
            });
    }
}
