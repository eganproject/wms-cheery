<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\DateRange;
use App\Http\Requests\Admin\StoreReturnReceiptRequest;
use App\Http\Requests\Admin\UpdateReturnReceiptRequest;
use App\Models\Outbound;
use App\Models\Product;
use App\Models\ReturnReceipt;
use App\Models\ReturnReceiptItem;
use App\Services\ApprovalService;
use App\Services\TableExport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReturnReceiptController extends Controller implements HasMiddleware
{
    public function __construct(protected ApprovalService $approvals)
    {
    }

    public static function middleware(): array
    {
        return [
            new Middleware('can:returns.view', only: ['index', 'show', 'export']),
            new Middleware('can:returns.create', only: ['create', 'store']),
            new Middleware('can:returns.update', only: ['edit', 'update']),
            new Middleware('can:returns.delete', only: ['destroy']),
            new Middleware('can:returns.post', only: ['submit', 'withdraw']),
            new Middleware('can:returns.approve', only: ['approve']),
        ];
    }

    /**
     * Excel penerimaan retur sesuai saringan yang aktif.
     */
    public function export(Request $request): StreamedResponse
    {
        $documents = function () use ($request) {
            $number = 0;

            foreach ($this->filtered($request)->with('user', 'approver', 'items')->withCount('items')->lazy(500) as $return) {
                yield [
                    ++$number,
                    $return->date,
                    $return->code,
                    $return->isMarketplace() ? 'Marketplace' : 'Reguler',
                    $return->sender,
                    $return->marketplace,
                    $return->tracking_number,
                    $return->reference,
                    $return->reason,
                    $return->statusLabel(),
                    $return->items_count,
                    (int) $return->items->sum('quantity'),
                    (int) $return->items->sum('good_quantity'),
                    (int) $return->items->sum('damaged_quantity'),
                    $return->user?->name,
                    $return->approver?->name,
                    $return->note,
                ];
            }
        };

        $items = function () use ($request) {
            foreach ($this->filtered($request)->with('items.product')->lazy(500) as $return) {
                foreach ($return->items as $item) {
                    yield [
                        $return->date,
                        $return->code,
                        $return->sender,
                        $return->marketplace,
                        $return->tracking_number,
                        $return->statusLabel(),
                        $item->product?->sku,
                        $item->product?->name,
                        (int) $item->quantity,
                        (int) $item->good_quantity,
                        (int) $item->damaged_quantity,
                        $item->note,
                    ];
                }
            }
        };

        return TableExport::make('Penerimaan Retur')
            ->note(collect([
                'Tanggal: '.DateRange::fromRequest($request)->label(),
                $request->filled('status') ? 'Status: '.$request->string('status') : null,
                $request->filled('type') ? 'Jenis: '.($request->input('type') === ReturnReceipt::TYPE_MARKETPLACE ? 'Marketplace' : 'Reguler') : null,
                $request->filled('marketplace') ? 'Marketplace: '.$request->string('marketplace') : null,
                $request->filled('search') ? 'Cari: '.$request->string('search')->trim() : null,
            ])->filter()->implode(' · '))
            ->sheet('Dokumen', [
                ['No', 'number'],
                ['Tanggal', 'date'],
                ['No. Dokumen', 'text'],
                ['Jenis', 'text'],
                ['Pengirim', 'text'],
                ['Marketplace', 'text'],
                ['No. Resi', 'text'],
                ['Referensi', 'text'],
                ['Alasan', 'text'],
                ['Status', 'text'],
                ['Jumlah Baris', 'number'],
                ['Qty Retur', 'sum'],
                ['Layak Jual', 'sum'],
                ['Rusak', 'sum'],
                ['Dibuat Oleh', 'text'],
                ['Disetujui Oleh', 'text'],
                ['Catatan', 'text'],
            ], $documents())
            ->sheet('Detail Barang', [
                ['Tanggal', 'date'],
                ['No. Dokumen', 'text'],
                ['Pengirim', 'text'],
                ['Marketplace', 'text'],
                ['No. Resi', 'text'],
                ['Status', 'text'],
                ['SKU', 'text'],
                ['Nama Barang', 'text'],
                ['Qty Retur', 'sum'],
                ['Layak Jual', 'sum'],
                ['Rusak', 'sum'],
                ['Catatan', 'text'],
            ], $items())
            ->download('retur-'.now()->format('Y-m-d-Hi').'.xlsx');
    }

    /**
     * Saringan yang sama untuk tabel di layar dan berkas export.
     */
    protected function filtered(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        return ReturnReceipt::query()
            ->search($request->string('search')->trim()->value())
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->string('type')))
            ->when($request->filled('marketplace'), fn ($query) => $query->where('marketplace', $request->string('marketplace')))
            ->dateBetween(DateRange::fromRequest($request))
            ->latest('date')
            ->latest('id');
    }

    public function index(Request $request): View
    {
        $returns = $this->filtered($request)
            ->with('user')
            ->withCount('items')
            ->withSum('items', 'quantity')
            ->paginate(10)
            ->withQueryString();

        return view('admin.returns.index', [
            'returns' => $returns,
            'marketplaces' => Outbound::marketplaces(),
        ]);
    }

    public function create(Request $request): View
    {
        return view('admin.returns.create', [
            'return' => null,
            'code' => ReturnReceipt::nextCode(),
            'products' => $this->products(),
            'marketplaces' => Outbound::marketplaces(),
            'reasons' => ReturnReceipt::reasons(),
            'defaultType' => $request->input('type') === ReturnReceipt::TYPE_MARKETPLACE
                ? ReturnReceipt::TYPE_MARKETPLACE
                : ReturnReceipt::TYPE_REGULAR,
        ]);
    }

    public function store(StoreReturnReceiptRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $return = DB::transaction(function () use ($data) {
            $return = ReturnReceipt::create([
                'code' => ReturnReceipt::nextCode(),
                'date' => $data['date'],
                'type' => $data['type'],
                'sender' => $data['sender'],
                'marketplace' => $data['marketplace'] ?? null,
                'tracking_number' => $data['tracking_number'] ?? null,
                'reference' => $data['reference'] ?? null,
                'reason' => $data['reason'] ?? null,
                'note' => $data['note'] ?? null,
                'status' => ReturnReceipt::STATUS_DRAFT,
                'user_id' => auth()->id(),
            ]);

            $return->items()->createMany($this->mergeLines($data['items'] ?? []));

            return $return;
        });

        // Dokumen yang punya resi harus lewat verifikasi scan lebih dulu.
        if ($return->requiresResiScan()) {
            return redirect()
                ->route('admin.returns.scan', $return)
                ->with('success', "Dokumen {$return->code} dibuat. Lanjutkan dengan scan resi retur.");
        }

        if ($request->boolean('submit')) {
            return $this->submit($request, $return)
                ->setTargetUrl(route('admin.returns.show', $return));
        }

        return redirect()
            ->route('admin.returns.show', $return)
            ->with('success', "Draft {$return->code} berhasil disimpan.");
    }

    public function show(ReturnReceipt $return): View
    {
        $return->load(['items.product', 'user', 'shipmentOrder.outbound']);

        return view('admin.returns.show', [
            'return' => $return,
            // Hanya relevan selama dokumennya belum diterima.
            'neverShipped' => $return->isPosted() ? null : ReturnReceipt::neverShippedWarning($return->shipmentOrder),
        ]);
    }

    public function edit(ReturnReceipt $return): View|RedirectResponse
    {
        if (! $return->isEditable()) {
            return redirect()
                ->route('admin.returns.show', $return)
                ->with('error', $return->isPending()
                    ? 'Dokumen sedang menunggu persetujuan. Tarik kembali pengajuan untuk mengubahnya.'
                    : 'Dokumen yang sudah disetujui bersifat final dan tidak dapat diubah.');
        }

        return view('admin.returns.edit', [
            'return' => $return->load('items.product'),
            'code' => $return->code,
            'products' => $this->products(),
            'marketplaces' => Outbound::marketplaces(),
            'reasons' => ReturnReceipt::reasons(),
            'defaultType' => $return->type,
        ]);
    }

    public function update(UpdateReturnReceiptRequest $request, ReturnReceipt $return): RedirectResponse
    {
        if (! $return->isEditable()) {
            return back()->with('error', $return->isPending()
                    ? 'Dokumen sedang menunggu persetujuan. Tarik kembali pengajuan untuk mengubahnya.'
                    : 'Dokumen yang sudah disetujui bersifat final dan tidak dapat diubah.');
        }

        $data = $request->validated();

        $trackingChanged = ($data['tracking_number'] ?? null) !== $return->tracking_number;

        $saved = DB::transaction(function () use ($return, $data, $trackingChanged) {
            if (! $return->lockForEditing()) {
                return false;
            }

            $return->update([
                'date' => $data['date'],
                'type' => $data['type'],
                'sender' => $data['sender'],
                'marketplace' => $data['marketplace'] ?? null,
                'tracking_number' => $data['tracking_number'] ?? null,
                'reference' => $data['reference'] ?? null,
                'reason' => $data['reason'] ?? null,
                'note' => $data['note'] ?? null,
            ]);

            $return->items()->delete();
            $return->items()->createMany($this->mergeLines($data['items'] ?? []));

            // Isi dokumen berubah, verifikasi sebelumnya tidak lagi sahih.
            // Tautan ke data import milik resi lama; scan resi berikutnya
            // menautkan ulang ke pesanan yang benar.
            $return->forceFill(array_merge(
                ['resi_verified_at' => null],
                $trackingChanged ? ['shipment_order_id' => null] : [],
            ))->save();

            return true;
        });

        if (! $saved) {
            return back()->with('error', $return::staleEditMessage());
        }

        if ($return->refresh()->requiresResiScan()) {
            return redirect()
                ->route('admin.returns.scan', $return)
                ->with('success', "Dokumen {$return->code} diperbarui. Scan resi diulang dari awal.");
        }

        return redirect()
            ->route('admin.returns.show', $return)
            ->with('success', "Dokumen {$return->code} berhasil diperbarui.");
    }

    /**
     * Ajukan dokumen untuk disetujui. Pengguna yang berwenang menyetujui
     * langsung diproses tanpa perlu antre.
     */
    public function submit(Request $request, ReturnReceipt $return): RedirectResponse
    {
        $return->load('items.product');

        $selfApprove = $request->user()->can('returns.approve');

        try {
            $selfApprove
                ? $this->approvals->submitAndApprove($return)
                : $this->approvals->submit($return);
        } catch (ValidationException $exception) {
            return back()->with('error', collect($exception->errors())->flatten()->implode(' '));
        }

        return back()->with('success', $selfApprove
            ? "Retur {$return->code} disetujui. Stok layak jual telah bertambah."
            : "Retur {$return->code} diajukan dan menunggu persetujuan.");
    }

    /**
     * Setujui dokumen yang sedang diajukan.
     */
    public function approve(ReturnReceipt $return): RedirectResponse
    {
        $return->load('items.product');

        try {
            $this->approvals->approve($return);
        } catch (ValidationException $exception) {
            return back()->with('error', collect($exception->errors())->flatten()->implode(' '));
        }

        return back()->with('success', "Retur {$return->code} disetujui. Stok layak jual telah bertambah.");
    }

    /**
     * Tarik kembali pengajuan menjadi draft.
     */
    public function withdraw(ReturnReceipt $return): RedirectResponse
    {
        try {
            $this->approvals->withdraw($return);
        } catch (ValidationException $exception) {
            return back()->with('error', collect($exception->errors())->flatten()->implode(' '));
        }

        return back()->with('success', "Pengajuan {$return->code} ditarik kembali ke draft.");
    }

    public function destroy(ReturnReceipt $return): RedirectResponse
    {
        if (! $return->isEditable()) {
            return back()->with('error', $return->isPending()
                ? 'Dokumen sedang menunggu persetujuan dan tidak dapat dihapus.'
                : 'Dokumen yang sudah disetujui bersifat final dan tidak dapat dihapus.');
        }

        $deleted = DB::transaction(fn () => $return->lockForEditing() && $return->delete());

        if (! $deleted) {
            return back()->with('error', $return::staleEditMessage());
        }

        return redirect()
            ->route('admin.returns.index')
            ->with('success', 'Dokumen retur berhasil dihapus.');
    }

    /**
     * Gabungkan baris dengan barang dan kondisi yang sama.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    protected function mergeLines(array $lines): array
    {
        return collect($lines)
            ->groupBy('product_id')
            ->map(function ($group, $productId) {
                $quantity = (int) $group->sum('quantity');
                $damaged = (int) $group->sum('damaged_quantity');

                return [
                    'product_id' => (int) $productId,
                    'quantity' => $quantity,
                    // Sisa terhadap jumlah pada resi dihitung sebagai hilang.
                    'good_quantity' => (int) $group->sum('good_quantity'),
                    'damaged_quantity' => $damaged,
                    'note' => $group->pluck('note')->filter()->implode(', ') ?: null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Katalog barang untuk editor baris.
     *
     * Paket bundling tidak ikut: yang datang di kardus retur selalu barang
     * satuan, dan kondisinya dinilai satu per satu. Retur yang masuk lewat
     * scan resi sudah dipecah sendiri oleh resolver.
     */
    protected function products()
    {
        return Product::singles()->where('is_active', true)->orderBy('name')->get();
    }
}
