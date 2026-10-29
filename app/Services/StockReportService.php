<?php

namespace App\Services;

use App\Models\Outbound;
use App\Models\Product;
use App\Models\StockMovement;
use App\Support\StockContribution;
use App\Support\StockReportFilters;
use App\Support\StockReportRow;
use App\Support\StockVelocity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * Laporan stok: saldo awal dan akhir tiap barang pada suatu periode, apa yang
 * bergerak di antaranya, dan seberapa cepat stoknya berputar.
 *
 * Semuanya dihitung di sisi basis data. Memuat seluruh barang beserta
 * mutasinya ke memori memang lebih mudah ditulis, tetapi laporan adalah
 * halaman yang justru dibuka saat datanya sudah banyak.
 *
 * Yang dilaporkan hanya saldo layak jual. Barang rusak punya saldo sendiri
 * dan tidak pernah ikut dijual, jadi mencampurnya ke perhitungan perputaran
 * hanya membuat angkanya terlihat lebih sehat daripada kenyataan.
 *
 * Saldo awal dan akhir memakai semua mutasi, tetapi kecepatan, kontribusi,
 * dan klasifikasi hanya memakai barang keluar operasional — dokumen barang
 * keluar. Selisih opname atau penyesuaian bukan penjualan; menghitungnya
 * membuat barang yang hilang di rak terlihat laku.
 */
class StockReportService
{
    /**
     * Saldo pada akhir periode.
     *
     * Yang tersimpan di products.stock adalah saldo hari ini, jadi untuk
     * periode yang sudah lewat ia ditarik mundur oleh mutasi sesudahnya.
     * Untuk periode yang berakhir hari ini, koreksinya nol.
     */
    protected const CLOSING = '(p.stock - COALESCE(m.in_after, 0) + COALESCE(m.out_after, 0))';

    /** Saldo awal periode: saldo akhir ditarik mundur oleh mutasi periode itu sendiri. */
    protected const OPENING = self::CLOSING.' - COALESCE(m.incoming, 0) + COALESCE(m.outgoing, 0)';

    /** Qty keluar operasional selama periode. */
    protected const SOLD = 'COALESCE(m.sold, 0)';

    /**
     * Saldo rusak per akhir periode, ditarik mundur dari saldo hari ini
     * dengan cara yang sama seperti saldo layak jual. Tanpa ini laporan
     * periode lampau menampilkan angka rusak hari ini.
     */
    /**
     * Terjual bersih: keluar lewat barang keluar, dikurangi yang dibatalkan
     * setelah serah terima (stoknya masuk lagi atas dokumen yang sama).
     * Tanpa pengurangan ini paket yang batal di hari yang sama tetap
     * terhitung laku, dan laju jual serta saran restock ikut membengkak.
     */
    protected const NET_SOLD = "SUM(CASE WHEN type = 'out' THEN quantity ELSE -quantity END)";

    protected const DAMAGED_CLOSING = '(p.damaged_stock - COALESCE(d.in_after, 0) + COALESCE(d.out_after, 0))';

    /**
     * Klasifikasi per periode, supaya tabel dan ringkasan pada satu halaman
     * tidak menghitungnya dua kali.
     *
     * @var array<string, StockContribution>
     */
    protected array $contributions = [];

    public function paginate(StockReportFilters $filters, int $perPage = 25, ?int $page = null): LengthAwarePaginator
    {
        return $this->ordered($filters)
            ->paginate($perPage, page: $page)
            ->withQueryString()
            ->through(fn (object $row) => StockReportRow::fromQuery($row, $filters->days(), $this->contribution($filters)));
    }

    /**
     * Seluruh baris laporan tanpa halaman — untuk export.
     *
     * @return LazyCollection<int, StockReportRow>
     */
    public function lazy(StockReportFilters $filters): LazyCollection
    {
        return $this->ordered($filters)
            ->lazy(500)
            ->map(fn (object $row) => StockReportRow::fromQuery($row, $filters->days(), $this->contribution($filters)));
    }

    /**
     * Batas klasifikasi untuk periode ini.
     *
     * Dihitung dari seluruh barang, bukan hanya yang lolos pencarian atau
     * kategori: kelas sebuah barang tidak boleh berubah hanya karena
     * pengguna mengetik nama lain di kotak pencarian.
     */
    public function contribution(StockReportFilters $filters): StockContribution
    {
        $key = $filters->from->toDateTimeString().'|'.$filters->to->toDateTimeString();

        return $this->contributions[$key] ??= StockContribution::fromLevels(
            DB::query()
                ->fromSub(
                    $this->sales($filters)
                        ->select('product_id')
                        ->selectRaw(self::NET_SOLD.' as quantity')
                        ->groupBy('product_id')
                        ->havingRaw(self::NET_SOLD.' > 0'),
                    'sold',
                )
                ->select('quantity')
                ->selectRaw('COUNT(*) as products')
                ->groupBy('quantity')
                ->get(),
        );
    }

