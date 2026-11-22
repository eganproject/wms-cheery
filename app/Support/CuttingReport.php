<?php

namespace App\Support;

use App\Models\Employee;
use App\Models\Fabric;
use App\Models\FabricCutting;
use App\Models\FabricMovement;
use App\Models\FabricUsage;
use App\Models\Product;
use App\Models\SewingBatch;
use App\Models\Supplier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Rekap Cutting & Jahit dalam satu periode.
 *
 * - Pemotong: kain yang diambil (tanggal scan keluar) dan pcs hasil potong
 *   (tanggal dicatat, termasuk yang disimpan di gudang), dibandingkan standar BOM.
 * - Penjahit: batch yang dikirim ke penjahit dalam periode, berapa yang sudah
 *   diterima (penerimaan disetujui), sisa di penjahit, dan selisih batch
 *   yang ditutup.
 */
class CuttingReport
{
    /** @var Collection<int, array<string, mixed>> */
    public Collection $cutters;

    /** @var Collection<int, array<string, mixed>> Pemotong × kain. */
    public Collection $fabrics;

    /** @var Collection<int, array<string, mixed>> Pemotong × barang. */
    public Collection $cutterProducts;

    /** @var Collection<int, array<string, mixed>> */
    public Collection $tailors;

    /** @var Collection<int, array<string, mixed>> Penjahit × barang. */
    public Collection $tailorProducts;

    public function __construct(public Carbon $from, public Carbon $to)
    {
        $this->build();
    }

    public static function for(Carbon $from, Carbon $to): self
    {
        return new self($from->copy()->startOfDay(), $to->copy()->endOfDay());
    }

    protected function build(): void
    {
        $movements = FabricMovement::query()
            ->whereNotNull('fabric_cutting_id')
            ->whereIn('type', [FabricMovement::TYPE_OUT, FabricMovement::TYPE_CANCEL, FabricMovement::TYPE_RETURN])
            ->whereBetween('moved_at', [$this->from, $this->to])
            ->get(['id', 'fabric_cutting_id', 'fabric_id', 'fabric_roll_id', 'type', 'quantity']);

        $batches = SewingBatch::query()
            ->where(fn ($query) => $query
                ->where(fn ($query) => $query->whereDate('date', '>=', $this->from->toDateString())->whereDate('date', '<=', $this->to->toDateString()))
                ->orWhere(fn ($query) => $query->whereDate('sent_at', '>=', $this->from->toDateString())->whereDate('sent_at', '<=', $this->to->toDateString())))
            ->with('items:id,sewing_batch_id,product_id,quantity')
            ->get(['id', 'fabric_cutting_id', 'supplier_id', 'date', 'sent_at', 'closed_at']);

        $inPeriod = fn ($date) => $date && $date->betweenIncluded($this->from->copy()->startOfDay(), $this->to);
        $tailorBatches = $batches->filter(fn ($batch) => $batch->supplier_id && $inPeriod($batch->sent_at ?? $batch->date));
        $batches = $batches->filter(fn ($batch) => $inPeriod($batch->date));

        $cuttingIds = $movements->pluck('fabric_cutting_id')->merge($batches->pluck('fabric_cutting_id'))->unique();

        $cuttings = FabricCutting::query()->whereIn('id', $cuttingIds)->get(['id', 'employee_id', 'created_at', 'status']);
        $cutterOf = $cuttings->pluck('employee_id', 'id');

        // Hasil potong per pemotong × barang.
        $pieces = [];
        foreach ($batches as $batch) {
            $cutter = (int) ($cutterOf[$batch->fabric_cutting_id] ?? 0);
            foreach ($batch->items as $item) {
                $pieces[$cutter][$item->product_id] = ($pieces[$cutter][$item->product_id] ?? 0) + (int) $item->quantity;
            }
        }

        // Kain terpakai per pemotong × kain.
        $used = [];
        $rolls = [];
        foreach ($movements as $movement) {
            $cutter = (int) ($cutterOf[$movement->fabric_cutting_id] ?? 0);
            $used[$cutter][$movement->fabric_id] = ($used[$cutter][$movement->fabric_id] ?? 0) - (float) $movement->quantity;
            if ($movement->type === FabricMovement::TYPE_OUT) {
                $rolls[$cutter][$movement->fabric_roll_id] = true;
            }
        }

        $productIds = collect($pieces)->flatMap(fn ($rows) => array_keys($rows))->unique();
        $products = Product::query()->whereIn('id', $productIds)->get(['id', 'sku', 'name'])->keyBy('id');
        $usages = FabricUsage::query()->whereIn('product_id', $productIds)->get(['product_id', 'fabric_id', 'quantity']);

        // Standar BOM = pcs hasil potong × pemakaian kain per pcs.
        $standard = [];
        foreach ($pieces as $cutter => $rows) {
            foreach ($rows as $productId => $quantity) {
                foreach ($usages->where('product_id', $productId) as $usage) {
                    $standard[$cutter][$usage->fabric_id] = ($standard[$cutter][$usage->fabric_id] ?? 0) + $quantity * (float) $usage->quantity;
                }
            }
        }

        $cutterIds = collect(array_keys($used))->merge(array_keys($pieces))->unique();
        $employees = Employee::query()->whereIn('id', $cutterIds->filter())->get(['id', 'name', 'position'])->keyBy('id');
        $fabricIds = collect($used)->merge($standard)->flatMap(fn ($rows) => array_keys($rows))->unique();
        $fabrics = Fabric::query()->whereIn('id', $fabricIds)->get()->keyBy('id');
        $name = fn (int $id) => $id ? ($employees[$id]->name ?? '—') : 'Tanpa pemotong';

        $this->cutters = $cutterIds
            ->map(fn (int $id) => [
                'id' => $id,
                'name' => $name($id),
                'position' => $id ? ($employees[$id]->position ?? null) : null,
                'cuttings' => $cuttings->where('employee_id', $id ?: null)
                    ->filter(fn ($cutting) => $cutting->created_at->between($this->from, $this->to))->count(),
                'rolls' => count($rolls[$id] ?? []),
                'pieces' => array_sum($pieces[$id] ?? []),
                'products' => count($pieces[$id] ?? []),
            ])
            ->sortByDesc('pieces')
            ->values();

        $this->fabrics = $cutterIds
            ->flatMap(function (int $id) use ($used, $standard, $fabrics, $name) {
                $ids = array_unique(array_merge(array_keys($used[$id] ?? []), array_keys($standard[$id] ?? [])));

                return collect($ids)->map(function ($fabricId) use ($id, $used, $standard, $fabrics, $name) {
                    $actual = round($used[$id][$fabricId] ?? 0, 2);
                    $bom = isset($standard[$id][$fabricId]) ? round($standard[$id][$fabricId], 2) : null;

                    return [
                        'cutter' => $name($id),
                        'fabric' => $fabrics[$fabricId] ?? null,
                        'used' => $actual,
                        'standard' => $bom,
                        'difference' => $bom === null ? null : round($actual - $bom, 2),
                    ];
                })->filter(fn ($row) => $row['fabric']);
            })
            ->values();

        $this->cutterProducts = collect($pieces)
            ->flatMap(fn ($rows, $id) => collect($rows)->map(fn ($quantity, $productId) => [
                'cutter' => $name((int) $id),
                'sku' => $products[$productId]->sku ?? '—',
                'name' => $products[$productId]->name ?? '—',
                'pieces' => $quantity,
            ]))
            ->sortBy([['cutter', 'asc'], ['pieces', 'desc']])
            ->values();

        $this->buildTailors($tailorBatches->values(), $products);
    }

