<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\DateRange;
use App\Models\DamagedDisposal;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Services\ApprovalService;
use App\Services\StockService;
use App\Services\TableExport;
use App\Support\Listing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Barang rusak dan pengeluaran barang di luar penjualan.
 *
 * Dua saldo dilayani dokumen yang sama. Dari saldo rusak: dibuang, dikembalikan
 * ke pemasok, atau diperbaiki sehingga layak jual kembali. Dari saldo layak
 * jual: dibuang karena kedaluwarsa, dikembalikan ke pemasok, atau dipindahkan
 * ke saldo rusak karena ternyata cacat.
 *
 * Dengan begitu tidak ada barang yang menguap tanpa jejak, dan tidak ada
 * pengeluaran yang terpaksa dicatat sebagai penyesuaian angka.
 */
class DamagedStockController extends Controller implements HasMiddleware
{
    public function __construct(protected ApprovalService $approvals, protected StockService $stock)
    {
    }

    public static function middleware(): array
    {
        return [
            new Middleware('can:disposals.view', only: ['index', 'show', 'export']),
            new Middleware('can:disposals.create', only: ['create', 'store']),
            new Middleware('can:disposals.delete', only: ['destroy']),
            new Middleware('can:disposals.post', only: ['submit', 'withdraw', 'ship', 'replace', 'reject', 'cancelReturn']),
            new Middleware('can:disposals.view', only: ['deliveryNote']),
            new Middleware('can:disposals.approve', only: ['saveReturnSettings']),
            new Middleware('can:disposals.approve', only: ['approve']),
        ];
    }

    /**
     * Dua tab dalam satu menu: saldo barang rusak saat ini, dan riwayat
     * dokumen pengeluaran. Hanya tab yang dibuka yang datanya diambil.
     */
    public function index(Request $request): View
    {
        $tab = $this->tabFrom($request);
        $search = $request->string('search')->trim()->value();

        $damaged = null;
        $disposals = null;
        $returns = null;
        $incoming = null;

        if ($tab === 'masuk') {
            $incoming = $this->incomingDamaged($request)
                ->with(['product:id,sku,name,unit,damaged_stock', 'user:id,name', 'reference'])
                ->paginate(Listing::perPage($request, pageName: 'masuk'), ['*'], 'masuk')
                ->withQueryString();
        } elseif ($tab === 'saldo') {
            $damaged = Product::query()
                ->where('damaged_stock', '>', 0)
                ->search($search)
                ->orderByDesc('damaged_stock')
                ->tap(fn ($query) => Listing::sort($query, $request, [
                    'name' => 'name',
                    'category' => 'category',
                    'damaged' => 'damaged_stock',
                    'stock' => 'stock',
                ], ['damaged', 'desc'], 'stok'))
                ->paginate(Listing::perPage($request, pageName: 'stok'), ['*'], 'stok')
                ->withQueryString();
        } elseif ($tab === 'pemasok') {
            $returns = $this->filteredSupplierReturns($request)
                ->with('supplier')
                ->withSum('items', 'quantity')
                ->withSum('items', 'replaced_quantity')
                ->withSum('items', 'rejected_quantity')
                ->withSum('items', 'cancelled_quantity')
                ->tap(fn ($query) => Listing::sort($query, $request, [
                    'date' => fn ($query, $dir) => $query->orderBy('date', $dir)->orderBy('id', $dir),
                    'shipped' => 'shipped_at',
                    'status' => 'return_status',
                    'quantity' => 'items_sum_quantity',
                ], ['date', 'desc'], 'retur'))
                ->paginate(Listing::perPage($request, pageName: 'retur'), ['*'], 'retur')
                ->withQueryString();
        } else {
            $disposals = $this->filteredDisposals($request)
                ->with('user', 'supplier')
                ->withCount('items')
                ->withSum('items', 'quantity')
                ->tap(fn ($query) => Listing::sort($query, $request, [
                    'date' => fn ($query, $dir) => $query->orderBy('date', $dir)->orderBy('id', $dir),
                    'bucket' => 'bucket',
                    'action' => 'action',
                    'items' => 'items_count',
                    'quantity' => 'items_sum_quantity',
                    'status' => 'status',
                ], ['date', 'desc'], 'dokumen'))
                ->paginate(Listing::perPage($request, pageName: 'dokumen'), ['*'], 'dokumen')
                ->withQueryString();
        }

        $skus = Product::where('damaged_stock', '>', 0)->count();

        return view('admin.disposals.index', [
            'tab' => $tab,
            'damaged' => $damaged,
            'disposals' => $disposals,
            'returns' => $returns,
            'incoming' => $incoming,
            'suppliers' => $tab === 'pemasok' ? Supplier::orderBy('name')->get(['id', 'name']) : collect(),
            'alertDays' => \App\Models\AppSetting::supplierReturnAlertDays(),
            'summary' => [
                'open_returns' => DamagedDisposal::openSupplierReturns()->count(),
                'overdue_returns' => DamagedDisposal::overdueSupplierReturns()->count(),
                'units' => (int) Product::sum('damaged_stock'),
                'skus' => $skus,
                'pending' => DamagedDisposal::pending()->count(),
                'documents' => DamagedDisposal::count(),
            ],
        ]);
    }

