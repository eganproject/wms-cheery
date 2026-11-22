<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FabricCutting;
use App\Models\FabricMovement;
use App\Models\FabricUsage;
use App\Models\SewingBatch;
use App\Models\Supplier;
use App\Services\FabricStockService;
use App\Services\TableExport;
use App\Support\CuttingReport;
use App\Support\Listing;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Cutting & WIP Jahit.
 *
 *   kain keluar (scan) → Cutting → serahkan hasil potong per varian ke
 *   penjahit (bertahap, jadi batch WIP Jahit) → penerimaan barang dari
 *   penjahit mengurangi batch → batch selesai.
 *
 * Tidak ada modul manufaktur: cukup isi qty dan klik.
 */
class FabricCuttingController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:fabrics.view', only: ['index', 'show', 'export', 'cuttingNote', 'batchNote']),
            new Middleware('can:fabrics.issue', only: ['handover', 'send', 'finish', 'destroyBatch']),
            new Middleware('can:fabrics.adjust', only: ['closeBatch']),
        ];
    }

    public function index(Request $request): View
    {
        if ($request->input('lihat') === 'rekap') {
            [$from, $to] = $this->period($request);

            return view('admin.fabric-cuttings.report', [
                'view' => 'rekap',
                'report' => CuttingReport::for($from, $to),
                'from' => $from,
                'to' => $to,
                'summary' => $this->summary(),
            ]);
        }

        $view = in_array($request->input('lihat'), ['gudang', 'jahit'], true) ? $request->input('lihat') : 'cutting';
        $status = (string) $request->input('status', 'terbuka');
        // Tautan lama: Hasil Potong difilter "di gudang" kini tab sendiri.
        if ($view === 'jahit' && $status === 'gudang') {
            [$view, $status] = ['gudang', 'terbuka'];
        }
        $term = $request->string('search')->trim()->value();

        $cuttings = $view === 'cutting'
            ? FabricCutting::query()
                ->with(['product:id,sku,name,image_path,product_group_id', 'product.group', 'group', 'employee:id,name', 'movements', 'batches.items'])
                ->when($status === 'terbuka', fn (Builder $query) => $query->open())
                ->when($status === 'selesai', fn (Builder $query) => $query->where('status', FabricCutting::STATUS_DONE))
                ->when($term, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                    ->where('code', 'like', "%{$term}%")
                    ->orWhereHas('product', fn (Builder $query) => $query->where('sku', 'like', "%{$term}%")->orWhere('name', 'like', "%{$term}%"))
                    ->orWhereHas('employee', fn (Builder $query) => $query->where('name', 'like', "%{$term}%"))))
                ->latest('id')
                ->paginate(Listing::perPage($request))
                ->withQueryString()
            : null;

        $batches = null;
        $received = collect();

        if ($view !== 'cutting') {
            $batches = SewingBatch::query()
                ->when($view === 'gudang', fn (Builder $query) => $query->inWarehouse())
                ->when($view === 'jahit', fn (Builder $query) => $query->atTailor())
                ->with(['supplier:id,name', 'cutting.product', 'cutting.group', 'items.product:id,sku,name,product_group_id', 'items.product.group'])
                ->when($request->filled('supplier_id'), fn (Builder $query) => $query->where('supplier_id', $request->integer('supplier_id')))
                ->when($term, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                    ->where('code', 'like', "%{$term}%")
                    ->orWhereHas('cutting', fn (Builder $query) => $query->where('code', 'like', "%{$term}%"))
                    ->orWhereHas('items.product', fn (Builder $query) => $query->where('sku', 'like', "%{$term}%")->orWhere('name', 'like', "%{$term}%"))))
                ->when($status !== 'semua', fn (Builder $query) => $query->notClosed())
                ->latest('id')
                ->get();

            $received = SewingBatch::receivedFor($batches->pluck('id'));
            $batches = $batches
                ->map(function (SewingBatch $batch) use ($received) {
                    $batch->setAttribute('received_map', $received->get($batch->id, collect()));
                    $batch->setAttribute('outstanding_total', $batch->outstanding($batch->received_map)->sum());

                    return $batch;
                })
                ->when($status === 'terbuka', fn ($rows) => $rows->filter(fn (SewingBatch $batch) => $batch->outstanding_total > 0))
                ->when($status === 'selesai', fn ($rows) => $rows->filter(fn (SewingBatch $batch) => $batch->closed_at || $batch->outstanding_total === 0))
                ->values();
        }

        return view('admin.fabric-cuttings.index', [
            'view' => $view,
            'status' => $status,
            'cuttings' => $cuttings,
            'batches' => $batches,
            'tailors' => Supplier::query()->where('is_tailor', true)->orderBy('name')->get(['id', 'name']),
            'summary' => $this->summary(),
        ]);
    }

    public function show(FabricCutting $fabricCutting): View
    {
        $cutting = $fabricCutting->load([
            'product.group', 'group', 'employee', 'user',
            'movements' => fn ($query) => $query->with(['roll.fabric', 'user:id,name', 'product:id,sku,name'])->orderBy('moved_at')->orderBy('id'),
            'batches' => fn ($query) => $query->with(['supplier:id,name', 'user:id,name', 'items.product:id,sku,name', 'inbounds:id,code,status,sewing_batch_id'])->orderBy('id'),
        ]);

        $variants = $cutting->variants();
        $received = SewingBatch::receivedFor($cutting->batches->pluck('id'));
        $used = $cutting->fabricUsed();

        // BOM per varian per kain, untuk memperkirakan berapa pcs dari kain ini.
        $bom = FabricUsage::query()
            ->whereIn('product_id', $variants->pluck('id'))
            ->get()
            ->groupBy('fabric_id');

        $fabrics = $cutting->movements->pluck('roll.fabric')->filter()->unique('id')->keyBy('id');

        $handedByVariant = $cutting->batches
            ->flatMap->items
            ->groupBy('product_id')
            ->map(fn ($items) => (int) $items->sum('quantity'));

        return view('admin.fabric-cuttings.show', [
            'cutting' => $cutting,
            'variants' => $variants,
            'received' => $received,
            'handedByVariant' => $handedByVariant,
            'fabricRows' => $used->map(function (float $quantity, int $fabricId) use ($fabrics, $bom, $cutting) {
                $usage = $bom->get($fabricId)?->firstWhere('product_id', $cutting->product_id)?->quantity
                    ?? $bom->get($fabricId)?->avg('quantity');

                return [
                    'fabric' => $fabrics[$fabricId] ?? null,
                    'quantity' => $quantity,
                    'usage' => $usage,
                    'estimate' => $usage > 0 ? (int) floor($quantity / $usage + 0.0001) : null,
                ];
            })->filter(fn ($row) => $row['fabric'])->values(),
            'rolls' => $this->rollsTaken($cutting),
            'tailors' => Supplier::query()
                ->where('is_tailor', true)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    /**
     * Catat hasil potong: qty per varian. Disimpan dulu di gudang (batch "Di
     * Gudang", tanpa penjahit) atau langsung diserahkan ke penjahit (WIP
     * Jahit). Bisa diulang bertahap.
     */
    public function handover(Request $request, FabricCutting $fabricCutting): RedirectResponse
    {
        $toWarehouse = $request->input('tujuan') === 'gudang';

        $data = $request->validate([
            'supplier_id' => [$toWarehouse ? 'nullable' : 'required', 'integer', 'exists:suppliers,id'],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'items' => ['required', 'array'],
            'items.*' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'supplier_id.required' => 'Pilih penjahit yang menerima hasil potong, atau pilih Simpan di Gudang.',
        ], ['supplier_id' => 'penjahit', 'items.*' => 'jumlah pcs']);

        if (! $toWarehouse) {
            $this->ensureTailor((int) $data['supplier_id']);
        }

        $allowed = $fabricCutting->variants()->pluck('id')->flip();
        $lines = collect($data['items'])
            ->mapWithKeys(fn ($quantity, $productId) => [(int) $productId => (int) $quantity])
            ->filter(fn (int $quantity, int $productId) => $quantity > 0 && $allowed->has($productId));

        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['items' => 'Isi jumlah pcs minimal untuk satu varian.']);
        }

        $batch = DB::transaction(function () use ($fabricCutting, $data, $lines, $request, $toWarehouse) {
            $batch = SewingBatch::create([
                'code' => SewingBatch::nextCode(),
                'fabric_cutting_id' => $fabricCutting->id,
                'supplier_id' => $toWarehouse ? null : $data['supplier_id'],
                'date' => $data['date'],
                'sent_at' => $toWarehouse ? null : $data['date'],
                'note' => filled($data['note'] ?? null) ? trim($data['note']) : null,
                'user_id' => $request->user()->id,
            ]);

            foreach ($lines as $productId => $quantity) {
                $batch->items()->create(['product_id' => $productId, 'quantity' => $quantity]);
            }

            return $batch->load('supplier');
        });

        return redirect()
            ->route('admin.fabric-cuttings.show', $fabricCutting)
            ->with('success', $toWarehouse
                ? "{$batch->code}: {$lines->sum()} pcs hasil potong disimpan di gudang."
                : "{$batch->code}: {$lines->sum()} pcs diserahkan ke {$batch->supplier->name}. Status WIP Jahit.");
    }

    /**
     * Kirim hasil potong yang disimpan di gudang ke penjahit. Bisa sebagian:
     * yang dikirim jadi batch baru, sisanya tetap di gudang.
     */
    public function send(Request $request, SewingBatch $sewingBatch): RedirectResponse
    {
        if (! $sewingBatch->inWarehouse() || $sewingBatch->closed_at) {
            return back()->with('error', "Batch {$sewingBatch->code} tidak sedang di gudang.");
        }

        $data = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'items' => ['required', 'array'],
            'items.*' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ], ['supplier_id.required' => 'Pilih penjahit tujuan.'], ['supplier_id' => 'penjahit', 'items.*' => 'jumlah pcs']);

        $this->ensureTailor((int) $data['supplier_id']);

        $stored = $sewingBatch->items()->get()->keyBy('product_id');
        $lines = collect($data['items'])
            ->mapWithKeys(fn ($quantity, $productId) => [(int) $productId => (int) $quantity])
            ->filter(fn (int $quantity) => $quantity > 0);

        foreach ($lines as $productId => $quantity) {
            $have = (int) ($stored[$productId]->quantity ?? 0);
            if ($quantity > $have) {
                throw ValidationException::withMessages(["items.{$productId}" => "Jumlah melebihi hasil potong di gudang ({$have} pcs)."]);
            }
        }

        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['items' => 'Isi jumlah pcs yang dikirim minimal untuk satu varian.']);
        }

        $all = $stored->every(fn ($item) => (int) ($lines[$item->product_id] ?? 0) === (int) $item->quantity);

        $sent = DB::transaction(function () use ($sewingBatch, $data, $lines, $stored, $all, $request) {
            if ($all) {
                $sewingBatch->forceFill(['supplier_id' => $data['supplier_id'], 'sent_at' => $data['date']])->save();

                return $sewingBatch;
            }

            $batch = SewingBatch::create([
                'code' => SewingBatch::nextCode(),
                'fabric_cutting_id' => $sewingBatch->fabric_cutting_id,
                'supplier_id' => $data['supplier_id'],
                // Tanggal potong tetap tanggal batch asal, supaya rekap pemotong tidak dobel.
                'date' => $sewingBatch->date,
                'sent_at' => $data['date'],
                'note' => 'Dari '.$sewingBatch->code,
                'user_id' => $request->user()->id,
            ]);

            foreach ($lines as $productId => $quantity) {
                $batch->items()->create(['product_id' => $productId, 'quantity' => $quantity]);
                $item = $stored[$productId];
                $left = (int) $item->quantity - $quantity;
                $left > 0 ? $item->update(['quantity' => $left]) : $item->delete();
            }

            return $batch;
        });

        $sent->load('supplier');

        return back()->with('success', "{$sent->code}: {$lines->sum()} pcs dikirim ke {$sent->supplier->name}."
            .($all ? '' : " Sisanya tetap di gudang ({$sewingBatch->code})."));
    }

    protected function ensureTailor(int $supplierId): void
    {
        if (! Supplier::whereKey($supplierId)->where('is_tailor', true)->exists()) {
            throw ValidationException::withMessages(['supplier_id' => 'Pemasok ini belum ditandai sebagai Penjahit/Konveksi.']);
        }
    }

    /**
     * Selesai cutting. Sisa kain per roll (bila ada) dikembalikan ke rollnya.
     */
    public function finish(Request $request, FabricCutting $fabricCutting, FabricStockService $stock): RedirectResponse
    {
        if (! $fabricCutting->isOpen()) {
            return back()->with('error', "Cutting {$fabricCutting->code} sudah selesai.");
        }

        $data = $request->validate([
            'returns' => ['nullable', 'array'],
            'returns.*' => ['nullable', 'numeric', 'min:0', 'max:999999'],
        ], [], ['returns.*' => 'sisa kain']);

        $taken = $this->rollsTaken($fabricCutting->load(['movements.roll.fabric']))->keyBy('roll.id');
        $returns = collect($data['returns'] ?? [])
            ->mapWithKeys(fn ($quantity, $rollId) => [(int) $rollId => round((float) $quantity, 2)])
            ->filter(fn (float $quantity) => $quantity > 0);

        foreach ($returns as $rollId => $quantity) {
            $row = $taken->get($rollId);

            if (! $row) {
                throw ValidationException::withMessages(["returns.{$rollId}" => 'Roll ini tidak dipakai di cutting ini.']);
            }

            if ($quantity > $row['taken'] + 0.001) {
                throw ValidationException::withMessages([
                    "returns.{$rollId}" => "Sisa kain {$row['roll']->code} maksimal {$row['roll']->fabric->format($row['taken'])} (yang diambil cutting ini).",
                ]);
            }
        }

        DB::transaction(function () use ($fabricCutting, $returns, $taken, $stock, $request) {
            foreach ($returns as $rollId => $quantity) {
                $stock->returnToRoll($taken[$rollId]['roll'], $quantity, $fabricCutting, $request->user());
            }

            $fabricCutting->forceFill([
                'status' => FabricCutting::STATUS_DONE,
                'finished_at' => now(),
            ])->save();
        });

        return redirect()
            ->route('admin.fabric-cuttings.show', $fabricCutting)
            ->with('success', "Cutting {$fabricCutting->code} selesai.".($returns->isNotEmpty() ? " Sisa kain dikembalikan ke {$returns->count()} roll." : ''));
    }

    /** Tutup batch yang tidak akan diterima lengkap (rusak/hilang di penjahit). */
    public function closeBatch(Request $request, SewingBatch $sewingBatch): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ], ['reason.required' => 'Isi alasan menutup batch, misalnya rusak atau hilang di penjahit.']);

        if ($sewingBatch->closed_at) {
            return back()->with('error', "Batch {$sewingBatch->code} sudah ditutup.");
        }

        $sewingBatch->forceFill(['closed_at' => now(), 'close_reason' => trim($data['reason'])])->save();

        return back()->with('success', "Batch {$sewingBatch->code} ditutup: {$sewingBatch->close_reason}.");
    }

    /** Hapus batch salah input, selama belum ada penerimaan yang merujuknya. */
    /** Surat jalan kain dari gudang ke pemotong: roll yang diambil untuk cutting ini. */
    public function cuttingNote(FabricCutting $fabricCutting): \Illuminate\Http\Response|RedirectResponse
    {
        $fabricCutting->load(['employee', 'user:id,name', 'product', 'group', 'movements' => fn ($query) => $query
            ->whereIn('type', [FabricMovement::TYPE_OUT, FabricMovement::TYPE_CANCEL, FabricMovement::TYPE_RETURN])
            ->with(['roll:id,code', 'fabric'])
            ->orderBy('moved_at')
            ->orderBy('id')]);

        $rolls = $fabricCutting->movements
            ->groupBy('fabric_roll_id')
            ->map(function ($rows) {
                $taken = -$rows->whereIn('type', [FabricMovement::TYPE_OUT, FabricMovement::TYPE_CANCEL])->sum('quantity');

                return [
                    'code' => $rows->first()->roll?->code ?? '—',
                    'fabric' => $rows->first()->fabric,
                    'date' => $rows->where('type', FabricMovement::TYPE_OUT)->min('moved_at'),
                    'taken' => round((float) $taken, 2),
                    'returned' => round((float) $rows->where('type', FabricMovement::TYPE_RETURN)->sum('quantity'), 2),
                ];
            })
            // Pengambilan yang dibatalkan tidak ikut di surat jalan.
            ->filter(fn ($row) => $row['taken'] > 0)
            ->sortBy([['date', 'asc'], ['code', 'asc']])
            ->values();

        if ($rolls->isEmpty()) {
            return back()->with('error', "Belum ada kain keluar untuk {$fabricCutting->code}.");
        }

        $totals = $rolls->groupBy(fn ($row) => $row['fabric']->id)->map(fn ($rows) => [
            'fabric' => $rows->first()['fabric'],
            'rolls' => $rows->count(),
            'taken' => $rows->sum('taken'),
            'returned' => $rows->sum('returned'),
        ])->values();

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('admin.fabric-cuttings.pdf.cutting-note', [
            'cutting' => $fabricCutting,
            'rolls' => $rolls,
            'totals' => $totals,
            'qr' => \App\Support\QrCode::file(route('admin.fabric-cuttings.show', $fabricCutting)),
        ])->setPaper('a4')->stream('surat-jalan-kain-'.$fabricCutting->code.'.pdf');
    }

    /** Surat jalan hasil potong ke penjahit (satu batch WIP Jahit). */
    public function batchNote(SewingBatch $sewingBatch): \Illuminate\Http\Response|RedirectResponse
    {
        if ($sewingBatch->inWarehouse()) {
            return back()->with('error', "{$sewingBatch->code} masih di gudang; surat jalan dibuat saat dikirim ke penjahit.");
        }

        $sewingBatch->load(['supplier', 'user:id,name', 'items.product', 'cutting.employee']);

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('admin.fabric-cuttings.pdf.batch-note', [
            'batch' => $sewingBatch,
            'qr' => \App\Support\QrCode::file(url($sewingBatch->url())),
        ])->setPaper('a4')->stream('surat-jalan-jahit-'.$sewingBatch->code.'.pdf');
    }

    public function destroyBatch(SewingBatch $sewingBatch): RedirectResponse
    {
        if ($sewingBatch->inbounds()->exists()) {
            return back()->with('error', "Batch {$sewingBatch->code} sudah dipakai di penerimaan barang, jadi tidak bisa dihapus. Tutup batch bila tidak akan diterima lengkap.");
        }

        $sewingBatch->delete();

        return redirect()
            ->to($sewingBatch->fabric_cutting_id
                ? route('admin.fabric-cuttings.show', $sewingBatch->fabric_cutting_id)
                : route('admin.fabric-cuttings.index', ['lihat' => $sewingBatch->inWarehouse() ? 'gudang' : 'jahit']))
            ->with('success', "Batch {$sewingBatch->code} dihapus.");
    }

    /**
     * Roll yang dipakai cutting ini beserta kain bersih yang diambil darinya.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    protected function rollsTaken(FabricCutting $cutting)
    {
        return $cutting->movements
            ->filter(fn (FabricMovement $movement) => $movement->roll)
            ->groupBy('fabric_roll_id')
            ->map(fn ($rows) => [
                'roll' => $rows->first()->roll,
                'taken' => round(-$rows->whereIn('type', [FabricMovement::TYPE_OUT, FabricMovement::TYPE_CANCEL, FabricMovement::TYPE_RETURN])->sum('quantity'), 2),
            ])
            ->filter(fn ($row) => $row['taken'] > 0)
            ->values();
    }

    public function export(Request $request): StreamedResponse
    {
        [$from, $to] = $this->period($request);
        $report = CuttingReport::for($from, $to);
        $period = $from->format('d/m/Y').' – '.$to->format('d/m/Y');

        return TableExport::make('Rekap Cutting & Jahit')
            ->note('Periode '.$period.'. Kain terpakai menurut tanggal scan keluar; pcs hasil potong menurut tanggal dicatat; penjahit menurut tanggal kirim.')
            ->sheet('Pemotong', [
                ['Pemotong', 'text'], ['Jabatan', 'text'], ['Cutting Dimulai', 'sum'], ['Roll Diambil', 'sum'], ['Pcs Hasil Potong', 'sum'], ['Jenis Barang', 'number'],
            ], $report->cutters->map(fn ($row) => [$row['name'], $row['position'], $row['cuttings'], $row['rolls'], $row['pieces'], $row['products']]))
            ->sheet('Pemakaian Kain', [
                ['Pemotong', 'text'], ['SKU Kain', 'text'], ['Nama Kain', 'text'], ['Satuan', 'text'], ['Terpakai', 'number'], ['Standar BOM', 'number'], ['Selisih', 'number'],
            ], $report->fabrics->map(fn ($row) => [$row['cutter'], $row['fabric']->sku, $row['fabric']->displayName(), $row['fabric']->unit, $row['used'], $row['standard'], $row['difference']]))
            ->sheet('Hasil Potong per Barang', [
                ['Pemotong', 'text'], ['SKU Barang', 'text'], ['Nama Barang', 'text'], ['Pcs', 'sum'],
            ], $report->cutterProducts->map(fn ($row) => [$row['cutter'], $row['sku'], $row['name'], $row['pieces']]))
            ->sheet('Penjahit', [
                ['Penjahit', 'text'], ['Batch', 'sum'], ['Diserahkan', 'sum'], ['Diterima', 'sum'], ['Sisa di Penjahit', 'sum'], ['Selisih Batch Ditutup', 'sum'],
            ], $report->tailors->map(fn ($row) => [$row['name'], $row['batches'], $row['handed'], $row['received'], $row['open'], $row['closed_short']]))
            ->sheet('Penjahit per Barang', [
                ['Penjahit', 'text'], ['SKU Barang', 'text'], ['Nama Barang', 'text'], ['Diserahkan', 'sum'], ['Diterima', 'sum'], ['Sisa di Penjahit', 'sum'], ['Selisih Batch Ditutup', 'sum'],
            ], $report->tailorProducts->map(fn ($row) => [$row['tailor'], $row['sku'], $row['name'], $row['handed'], $row['received'], $row['open'], $row['closed_short']]))
            ->download('rekap-cutting-jahit-'.$from->format('Y-m-d').'-sd-'.$to->format('Y-m-d').'.xlsx');
    }

    /** @return array{0: Carbon, 1: Carbon} */
    protected function period(Request $request): array
    {
        $parse = function (?string $value, Carbon $fallback) {
            try {
                return $value ? Carbon::createFromFormat('Y-m-d', $value)->startOfDay() : $fallback;
            } catch (\Throwable) {
                return $fallback;
            }
        };

        $from = $parse($request->input('from'), now()->startOfMonth());
        $to = $parse($request->input('to'), now()->startOfDay());

        return $from->greaterThan($to) ? [$to, $from] : [$from, $to];
    }

    /** @return array<string, int> */
    protected function summary(): array
    {
        $open = SewingBatch::query()->notClosed()->with('items')->get();
        $received = SewingBatch::receivedFor($open->pluck('id'));
        $left = fn (SewingBatch $batch) => $batch->outstanding($received->get($batch->id, collect()))->sum();
        $atTailor = $open->filter(fn (SewingBatch $batch) => ! $batch->inWarehouse() && $left($batch) > 0);

        return [
            'cutting' => FabricCutting::query()->open()->count(),
            'warehouse' => (int) $open->filter(fn (SewingBatch $batch) => $batch->inWarehouse())->sum($left),
            'batches' => $atTailor->count(),
            'wip' => (int) $atTailor->sum($left),
        ];
    }
}
