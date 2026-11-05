<?php

namespace App\Support;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Rencana produksi untuk beberapa tanggal batas kirim yang dipilih: berapa
 * unit tiap barang yang dibutuhkan, dan berapa yang harus diproduksi karena
 * stok gudang tidak cukup.
 *
 * Stok dibagi menurut urutan batas kirim, sama seperti packing: resi yang
 * batasnya lebih awal (termasuk tanggal yang tidak dipilih dan yang sudah
 * lewat) memakai stok lebih dulu. Jadi kekurangan di tanggal yang dipilih
 * tidak terlalu optimis.
 */
class ProductionPlan
{
    /** Paling banyak tanggal yang bisa dipilih sekaligus. */
    public const MAX_DATES = 62;

    /**
     * @param  Collection<int, Carbon>  $dates  tanggal yang dipilih, urut
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, int>  $totals
     */
    public function __construct(
        public readonly Collection $dates,
        public readonly Collection $rows,
        public readonly array $totals,
    ) {
    }

    /**
     * @param  \Closure(): Builder  $base  resi yang belum dikirim (sudah tersaring)
     * @param  Collection<int, Carbon>  $dates
     */
    public static function for(\Closure $base, Collection $dates, string $mode = PackingList::BY_PRODUCT): self
    {
        $dates = $dates->sortBy(fn (Carbon $date) => $date->timestamp)->values();
        $selected = $dates->map(fn (Carbon $date) => $date->format('Y-m-d'))->flip();

        if ($dates->isEmpty()) {
            return new self($dates, collect(), self::emptyTotals());
        }

        // Semua tanggal batas kirim sampai tanggal terakhir yang dipilih, karena
        // tanggal yang tidak dipilih di antaranya juga memakai stok.
        $allDays = $base()->toBase()
            ->whereNotNull('shipment_orders.ship_by')
            ->where('shipment_orders.ship_by', '<=', $dates->last()->copy()->endOfDay())
            ->selectRaw('DATE(shipment_orders.ship_by) AS day')
            ->groupBy('day')
            ->orderBy('day')
            ->pluck('day')
            ->map(fn ($day) => Carbon::parse($day)->format('Y-m-d'));

        $lines = [];
        $orders = 0;

        foreach ($allDays as $day) {
            $start = Carbon::parse($day)->startOfDay();
            $list = PackingList::for(
                $base()->whereBetween('shipment_orders.ship_by', [$start, $start->copy()->endOfDay()]),
                $mode,
            );
            $isSelected = $selected->has($day);

            if ($isSelected) {
                $orders += $list->totals['orders'];
            }

            foreach ($list->rows as $row) {
                $key = $row['product_id'] !== null ? 'p'.$row['product_id'] : 's'.$row['sku'];
                $lines[$key] ??= [
                    'product_id' => $row['product_id'],
                    'sku' => $row['sku'],
                    'name' => $row['name'],
                    'image_url' => $row['image_url'] ?? null,
                    'image_path' => $row['image_path'] ?? null,
                    'matched' => $row['matched'],
                    'from_bundle' => $row['from_bundle'],
                    'days' => [],
                    'earlier' => 0,
                    'scanned' => 0,
                ];
                $lines[$key]['from_bundle'] = $lines[$key]['from_bundle'] || $row['from_bundle'];
                $lines[$key]['scanned'] += $row['scanned'];

                if ($isSelected) {
                    $lines[$key]['days'][$day] = ($lines[$key]['days'][$day] ?? 0) + $row['remaining'];
                } else {
                    $lines[$key]['earlier_days'][$day] = ($lines[$key]['earlier_days'][$day] ?? 0) + $row['remaining'];
                }
            }
        }

        $stocks = Product::query()
            ->whereIn('id', collect($lines)->pluck('product_id')->filter()->unique())
            ->pluck('stock', 'id');

        $rows = collect($lines)
            ->map(function (array $line) use ($stocks, $allDays) {
                $stock = $line['product_id'] !== null && $stocks->has($line['product_id']) ? (int) $stocks[$line['product_id']] : null;
                // Barang yang sudah discan sudah turun dari rak meskipun stoknya
                // baru dipotong saat serah terima.
                $available = $stock === null ? 0 : max(0, $stock - $line['scanned']);
                $left = $available;
                $earlier = 0;
                $earlierShort = 0;
                $short = 0;
                $shortDays = [];

                foreach ($allDays as $day) {
                    $need = $line['days'][$day] ?? $line['earlier_days'][$day] ?? 0;
                    $take = min($left, $need);
                    $left -= $take;

                    if (isset($line['days'][$day])) {
                        $short += $need - $take;
                        $shortDays[$day] = $need - $take;
                    } else {
                        $earlier += $need;
                        $earlierShort += $need - $take;
                    }
                }

                $needed = array_sum($line['days']);

                return [
                    'product_id' => $line['product_id'],
                    'sku' => $line['sku'],
                    'name' => $line['name'],
                    'image_url' => $line['image_url'],
                    'image_path' => $line['image_path'],
                    'matched' => $line['matched'],
                    'from_bundle' => $line['from_bundle'],
                    'days' => $line['days'],
                    'short_days' => $shortDays,
                    'needed' => $needed,
                    'stock' => $stock,
                    'available' => $available,
                    'earlier' => $earlier,
                    'earlier_short' => $earlierShort,
                    'covered' => $needed - $short,
                    'short' => $short,
                ];
            })
            ->filter(fn (array $row) => $row['needed'] > 0)
            ->sortBy([['short', 'desc'], ['needed', 'desc'], ['sku', 'asc']])
            ->values();

        return new self($dates, $rows, [
            'orders' => $orders,
            'products' => $rows->count(),
            'needed' => (int) $rows->sum('needed'),
            'covered' => (int) $rows->sum('covered'),
            'short' => (int) $rows->sum('short'),
            'short_products' => $rows->where('short', '>', 0)->count(),
            'earlier_short' => (int) $rows->sum('earlier_short'),
            'unmatched' => $rows->where('matched', false)->count(),
        ]);
    }

    /**
     * Tanggal dari ?tanggal=2026-11-05,2026-11-06 atau ?dari=..&sampai=..
     *
     * @return Collection<int, Carbon>
     */
    public static function datesFrom(?string $list, ?string $from, ?string $to): Collection
    {
        $parse = function (?string $value): ?Carbon {
            if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                return null;
            }

            return Carbon::create((int) $m[1], (int) $m[2], (int) $m[3])->startOfDay();
        };

        $dates = collect();
        $start = $parse($from);
        $end = $parse($to);

        if ($start && $end) {
            if ($end->lt($start)) {
                [$start, $end] = [$end, $start];
            }

            for ($day = $start->copy(); $day->lte($end) && $dates->count() < self::MAX_DATES; $day->addDay()) {
                $dates->push($day->copy());
            }
        }

        foreach (explode(',', (string) $list) as $value) {
            if ($date = $parse(trim($value))) {
                $dates->push($date);
            }
        }

        return $dates
            ->unique(fn (Carbon $date) => $date->format('Y-m-d'))
            ->sortBy(fn (Carbon $date) => $date->timestamp)
            ->take(self::MAX_DATES)
            ->values();
    }

    /** @return array<string, int> */
    protected static function emptyTotals(): array
    {
        return ['orders' => 0, 'products' => 0, 'needed' => 0, 'covered' => 0, 'short' => 0, 'short_products' => 0, 'earlier_short' => 0, 'unmatched' => 0];
    }
}
