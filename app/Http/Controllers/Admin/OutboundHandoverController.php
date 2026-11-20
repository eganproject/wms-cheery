<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Outbound;
use App\Services\ApprovalService;
use App\Models\ShipmentOrder;
use App\Services\TableExport;
use App\Support\DateRange;
use App\Support\Listing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Halaman Serah Terima Paket: paket yang sudah lolos QC diserahkan ke kurir.
 *
 * Stasiun packing hanya memastikan isi paket sesuai pesanan, lalu langsung
 * lanjut ke resi berikutnya. Keputusan mengirim — yang memindahkan stok —
 * dikerjakan terpisah dari sini, sekaligus untuk banyak paket.
 */
class OutboundHandoverController extends Controller implements HasMiddleware
{
    public function __construct(protected ApprovalService $approvals)
    {
    }

    public static function middleware(): array
    {
        return [
            new Middleware('can:outbounds.view', only: ['index', 'export', 'history', 'historyExport']),
            new Middleware('can:outbounds.post', only: ['process', 'scan']),
        ];
    }

    /**
     * Alamat lama /admin/outbounds/ready (dulu "Siap Dikirim") dialihkan ke
     * sini supaya bookmark operator tetap jalan.
     */
    public function legacy(Request $request): RedirectResponse
    {
        return redirect()->route('admin.outbounds.handover', $request->query(), 301);
    }

    /**
     * Excel antrean serah terima sesuai saringan: bisa dicetak sebagai
     * manifest untuk dicocokkan dengan kurir saat paket diserahkan.
     */
    public function export(Request $request): StreamedResponse
    {
        $rows = function () use ($request) {
            $number = 0;

            foreach ($this->filtered($request)->with('shipmentOrder')->withCount('items')->withSum('items', 'quantity')->lazy(500) as $outbound) {
                $order = $outbound->shipmentOrder;

                yield [
                    ++$number,
                    $outbound->tracking_number,
                    $order?->order_number,
                    $outbound->code,
                    $order?->courier,
                    $order?->marketplace,
                    $order?->store_name,
                    $outbound->recipient ?: $order?->buyer_name,
                    $outbound->items_count,
                    (int) $outbound->items_sum_quantity,
                    $outbound->resi_verified_at,
                    $order?->isCancelled() ? 'BATAL, jangan dikirim' : null,
                    null,
                ];
            }
        };

        return TableExport::make('Serah Terima Paket')
            ->note(collect([
                'Paket lolos QC yang menunggu diserahkan ke kurir',
                $request->filled('marketplace') ? 'Marketplace: '.$request->string('marketplace') : null,
                $request->filled('courier') ? 'Kurir: '.($request->input('courier') === '-' ? 'tanpa kurir' : $request->string('courier')) : null,
                $request->filled('store') ? 'Toko: '.$request->string('store') : null,
                $request->filled('search') ? 'Cari: '.$request->string('search')->trim() : null,
            ])->filter()->implode(' · '))
            ->sheet('Antrean Serah Terima', [
                ['No', 'number'],
                ['No. Resi', 'text'],
                ['No. Pesanan', 'text'],
                ['No. Dokumen', 'text'],
                ['Kurir', 'text'],
                ['Marketplace', 'text'],
                ['Toko', 'text'],
                ['Penerima', 'text'],
                ['Jumlah Baris', 'number'],
                ['Total Qty', 'sum'],
                ['Resi Discan', 'datetime'],
                ['Peringatan', 'text'],
                ['Paraf Kurir', 'text'],
            ], $rows())
            ->download('serah-terima-paket-'.now()->format('Y-m-d-Hi').'.xlsx');
    }

    /**
     * Saringan yang sama untuk tabel di layar dan berkas export.
     */
    protected function filtered(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        return Outbound::awaitingHandover()
            ->search($request->string('search')->trim()->value())
            ->fromMarketplace($request->string('marketplace')->value())
            ->tap(fn (Builder $query) => $this->courierStore($query, $request))
            // Paket yang paling lama menunggu dikerjakan lebih dulu.
            ->oldest('id');
    }

