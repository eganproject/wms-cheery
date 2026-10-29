<?php

namespace App\Services;

use App\Models\DamagedDisposal;
use App\Models\Inbound;
use App\Models\Outbound;
use App\Models\Product;
use App\Models\ProductImport;
use App\Models\ReturnReceipt;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\StockOpname;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Satu-satunya tempat stok barang berubah.
 *
 * Setiap perubahan selalu meninggalkan jejak di stock_movements sehingga
 * kartu stok bisa direkonstruksi dan saldo bisa ditelusuri.
 */
class StockService
{
    /**
     * Posting barang masuk: stok bertambah.
     */
    public function postInbound(Inbound $inbound): void
    {
        if ($inbound->isPosted()) {
            throw ValidationException::withMessages([
                'status' => 'Dokumen ini sudah diposting sebelumnya.',
            ]);
        }

        $inbound->loadMissing('items');

        if ($inbound->items->isEmpty()) {
            throw ValidationException::withMessages([
                'items' => 'Dokumen belum memiliki baris barang.',
            ]);
        }

        DB::transaction(function () use ($inbound) {
            foreach ($inbound->items as $item) {
                // Yang rusak adalah bagian dari jumlah yang diterima, bukan
                // tambahan di luarnya — jadi keduanya dipisah di sini supaya
                // saldo layak jual tidak pernah memuat barang yang penyok.
                if ($item->goodQuantity() > 0) {
                    $this->move($item->product_id, 'in', $item->goodQuantity(), $inbound, "Barang masuk {$inbound->code}");
                }

                if ($item->damaged_quantity > 0) {
                    $this->move(
                        $item->product_id, 'in', $item->damaged_quantity, $inbound,
                        "Barang masuk rusak {$inbound->code}", StockMovement::BUCKET_DAMAGED,
                    );
                }
            }

            $inbound->forceFill([
                'status' => Inbound::STATUS_POSTED,
                'posted_at' => now(),
            ])->save();
        });
    }

    /**
     * Posting barang keluar: stok berkurang.
     */
    public function postOutbound(Outbound $outbound): void
    {
        if ($outbound->isPosted()) {
            throw ValidationException::withMessages([
                'status' => 'Dokumen ini sudah diproses sebelumnya.',
            ]);
        }

        $outbound->loadMissing('items.product');

        if ($blockers = $outbound->postingBlockers()) {
            throw ValidationException::withMessages(['status' => $blockers]);
        }

        $this->guardAgainstNegativeStock($outbound->items);

        DB::transaction(function () use ($outbound) {
            foreach ($outbound->items as $item) {
                $this->move($item->product_id, 'out', $item->quantity, $outbound, "Barang keluar {$outbound->code}");
            }

            $outbound->forceFill([
                'status' => Outbound::STATUS_POSTED,
                'posted_at' => now(),
            ])->save();
        });
    }

    /**
     * Batalkan pengiriman yang sudah diposting: stok kembali, dokumen kembali
     * ke draft (tidak lagi dihitung terkirim). Dipakai saat pembeli membatalkan
     * pesanan pada hari yang sama dengan QC kemas. Jejaknya tetap ada di kartu
     * stok (keluar lalu masuk lagi) dan di kolom reversed_at.
     */
    public function reverseOutbound(Outbound $outbound, string $description): void
    {
        if (! $outbound->isPosted()) {
            throw ValidationException::withMessages([
                'status' => 'Dokumen ini belum dikirim, jadi tidak ada stok yang perlu dikembalikan.',
            ]);
        }

        $outbound->loadMissing('items');

        DB::transaction(function () use ($outbound, $description) {
            // Dibaca ulang dengan kunci: dua permintaan batal yang bersamaan
            // tidak boleh sama-sama mengembalikan stok.
            if (Outbound::lockForUpdate()->whereKey($outbound->getKey())->value('status') !== Outbound::STATUS_POSTED) {
                throw ValidationException::withMessages([
                    'status' => 'Dokumen ini sudah dibatalkan, stoknya sudah dikembalikan.',
                ]);
            }

            foreach ($outbound->items as $item) {
                $this->move($item->product_id, 'in', $item->quantity, $outbound, $description);
            }

            $outbound->forceFill([
                'status' => Outbound::STATUS_DRAFT,
                'posted_at' => null,
                'submitted_at' => null,
                'submitted_by' => null,
                'approved_at' => null,
                'approved_by' => null,
                'reversed_at' => now(),
                'reversed_by' => auth()->id(),
            ])->save();
        });
    }

