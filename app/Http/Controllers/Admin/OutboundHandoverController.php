<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Outbound;
use App\Services\ApprovalService;
use App\Services\TableExport;
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
            new Middleware('can:outbounds.view', only: ['index', 'export']),
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
            // Paket yang paling lama menunggu dikerjakan lebih dulu.
            ->oldest('id');
    }

    public function index(Request $request): View
    {
        $outbounds = $this->filtered($request)
            ->with('user', 'shipmentOrder')
            ->withCount('items')
            ->withSum('items', 'quantity')
            ->paginate(20)
            ->withQueryString();

        return view('admin.outbounds.handover', [
            'outbounds' => $outbounds,
            'marketplaces' => Outbound::marketplaces(),
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
            $outbound->isRejected() => "Ditolak penyetuju · {$outbound->code}",
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
            $request->only(['search', 'marketplace']),
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