    /**
     * Riwayat serah terima: paket marketplace yang sudah diserahkan ke kurir
     * (dokumennya diproses), menurut waktu serah terimanya. Bawaannya hari ini.
     */
    public function history(Request $request): View
    {
        $range = DateRange::fromRequest($request);

        $summary = $this->historyQuery($request, withCourier: false)
            ->leftJoin('shipment_orders as so', 'so.id', '=', 'outbounds.shipment_order_id')
            ->toBase()
            ->selectRaw("COALESCE(NULLIF(so.courier, ''), '-') as courier_name, COUNT(*) as packages")
            ->groupBy('courier_name')
            ->orderByDesc('packages')
            ->get();

        $outbounds = $this->historyQuery($request)
            ->with(['shipmentOrder', 'submitter:id,name', 'user:id,name'])
            ->withCount('items')
            ->withSum('items', 'quantity')
            ->orderByDesc('posted_at')
            ->orderByDesc('id')
            ->paginate(Listing::perPage($request))
            ->withQueryString();

        return view('admin.outbounds.handover-history', [
            'outbounds' => $outbounds,
            'range' => $range,
            'summary' => $summary,
            'total' => (int) $summary->sum('packages'),
            'units' => (int) $this->historyQuery($request)
                ->join('outbound_items', 'outbound_items.outbound_id', '=', 'outbounds.id')
                ->sum('outbound_items.quantity'),
            'marketplaces' => ShipmentOrder::filterOptions('marketplace'),
            'stores' => ShipmentOrder::filterOptions('store_name'),
            // Petugas yang menyerahkan paket pada periode ini saja.
            'handlers' => \App\Models\User::query()
                ->whereIn('id', $this->historyQuery($request, withUser: false)->whereNotNull('outbounds.submitted_by')->select('outbounds.submitted_by'))
                ->orderBy('name')
                ->pluck('name', 'id'),
            'handoverCount' => Outbound::awaitingHandover()->count(),
        ]);
    }

    public function historyExport(Request $request): StreamedResponse
    {
        $rows = function () use ($request) {
            $number = 0;

            foreach ($this->historyQuery($request)->with(['shipmentOrder', 'submitter:id,name', 'user:id,name'])
                ->withCount('items')->withSum('items', 'quantity')
                ->orderBy('posted_at')->orderBy('id')->lazy(500) as $outbound) {
                $order = $outbound->shipmentOrder;

                yield [
                    ++$number,
                    $outbound->posted_at,
                    $outbound->tracking_number,
                    $order?->order_number,
                    $outbound->code,
                    $order?->courier,
                    $order?->marketplace,
                    $order?->store_name,
                    $outbound->recipient ?: $order?->buyer_name,
                    $outbound->items_count,
                    (int) $outbound->items_sum_quantity,
                    $outbound->resi_verified_at,
                    $outbound->user?->name,
                    $outbound->submitter?->name,
                ];
            }
        };

        return TableExport::make('Riwayat Serah Terima')
            ->note(collect([
                'Waktu serah terima: '.DateRange::fromRequest($request)->label(),
                $request->filled('courier') ? 'Kurir: '.($request->input('courier') === '-' ? 'tanpa kurir' : $request->string('courier')) : null,
                $request->filled('store') ? 'Toko: '.$request->string('store') : null,
                $request->filled('by') ? 'Diserahkan oleh: '.\App\Models\User::whereKey((int) $request->input('by'))->value('name') : null,
                $request->filled('marketplace') ? 'Marketplace: '.$request->string('marketplace') : null,
                $request->filled('search') ? 'Cari: '.$request->string('search')->trim() : null,
            ])->filter()->implode(' · '))
            ->sheet('Riwayat Serah Terima', [
                ['No', 'number'],
                ['Serah Terima', 'datetime'],
                ['No. Resi', 'text'],
                ['No. Pesanan', 'text'],
                ['No. Dokumen', 'text'],
                ['Kurir', 'text'],
                ['Marketplace', 'text'],
                ['Toko', 'text'],
                ['Penerima', 'text'],
                ['Jumlah SKU', 'number'],
                ['Total Qty', 'sum'],
                ['QC Kemas', 'datetime'],
                ['Petugas QC', 'text'],
                ['Diserahkan Oleh', 'text'],
            ], $rows())
            ->download('riwayat-serah-terima-'.now()->format('Y-m-d-Hi').'.xlsx');
    }