    /**
     * Tab yang dibuka. Tautan lama yang membawa rentang tanggal selalu
     * dimaksudkan untuk riwayat, jadi langsung dibawa ke sana.
     */
    /**
     * Excel mengikuti tab yang terbuka: "Saldo Rusak" berisi barang yang
     * masih punya stok rusak, "Riwayat" berisi dokumen penanganannya.
     */
    public function export(Request $request): StreamedResponse
    {
        $search = $request->string('search')->trim()->value();
        $stamp = now()->format('Y-m-d-Hi');

        if ($this->tabFrom($request) === 'saldo') {
            $rows = function () use ($search) {
                $number = 0;

                foreach (Product::query()->where('damaged_stock', '>', 0)->search($search)->orderByDesc('damaged_stock')->orderBy('id')->lazy(500) as $product) {
                    yield [++$number, $product->sku, $product->name, $product->unit, (int) $product->stock, (int) $product->damaged_stock];
                }
            };

            return TableExport::make('Saldo Barang Rusak')
                ->note($search !== '' ? 'Cari: '.$search : null)
                ->sheet('Saldo Rusak', [
                    ['No', 'number'],
                    ['SKU', 'text'],
                    ['Nama Barang', 'text'],
                    ['Satuan', 'text'],
                    ['Stok Layak Jual', 'sum'],
                    ['Stok Rusak', 'sum'],
                ], $rows())
                ->download('saldo-barang-rusak-'.$stamp.'.xlsx');
        }

        if ($this->tabFrom($request) === 'pemasok') {
            return $this->exportSupplierReturns($request, $stamp);
        }

        if ($this->tabFrom($request) === 'masuk') {
            $rows = function () use ($request) {
                $number = 0;

                foreach ($this->incomingDamaged($request)->with(['product', 'user', 'reference'])->lazy(500) as $movement) {
                    yield [
                        ++$number,
                        $movement->created_at,
                        $movement->sourceLabel(),
                        $movement->reference?->code,
                        $movement->product?->sku,
                        $movement->product?->name,
                        (int) $movement->quantity,
                        (int) $movement->balance_after,
                        $movement->description,
                        $movement->user?->name,
                    ];
                }
            };

            return TableExport::make('Barang Rusak Masuk')
                ->note(collect([
                    'Tanggal: '.DateRange::fromRequest($request)->label(),
                    $search !== '' ? 'Cari: '.$search : null,
                ])->filter()->implode(' · '))
                ->sheet('Barang Rusak Masuk', [
                    ['No', 'number'],
                    ['Waktu', 'datetime'],
                    ['Asal', 'text'],
                    ['No. Dokumen', 'text'],
                    ['SKU', 'text'],
                    ['Nama Barang', 'text'],
                    ['Qty', 'sum'],
                    ['Saldo Rusak Sesudah', 'number'],
                    ['Keterangan', 'text'],
                    ['Oleh', 'text'],
                ], $rows())
                ->download('barang-rusak-masuk-'.$stamp.'.xlsx');
        }

        $documents = function () use ($request) {
            $number = 0;

            foreach ($this->filteredDisposals($request)->with('user', 'approver', 'supplier')->withCount('items')->withSum('items', 'quantity')->lazy(500) as $disposal) {
                yield [
                    ++$number,
                    $disposal->date,
                    $disposal->code,
                    $disposal->bucketLabel(),
                    $disposal->actionLabel(),
                    $disposal->supplier?->name,
                    $disposal->statusLabel(),
                    $disposal->items_count,
                    (int) $disposal->items_sum_quantity,
                    $disposal->user?->name,
                    $disposal->approver?->name,
                    $disposal->note,
                ];
            }
        };

        $items = function () use ($request) {
            foreach ($this->filteredDisposals($request)->with('items.product', 'supplier')->lazy(500) as $disposal) {
                foreach ($disposal->items as $item) {
                    yield [
                        $disposal->date,
                        $disposal->code,
                        $disposal->bucketLabel(),
                        $disposal->actionLabel(),
                        $disposal->statusLabel(),
                        $item->product?->sku,
                        $item->product?->name,
                        (int) $item->quantity,
                        $item->note,
                    ];
                }
            }
        };

        $action = $request->string('action')->value();
        $bucket = $request->string('bucket')->value();

        return TableExport::make('Penanganan Barang Rusak')
            ->note(collect([
                'Tanggal: '.DateRange::fromRequest($request)->label(),
                $bucket === StockMovement::BUCKET_GOOD ? 'Asal: stok layak jual' : ($bucket === StockMovement::BUCKET_DAMAGED ? 'Asal: stok rusak' : null),
                array_key_exists($action, DamagedDisposal::actions()) ? 'Tindakan: '.DamagedDisposal::actions()[$action] : null,
                $search !== '' ? 'Cari: '.$search : null,
            ])->filter()->implode(' · '))
            ->sheet('Dokumen', [
                ['No', 'number'],
                ['Tanggal', 'date'],
                ['No. Dokumen', 'text'],
                ['Asal Stok', 'text'],
                ['Tindakan', 'text'],
                ['Pemasok', 'text'],
                ['Status', 'text'],
                ['Jumlah Baris', 'number'],
                ['Total Qty', 'sum'],
                ['Dibuat Oleh', 'text'],
                ['Disetujui Oleh', 'text'],
                ['Catatan', 'text'],
            ], $documents())
            ->sheet('Detail Barang', [
                ['Tanggal', 'date'],
                ['No. Dokumen', 'text'],
                ['Asal Stok', 'text'],
                ['Tindakan', 'text'],
                ['Status', 'text'],
                ['SKU', 'text'],
                ['Nama Barang', 'text'],
                ['Qty', 'sum'],
                ['Catatan', 'text'],
            ], $items())
            ->download('penanganan-barang-rusak-'.$stamp.'.xlsx');
    }