    /**
     * Terima retur: hanya barang berkondisi layak jual yang kembali ke stok.
     * Barang rusak tetap tercatat pada dokumen tetapi tidak menambah stok.
     */
    public function postReturn(ReturnReceipt $return): void
    {
        if ($return->isPosted()) {
            throw ValidationException::withMessages([
                'status' => 'Dokumen ini sudah diterima sebelumnya.',
            ]);
        }

        $return->loadMissing('items');

        if ($blockers = $return->postingBlockers()) {
            throw ValidationException::withMessages(['status' => $blockers]);
        }

        DB::transaction(function () use ($return) {
            foreach ($return->items as $item) {
                if ($item->good_quantity > 0) {
                    $this->move($item->product_id, 'in', $item->good_quantity, $return, "Penerimaan retur {$return->code}");
                }

                // Barang rusak tidak menambah stok layak jual, tetapi tetap
                // ada fisiknya di gudang — jadi masuk ke saldo rusak.
                if ($item->damaged_quantity > 0) {
                    $this->move(
                        $item->product_id, 'in', $item->damaged_quantity, $return,
                        "Retur rusak {$return->code}", StockMovement::BUCKET_DAMAGED,
                    );
                }
            }

            $return->forceFill([
                'status' => ReturnReceipt::STATUS_POSTED,
                'posted_at' => now(),
            ])->save();
        });
    }

    /**
     * Terapkan penyesuaian stok: saldo disamakan dengan hasil hitung fisik.
     *
     * Selisih dihitung terhadap saldo saat baris disusun (saat barang
     * dihitung), lalu dibukukan pada saldo saat disetujui. Barang yang
     * bergerak di antara keduanya tetap tercatat. Selisih yang dibukukan
     * disimpan pada barisnya.
     */
    public function postAdjustment(StockAdjustment $adjustment): void
    {
        if ($adjustment->isPosted()) {
            throw ValidationException::withMessages([
                'status' => 'Dokumen ini sudah disesuaikan sebelumnya.',
            ]);
        }

        $adjustment->loadMissing('items.product');

        if ($blockers = $adjustment->postingBlockers()) {
            throw ValidationException::withMessages(['status' => $blockers]);
        }

        DB::transaction(function () use ($adjustment) {
            foreach ($adjustment->items as $item) {
                $product = Product::lockForUpdate()->findOrFail($item->product_id);

                // Selisih terhadap saldo saat barisnya disusun (system_quantity),
                // bukan saldo saat disetujui: barang yang keluar-masuk di antara
                // keduanya memang terjadi dan tidak boleh ikut terhapus.
                $difference = $item->actual_quantity - $item->system_quantity;

                if ($difference === 0) {
                    $item->update(['applied_difference' => 0]);

                    continue;
                }

                $this->move(
                    $product->id,
                    $difference > 0 ? 'in' : 'out',
                    abs($difference),
                    $adjustment,
                    "Penyesuaian stok {$adjustment->code} — {$adjustment->reason}",
                );

                $item->update(['applied_difference' => $difference]);
            }

            $adjustment->forceFill([
                'status' => StockAdjustment::STATUS_POSTED,
                'posted_at' => now(),
            ])->save();
        });
    }

    /**
     * Selesaikan dokumen pengeluaran barang.
     *
     * Unit keluar dari saldo asal yang disebut dokumennya. Bila tindakannya
     * hanya memindahkan barang antar saldo — diperbaiki atau ditandai rusak —
     * unit yang sama langsung masuk ke saldo tujuan dalam transaksi yang sama,
     * sehingga tidak pernah ada saat di mana barangnya seolah lenyap.
     */
    public function postDisposal(DamagedDisposal $disposal): void
    {
        if ($disposal->isPosted()) {
            throw ValidationException::withMessages([
                'status' => 'Dokumen ini sudah diproses sebelumnya.',
            ]);
        }

        $disposal->loadMissing('items.product');

        if ($blockers = $disposal->postingBlockers()) {
            throw ValidationException::withMessages(['status' => $blockers]);
        }

        DB::transaction(function () use ($disposal) {
            $source = $disposal->sourceBucket();
            $target = $disposal->targetBucket();

            $leaving = $disposal->isFromDamaged()
                ? "Barang rusak {$disposal->code} — {$disposal->actionLabel()}"
                : "Pengeluaran barang {$disposal->code} — {$disposal->actionLabel()}";

            $arriving = match ($target) {
                StockMovement::BUCKET_GOOD => "Perbaikan barang rusak {$disposal->code}",
                StockMovement::BUCKET_DAMAGED => "Ditandai rusak {$disposal->code}",
                default => null,
            };

            foreach ($disposal->items as $item) {
                $this->move($item->product_id, 'out', $item->quantity, $disposal, $leaving, $source);

                if ($target !== null) {
                    $this->move($item->product_id, 'in', $item->quantity, $disposal, $arriving, $target);
                }
            }

            $disposal->forceFill([
                'status' => DamagedDisposal::STATUS_POSTED,
                'posted_at' => now(),
            ])->save();
        });
    }

