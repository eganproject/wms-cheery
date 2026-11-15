<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Fabric;
use App\Models\FabricMovement;
use App\Models\FabricReceipt;
use App\Models\Supplier;
use App\Services\FabricStockService;
use App\Services\TableExport;
use App\Support\DateRange;
use App\Support\Listing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Kain Masuk: kain dari pemasok dicatat per roll. Setiap roll langsung
 * mendapat kode QR (SKU kain + nomor roll) untuk dicetak dan ditempel.
 */
class FabricReceiptController extends Controller implements HasMiddleware
{
    /** Batas roll dalam satu dokumen, supaya form dan label tetap ringan. */
    public const MAX_ROLLS = 500;

    public static function middleware(): array
    {
        return [
            new Middleware('can:fabrics.view', only: ['index', 'show', 'export']),
            new Middleware('can:fabrics.receive', only: ['create', 'store', 'destroy']),
        ];
    }

    public function index(Request $request): View
    {
        $receipts = $this->filtered($request)
            ->with(['supplier:id,name', 'user:id,name'])
            ->withCount('rolls')
            ->withSum('rolls', 'initial_quantity')
            ->tap(fn ($query) => Listing::sort($query, $request, [
                'code' => 'code',
                'date' => fn ($query, $dir) => $query->orderBy('date', $dir)->orderBy('id', $dir),
                'rolls' => 'rolls_count',
            ], ['date', 'desc']))
            ->paginate(Listing::perPage($request))
            ->withQueryString();

        return view('admin.fabric-receipts.index', [
            'receipts' => $receipts,
            'suppliers' => Supplier::query()->whereHas('fabricReceipts')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $range = DateRange::fromRequest($request);
        $receipts = $this->filtered($request)
            ->with(['supplier:id,name', 'user:id,name', 'rolls.fabric'])
            ->orderBy('date')->orderBy('id')
            ->get();

        return TableExport::make('Kain Masuk')
            ->note('Tanggal: '.$range->label())
            ->sheet('Roll Masuk', [
                ['Tanggal', 'date'], ['No. Dokumen', 'text'], ['Pemasok', 'text'], ['No. Surat Jalan', 'text'],
                ['Kode Roll', 'text'], ['SKU Kain', 'text'], ['Nama Kain', 'text'], ['Satuan', 'text'],
                ['Panjang Awal', 'sum'], ['Sisa Sekarang', 'sum'], ['Lot', 'text'], ['Lokasi', 'text'], ['Dicatat oleh', 'text'],
            ], $receipts->flatMap(fn (FabricReceipt $receipt) => $receipt->rolls->sortBy('code')->map(fn ($roll) => [
                $receipt->date, $receipt->code, $receipt->supplier?->name, $receipt->reference,
                $roll->code, $roll->fabric->sku, $roll->fabric->displayName(), $roll->fabric->unit,
                $roll->initial_quantity, $roll->quantity, $roll->lot, $roll->location, $receipt->user?->name,
            ])))
            ->download('kain-masuk-'.now()->format('Y-m-d').'.xlsx');
    }

    protected function filtered(Request $request): Builder
    {
        $range = DateRange::fromRequest($request);
        $term = $request->string('search')->trim()->value();

        return FabricReceipt::query()
            ->when($term, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('code', 'like', "%{$term}%")
                ->orWhere('reference', 'like', "%{$term}%")
                ->orWhereHas('rolls', fn (Builder $query) => $query->where('code', 'like', "%{$term}%"))))
            ->when($request->filled('supplier_id'), fn (Builder $query) => $query->where('supplier_id', $request->integer('supplier_id')))
            ->when($request->filled('fabric_id'), fn (Builder $query) => $query->whereHas('rolls', fn (Builder $query) => $query->where('fabric_id', $request->integer('fabric_id'))))
            ->tap(fn ($query) => DateRange::constrain($query, 'date', $range->from, $range->to));
    }

    public function create(Request $request): View
    {
        return view('admin.fabric-receipts.create', [
            'fabrics' => Fabric::query()->where('is_active', true)->orderBy('sku')
                ->get(['id', 'sku', 'name', 'color', 'unit', 'supplier_id'])
                ->map(fn (Fabric $fabric) => [
                    'id' => $fabric->id,
                    'sku' => $fabric->sku,
                    'name' => $fabric->displayName(),
                    'unit' => $fabric->unitShort(),
                    'supplier_id' => $fabric->supplier_id,
                ]),
            'suppliers' => Supplier::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'preselect' => $request->integer('fabric') ?: null,
        ]);
    }

    public function store(Request $request, FabricStockService $stock): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date', 'before_or_equal:today'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.fabric_id' => ['required', 'integer', 'exists:fabrics,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999'],
            'lines.*.rolls' => ['required', 'integer', 'min:1', 'max:'.self::MAX_ROLLS],
            'lines.*.lot' => ['nullable', 'string', 'max:60'],
            'lines.*.location' => ['nullable', 'string', 'max:60'],
        ], [
            'lines.required' => 'Tambahkan minimal satu baris kain.',
            'lines.*.quantity.gt' => 'Panjang/berat per roll harus lebih dari 0.',
        ], [
            'date' => 'tanggal',
            'lines.*.fabric_id' => 'kain',
            'lines.*.quantity' => 'panjang/berat per roll',
            'lines.*.rolls' => 'jumlah roll',
        ]);

        $total = collect($data['lines'])->sum(fn ($line) => (int) $line['rolls']);

        if ($total > self::MAX_ROLLS) {
            return back()->withInput()->withErrors(['lines' => 'Maksimal '.self::MAX_ROLLS." roll per dokumen; yang diisi {$total}. Bagi menjadi beberapa dokumen."]);
        }

        $receipt = $stock->receive($data, array_values($data['lines']), $request->user());

        return redirect()
            ->route('admin.fabric-receipts.show', $receipt)
            ->with('success', "Kain masuk {$receipt->code} tersimpan: {$total} roll. Cetak label QR lalu tempel di tiap roll.");
    }

    public function show(FabricReceipt $fabricReceipt): View
    {
        $fabricReceipt->load(['supplier', 'user', 'rolls' => fn ($query) => $query->with('fabric')->orderBy('fabric_id')->orderBy('number')]);
        $fabricReceipt->rolls->each->setRelation('receipt', $fabricReceipt);

        return view('admin.fabric-receipts.show', [
            'receipt' => $fabricReceipt,
            'used' => FabricMovement::query()
                ->whereIn('fabric_roll_id', $fabricReceipt->rolls->pluck('id'))
                ->where('type', '!=', FabricMovement::TYPE_IN)
                ->exists(),
        ]);
    }

    /**
     * Hapus dokumen salah input. Hanya bila belum ada kain yang keluar atau
     * dikoreksi dari roll-nya.
     */
    public function destroy(FabricReceipt $fabricReceipt): RedirectResponse
    {
        $rollIds = $fabricReceipt->rolls()->pluck('id');

        if (FabricMovement::query()->whereIn('fabric_roll_id', $rollIds)->where('type', '!=', FabricMovement::TYPE_IN)->exists()) {
            return back()->with('error', 'Sebagian roll di dokumen ini sudah dipakai (keluar atau dikoreksi), jadi dokumennya tidak bisa dihapus.');
        }

        DB::transaction(function () use ($fabricReceipt, $rollIds) {
            FabricMovement::query()->whereIn('fabric_roll_id', $rollIds)->delete();
            $fabricReceipt->rolls()->delete();
            $fabricReceipt->delete();
        });

        return redirect()->route('admin.fabric-receipts.index')->with('success', "Kain masuk {$fabricReceipt->code} dihapus.");
    }
}