    protected function exportSupplierReturns(Request $request, string $stamp): StreamedResponse
    {
        $documents = function () use ($request) {
            $number = 0;

            foreach ($this->filteredSupplierReturns($request)->with('supplier')
                ->withSum('items', 'quantity')->withSum('items', 'replaced_quantity')->withSum('items', 'rejected_quantity')->withSum('items', 'cancelled_quantity')
                ->lazy(500) as $disposal) {
                $sent = (int) $disposal->items_sum_quantity;
                $replaced = (int) $disposal->items_sum_replaced_quantity;
                $rejected = (int) $disposal->items_sum_rejected_quantity;

                yield [
                    ++$number,
                    $disposal->date,
                    $disposal->code,
                    $disposal->supplier?->name,
                    $disposal->bucketLabel(),
                    $disposal->returnStatusLabel(),
                    $disposal->shipped_at,
                    $disposal->courier,
                    $disposal->tracking_number,
                    $disposal->daysWithSupplier(),
                    $sent,
                    $replaced,
                    $rejected,
                    max(0, $sent - $replaced - $rejected - (int) $disposal->items_sum_cancelled_quantity),
                    $disposal->closed_at,
                    $disposal->close_note,
                ];
            }
        };

        $items = function () use ($request) {
            foreach ($this->filteredSupplierReturns($request)->with('items.product', 'supplier')->lazy(500) as $disposal) {
                foreach ($disposal->items as $item) {
                    yield [
                        $disposal->code,
                        $disposal->supplier?->name,
                        $disposal->returnStatusLabel(),
                        $item->product?->sku,
                        $item->product?->name,
                        $item->quantity,
                        $item->replaced_quantity,
                        $item->rejected_quantity,
                        $item->outstandingQuantity(),
                    ];
                }
            }
        };

        return TableExport::make('Retur ke Pemasok')
            ->sheet('Dokumen', [
                ['No', 'number'],
                ['Tanggal', 'date'],
                ['No. Dokumen', 'text'],
                ['Pemasok', 'text'],
                ['Asal Stok', 'text'],
                ['Status', 'text'],
                ['Tanggal Kirim', 'date'],
                ['Ekspedisi', 'text'],
                ['No. Resi', 'text'],
                ['Hari di Pemasok', 'number'],
                ['Dikirim', 'sum'],
                ['Diganti', 'sum'],
                ['Ditolak', 'sum'],
                ['Sisa Ditunggu', 'sum'],
                ['Selesai', 'date'],
                ['Alasan Ditolak', 'text'],
            ], $documents())
            ->sheet('Detail Barang', [
                ['No. Dokumen', 'text'],
                ['Pemasok', 'text'],
                ['Status', 'text'],
                ['SKU', 'text'],
                ['Nama Barang', 'text'],
                ['Dikirim', 'sum'],
                ['Diganti', 'sum'],
                ['Ditolak', 'sum'],
                ['Sisa Ditunggu', 'sum'],
            ], $items())
            ->download('retur-ke-pemasok-'.$stamp.'.xlsx');
    }