    /**
     * Ringkasan seluruh barang yang lolos saringan, dalam satu query.
     *
     * @return array<string, int|float|null>
     */
    public function summary(StockReportFilters $filters): array
    {
        $row = $this->base($filters)
            ->selectRaw('COUNT(*) as products')
            ->selectRaw('COALESCE(SUM(COALESCE(m.incoming, 0)), 0) as incoming')
            ->selectRaw('COALESCE(SUM('.self::SOLD.'), 0) as outgoing')
            ->selectRaw('COALESCE(SUM('.self::CLOSING.'), 0) as closing')
            ->selectRaw('COALESCE(SUM('.self::OPENING.'), 0) as opening')
            ->selectRaw('COALESCE(SUM('.self::DAMAGED_CLOSING.'), 0) as damaged')
            ->selectRaw('COALESCE(SUM(CASE WHEN '.self::CLOSING.' <= p.min_stock THEN 1 ELSE 0 END), 0) as low')
            ->first();

        $days = $filters->days();
        $outgoing = (int) ($row->outgoing ?? 0);
        $closing = (int) ($row->closing ?? 0);
        $opening = (int) ($row->opening ?? 0);

        $average = ($opening + $closing) / 2;
        $perDay = $outgoing / $days;

        return [
            'days' => $days,
            'products' => (int) ($row->products ?? 0),
            'opening' => $opening,
            'incoming' => (int) ($row->incoming ?? 0),
            'outgoing' => $outgoing,
            'closing' => $closing,
            'damaged' => (int) ($row->damaged ?? 0),
            'low' => (int) ($row->low ?? 0),
            'per_day' => $perDay,
            'turnover' => $average > 0 ? $outgoing / $average : null,
            'cover' => $perDay > 0 ? $closing / $perDay : null,
        ];
    }

    /**
     * Rekap tiap kelas pergerakan untuk kartu di atas tabel.
     *
     * Mengikuti pencarian dan kategori, tetapi tidak mengikuti pilihan kelas
     * yang sedang dilihat — kartu Fast Moving tidak boleh menjadi nol hanya
     * karena pengguna sedang membuka daftar Slow Moving.
     *
     * @return array<string, array{products: int, outgoing: int, closing: int, share: float}>
     */
    public function classSummary(StockReportFilters $filters): array
    {
        $contribution = $this->contribution($filters);
        $sold = self::SOLD;

        $conditions = [
            StockVelocity::FAST => ["{$sold} >= ?", [$contribution->fastMin]],
            StockVelocity::MEDIUM => ["{$sold} >= ? AND {$sold} < ?", [$contribution->mediumMin, $contribution->fastMin]],
            StockVelocity::SLOW => ["{$sold} > 0 AND {$sold} < ?", [$contribution->mediumMin]],
            StockVelocity::NON_MOVING => ["{$sold} = 0", []],
        ];

        $query = $this->base($filters, withView: false);

        foreach ($conditions as $class => [$condition, $bindings]) {
            $query->selectRaw("COALESCE(SUM(CASE WHEN {$condition} THEN 1 ELSE 0 END), 0) as {$class}_products", $bindings)
                ->selectRaw("COALESCE(SUM(CASE WHEN {$condition} THEN {$sold} ELSE 0 END), 0) as {$class}_outgoing", $bindings)
                ->selectRaw("COALESCE(SUM(CASE WHEN {$condition} THEN ".self::CLOSING." ELSE 0 END), 0) as {$class}_closing", $bindings);
        }

        $row = $query->first();

        return collect($conditions)->mapWithKeys(function ($condition, string $class) use ($row, $contribution) {
            $outgoing = (int) ($row->{$class.'_outgoing'} ?? 0);

            return [$class => [
                'products' => (int) ($row->{$class.'_products'} ?? 0),
                'outgoing' => $outgoing,
                'closing' => (int) ($row->{$class.'_closing'} ?? 0),
                'share' => $contribution->share($outgoing),
            ]];
        })->all();
    }

    /**
     * Kategori yang benar-benar dipakai, untuk isi dropdown saringan.
     *
     * @return Collection<int, string>
     */
    public function categories(): Collection
    {
        return Product::query()
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->orderBy('category')
            ->pluck('category');
    }

    /* --------------------------------------------------------- query ----- */

