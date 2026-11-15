<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FabricRoll;
use App\Support\QrCode;
use App\Support\QrLabelLayout;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Label QR roll kain. Isi QR = kode roll (SKU kain + nomor roll), jadi saat
 * discan langsung terbaca kain dan sisa panjangnya. Ukuran label sama
 * dengan label QR barang.
 */
class FabricLabelController extends Controller implements HasMiddleware
{
    public const MAX_LABELS = 1000;

    public const MAX_COPIES = 50;

    public static function middleware(): array
    {
        return [new Middleware('can:fabrics.receive')];
    }

    /**
     * Halaman pilih ukuran label, sama dengan Cetak Label QR barang. Roll
     * dibawa dari centangan di Kain Masuk / detail kain (?rolls[]=..).
     */
    public function create(Request $request): View
    {
        $ids = collect((array) $request->input('rolls'))->map(fn ($id) => (int) $id)->filter()->take(self::MAX_LABELS);

        $rolls = $ids->isEmpty() ? collect() : FabricRoll::query()->with('fabric')->whereIn('id', $ids)
            ->orderBy('fabric_id')->orderBy('number')->get();

        $back = $request->filled('kembali') && str_starts_with((string) $request->input('kembali'), url('/'))
            ? (string) $request->input('kembali')
            : route('admin.fabric-receipts.index');

        return view('admin.fabric-labels.create', [
            'items' => $rolls->map(fn (FabricRoll $roll) => [
                'id' => $roll->id,
                'sku' => $roll->code,
                'name' => $roll->fabric->displayName(),
                'variant' => $roll->fabric->format($roll->initial_quantity),
                'copies' => 1,
            ])->values(),
            'layouts' => collect(QrLabelLayout::all())->map(fn ($layout, $key) => QrLabelLayout::get($key)),
            'defaultLayout' => QrLabelLayout::DEFAULT,
            'maxLabels' => self::MAX_LABELS,
            'maxCopies' => self::MAX_COPIES,
            'back' => $back,
        ]);
    }

    public function pdf(Request $request): Response
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:'.self::MAX_LABELS],
            'items.*.id' => ['required', 'integer'],
            'items.*.copies' => ['required', 'integer', 'min:1', 'max:'.self::MAX_COPIES],
            'layout' => ['nullable', 'string'],
            'show_name' => ['nullable', 'boolean'],
            'text_position' => ['nullable', 'in:below,right'],
            'gap' => ['nullable', 'numeric', 'min:0', 'max:10'],
            'skip' => ['nullable', 'integer', 'min:0', 'max:99'],
        ], [
            'items.required' => 'Pilih minimal satu roll untuk dicetak labelnya.',
        ], ['items.*.copies' => 'jumlah label']);

        $copies = collect($data['items'])
            ->groupBy(fn ($item) => (int) $item['id'])
            ->map(fn ($rows) => $rows->sum(fn ($item) => (int) $item['copies']));

        if ($copies->sum() > self::MAX_LABELS) {
            throw ValidationException::withMessages(['items' => 'Maksimal '.self::MAX_LABELS.' label sekali cetak. Bagi menjadi beberapa kali cetak.']);
        }

        $rolls = FabricRoll::query()->with('fabric')->whereIn('id', $copies->keys())
            ->orderBy('fabric_id')->orderBy('number')->get();

        if ($rolls->isEmpty()) {
            throw ValidationException::withMessages(['items' => 'Roll yang dipilih tidak ditemukan.']);
        }

        $showName = (bool) ($data['show_name'] ?? true);

        $layout = QrLabelLayout::get($data['layout'] ?? null);

        if (isset($data['gap'])) {
            $layout = QrLabelLayout::withGap($layout, (float) $data['gap']);
        }

        if (isset($data['text_position'])) {
            $layout['stacked'] = $data['text_position'] === 'below';
        }

        $skip = $layout['sheet'] ? min((int) ($data['skip'] ?? 0), $layout['per_page'] - 1) : 0;
        $labels = array_fill(0, $skip, null);
        $chars = $layout['name_chars'];

        foreach ($rolls as $roll) {
            // Baris kedua: nama kain dan panjang awal roll. Sisa terbaru
            // selalu dari scan, bukan dari label.
            $variant = $roll->fabric->format($roll->initial_quantity);
            $label = [
                'sku' => $roll->code,
                'qr' => QrCode::file($roll->code),
                'name' => $showName ? Str::limit($roll->fabric->displayName(), $layout['stacked'] ? max(8, $chars - mb_strlen($variant) - 3) : (int) ($chars * 0.7), '…') : '',
                'variant' => $showName ? $variant : '',
            ];

            for ($i = 0; $i < $copies[$roll->id]; $i++) {
                $labels[] = $label;
            }
        }

        @set_time_limit(180);
        if (preg_match('/^(\d+)M$/i', (string) ini_get('memory_limit'), $limit) && (int) $limit[1] < 512) {
            @ini_set('memory_limit', '512M');
        }

        return Pdf::loadView('admin.products.labels-pdf', [
            'pages' => array_chunk($labels, $layout['per_page']),
            'layout' => $layout,
            'showName' => $showName,
        ])
            ->setPaper(QrLabelLayout::paper($layout))
            ->setOption('isFontSubsettingEnabled', true)
            ->stream('label-roll-kain-'.now()->format('Ymd-His').'.pdf');
    }
}