    /**
     * Saringan dokumen penanganan, sama untuk tabel dan berkas export.
     */
    protected function filteredDisposals(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        $bucket = $request->string('bucket')->value();
        $action = $request->string('action')->value();

        return DamagedDisposal::query()
            ->search($request->string('search')->trim()->value())
            ->when(in_array($bucket, [StockMovement::BUCKET_GOOD, StockMovement::BUCKET_DAMAGED], true),
                fn ($query) => $query->where('bucket', $bucket))
            ->when(array_key_exists($action, DamagedDisposal::actions()),
                fn ($query) => $query->where('action', $action))
            ->dateBetween(DateRange::fromRequest($request))
            ->latest('date')
            ->latest('id');
    }

    /**
     * Unit yang masuk ke saldo rusak dari mana pun: penerimaan barang,
     * penerimaan retur, temuan opname, penyesuaian, atau barang layak jual
     * yang ditandai rusak. Dibaca dari kartu stok, jadi tidak ada yang
     * terlewat walau dokumen asalnya bukan dokumen Barang Rusak.
     */
    protected function incomingDamaged(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        $range = DateRange::fromRequest($request);

        return StockMovement::query()
            ->where('bucket', StockMovement::BUCKET_DAMAGED)
            ->where('type', 'in')
            ->search($request->string('search')->trim()->value())
            ->fromSource($request->string('source')->value() ?: null)
            ->tap(fn ($query) => DateRange::constrain($query, 'created_at', $range->from, $range->to))
            ->latest('created_at')
            ->latest('id');
    }

    /**
     * Saringan tab Retur ke Pemasok. Bawaannya hanya yang belum selesai,
     * karena itulah yang perlu dikejar.
     */
    protected function filteredSupplierReturns(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        $status = $request->string('return_status')->value() ?: 'open';
        $supplier = $request->integer('supplier_id');

        return DamagedDisposal::query()
            ->supplierReturns()
            ->search($request->string('search')->trim()->value())
            ->when($status === 'open', fn ($query) => $query->whereIn('return_status', DamagedDisposal::OPEN_RETURN_STATUSES))
            ->when($status === 'overdue', fn ($query) => $query->overdueSupplierReturns())
            ->when(array_key_exists($status, DamagedDisposal::returnStatuses()), fn ($query) => $query->where('return_status', $status))
            ->when($supplier > 0, fn ($query) => $query->where('supplier_id', $supplier))
            ->latest('date')
            ->latest('id');
    }