    /**
     * Paket marketplace yang sudah diserahkan ke kurir pada rentang tanggal.
     */
    protected function historyQuery(Request $request, bool $withCourier = true, bool $withUser = true): Builder
    {
        $range = DateRange::fromRequest($request);

        return Outbound::query()
            ->where('outbounds.type', Outbound::TYPE_MARKETPLACE)
            ->where('outbounds.status', Outbound::STATUS_POSTED)
            ->tap(fn (Builder $query) => DateRange::constrain($query, 'outbounds.posted_at', $range->from, $range->to))
            ->search($request->string('search')->trim()->value())
            ->fromMarketplace($request->string('marketplace')->value())
            ->tap(fn (Builder $query) => $this->courierStore($query, $request, $withCourier))
            ->when($withUser && $request->filled('by'), fn (Builder $query) => $query->where('outbounds.submitted_by', (int) $request->input('by')));
    }

    /**
     * Saringan kurir dan toko lewat pesanan yang tertaut. Kurir "-" berarti
     * resi tanpa kurir.
     */
    protected function courierStore(Builder $query, Request $request, bool $withCourier = true): Builder
    {
        $courier = $withCourier ? $request->string('courier')->value() : '';
        $store = $request->string('store')->value();

        return $query
            ->when($courier !== '', fn (Builder $query) => $query->whereHas('shipmentOrder', fn (Builder $order) => $courier === '-'
                ? $order->where(fn (Builder $order) => $order->whereNull('courier')->orWhere('courier', ''))
                : $order->where('courier', $courier)))
            ->when($store !== '', fn (Builder $query) => $query->whereHas('shipmentOrder', fn (Builder $order) => $order->where('store_name', $store)));
    }

    public function index(Request $request): View
    {
        $outbounds = $this->filtered($request)
            ->with('user', 'shipmentOrder')
            ->withCount('items')
            ->withSum('items', 'quantity')
            ->paginate(Listing::perPage($request))
            ->withQueryString();

        return view('admin.outbounds.handover', [
            'outbounds' => $outbounds,
            // Marketplace barang keluar dibaca dari data import yang tertaut,
            // jadi pilihannya juga dari sana — bukan daftar tetap yang
            // sebagian besar isinya tidak pernah cocok dengan apa pun.
            'marketplaces' => \App\Models\ShipmentOrder::filterOptions('marketplace'),
            'couriers' => ShipmentOrder::filterOptions('courier'),
            'stores' => ShipmentOrder::filterOptions('store_name'),
            // Sengaja tanpa filter: memindai resi bekerja atas seluruh antrean,
            // bukan hanya atas yang kebetulan tampil di layar.
            'handoverCount' => Outbound::awaitingHandover()->count(),
        ]);
    }

