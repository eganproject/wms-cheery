<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ParsesApiParameters;
use App\Http\Controllers\Controller;
use App\Services\StockReportService;
use App\Support\StockReportFilters;
use App\Support\StockReportRow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Laporan stok lewat API: saldo awal, masuk, keluar, saldo akhir, dan
 * perputaran tiap barang — angka yang sama dengan halaman Stok → Laporan Stok.
 */
class StockReportController extends Controller
{
    use ParsesApiParameters;

    public function index(Request $request, StockReportService $report): JsonResponse
    {
        [$page, $perPage] = $this->paging($request);
        if ($page === false || $perPage === false) {
            return $this->bad('page dan per_page harus berupa integer positif; per_page maksimum 500.');
        }

        $from = $this->day($request->input('from'));
        $to = $this->day($request->input('to'));
        if ($from === false || $to === false) {
            return $this->bad('from dan to harus berupa tanggal YYYY-MM-DD yang valid (WIB).');
        }
        // Bawaan sama dengan halaman admin: 30 hari terakhir sampai hari ini.
        $to = ($to ?? now('Asia/Jakarta')->toImmutable())->endOfDay();
        $from = ($from ?? $to->subDays(StockReportFilters::DEFAULT_DAYS - 1))->startOfDay();
        if ($from->gt($to)) {
            return $this->bad('from tidak boleh melebihi to.');
        }

        $view = $this->choice($request, 'view', array_keys(StockReportFilters::VIEWS));
        $sort = $this->choice($request, 'sort', array_keys(StockReportFilters::SORTS));
        if ($view === false) {
            return $this->bad('view harus salah satu dari: '.implode(', ', array_keys(StockReportFilters::VIEWS)).'.');
        }
        if ($sort === false) {
            return $this->bad('sort harus salah satu dari: '.implode(', ', array_keys(StockReportFilters::SORTS)).'.');
        }

        $filters = new StockReportFilters(
            from: Carbon::instance($from),
            to: Carbon::instance($to),
            search: $this->text($request, 'search'),
            category: $this->text($request, 'category'),
            view: $view ?? 'semua',
            sort: $sort ?? 'keluar',
        );

        $rows = $report->paginate($filters, $perPage, $page);
        $summary = $report->summary($filters);

        return response()->json([
            'success' => true,
            'meta' => [
                'warehouse_code' => config('stock_api.warehouse_code'),
                'server_time' => now('Asia/Jakarta')->toIso8601String(),
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                ...$this->pageMeta($page, $perPage, $rows->total()),
                'summary' => $summary,
                'classes' => $report->classSummary($filters),
            ],
            'data' => collect($rows->items())->map(fn (StockReportRow $row) => [
                'sku' => $row->sku,
                'name' => $row->name,
                'category' => $row->category,
                'uom' => $row->unit,
                'min_qty' => $row->minStock,
                'opening' => $row->opening,
                'incoming' => $row->incoming,
                'outgoing' => $row->outgoing,
                'sold' => $row->sold,
                'closing' => $row->closing,
                'damaged' => $row->damaged,
                'frequency' => $row->frequency,
                'per_day' => round($row->perDay(), 4),
                'share' => round($row->share(), 4),
                'movement_class' => $row->movementClass(),
                'turnover' => $row->turnover() === null ? null : round($row->turnover(), 4),
                'days_of_cover' => $row->daysOfCover() === null ? null : round($row->daysOfCover(), 2),
                'urgency' => $row->urgency(),
                'last_out_at' => $this->iso($row->lastOutAt),
            ])->values(),
        ]);
    }
}
