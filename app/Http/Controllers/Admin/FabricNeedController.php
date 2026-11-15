<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\OrderPlanner;
use App\Services\TableExport;
use App\Support\FabricNeeds;
use App\Support\PackingList;
use App\Support\ProductionPlan;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Kebutuhan Kain: dari Rencana Produksi (barang yang harus diproduksi karena
 * stok barang jadi tidak cukup untuk pesanan) dikalikan pemakaian kain per
 * barang, dibandingkan dengan sisa roll kain.
 */
class FabricNeedController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('can:fabrics.view')];
    }

    public function index(Request $request, OrderPlanner $planner): View
    {
        $planner->refresh();

        return view('admin.fabric-needs.index', $this->data($request) + [
            'kinds' => PoDeadlineController::KINDS,
        ]);
    }

    public function export(Request $request, OrderPlanner $planner): StreamedResponse
    {
        $planner->refresh();
        $data = $this->data($request);
        /** @var FabricNeeds $needs */
        $needs = $data['needs'];

        $export = TableExport::make('Kebutuhan Kain')
            ->note($data['description'])
            ->note('Kebutuhan = jumlah harus diproduksi (Rencana Produksi) × pemakaian kain per unit.');

        $export->sheet('Per Jenis Kain', [
            ['SKU Kain', 'text'], ['Nama Kain', 'text'], ['Satuan', 'text'], ['Unit Harus Diproduksi', 'sum'],
            ['Kebutuhan', 'number'], ['Sudah Keluar', 'number'], ['Sisa Kebutuhan', 'number'], ['Stok Kain', 'number'], ['Roll Berisi', 'number'], ['Kurang', 'number'],
        ], $needs->rows->map(fn (array $row) => [
            $row['fabric']->sku, $row['fabric']->displayName(), $row['fabric']->unit, $row['units'],
            $row['need'], $row['issued'], $row['remaining'], $row['stock'], $row['open_rolls'], $row['short'],
        ]));

        $export->sheet('Rincian per Barang', [
            ['SKU Kain', 'text'], ['Nama Kain', 'text'], ['SKU Barang', 'text'], ['Nama Barang', 'text'],
            ['Harus Diproduksi', 'sum'], ['Pemakaian / Unit', 'number'], ['Kebutuhan Kain', 'number'], ['Satuan', 'text'],
        ], $needs->rows->flatMap(fn (array $row) => $row['products']->map(fn (array $product) => [
            $row['fabric']->sku, $row['fabric']->displayName(), $product['sku'], $product['name'],
            $product['short'], $product['usage'], $product['need'], $row['fabric']->unit,
        ])));

        if ($needs->missing->isNotEmpty()) {
            $export->sheet('Belum Ada Pemakaian Kain', [
                ['SKU Barang', 'text'], ['Nama Barang', 'text'], ['Harus Diproduksi', 'sum'],
            ], $needs->missing->map(fn (array $row) => [$row['sku'], $row['name'], $row['short']]));
        }

        return $export->download('kebutuhan-kain-'.$data['from']->format('Y-m-d').'-sd-'.$data['to']->format('Y-m-d').'.xlsx');
    }

    /** @return array<string, mixed> */
    protected function data(Request $request): array
    {
        $kind = array_key_exists((string) $request->input('jenis'), PoDeadlineController::KINDS) ? (string) $request->input('jenis') : 'po';
        $deadlines = app(PoDeadlineController::class);
        $base = fn () => $deadlines->base($request, $kind, '');

        [$from, $to] = $this->period($request, $base);
        $dates = ProductionPlan::datesFrom(null, $from->format('Y-m-d'), $to->format('Y-m-d'));
        $plan = ProductionPlan::for($base, $dates, PackingList::BY_PRODUCT);

        $issuedSince = $this->date($request->input('keluar_sejak'));
        $needs = FabricNeeds::from($plan, $issuedSince);

        $description = collect([
            'Jenis: '.PoDeadlineController::KINDS[$kind],
            'Batas kirim: '.PoDeadlineController::describeDates($dates),
            $issuedSince ? 'Dikurangi kain keluar sejak '.$issuedSince->translatedFormat('d M Y') : null,
        ])->filter()->implode(' · ');

        return [
            'needs' => $needs,
            'plan' => $plan,
            'kind' => $kind,
            'from' => $from,
            'to' => $to,
            'issuedSince' => $issuedSince,
            'description' => $description,
            'productionUrl' => route('admin.orders.deadlines.production', ['dari' => $from->format('Y-m-d'), 'sampai' => $to->format('Y-m-d'), 'jenis' => $kind]),
        ];
    }

    /**
     * Rentang batas kirim. Bawaannya: dari batas kirim terlama yang belum
     * dikirim (paling jauh 30 hari ke belakang) sampai 30 hari ke depan.
     * Paling panjang 62 hari, sama dengan Rencana Produksi.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function period(Request $request, \Closure $base): array
    {
        $today = Carbon::today();
        $from = $this->date($request->input('dari'));
        $to = $this->date($request->input('sampai'));

        if (! $from) {
            $earliest = $base()->toBase()->whereNotNull('shipment_orders.ship_by')->min('shipment_orders.ship_by');
            $from = $earliest ? Carbon::parse($earliest)->startOfDay() : $today->copy();
            $from = $from->max($today->copy()->subDays(30))->min($today->copy());
        }

        $to ??= $today->copy()->addDays(30);

        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        if ($from->diffInDays($to) >= ProductionPlan::MAX_DATES) {
            $to = $from->copy()->addDays(ProductionPlan::MAX_DATES - 1);
        }

        return [$from, $to];
    }

    protected function date(mixed $value): ?Carbon
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value)
            ? Carbon::parse($value)->startOfDay()
            : null;
    }
}