    /**
     * Proses paket terpilih. Satu paket yang gagal tidak menggagalkan sisanya —
     * hasilnya dilaporkan per dokumen supaya jelas mana yang perlu ditangani.
     */
    public function process(Request $request): RedirectResponse
    {
        $ids = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ])['ids'];

        $documents = Outbound::awaitingHandover()
            ->whereIn('id', $ids)
            ->with('items.product', 'shipmentOrder')
            ->get();

        if ($documents->isEmpty()) {
            return $this->backToQueue($request)
                ->with('error', 'Tidak ada paket yang bisa diproses. Mungkin sudah dikerjakan orang lain.');
        }

        $selfApprove = $request->user()->can('outbounds.approve');

        $done = [];
        $failed = [];

        foreach ($documents as $outbound) {
            // Dijaga di sini juga, bukan hanya pada jalur scan: mencentang
            // seluruh halaman lalu menekan proses adalah cara termudah
            // mengirim paket batal tanpa sengaja.
            if ($outbound->shipmentOrder?->isCancelled()) {
                $failed[] = $outbound->code.' — Pesanan dibatalkan pembeli, kembalikan barang ke rak.';

                continue;
            }

            try {
                $selfApprove
                    ? $this->approvals->submitAndApprove($outbound)
                    : $this->approvals->submit($outbound);

                $done[] = $outbound->code;
            } catch (ValidationException $exception) {
                $failed[] = $outbound->code.' — '.collect($exception->errors())->flatten()->implode(' ');
            }
        }

        return $this->backToQueue($request)
            ->with('success', $done ? $this->summary($done, $selfApprove) : null)
            ->with('error', $failed
                ? count($failed).' paket gagal diproses. '.implode(' | ', $failed)
                : null);
    }

    /**
     * Kirim satu paket dengan memindai resinya.
     *
     * Dipakai di titik serah ke kurir: paket diangkat, resinya discan, dan
     * layar langsung siap untuk paket berikutnya. Mencentang daftar tetap ada
     * untuk memproses borongan, tetapi memindai punya keunggulan yang tidak
     * bisa ditiru centang — yang tercatat terkirim adalah paket yang benar-
     * benar ada di tangan, bukan baris yang kebetulan tercentang di layar.
     */
    public function scan(Request $request): JsonResponse
    {
        $code = trim($request->validate([
            'code' => ['required', 'string', 'max:191'],
        ])['code']);

        $outbound = Outbound::findByScannedCode($code);

        if (! $outbound) {
            throw ValidationException::withMessages([
                'code' => 'Resi tidak dikenali · belum discan di QC Kemas',
            ]);
        }

        $outbound->load('items.product', 'shipmentOrder');

        $this->guardHandoverable($outbound);

        $selfApprove = $request->user()->can('outbounds.approve');
        $units = (int) $outbound->items->sum('quantity');

        try {
            $selfApprove
                ? $this->approvals->submitAndApprove($outbound)
                : $this->approvals->submit($outbound);
        } catch (ValidationException $exception) {
            // Stok bisa saja sudah diambil paket lain sejak antrean disusun.
            throw ValidationException::withMessages([
                'code' => collect($exception->errors())->flatten()->implode(' '),
            ]);
        }

        return response()->json([
            'outbound' => [
                'id' => $outbound->id,
                'code' => $outbound->code,
                'tracking_number' => $outbound->tracking_number,
                'marketplace' => $outbound->marketplace,
                'recipient' => $outbound->recipient,
                'units' => $units,
            ],
            'shipped' => $selfApprove,
            'message' => $selfApprove
                ? "Dikirim · {$units} unit"
                : 'Diajukan · menunggu persetujuan',
            'remaining' => Outbound::awaitingHandover()->count(),
        ]);
    }

    /**
     * Tolak paket yang belum boleh dikirim, dengan alasan yang menyebutkan
     * tindakan berikutnya — bukan sekadar "tidak bisa".
     *
     * Nomor dokumen hanya disebut saat memang membantu mengenali paketnya.
     * Pada paket yang QC-nya belum selesai, operator memegang barangnya dan
     * yang perlu ia tahu adalah berapa unit yang kurang.
     */
    protected function guardHandoverable(Outbound $outbound): void
    {
        $reason = match (true) {
            $outbound->isPosted() => "Sudah dikirim · {$outbound->code}",
            // Pembatalan bisa datang setelah paket selesai di-QC dan sudah
            // berada di antrean, jadi tidak cukup menjaganya di stasiun packing.
            $outbound->shipmentOrder?->isCancelled() => "Pesanan dibatalkan pembeli · kembalikan barang, hapus {$outbound->code}",
            $outbound->isPending() => "Menunggu persetujuan · {$outbound->code}",
            $outbound->isRejected() => "Ditolak penyetuju · buka {$outbound->code} dari Pesanan untuk ajukan ulang",
            ! $outbound->isMarketplace() => "Bukan paket marketplace · proses dari dokumen {$outbound->code}",
            ! $outbound->isResiVerified() => 'Belum discan di QC Kemas',
            ! $outbound->isFullyScanned() => 'Belum selesai QC · sisa '
                .($outbound->totalQuantity() - $outbound->totalScanned()).' unit',
            default => null,
        };

        if ($reason !== null) {
            throw ValidationException::withMessages(['code' => $reason]);
        }
    }

    /**
     * Kembali ke antrean dengan filter yang tadi dipakai.
     *
     * Sengaja tidak memakai `back()`: fungsi itu membaca header Referer, yang
     * tidak selalu dikirim, dan cadangannya di sesi tidak pernah diperbarui
     * pada navigasi AJAX. Filternya dibawa sendiri oleh form, jadi tujuannya
     * pasti — operator yang menyaring satu marketplace tidak terlempar ke
     * seluruh antrean setelah memproses.
     */
    protected function backToQueue(Request $request): RedirectResponse
    {
        return redirect()->route('admin.outbounds.handover', array_filter(
            $request->only(['search', 'marketplace', 'courier', 'store']),
            fn ($value) => filled($value),
        ));
    }

    /**
     * @param  array<int, string>  $codes
     */
    protected function summary(array $codes, bool $selfApprove): string
    {
        $count = count($codes);

        $subject = $count === 1
            ? "Paket {$codes[0]}"
            : "{$count} paket";

        return $selfApprove
            ? "{$subject} dikirim. Stok sudah berkurang."
            : "{$subject} diajukan dan menunggu persetujuan.";
    }
}
