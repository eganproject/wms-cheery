<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Mencari lama PO yang berlaku untuk satu barang pada satu waktu.
 *
 * Periode dipasang pada produk induk (berlaku untuk semua variannya) atau,
 * untuk barang tanpa induk, pada barang itu sendiri. Periode yang berlaku
 * adalah yang mulai paling akhir tetapi tidak lewat dari waktu yang dicari.
 * Paket bundling tanpa periode sendiri memakai lama PO terlama dari isinya.
 */
class PoPeriodResolver
{
    /** @var array<string, array<int, array{0: string, 1: int}>> pemilik => [[mulai, hari], ...] urut naik */
    protected array $periods = [];

    public function __construct()
    {
        $rows = DB::table('product_po_periods')
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get(['product_group_id', 'product_id', 'starts_at', 'days']);

        foreach ($rows as $row) {
            $owner = $row->product_group_id ? 'g'.$row->product_group_id : 'p'.$row->product_id;
            $this->periods[$owner][] = [Carbon::parse($row->starts_at)->toDateTimeString(), (int) $row->days];
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $products  dari OrderPlanner::products()
     */
    public function daysFor(int $productId, array $products, Carbon $at, int $depth = 0): ?int
    {
        $product = $products[$productId] ?? null;

        if (! $product) {
            return null;
        }

        $own = $this->lookup(self::ownerOf($product), $at);

        if ($own !== null || $product['bundle'] === [] || $depth > 0) {
            return $own;
        }

        $days = [];

        foreach (array_keys($product['bundle']) as $componentId) {
            $value = $this->daysFor($componentId, $products, $at, $depth + 1);

            if ($value === null) {
                return null;
            }

            $days[] = $value;
        }

        return $days === [] ? null : max($days);
    }

    /**
     * @param  array<string, mixed>  $product
     */
    public static function ownerOf(array $product): string
    {
        return $product['group'] ? 'g'.$product['group'] : 'p'.$product['id'];
    }

    protected function lookup(string $owner, Carbon $at): ?int
    {
        $found = null;
        $moment = $at->toDateTimeString();

        foreach ($this->periods[$owner] ?? [] as [$start, $days]) {
            if ($start > $moment) {
                break;
            }

            $found = $days;
        }

        return $found;
    }
}
