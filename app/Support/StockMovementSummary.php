<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Ringkasan sekumpulan mutasi stok, dihitung dalam satu query.
 *
 * Dipakai halaman Mutasi Stok dan API-nya supaya kartu ringkasan di layar
 * dan angka yang ditarik sistem lain tidak mungkin bercerita beda.
 */
class StockMovementSummary
{
    /**
     * @return array{entries: int, incoming: int, outgoing: int, net: int, products: int}
     */
    public static function of(Builder $query): array
    {
        $row = $query->toBase()
            ->selectRaw('COUNT(*) as entries')
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'in' THEN quantity ELSE 0 END), 0) as incoming")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'out' THEN quantity ELSE 0 END), 0) as outgoing")
            ->selectRaw('COUNT(DISTINCT product_id) as products')
            ->first();

        return [
            'entries' => (int) ($row->entries ?? 0),
            'incoming' => (int) ($row->incoming ?? 0),
            'outgoing' => (int) ($row->outgoing ?? 0),
            'net' => (int) ($row->incoming ?? 0) - (int) ($row->outgoing ?? 0),
            'products' => (int) ($row->products ?? 0),
        ];
    }
}
