<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nama marketplace pada barang keluar tidak lagi diketik di form.
 *
 * Sumbernya satu: data import resi (shipment_orders.marketplace), lewat
 * outbounds.shipment_order_id. Sebelum kolomnya dibuang:
 *
 * 1. Dokumen marketplace yang resinya ada di data import tetapi belum
 *    tertaut (dibuat manual dan isinya kebetulan sudah sama) ditautkan,
 *    asalkan resinya cocok dengan tepat satu pesanan yang belum dipakai
 *    dokumen lain.
 * 2. Nilai yang tetap tidak bisa dibaca dari data import — tidak tertaut,
 *    atau berbeda dengan data import — disalin ke catatan dengan penanda
 *    yang bisa dikembalikan oleh down(). Tidak ada nilai yang hilang.
 */
return new class extends Migration
{
    private const MARK = '[Marketplace lama: ';

    public function up(): void
    {
        DB::transaction(function () {
            $this->linkUnlinkedDocuments();
            $this->keepOrphanValuesInNote();
        });

        Schema::table('outbounds', function (Blueprint $table) {
            $table->dropColumn('marketplace');
        });
    }

    public function down(): void
    {
        Schema::table('outbounds', function (Blueprint $table) {
            $table->string('marketplace')->nullable()->after('recipient');
        });

        DB::transaction(function () {
            // Ambil kembali dari data import untuk dokumen yang tertaut.
            DB::table('outbounds')
                ->whereNotNull('shipment_order_id')
                ->select('id', 'shipment_order_id')
                ->chunkById(500, function ($rows) {
                    $markets = DB::table('shipment_orders')
                        ->whereIn('id', $rows->pluck('shipment_order_id'))
                        ->pluck('marketplace', 'id');

                    foreach ($rows as $row) {
                        DB::table('outbounds')->where('id', $row->id)
                            ->update(['marketplace' => $markets[$row->shipment_order_id] ?? null]);
                    }
                });

            // Nilai yang dulu dipindah ke catatan dikembalikan ke kolomnya.
            DB::table('outbounds')
                ->where('note', 'like', '%'.self::MARK.'%')
                ->orderBy('id')
                ->select('id', 'note')
                // get(), bukan chunk(): baris yang diubah keluar dari saringan
                // dan paging berbasis offset akan melompatinya.
                ->get()
                ->each(function ($row) {
                    if (! preg_match('/\n?\[Marketplace lama: (.*?)\]$/u', $row->note, $match)) {
                        return;
                    }

                    $note = trim(substr($row->note, 0, -strlen($match[0])));

                    DB::table('outbounds')->where('id', $row->id)->update([
                        'marketplace' => $match[1],
                        'note' => $note === '' ? null : $note,
                    ]);
                });
        });

        // Tautan yang dibuat up() sengaja dibiarkan: resinya memang pesanan
        // itu, dan stasiun scan akan menautkannya juga.
    }

    private function linkUnlinkedDocuments(): void
    {
        $taken = DB::table('outbounds')->whereNotNull('shipment_order_id')->pluck('shipment_order_id')->flip();

        DB::table('outbounds')
            ->where('type', 'marketplace')
            ->whereNull('shipment_order_id')
            ->whereNotNull('tracking_number')
            ->orderBy('id')
            ->select('id', 'tracking_number')
            ->get()
            ->each(function ($outbound) use (&$taken) {
                $matches = DB::table('shipment_orders')
                    ->where('tracking_number', trim($outbound->tracking_number))
                    ->pluck('id');

                if ($matches->count() !== 1 || $taken->has($matches[0])) {
                    return;
                }

                DB::table('outbounds')->where('id', $outbound->id)
                    ->update(['shipment_order_id' => $matches[0]]);

                $taken[$matches[0]] = true;
            });
    }

    private function keepOrphanValuesInNote(): void
    {
        DB::table('outbounds')
            ->leftJoin('shipment_orders', 'shipment_orders.id', '=', 'outbounds.shipment_order_id')
            ->whereNotNull('outbounds.marketplace')
            ->where('outbounds.marketplace', '!=', '')
            ->where(fn ($query) => $query->whereNull('shipment_orders.marketplace')
                ->orWhereColumn('shipment_orders.marketplace', '!=', 'outbounds.marketplace'))
            ->orderBy('outbounds.id')
            ->select('outbounds.id', 'outbounds.note', 'outbounds.marketplace')
            ->get()
            ->each(function ($row) {
                $note = trim((string) $row->note);
                $mark = self::MARK.$row->marketplace.']';

                DB::table('outbounds')->where('id', $row->id)->update([
                    'note' => $note === '' ? $mark : $note."\n".$mark,
                ]);
            });
    }
};