    protected function buildTailors(Collection $batches, Collection $products): void
    {
        $received = SewingBatch::receivedFor($batches->pluck('id'));
        $suppliers = Supplier::query()->whereIn('id', $batches->pluck('supplier_id')->unique())->get(['id', 'name'])->keyBy('id');
        $missing = $batches->flatMap(fn ($batch) => $batch->items->pluck('product_id'))->diff($products->keys());
        if ($missing->isNotEmpty()) {
            $products = $products->union(Product::query()->whereIn('id', $missing)->get(['id', 'sku', 'name'])->keyBy('id'));
        }

        $lines = [];
        foreach ($batches as $batch) {
            $got = $received->get($batch->id, collect());
            foreach ($batch->items as $item) {
                $key = $batch->supplier_id.'-'.$item->product_id;
                $handed = (int) $item->quantity;
                $in = min($handed, (int) ($got[$item->product_id] ?? 0));
                $line = $lines[$key] ?? ['supplier_id' => $batch->supplier_id, 'product_id' => $item->product_id, 'handed' => 0, 'received' => 0, 'open' => 0, 'closed_short' => 0];
                $line['handed'] += $handed;
                $line['received'] += $in;
                $line[$batch->closed_at ? 'closed_short' : 'open'] += $handed - $in;
                $lines[$key] = $line;
            }
        }

        $this->tailorProducts = collect($lines)
            ->map(fn ($line) => $line + [
                'tailor' => $suppliers[$line['supplier_id']]->name ?? '—',
                'sku' => $products[$line['product_id']]->sku ?? '—',
                'name' => $products[$line['product_id']]->name ?? '—',
            ])
            ->sortBy([['tailor', 'asc'], ['sku', 'asc']])
            ->values();

        $this->tailors = $batches->groupBy('supplier_id')
            ->map(function ($rows, $supplierId) use ($suppliers) {
                $lines = $this->tailorProducts->where('supplier_id', $supplierId);

                return [
                    'name' => $suppliers[$supplierId]->name ?? '—',
                    'batches' => $rows->count(),
                    'handed' => $lines->sum('handed'),
                    'received' => $lines->sum('received'),
                    'open' => $lines->sum('open'),
                    'closed_short' => $lines->sum('closed_short'),
                ];
            })
            ->sortByDesc('handed')
            ->values();
    }
}