    /**
     * Terapkan hasil stok opname.
     *
     * Hanya baris yang benar-benar dihitung yang dibukukan; baris yang belum
     * disentuh petugas dibiarkan apa adanya, karena tidak dihitung bukan
     * berarti nol. Selisihnya dihitung terhadap saldo pada saat rak
     * dihitung (saldo sekarang dikurangi pergerakan sesudah hitungan), jadi
     * paket yang terkirim sambil menunggu persetujuan tidak ikut terhapus.
     */
    public function postOpname(StockOpname $opname): void
    {
        if ($opname->isPosted()) {
            throw ValidationException::withMessages([
                'status' => 'Sesi opname ini sudah diterapkan sebelumnya.',
            ]);
        }

        $opname->loadMissing('items.product');

        if ($blockers = $opname->postingBlockers()) {
            throw ValidationException::withMessages(['status' => $blockers]);
        }

        DB::transaction(function () use ($opname) {
            foreach ($opname->items as $item) {
                if (! $item->isCounted()) {
                    continue;
                }

                $product = Product::lockForUpdate()->findOrFail($item->product_id);

                // Selisih terhadap saldo pada saat rak dihitung, bukan saat
                // disetujui. Paket yang terkirim (atau barang yang masuk)
                // sesudah hitungan tetap tercatat; tanpa ini stoknya kembali
                // ke angka hitungan seolah paket itu tidak pernah keluar.
                $atCount = $product->stock - $this->netMovementSince($product->id, $item->counted_at);
                $difference = $item->counted_quantity - $atCount;

                if ($difference !== 0) {
                    $this->move(
                        $product->id,
                        $difference > 0 ? 'in' : 'out',
                        abs($difference),
                        $opname,
                        "Stok opname {$opname->code} — {$opname->scopeLabel()}",
                    );
                }

                /*
                    Unit rusak yang ditemukan saat menghitung ditambahkan ke
                    saldo rusak, bukan menggantikannya: barang rusak lain bisa
                    saja tersimpan di rak yang tidak ikut dihitung sesi ini.

                    Tanpa ini, temuan seperti itu hanya membuat hasil hitung
                    lebih kecil — sehingga tercatat sebagai barang hilang,
                    padahal barangnya masih ada dan mungkin bisa diklaim.
                */
                if ($item->damaged_quantity > 0) {
                    $this->move(
                        $product->id, 'in', $item->damaged_quantity, $opname,
                        "Rusak ditemukan saat opname {$opname->code}",
                        StockMovement::BUCKET_DAMAGED,
                    );
                }

                $item->update([
                    'applied_difference' => $difference,
                    'applied_damaged' => $item->damaged_quantity,
                ]);
            }

            $opname->forceFill([
                'status' => StockOpname::STATUS_POSTED,
                'posted_at' => now(),
            ])->save();
        });
    }

