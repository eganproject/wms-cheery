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
use Symfony\Component\HttpFoundation\Response;

/**
 * Label QR roll kain. Isi QR = kode roll (SKU kain + nomor roll), jadi saat
 * discan langsung terbaca kain dan sisa panjangnya. Ukuran label sama
 * dengan label QR barang.
 */
class FabricLabelController extends Controller implements HasMiddleware
{
    public const MAX_LABELS = 1000;

    public static function middleware(): array
    {
        return [new Middleware('can:fabrics.receive')];
    }

    public function pdf(Request $request): Response
    {
        $data = $request->validate([
            'rolls' => ['required', 'array', 'min:1', 'max:'.self::MAX_LABELS],
            'rolls.*' => ['integer'],
            'copies' => ['nullable', 'integer', 'min:1', 'max:10'],
            'layout' => ['nullable', 'string'],
            'text_position' => ['nullable', 'in:below,right'],
            'gap' => ['nullable', 'numeric', 'min:0', 'max:10'],
            'skip' => ['nullable', 'integer', 'min:0', 'max:99'],
        ], [
            'rolls.required' => 'Pilih minimal satu roll untuk dicetak labelnya.',
        ]);

        $rolls = FabricRoll::query()->with('fabric')->whereIn('id', $data['rolls'])
            ->orderBy('fabric_id')->orderBy('number')->get();

        if ($rolls->isEmpty()) {
            throw ValidationException::withMessages(['rolls' => 'Roll yang dipilih tidak ditemukan.']);
        }

        $copies = (int) ($data['copies'] ?? 1);

        if ($rolls->count() * $copies > self::MAX_LABELS) {
            throw ValidationException::withMessages(['rolls' => 'Maksimal '.self::MAX_LABELS.' label sekali cetak.']);
        }

        $layout = QrLabelLayout::get($data['layout'] ?? 'thermal-50x30');

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
                'name' => Str::limit($roll->fabric->displayName(), $layout['stacked'] ? max(8, $chars - mb_strlen($variant) - 3) : (int) ($chars * 0.7), '…'),
                'variant' => $variant,
            ];

            for ($i = 0; $i < $copies; $i++) {
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
            'showName' => true,
        ])
            ->setPaper(QrLabelLayout::paper($layout))
            ->setOption('isFontSubsettingEnabled', true)
            ->stream('label-roll-kain-'.now()->format('Ymd-His').'.pdf');
    }
}