    /**
     * Daftar barang lengkap dengan angka periodenya, sudah diurutkan.
     */
    protected function ordered(StockReportFilters $filters): Builder
    {
        $query = $this->base($filters)
            ->leftJoinSub($this->lastOutgoing($filters), 'lo', 'lo.product_id', '=', 'p.id')
            ->select('p.id', 'p.sku', 'p.name', 'p.category', 'p.unit', 'p.min_stock')
            ->selectRaw(self::DAMAGED_CLOSING.' as damaged_stock')
            ->selectRaw('COALESCE(m.incoming, 0) as incoming')
            ->selectRaw('COALESCE(m.outgoing, 0) as outgoing')
            ->selectRaw(self::SOLD.' as sold')
            ->selectRaw('COALESCE(m.frequency, 0) as frequency')
            ->selectRaw(self::CLOSING.' as closing')
            ->selectRaw(self::OPENING.' as opening')
            ->selectRaw('lo.last_out_at as last_out_at');

        return match ($filters->sort) {
            'masuk' => $query->orderByRaw('COALESCE(m.incoming, 0) DESC')->orderBy('p.name'),
            'frekuensi' => $query->orderByRaw('COALESCE(m.frequency, 0) DESC')->orderByRaw(self::SOLD.' DESC')->orderBy('p.name'),
            'stok' => $query->orderByRaw(self::CLOSING.' DESC')->orderBy('p.name'),
            'sisa' => $query
                // Barang yang tidak bergerak tidak punya perkiraan habis sama
                // sekali; ditaruh paling belakang, bukan dianggap paling awet.
                ->orderByRaw('CASE WHEN '.self::SOLD.' = 0 THEN 1 ELSE 0 END')
                ->orderByRaw(self::CLOSING.' * 1.0 / NULLIF(m.sold, 0)')
                ->orderBy('p.name'),
            'nama' => $query->orderBy('p.name'),
            default => $query->orderByRaw(self::SOLD.' DESC')->orderBy('p.name'),
        };
    }

    /**
     * Daftar barang beserta mutasi periodenya, tanpa select dan tanpa urutan,
     * supaya bisa dipakai baik untuk tabel maupun untuk agregat ringkasan.
     */
    protected function base(StockReportFilters $filters, bool $withView = true): Builder
    {
        $view = $withView ? $filters->view : 'semua';
        $sold = self::SOLD;
        $contribution = in_array($view, ['fast', 'medium', 'slow'], true) ? $this->contribution($filters) : null;

        return DB::table('products as p')
            ->leftJoinSub($this->movements($filters), 'm', 'm.product_id', '=', 'p.id')
            ->leftJoinSub($this->damagedAfter($filters), 'd', 'd.product_id', '=', 'p.id')
            /*
                Paket bundling tidak punya saldo dan tidak pernah menghasilkan
                mutasi, jadi barisnya akan berisi nol di setiap kolom sepanjang
                periode apa pun. Yang ikut hanyalah barang yang benar-benar
                bergerak — mencampurnya hanya mengencerkan angka perputaran.
            */
            ->where('p.type', Product::TYPE_SINGLE)
            ->when($filters->search, fn (Builder $query, string $term) => $query->where(
                fn (Builder $query) => $query
                    ->where('p.sku', 'like', "%{$term}%")
                    ->orWhere('p.name', 'like', "%{$term}%")
                    ->orWhere('p.barcode', 'like', "%{$term}%")
                    ->orWhere('p.category', 'like', "%{$term}%"),
            ))
            ->when($filters->category, fn (Builder $query, string $category) => $query->where('p.category', $category))
            ->when($view === 'bergerak', fn (Builder $query) => $query->whereRaw("{$sold} > 0"))
            ->when($view === 'fast', fn (Builder $query) => $query->whereRaw("{$sold} >= ?", [$contribution->fastMin]))
            ->when($view === 'medium', fn (Builder $query) => $query
                ->whereRaw("{$sold} >= ?", [$contribution->mediumMin])
                ->whereRaw("{$sold} < ?", [$contribution->fastMin]))
            ->when($view === 'slow', fn (Builder $query) => $query
                ->whereRaw("{$sold} > 0")
                ->whereRaw("{$sold} < ?", [$contribution->mediumMin]))
            ->when($view === 'mati', fn (Builder $query) => $query->whereRaw("{$sold} = 0"))
            ->when($view === 'menipis', fn (Builder $query) => $query->whereRaw(self::CLOSING.' <= p.min_stock'));
    }

