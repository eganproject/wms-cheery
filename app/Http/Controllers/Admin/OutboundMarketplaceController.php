<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Outbound;
use App\Models\OutboundBundle;
use App\Models\OutboundItem;
use App\Models\ShipmentOrder;
use App\Services\OutboundScanService;
use App\Services\OutboundSubstitutionService;
use App\Services\ShipmentOrderResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Stasiun packing pesanan marketplace.
 *
 * Satu halaman dipakai berulang: scan resi, scan barangnya, lalu layar
 * langsung kembali menunggu resi berikutnya. Stasiun ini hanya memverifikasi
 * isi paket — pengirimannya dikerjakan di antrean "Serah Terima Paket", supaya
 * operator tidak pernah berhenti untuk mengurus dokumen.
 */
class OutboundMarketplaceController extends Controller implements HasMiddleware
{
    public function __construct(
        protected ShipmentOrderResolver $resolver,
        protected OutboundScanService $scanner,
        protected OutboundSubstitutionService $substitutions,
    ) {
    }

    public static function middleware(): array
    {
        return [
            new Middleware('can:outbounds.create', only: ['create', 'store']),
            new Middleware('can:outbounds.scan', only: ['create', 'store']),
        ];
    }

    public function create(): View
    {
        return view('admin.outbounds.marketplace', [
            // Hanya yang scannya belum tuntas; yang sudah lengkap ada di
            // antrean serah terima, bukan pekerjaan stasiun ini lagi.
            'pending' => Outbound::stillScanning()->latest('id')->take(5)->get(),
            'handoverCount' => Outbound::awaitingHandover()->count(),
        ]);
    }

    /**
     * Scan resi: cari ke data import, bentuk dokumennya, lalu kirim balik
     * daftar barang yang harus discan berikut alamat endpoint lanjutannya.
     */
    public function store(Request $request): JsonResponse
    {
        $code = $request->validate([
            'code' => ['required', 'string', 'max:191'],
        ])['code'];

        $order = $this->resolver->resolve($code);

        if (! $order) {
            throw ValidationException::withMessages([
                'code' => 'Resi tidak ditemukan di data import. Import dulu berkas pesanan dari TikTok Shop, atau buat dokumen manual.',
            ]);
        }

        // Diperiksa sebelum dokumen dibentuk: paket batal tidak boleh dibuka
        // sama sekali, karena setiap unit yang discan akan berakhir sebagai
        // stok yang berkurang untuk pesanan yang tidak akan pernah berangkat.
        if ($order->isCancelled()) {
            throw ValidationException::withMessages([
                'code' => 'Pesanan dibatalkan pembeli · kembalikan barang ke rak',
            ]);
        }

        // Layar yang bisa menawarkan substitusi (QC Kemas web dan Android
        // versi baru) mengirim flag ini; paket yang stoknya kurang tetap
        // dibuka untuk pengguna yang boleh mengganti SKU.
        $canSubstitute = $request->boolean('substitution') && (bool) $request->user()?->can('outbounds.substitute');

        $outbound = $this->documentFor($order, $canSubstitute);

        return response()->json([
            'outbound' => [
                'id' => $outbound->id,
                'code' => $outbound->code,
                'tracking_number' => $outbound->tracking_number,
                'marketplace' => $outbound->marketplace,
                'recipient' => $outbound->recipient,
                'order_number' => $order->order_number,
            ],
            // Stok ikut dikirim supaya operator tahu sejak resi discan bila ada
            // barang yang tidak cukup — bukan setelah semuanya terlanjur discan.
            'items' => $this->scanner->itemsPayload($outbound),
            // Paket yang menghasilkan baris di atas. Yang discan tetap
            // barangnya, tetapi operator perlu tahu ia sedang merakit paket —
            // tanpa ini layar hanya menampilkan barang lepas yang jumlahnya
            // tidak cocok dengan apa pun yang tertulis di label paket.
            'bundles' => $outbound->bundles->map(fn (OutboundBundle $bundle) => [
                'sku' => $bundle->bundle?->sku,
                'name' => $bundle->bundle?->name,
                'quantity' => $bundle->quantity,
                'composition' => $bundle->composition,
            ])->values(),
            'progress' => $this->scanner->progress($outbound),
            'can_substitute' => $canSubstitute,
            'urls' => [
                'item' => route('admin.outbounds.scan.item', $outbound),
                'substitute' => route('admin.outbounds.scan.substitute', $outbound),
                'detail' => route('admin.outbounds.show', $outbound),
            ],
        ]);
    }

    /**
     * Ambil dokumen draft yang sudah ada untuk resi ini, atau buat baru dari
     * data import. Resi langsung ditandai terverifikasi karena baru saja discan.
     */
    protected function documentFor(ShipmentOrder $order, bool $canSubstitute = false): Outbound
    {
        // Baris pesanannya dikunci lebih dulu: dua stasiun QC yang memindai
        // resi yang sama pada saat bersamaan tidak boleh sama-sama membuat
        // dokumen baru untuknya. Yang kedua menunggu, lalu memakai dokumen
        // yang dibuat yang pertama.
        return DB::transaction(function () use ($order, $canSubstitute) {
            ShipmentOrder::whereKey($order->getKey())->lockForUpdate()->first();

            return $this->openDocumentFor($order, $canSubstitute);
        });
    }