    protected function tabFrom(Request $request): string
    {
        $tab = $request->string('tab')->value();

        if (in_array($tab, ['saldo', 'masuk', 'riwayat', 'pemasok'], true)) {
            return $tab;
        }

        return $request->hasAny(['from', 'to', 'bucket', 'action']) ? 'riwayat' : 'saldo';
    }

    public function create(Request $request): View
    {
        $bucket = $this->bucketFrom($request);

        $products = Product::where($this->columnFor($bucket), '>', 0)->orderBy('name')->get();

        return view('admin.disposals.create', [
            'code' => DamagedDisposal::nextCode(),
            'bucket' => $bucket,
            'products' => $products,
            // Dibuka dari tombol "Tangani" di tab saldo: barangnya langsung
            // masuk daftar dengan seluruh saldonya, tinggal disesuaikan.
            'preselected' => $products->firstWhere('id', $request->integer('product')),
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $bucket = $this->bucketFrom($request);
        $column = $this->columnFor($bucket);

        // Saldo asal tidak divalidasi: bucketFrom() hanya mengenal dua nilai dan
        // jatuh ke saldo rusak untuk apa pun selainnya — persis satu-satunya
        // perilaku yang dulu ada, sehingga form tanpa kolom itu tetap berlaku.
        $data = $request->validate([
            'date' => ['required', 'date'],
            // Tindakannya harus masuk akal bagi saldo asal: memperbaiki barang
            // yang memang layak jual tidak punya arti.
            'action' => ['required', Rule::in(array_keys(DamagedDisposal::actionsFor($bucket)))],
            // Barang yang dikembalikan harus jelas pergi ke pemasok mana;
            // tanpa itu tidak ada yang bisa ditagih atau ditelusuri.
            'supplier_id' => ['nullable', Rule::requiredIf(fn () => $request->input('action') === DamagedDisposal::ACTION_RETURN), 'exists:suppliers,id'],
            'note' => ['nullable', 'string', 'max:1000'],
            'quantities' => ['array'],
            'quantities.*' => ['nullable', 'integer', 'min:0'],
        ], ['supplier_id.required' => 'Pilih pemasok tujuan untuk barang yang dikembalikan.'], ['quantities.*' => 'jumlah']);

        $lines = collect($data['quantities'] ?? [])
            ->filter(fn ($quantity) => (int) $quantity > 0);

        if ($lines->isEmpty()) {
            return back()->withInput()->with('error', 'Isi jumlah pada minimal satu barang.');
        }

        $products = Product::whereIn('id', $lines->keys())->get()->keyBy('id');
        $label = $bucket === StockMovement::BUCKET_GOOD ? 'stok layak jual' : 'stok rusak';

        // Tidak boleh mengeluarkan lebih banyak daripada yang tercatat.
        foreach ($lines as $productId => $quantity) {
            $product = $products->get((int) $productId);

            if (! $product || (int) $quantity > $product->{$column}) {
                throw ValidationException::withMessages([
                    'quantities' => "Jumlah {$product?->name} melebihi {$label} yang tercatat ({$product?->{$column}}).",
                ]);
            }
        }

        $disposal = DB::transaction(function () use ($data, $lines, $bucket) {
            $disposal = DamagedDisposal::create([
                'code' => DamagedDisposal::nextCode(),
                'date' => $data['date'],
                'bucket' => $bucket,
                'action' => $data['action'],
                'supplier_id' => $data['action'] === DamagedDisposal::ACTION_RETURN ? ($data['supplier_id'] ?? null) : null,
                'note' => $data['note'] ?? null,
                'status' => DamagedDisposal::STATUS_DRAFT,
                'user_id' => auth()->id(),
            ]);

            $disposal->items()->createMany(
                $lines->map(fn ($quantity, $productId) => [
                    'product_id' => (int) $productId,
                    'quantity' => (int) $quantity,
                ])->values()->all(),
            );

            return $disposal;
        });

        return redirect()
            ->route('admin.disposals.show', $disposal)
            ->with('success', "Dokumen {$disposal->code} dibuat. Ajukan untuk memproses barangnya.");
    }

    public function show(DamagedDisposal $disposal): View
    {
        $disposal->load('items.product', 'user', 'supplier', 'closer', 'replacements.items.product', 'replacements.user');

        return view('admin.disposals.show', compact('disposal'));
    }

    public function submit(Request $request, DamagedDisposal $disposal): RedirectResponse
    {
        $disposal->load('items.product');

        $selfApprove = $request->user()->can('disposals.approve');

        try {
            $selfApprove
                ? $this->approvals->submitAndApprove($disposal)
                : $this->approvals->submit($disposal);
        } catch (ValidationException $exception) {
            return back()->with('error', collect($exception->errors())->flatten()->implode(' '));
        }

        return back()->with('success', $selfApprove
            ? "Dokumen {$disposal->code} diproses. {$disposal->postedSummary()}"
            : "Dokumen {$disposal->code} diajukan dan menunggu persetujuan.");
    }

    public function approve(DamagedDisposal $disposal): RedirectResponse
    {
        $disposal->load('items.product');

        try {
            $this->approvals->approve($disposal);
        } catch (ValidationException $exception) {
            return back()->with('error', collect($exception->errors())->flatten()->implode(' '));
        }

        return back()->with('success', "Dokumen {$disposal->code} diproses. {$disposal->postedSummary()}");
    }

    /**
     * Saldo asal yang sedang dikerjakan; bawaannya saldo rusak, sama seperti
     * satu-satunya perilaku yang dulu ada.
     */
    protected function bucketFrom(Request $request): string
    {
        return $request->input('bucket') === StockMovement::BUCKET_GOOD
            ? StockMovement::BUCKET_GOOD
            : StockMovement::BUCKET_DAMAGED;
    }

    protected function columnFor(string $bucket): string
    {
        return $bucket === StockMovement::BUCKET_GOOD ? 'stock' : 'damaged_stock';
    }

    /* ------------------------------------------- retur ke pemasok -- */

    /**
     * Catat pengiriman barang ke pemasok. Bisa diulang untuk membetulkan
     * ekspedisi atau resi selama returnya belum selesai.
     */
    public function ship(Request $request, DamagedDisposal $disposal): RedirectResponse
    {
        if (! $disposal->tracksSupplierReturn() || $disposal->isReturnClosed()) {
            return back()->with('error', 'Dokumen ini bukan retur ke pemasok yang masih berjalan.');
        }

        $data = $request->validate([
            'shipped_at' => ['required', 'date'],
            'courier' => ['nullable', 'string', 'max:100'],
            'tracking_number' => ['nullable', 'string', 'max:100'],
        ], [], ['shipped_at' => 'tanggal kirim', 'courier' => 'ekspedisi', 'tracking_number' => 'nomor resi']);

        $updated = DamagedDisposal::whereKey($disposal->id)
            ->whereIn('return_status', [DamagedDisposal::RETURN_UNSENT, DamagedDisposal::RETURN_SENT])
            ->update($data + ['return_status' => DamagedDisposal::RETURN_SENT]);

        if (! $updated) {
            return back()->with('error', 'Retur ini sudah selesai, data pengiriman tidak bisa diubah.');
        }

        return back()->with('success', "Retur {$disposal->code} tercatat dikirim ke pemasok.");
    }

    public function replace(Request $request, DamagedDisposal $disposal): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
            'replacements' => ['array'],
            'replacements.*' => ['nullable', 'integer', 'min:0'],
        ], [], ['replacements.*' => 'jumlah pengganti']);