    /**
     * Pergerakan bersih saldo layak jual sesudah suatu waktu (masuk dikurangi keluar).
     */
    protected function netMovementSince(int $productId, ?\DateTimeInterface $since): int
    {
        if ($since === null) {
            return 0;
        }

        return (int) StockMovement::query()
            ->where('product_id', $productId)
            ->where('bucket', StockMovement::BUCKET_GOOD)
            ->where('created_at', '>', $since)
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'in' THEN quantity ELSE -quantity END), 0) as net")
            ->value('net');
    }

    /**
     * Setel stok ke angka tertentu, mis. saat import stok awal dari Excel.
     *
     * Selisihnya tetap dicatat sebagai pergerakan stok supaya kartu stok
     * bisa direkonsiliasi seperti transaksi lain.
     *
     * @return bool Apakah stok benar-benar berubah.
     */
    public function setStock(Product $product, int $target, ProductImport $reference, string $description): bool
    {
        if ($target < 0) {
            throw ValidationException::withMessages([
                'stock' => "Stok {$product->sku} tidak boleh bernilai negatif.",
            ]);
        }

        return DB::transaction(function () use ($product, $target, $reference, $description) {
            $locked = Product::lockForUpdate()->findOrFail($product->id);

            $difference = $target - $locked->stock;

            if ($difference === 0) {
                return false;
            }

            $this->move(
                $locked->id,
                $difference > 0 ? 'in' : 'out',
                abs($difference),
                $reference,
                $description,
            );

            return true;
        });
    }

    /**
     * Catat satu pergerakan stok dan perbarui saldo barang.
     *
     * Tiap barang punya dua saldo: layak jual dan rusak. Saldo mana yang
     * disentuh ditentukan $bucket, dan pergerakannya menyimpan itu juga
     * supaya kartu stok keduanya bisa direkonstruksi terpisah.
     */
    protected function move(
        int $productId,
        string $type,
        int $quantity,
        Inbound|Outbound|ReturnReceipt|ProductImport|StockAdjustment|StockOpname|DamagedDisposal $reference,
        string $description,
        string $bucket = StockMovement::BUCKET_GOOD,
    ): void {
        // Kunci baris agar dua dokumen tidak menghitung saldo dari angka yang sama.
        $product = Product::lockForUpdate()->findOrFail($productId);

        /*
            Paket bundling tidak punya saldo, jadi tidak ada yang bisa
            digerakkan atas namanya.

            Penjagaannya ditaruh di sini, bukan di tiap pemanggil, karena
            inilah satu-satunya pintu menuju perubahan stok. Selama ia
            tertutup, tidak ada dokumen jenis apa pun — sekarang maupun yang
            ditambahkan nanti — yang bisa menghitung satu barang dua kali:
            sekali sebagai paket, sekali sebagai isinya.

            Pemeriksaannya membaca kolom pada model yang sudah dimuat baris di
            atas, jadi tidak ada kueri tambahan pada tiap pergerakan stok.
        */
        if ($product->isBundle()) {
            throw ValidationException::withMessages([
                'items' => "{$product->sku} adalah paket bundling dan tidak punya stok sendiri. Yang bergerak adalah barang isinya.",
            ]);
        }

        $column = $bucket === StockMovement::BUCKET_DAMAGED ? 'damaged_stock' : 'stock';
        $current = (int) $product->{$column};

        $balance = $type === 'in' ? $current + $quantity : $current - $quantity;

        if ($balance < 0) {
            throw ValidationException::withMessages([
                'items' => $bucket === StockMovement::BUCKET_DAMAGED
                    ? "Stok rusak {$product->name} tidak mencukupi (tersisa {$current} {$product->unit})."
                    : "Stok {$product->name} tidak mencukupi (tersisa {$current} {$product->unit}).",
            ]);
        }

        $product->forceFill([$column => $balance])->save();

        StockMovement::create([
            'product_id' => $product->id,
            'type' => $type,
            'bucket' => $bucket,
            'quantity' => $quantity,
            'balance_after' => $balance,
            'reference_type' => $reference->getMorphClass(),
            'reference_id' => $reference->getKey(),
            'description' => $description,
            'user_id' => auth()->id(),
        ]);
    }

    /**
     * Cek ketersediaan stok sebelum transaksi dimulai supaya pesan error
     * bisa menyebut semua barang yang bermasalah sekaligus.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\InboundItem|\App\Models\OutboundItem|\App\Models\ReturnReceiptItem>  $items
     */
    protected function guardAgainstNegativeStock($items): void
    {
        $errors = [];

        foreach ($items->groupBy('product_id') as $productId => $group) {
            $product = $group->first()->product ?? Product::find($productId);
            $needed = $group->sum('quantity');

            if ($product && $needed > $product->stock) {
                $errors[] = "Stok {$product->name} tidak mencukupi: butuh {$needed}, tersedia {$product->stock} {$product->unit}.";
            }
        }

        if ($errors) {
            throw ValidationException::withMessages(['items' => $errors]);
        }
    }
}
