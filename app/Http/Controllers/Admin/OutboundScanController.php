<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Outbound;
use App\Models\OutboundItem;
use App\Models\Product;
use App\Services\OutboundScanService;
use App\Services\OutboundSubstitutionService;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * Verifikasi pengiriman marketplace: scan resi lalu scan barang.
 *
 * Endpoint scan mengembalikan JSON supaya panel bisa diperbarui seketika
 * tanpa memuat ulang halaman.
 */
class OutboundScanController extends Controller implements HasMiddleware
{
    public function __construct(
        protected OutboundScanService $scanner,
        protected OutboundSubstitutionService $substitutions,
    ) {
    }

    public static function middleware(): array
    {
        return [
            new Middleware('can:outbounds.scan'),
            new Middleware('can:outbounds.substitute', only: ['substitute', 'revertSubstitute']),
        ];
    }

    public function show(Outbound $outbound): View|RedirectResponse
    {
        if (! $outbound->isMarketplace()) {
            return redirect()
                ->route('admin.outbounds.show', $outbound)
                ->with('error', 'Verifikasi scan hanya berlaku untuk pengiriman marketplace.');
        }

        $outbound->load('items.product', 'bundles.bundle');

        return view('admin.outbounds.scan', [
            'outbound' => $outbound,
            'progress' => $this->scanner->progress($outbound),
        ]);
    }

    public function resi(Request $request, Outbound $outbound): JsonResponse
    {
        $code = $this->code($request);

        $result = $this->scanner->verifyResi($outbound, $code);

        return response()->json($result + ['progress' => $this->scanner->progress($outbound)]);
    }

    public function item(Request $request, Outbound $outbound): JsonResponse
    {
        $code = $this->code($request);

        // Pesanan borongan cukup discan sekali dengan menyebut jumlahnya.
        // Tanpa jumlah, satu scan tetap berarti satu unit.
        $request->validate([
            'quantity' => ['nullable', 'integer', 'min:1', 'max:9999'],
        ]);

        $quantity = max(1, (int) $request->input('quantity', 1));

        $outbound->load('items.product');

        $result = $this->scanner->scanItem(
            $outbound,
            $code,
            $quantity,
            // Hanya layar yang bisa menampilkan tawaran substitusi (QC Kemas
            // web dan aplikasi Android versi baru) yang memintanya.
            $request->boolean('substitution') && (bool) $request->user()?->can('outbounds.substitute'),
        );

        return response()->json($result + ['progress' => $this->scanner->progress($outbound)]);
    }

    /**
     * Substitusi SKU: ganti unit baris pesanan dengan barang yang discan.
     */
    public function substitute(Request $request, Outbound $outbound): JsonResponse
    {
        $data = $request->validate([
            'item_id' => ['required', 'integer'],
            'code' => ['required', 'string', 'max:191'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'reason' => ['required', 'string', Rule::in(array_keys(OutboundItem::SUBSTITUTION_REASONS))],
            'note' => ['nullable', 'string', 'max:255', 'required_if:reason,lainnya'],
        ], [
            'reason.required' => 'Pilih alasan substitusi.',
            'note.required_if' => 'Tulis keterangan untuk alasan "Lainnya".',
        ]);

        $this->guardScannable($outbound);

        $product = Product::findByCode($data['code']);

        if (! $product) {
            throw ValidationException::withMessages([
                'code' => "Kode {$data['code']} tidak dikenali sebagai barcode maupun SKU barang mana pun.",
            ]);
        }

        $outbound->load('items.product');
        $line = $outbound->items->firstWhere('id', (int) $data['item_id']);

        if (! $line) {
            throw ValidationException::withMessages([
                'code' => 'Baris pesanan tidak ditemukan pada paket ini.',
            ]);
        }

        $quantity = max(1, (int) ($data['quantity'] ?? 1));
        $original = $line->product;

        $this->substitutions->substitute(
            $outbound,
            $line,
            $product,
            $quantity,
            $data['reason'],
            $data['note'] ?? null,
            $request->user()?->id,
        );

        return $this->substitutionResponse(
            $outbound,
            "{$original->sku} diganti {$product->sku} · {$quantity} {$product->unit}",
        );
    }

    /**
     * Batalkan satu baris pengganti; unitnya kembali ke barang pesanan.
     */
    public function revertSubstitute(Outbound $outbound, OutboundItem $item): JsonResponse
    {
        $this->guardScannable($outbound);

        $this->substitutions->revert($outbound, $item);

        return $this->substitutionResponse($outbound, 'Substitusi dibatalkan · scan lagi barang pesanan aslinya');
    }

    protected function substitutionResponse(Outbound $outbound, string $message): JsonResponse
    {
        $outbound->unsetRelation('items');
        $progress = $this->scanner->progress($outbound);

        return response()->json([
            'message' => $message,
            'items' => $this->scanner->itemsPayload($outbound),
            'progress' => $progress,
            'completed' => $progress['ready'],
        ]);
    }

    protected function guardScannable(Outbound $outbound): void
    {
        if (! $outbound->isMarketplace() || $outbound->isPosted() || $outbound->isPending()) {
            throw ValidationException::withMessages([
                'code' => 'Paket ini tidak sedang dalam QC Kemas.',
            ]);
        }

        if (! $outbound->isResiVerified()) {
            throw ValidationException::withMessages([
                'code' => 'Scan resi terlebih dahulu sebelum scan barang.',
            ]);
        }
    }

    public function reset(Outbound $outbound): RedirectResponse
    {
        $this->scanner->reset($outbound);

        return back()->with('success', 'Progres scan direset. Mulai kembali dari scan resi.');
    }

    protected function code(Request $request): string
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:191'],
        ])['code'];
    }
}