    /**
     * Rekap mutasi per barang: yang terjadi di dalam periode, dan yang terjadi
     * sesudahnya.
     *
     * Keduanya diambil sekaligus karena saldo akhir periode hanya bisa
     * diketahui dengan menarik mundur saldo hari ini — satu query yang
     * memisahkan dua rentang lebih murah daripada dua query terpisah.
     */
    protected function movements(StockReportFilters $filters): Builder
    {
        return DB::table('stock_movements')
            ->select('product_id')
            ->selectRaw("SUM(CASE WHEN stock_movements.created_at <= ? AND stock_movements.type = 'in' THEN quantity ELSE 0 END) as incoming", [$filters->to])
            ->selectRaw("SUM(CASE WHEN stock_movements.created_at <= ? AND stock_movements.type = 'out' THEN quantity ELSE 0 END) as outgoing", [$filters->to])
            ->selectRaw("SUM(CASE WHEN stock_movements.created_at > ? AND stock_movements.type = 'in' THEN quantity ELSE 0 END) as in_after", [$filters->to])
            ->selectRaw("SUM(CASE WHEN stock_movements.created_at > ? AND stock_movements.type = 'out' THEN quantity ELSE 0 END) as out_after", [$filters->to])
            // Bersih dari pembatalan setelah serah terima (lihat NET_SOLD).
            ->selectRaw(
                "SUM(CASE WHEN stock_movements.created_at <= ? AND reference_type = ? THEN (CASE WHEN stock_movements.type = 'out' THEN quantity ELSE -quantity END) ELSE 0 END) as sold",
                [$filters->to, Outbound::class],
            )
            // Frekuensi dihitung per dokumen: satu dokumen berisi dua baris
            // barang yang sama tetap satu kali keluar. Dokumen yang batal
            // setelah serah terima tidak lagi berstatus terkirim, jadi tidak
            // ikut terhitung.
            ->selectRaw(
                "COUNT(DISTINCT CASE WHEN stock_movements.created_at <= ? AND stock_movements.type = 'out' AND reference_type = ? AND sent.id IS NOT NULL THEN reference_id END) as frequency",
                [$filters->to, Outbound::class],
            )
            ->leftJoin('outbounds as sent', fn ($join) => $join
                ->on('sent.id', '=', 'stock_movements.reference_id')
                ->where('stock_movements.reference_type', Outbound::class)
                ->where('sent.status', Outbound::STATUS_POSTED))
            ->where('bucket', StockMovement::BUCKET_GOOD)
            ->where('stock_movements.created_at', '>=', $filters->from)
            ->groupBy('product_id');
    }

    /**
     * Mutasi saldo rusak sesudah akhir periode, untuk menarik mundur saldo
     * rusak hari ini ke akhir periode.
     */
    protected function damagedAfter(StockReportFilters $filters): Builder
    {
        return DB::table('stock_movements')
            ->select('product_id')
            ->selectRaw("SUM(CASE WHEN type = 'in' THEN quantity ELSE 0 END) as in_after")
            ->selectRaw("SUM(CASE WHEN type = 'out' THEN quantity ELSE 0 END) as out_after")
            ->where('bucket', StockMovement::BUCKET_DAMAGED)
            ->where('created_at', '>', $filters->to)
            ->groupBy('product_id');
    }

    /**
     * Barang keluar operasional selama periode, satu baris per mutasi.
     */
    protected function sales(StockReportFilters $filters): Builder
    {
        // Masuk atas dokumen barang keluar hanya terjadi saat paket batal
        // setelah serah terima; ikut dibaca supaya bisa dikurangkan.
        return DB::table('stock_movements')
            ->where('bucket', StockMovement::BUCKET_GOOD)
            ->where('reference_type', Outbound::class)
            ->whereBetween('created_at', [$filters->from, $filters->to]);
    }

    /**
     * Kapan tiap barang terakhir keluar secara operasional, dibatasi sampai
     * akhir periode supaya tidak menyebut tanggal yang belum terjadi pada
     * laporan periode lampau. Tidak dibatasi awal periode: bagi barang
     * Non-Moving, tanggal sebelum periode justru yang paling perlu dilihat.
     */
    protected function lastOutgoing(StockReportFilters $filters): Builder
    {
        return DB::table('stock_movements')
            ->select('product_id')
            ->selectRaw('MAX(stock_movements.created_at) as last_out_at')
            // Paket yang batal setelah serah terima tidak dihitung keluar.
            ->join('outbounds as sent', fn ($join) => $join
                ->on('sent.id', '=', 'stock_movements.reference_id')
                ->where('sent.status', Outbound::STATUS_POSTED))
            ->where('bucket', StockMovement::BUCKET_GOOD)
            ->where('stock_movements.type', 'out')
            ->where('reference_type', Outbound::class)
            ->where('stock_movements.created_at', '<=', $filters->to)
            ->groupBy('product_id');
    }
}