    protected function openDocumentFor(ShipmentOrder $order, bool $canSubstitute = false): Outbound
    {
        $existing = Outbound::with('items')
            ->where('shipment_order_id', $order->id)
            ->orWhere('tracking_number', $order->tracking_number)
            ->latest('id')
            ->first();

        if ($existing && ! $existing->isEditable()) {
            throw ValidationException::withMessages([
                'code' => "Resi ini sudah diproses pada dokumen {$existing->code}.",
            ]);
        }

        // Paket yang scannya sudah tuntas tidak boleh dibuka ulang.
        //
        // Dokumennya masih berstatus draft karena menunggu diproses di
        // antrean, dan tanpa penjagaan ini scan resi kedua akan menghapus
        // seluruh baris barangnya lalu membuatnya dari nol — hasil QC yang
        // sudah selesai hilang tanpa pemberitahuan. Kejadian ini gampang
        // terjadi tanpa disengaja: setelah paket ditutup layar kembali
        // menunggu resi, sementara label yang sama masih di depan kamera.
        if ($existing && $existing->isResiVerified() && $existing->items->isNotEmpty()
            && $existing->items->every(fn (OutboundItem $item) => $item->isFullyScanned())) {
            throw ValidationException::withMessages([
                'code' => "Resi sudah selesai discan · menunggu diproses ({$existing->code})",
            ]);
        }

        // Baris barang selalu diambil ulang dari data import agar sesuai pesanan.
        // Paket bundling sudah dipecah menjadi barang isinya oleh resolver.
        $lines = $this->resolver->toOutboundLines($order);

        // Substitusi SKU yang sudah dilakukan pada paket ini tetap berlaku:
        // unit penggantinya dikurangkan dari baris pesanan aslinya.
        $substituted = $existing
            ? $existing->items->filter(fn (OutboundItem $item) => $item->isSubstitute())->values()
            : collect();
        [$orderLines, $substituteLines] = $this->substitutions->reapply($lines->items, $substituted);

        // Resi tanpa barang sama sekali (baris pesanannya kosong di berkas
        // import) tidak dibuka: tidak ada yang bisa discan, sehingga layar
        // tertahan di tahap scan barang tanpa jalan keluar.
        if ($lines->items === []) {
            throw ValidationException::withMessages([
                'code' => 'Resi ini tidak berisi barang · periksa data import pesanannya',
            ]);
        }

        // Paket yang stoknya tidak cukup tidak dibuka sama sekali: lebih baik
        // ditolak sebelum ada yang discan daripada tersangkut di tengah.
        //
        // Yang diperiksa adalah barang hasil pemecahan, bukan SKU paketnya —
        // sehingga pesannya menyebut komponen mana yang kurang, yang memang
        // satu-satunya hal yang bisa ditindaklanjuti operator.
        //
        // Pengguna yang boleh substitusi SKU tetap bisa membuka paketnya:
        // barang yang stoknya kurang bisa diganti barang lain saat scan.
        if (! $canSubstitute && ($shortages = $this->scanner->stockShortages(array_merge($orderLines, $substituteLines)))) {
            throw ValidationException::withMessages([
                'code' => $shortages,
            ]);
        }

        return DB::transaction(function () use ($order, $existing, $lines, $orderLines, $substituteLines) {
            $outbound = $existing ?? new Outbound([
                'code' => Outbound::nextCode(),
                'date' => now()->toDateString(),
                'type' => Outbound::TYPE_MARKETPLACE,
                'user_id' => auth()->id(),
            ]);

            $outbound->fill([
                'recipient' => $order->buyer_name ?: ($order->store_name ?: 'Pembeli marketplace'),
                'tracking_number' => $order->tracking_number,
                'shipment_order_id' => $order->id,
                'status' => Outbound::STATUS_DRAFT,
                'resi_verified_at' => now(),
            ])->save();

            // Scan ulang resi yang baru setengah discan melanjutkan hitungan
            // terakhir, bukan mengulang dari nol. Barisnya tetap ditulis ulang
            // dari data import (pesanannya bisa berubah), tetapi unit yang
            // sudah discan dibawa — tidak pernah melebihi jumlah pesanan.
            $scanned = $existing
                ? $existing->items->reject(fn (OutboundItem $item) => $item->isSubstitute())
                    ->groupBy('product_id')->map(fn ($items) => (int) $items->sum('scanned_quantity'))
                : collect();

            $outbound->items()->delete();
            $outbound->bundles()->delete();
            $outbound->items()->createMany(array_map(fn (array $line) => array_merge($line, [
                'scanned_quantity' => min($scanned[$line['product_id']] ?? 0, (int) $line['quantity']),
            ]), $orderLines));
            $outbound->items()->createMany($substituteLines);
            $outbound->bundles()->createMany($lines->bundles);

            return $outbound->load('items.product', 'items.substitutedFrom', 'bundles.bundle');
        });
    }
}