        try {
            $this->stock->receiveSupplierReplacement($disposal, $data['date'], $data['note'] ?? null, $data['replacements'] ?? []);
        } catch (ValidationException $exception) {
            return back()->withInput()->with('error', collect($exception->errors())->flatten()->implode(' '));
        }

        return back()->with('success', $disposal->isReturnDone()
            ? "Barang pengganti diterima dan masuk stok layak jual. Retur {$disposal->code} selesai."
            : 'Barang pengganti diterima dan masuk stok layak jual.');
    }

    public function reject(Request $request, DamagedDisposal $disposal): RedirectResponse
    {
        $data = $request->validate([
            'close_note' => ['required', 'string', 'max:1000'],
            'back_to_warehouse' => ['nullable', 'boolean'],
        ], ['close_note.required' => 'Tulis alasan pemasok menolak.']);

        try {
            $this->stock->rejectSupplierReturn($disposal, $data['close_note'], $request->boolean('back_to_warehouse'));
        } catch (ValidationException $exception) {
            return back()->with('error', collect($exception->errors())->flatten()->implode(' '));
        }

        return back()->with('success', "Sisa retur {$disposal->code} ditutup sebagai ditolak pemasok.");
    }

    public function cancelReturn(Request $request, DamagedDisposal $disposal): RedirectResponse
    {
        $data = $request->validate(['cancel_note' => ['nullable', 'string', 'max:1000']]);

        try {
            $this->stock->cancelSupplierReturn($disposal, $data['cancel_note'] ?? null);
        } catch (ValidationException $exception) {
            return back()->with('error', collect($exception->errors())->flatten()->implode(' '));
        }

        return back()->with('success', "Retur {$disposal->code} dibatalkan. Unitnya kembali ke {$disposal->bucketLabel()}.");
    }

    /**
     * Surat jalan retur untuk dikirim bersama barangnya: bukti bagi pemasok,
     * dan nomor yang dicocokkan saat barang penggantinya datang.
     */
    public function deliveryNote(DamagedDisposal $disposal): \Illuminate\Http\Response|RedirectResponse
    {
        if (! $disposal->tracksSupplierReturn()) {
            return back()->with('error', 'Surat jalan hanya untuk retur ke pemasok yang sudah diproses.');
        }

        $disposal->load('items.product', 'supplier', 'user');

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('admin.disposals.delivery-note-pdf', [
            'disposal' => $disposal,
            'qr' => \App\Support\QrCode::file(route('admin.disposals.show', $disposal)),
        ])->setPaper('a4')->download('surat-jalan-retur-'.$disposal->code.'.pdf');
    }

    public function saveReturnSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'alert_days' => ['required', 'integer', 'min:1', 'max:365'],
        ], [], ['alert_days' => 'batas hari']);

        \App\Models\AppSetting::put(\App\Models\AppSetting::SUPPLIER_RETURN_ALERT_DAYS, (string) $data['alert_days']);

        return back()->with('success', "Retur kini ditandai terlambat setelah {$data['alert_days']} hari.");
    }

    public function withdraw(DamagedDisposal $disposal): RedirectResponse
    {
        try {
            $this->approvals->withdraw($disposal);
        } catch (ValidationException $exception) {
            return back()->with('error', collect($exception->errors())->flatten()->implode(' '));
        }

        return back()->with('success', "Pengajuan {$disposal->code} ditarik kembali ke draft.");
    }

    public function destroy(DamagedDisposal $disposal): RedirectResponse
    {
        if (! $disposal->isEditable()) {
            return back()->with('error', $disposal->isPending()
                ? 'Dokumen sedang menunggu persetujuan dan tidak dapat dihapus.'
                : 'Dokumen yang sudah disetujui bersifat final dan tidak dapat dihapus.');
        }

        $deleted = DB::transaction(fn () => $disposal->lockForEditing() && $disposal->delete());

        if (! $deleted) {
            return back()->with('error', $disposal::staleEditMessage());
        }

        return redirect()
            ->route('admin.disposals.index')
            ->with('success', 'Dokumen penanganan barang rusak berhasil dihapus.');
    }
}
