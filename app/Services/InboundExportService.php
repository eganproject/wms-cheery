<?php

namespace App\Services;

use App\Models\Inbound;
use App\Support\DateRange;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Export daftar penerimaan barang ke Excel.
 *
 * Empat lembar untuk empat kebutuhan: "Ringkasan" untuk yang ingin tahu
 * angkanya sekilas (total, per status, per pemasok, per tanggal), "Rekap
 * Barang" untuk melihat barang apa saja yang datang, "Dokumen" satu baris per
 * dokumen, dan "Detail Barang" satu baris per barang per dokumen untuk diolah
 * ulang.
 *
 * Isinya mengikuti saringan yang sedang aktif di halaman, jadi berkas dan
 * layar selalu menceritakan data yang sama.
 *
 * Angka dan tanggal ditulis sebagai nilai asli, bukan teks berformat, supaya
 * berkasnya bisa langsung diurutkan, disaring, dan dijumlahkan di spreadsheet.
 */
class InboundExportService
{
    protected const HEADER_ROW = 5;

    protected const DATE_FORMAT = 'dd/mm/yyyy';

    protected const NUMBER_FORMAT = '#,##0';

    /** @var array<int, array{0: string, 1: string}> label, jenis kolom */
    protected array $documentColumns = [
        ['No', 'number'],
        ['Tanggal', 'date'],
        ['No. Dokumen', 'text'],
        ['Pemasok', 'text'],
        ['Referensi', 'text'],
        ['Status', 'text'],
        ['Jumlah Baris', 'number'],
        ['Qty Diterima', 'number'],
        ['Qty Rusak', 'number'],
        ['Qty Layak', 'number'],
        ['Dibuat Oleh', 'text'],
        ['Disetujui Oleh', 'text'],
        ['Waktu Disetujui', 'text'],
        ['Catatan', 'wrap'],
    ];

    /** @var array<int, array{0: string, 1: string}> */
    protected array $detailColumns = [
        ['No', 'number'],
        ['Tanggal', 'date'],
        ['No. Dokumen', 'text'],
        ['Pemasok', 'text'],
        ['Referensi', 'text'],
        ['Status', 'text'],
        ['SKU', 'text'],
        ['Nama Barang', 'text'],
        ['Kategori', 'text'],
        ['Satuan', 'text'],
        ['Qty Diterima', 'number'],
        ['Qty Rusak', 'number'],
        ['Qty Layak', 'number'],
        ['Catatan Baris', 'wrap'],
    ];

    /** @var array<int, array{0: string, 1: string}> */
    protected array $productColumns = [
        ['No', 'number'],
        ['SKU', 'text'],
        ['Nama Barang', 'text'],
        ['Kategori', 'text'],
        ['Satuan', 'text'],
        ['Jumlah Dokumen', 'number'],
        ['Qty Diterima', 'number'],
        ['Qty Rusak', 'number'],
        ['Qty Layak', 'number'],
        ['Qty Sudah Diposting', 'number'],
    ];

    /**
     * @param  array{search: ?string, status: ?string, range: DateRange}  $filters
     */
    public function download(Builder $query, array $filters, string $filename): StreamedResponse
    {
        $spreadsheet = $this->build($query, $filters);

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0, must-revalidate',
        ]);
    }

    /**
     * @param  array{search: ?string, status: ?string, range: DateRange}  $filters
     */
    public function build(Builder $query, array $filters): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()
            ->setCreator(config('app.name'))
            ->setTitle('Laporan Penerimaan Barang');
        $spreadsheet->getDefaultStyle()->getFont()->setName('Calibri')->setSize(10);

        $summary = $spreadsheet->getActiveSheet();
        $summary->setTitle('Ringkasan');
        $products = $spreadsheet->createSheet()->setTitle('Rekap Barang');
        $documents = $spreadsheet->createSheet()->setTitle('Dokumen');
        $details = $spreadsheet->createSheet()->setTitle('Detail Barang');

        $periodLabel = $this->periodLabel($filters['range']);

        $this->writeTableHeading($documents, 'DAFTAR DOKUMEN PENERIMAAN BARANG', $periodLabel, $this->documentColumns);
        $this->writeTableHeading($details, 'DETAIL PENERIMAAN BARANG PER DOKUMEN', $periodLabel, $this->detailColumns);

        // Satu kali baca dari database: kedua lembar tabel ditulis dan seluruh
        // rekap dikumpulkan sambil jalan, bukan lewat query terpisah.
        $stats = $this->emptyStats();
        $docRow = self::HEADER_ROW + 1;
        $detailRow = self::HEADER_ROW + 1;

        $query
            ->with(['supplier', 'user', 'approver', 'items.product'])
            ->orderBy('date')
            ->orderBy('id')
            ->chunk(200, function ($inbounds) use ($documents, $details, &$stats, &$docRow, &$detailRow) {
                foreach ($inbounds as $inbound) {
                    $this->writeDocument($documents, $docRow, $inbound);
                    $docRow++;

                    foreach ($inbound->items as $item) {
                        $this->writeDetail($details, $detailRow, $inbound, $item);
                        $detailRow++;
                    }

                    $this->collect($stats, $inbound);
                }
            });

        $this->finishTable($documents, $this->documentColumns, $docRow - 1, true);
        $this->finishTable($details, $this->detailColumns, $detailRow - 1, true);

        $this->writeProducts($products, $periodLabel, $stats['products']);
        $this->writeSummary($summary, $filters, $periodLabel, $stats);

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /* ------------------------------------------------------ pengumpulan --- */

    /**
     * @return array<string, mixed>
     */
    protected function emptyStats(): array
    {
        return [
            'documents' => 0,
            'lines' => 0,
            'received' => 0,
            'damaged' => 0,
            'good' => 0,
            'first_date' => null,
            'last_date' => null,
            'statuses' => [],
            'suppliers' => [],
            'dates' => [],
            'products' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    protected function collect(array &$stats, Inbound $inbound): void
    {
        $received = (int) $inbound->items->sum('quantity');
        $damaged = (int) $inbound->items->sum('damaged_quantity');
        $good = (int) $inbound->items->sum(fn ($item) => $item->goodQuantity());
        $lines = $inbound->items->count();
        $posted = $inbound->isPosted();

        $stats['documents']++;
        $stats['lines'] += $lines;
        $stats['received'] += $received;
        $stats['damaged'] += $damaged;
        $stats['good'] += $good;

        $date = $inbound->date->toDateString();
        $stats['first_date'] = $stats['first_date'] === null ? $date : min($stats['first_date'], $date);
        $stats['last_date'] = $stats['last_date'] === null ? $date : max($stats['last_date'], $date);

        $this->bump($stats['statuses'], $inbound->statusLabel(), $lines, $received, $damaged, $good);
        $this->bump($stats['suppliers'], $inbound->supplier?->name ?? 'Tanpa pemasok', $lines, $received, $damaged, $good);
        $this->bump($stats['dates'], $date, $lines, $received, $damaged, $good);

        foreach ($inbound->items as $item) {
            $key = $item->product_id;

            $stats['products'][$key] ??= [
                'sku' => (string) ($item->product?->sku ?? '-'),
                'name' => (string) ($item->product?->name ?? '-'),
                'category' => (string) ($item->product?->category ?: '-'),
                'unit' => (string) ($item->product?->unit ?? '-'),
                'documents' => [],
                'received' => 0,
                'damaged' => 0,
                'good' => 0,
                'posted' => 0,
            ];

            $product = &$stats['products'][$key];
            $product['documents'][$inbound->id] = true;
            $product['received'] += (int) $item->quantity;
            $product['damaged'] += (int) $item->damaged_quantity;
            $product['good'] += $item->goodQuantity();
            $product['posted'] += $posted ? (int) $item->quantity : 0;
            unset($product);
        }
    }

    /**
     * @param  array<string, array<string, int>>  $bucket
     */
    protected function bump(array &$bucket, string $key, int $lines, int $received, int $damaged, int $good): void
    {
        $bucket[$key] ??= ['documents' => 0, 'lines' => 0, 'received' => 0, 'damaged' => 0, 'good' => 0];
        $bucket[$key]['documents']++;
        $bucket[$key]['lines'] += $lines;
        $bucket[$key]['received'] += $received;
        $bucket[$key]['damaged'] += $damaged;
        $bucket[$key]['good'] += $good;
    }

    /* -------------------------------------------------------- ringkasan --- */

    /**
     * @param  array{search: ?string, status: ?string, range: DateRange}  $filters
     * @param  array<string, mixed>  $stats
     */
    protected function writeSummary(Worksheet $sheet, array $filters, string $periodLabel, array $stats): void
    {
        $sheet->setCellValue('A1', 'LAPORAN PENERIMAAN BARANG');
        $sheet->setCellValue('A2', config('app.name').' · Periode: '.$periodLabel);
        $sheet->setCellValue('A3', 'Diunduh '.now()->translatedFormat('d F Y H:i').
            (auth()->user() ? ' oleh '.auth()->user()->name : ''));

        $sheet->mergeCells('A1:F1');
        $sheet->mergeCells('A2:F2');
        $sheet->mergeCells('A3:F3');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle('A2')->getFont()->setSize(10)->getColor()->setRGB('404040');
        $sheet->getStyle('A3')->getFont()->setSize(9)->setItalic(true)->getColor()->setRGB('6D6D6D');

        $row = 5;

        $row = $this->writeKeyValues($sheet, $row, 'Filter yang Diterapkan', [
            ['Periode', $periodLabel],
            ['Status', $this->statusFilterLabel($filters['status'])],
            ['Pencarian', $filters['search'] ?: '-'],
        ]);

        $average = $stats['documents'] > 0 ? round($stats['received'] / $stats['documents'], 1) : 0;
        $damageRate = $stats['received'] > 0 ? $stats['damaged'] / $stats['received'] : 0;

        $row = $this->writeKeyValues($sheet, $row + 1, 'Ringkasan Total', [
            ['Total Dokumen', $stats['documents']],
            ['Total Baris Barang', $stats['lines']],
            ['Jumlah SKU Berbeda', count($stats['products'])],
            ['Jumlah Pemasok', count($stats['suppliers'])],
            ['Total Qty Diterima', $stats['received']],
            ['Total Qty Rusak', $stats['damaged']],
            ['Total Qty Layak', $stats['good']],
            ['Persentase Rusak', [$damageRate, '0.00%']],
            ['Rata-rata Qty per Dokumen', [$average, '#,##0.0']],
            ['Tanggal Transaksi Pertama', $this->displayDate($stats['first_date'])],
            ['Tanggal Transaksi Terakhir', $this->displayDate($stats['last_date'])],
        ]);

        if ($stats['documents'] === 0) {
            $sheet->setCellValue([1, $row + 1], 'Tidak ada dokumen penerimaan barang yang cocok dengan filter ini.');
            $sheet->getStyle([1, $row + 1])->getFont()->setItalic(true)->getColor()->setRGB('6D6D6D');
        } else {
            $row = $this->writeBreakdown($sheet, $row + 1, 'Rekap per Status', 'Status', $stats['statuses'], $stats);
            $row = $this->writeBreakdown($sheet, $row + 1, 'Rekap per Pemasok', 'Pemasok', $this->sortByReceived($stats['suppliers']), $stats);

            $dates = $stats['dates'];
            ksort($dates);
            $labelled = [];
            foreach ($dates as $date => $values) {
                $labelled[Carbon::parse($date)->translatedFormat('d M Y')] = $values;
            }

            $row = $this->writeBreakdown($sheet, $row + 1, 'Rekap per Tanggal', 'Tanggal', $labelled, $stats);

            $sheet->setCellValue([1, $row + 1], 'Catatan: stok gudang hanya bertambah dari dokumen berstatus "Diposting". '.
                'Dokumen draft, menunggu persetujuan, atau ditolak belum menggerakkan stok.');
            $sheet->mergeCells([1, $row + 1, 6, $row + 1]);
            $sheet->getStyle([1, $row + 1])->getFont()->setItalic(true)->setSize(9)->getColor()->setRGB('6D6D6D');
            $sheet->getStyle([1, $row + 1])->getAlignment()->setWrapText(true);
            $sheet->getRowDimension($row + 1)->setRowHeight(28);
        }

        $sheet->getColumnDimension('A')->setWidth(30);
        foreach (['B', 'C', 'D', 'E', 'F'] as $column) {
            $sheet->getColumnDimension($column)->setWidth(16);
        }

        $sheet->setShowGridlines(false);
        $this->pageSetup($sheet, PageSetup::ORIENTATION_PORTRAIT);
    }

    /**
     * Blok dua kolom: label di kiri, nilai di kanan.
     *
     * @param  array<int, array{0: string, 1: mixed}>  $rows  Nilai boleh berupa [angka, format].
     * @return int Baris kosong pertama setelah blok.
     */
    protected function writeKeyValues(Worksheet $sheet, int $row, string $title, array $rows): int
    {
        $this->writeSectionTitle($sheet, $row, $title, 2);
        $start = $row + 1;
        $row++;

        foreach ($rows as [$label, $value]) {
            $sheet->setCellValue([1, $row], $label);

            $format = null;
            if (is_array($value)) {
                [$value, $format] = $value;
            }

            $sheet->setCellValue([2, $row], $value);

            if (is_int($value) || is_float($value)) {
                $sheet->getStyle([2, $row])->getNumberFormat()->setFormatCode($format ?? self::NUMBER_FORMAT);
                $sheet->getStyle([2, $row])->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                $sheet->getStyle([2, $row])->getFont()->setBold(true);
            } else {
                $sheet->getStyle([2, $row])->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            }

            $row++;
        }

        $this->borders($sheet, [1, $start, 2, $row - 1]);
        $sheet->getStyle([1, $start, 1, $row - 1])->getFont()->getColor()->setRGB('404040');

        return $row;
    }

    /**
     * Tabel rekap kecil: satu baris per kelompok, plus baris total.
     *
     * @param  array<string, array<string, int>>  $groups
     * @param  array<string, mixed>  $stats
     * @return int Baris kosong pertama setelah tabel.
     */
    protected function writeBreakdown(Worksheet $sheet, int $row, string $title, string $label, array $groups, array $stats): int
    {
        $headers = [$label, 'Dokumen', 'Baris', 'Qty Diterima', 'Qty Rusak', 'Qty Layak'];

        $this->writeSectionTitle($sheet, $row, $title, count($headers));
        $row++;

        foreach ($headers as $index => $header) {
            $sheet->setCellValue([$index + 1, $row], $header);
        }
        $this->styleHeader($sheet, $row, count($headers));
        $start = $row;
        $row++;

        foreach ($groups as $name => $values) {
            $sheet->setCellValue([1, $row], (string) $name);
            $sheet->setCellValue([2, $row], $values['documents']);
            $sheet->setCellValue([3, $row], $values['lines']);
            $sheet->setCellValue([4, $row], $values['received']);
            $sheet->setCellValue([5, $row], $values['damaged']);
            $sheet->setCellValue([6, $row], $values['good']);
            $row++;
        }

        $sheet->setCellValue([1, $row], 'TOTAL');
        $sheet->setCellValue([2, $row], $stats['documents']);
        $sheet->setCellValue([3, $row], $stats['lines']);
        $sheet->setCellValue([4, $row], $stats['received']);
        $sheet->setCellValue([5, $row], $stats['damaged']);
        $sheet->setCellValue([6, $row], $stats['good']);
        $this->styleTotalRow($sheet, $row, 6);

        $sheet->getStyle([2, $start + 1, 6, $row])->getNumberFormat()->setFormatCode(self::NUMBER_FORMAT);
        $this->borders($sheet, [1, $start, 6, $row]);

        return $row + 1;
    }

    protected function writeSectionTitle(Worksheet $sheet, int $row, string $title, int $width): void
    {
        $sheet->setCellValue([1, $row], strtoupper($title));
        $style = $sheet->getStyle([1, $row, $width, $row]);
        $style->getFont()->setBold(true)->setSize(11)->getColor()->setRGB('0A0A0A');
        $style->getBorders()->getBottom()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setRGB('0A0A0A');
        $sheet->getRowDimension($row)->setRowHeight(20);
    }

    /**
     * @param  array<string, array<string, int>>  $groups
     * @return array<string, array<string, int>>
     */
    protected function sortByReceived(array $groups): array
    {
        uasort($groups, fn ($a, $b) => $b['received'] <=> $a['received']);

        return $groups;
    }

    /* ----------------------------------------------------- rekap barang --- */

    /**
     * @param  array<int, array<string, mixed>>  $products
     */
    protected function writeProducts(Worksheet $sheet, string $periodLabel, array $products): void
    {
        $this->writeTableHeading($sheet, 'REKAP PENERIMAAN PER BARANG', $periodLabel, $this->productColumns);

        // Barang yang paling banyak datang di atas: itu yang biasanya dicari.
        uasort($products, fn ($a, $b) => [$b['received'], $a['name']] <=> [$a['received'], $b['name']]);

        $row = self::HEADER_ROW + 1;
        $number = 1;

        foreach ($products as $product) {
            $values = [
                $number++,
                $product['sku'],
                $product['name'],
                $product['category'],
                $product['unit'],
                count($product['documents']),
                $product['received'],
                $product['damaged'],
                $product['good'],
                $product['posted'],
            ];

            $this->writeRow($sheet, $row, $values, $this->productColumns);
            $row++;
        }

        $lastRow = $row - 1;

        if ($products !== []) {
            $sheet->setCellValue([1, $row], 'TOTAL');
            $sheet->mergeCells([1, $row, 5, $row]);

            foreach ([7, 8, 9, 10] as $column) {
                $letter = Coordinate::stringFromColumnIndex($column);
                $sheet->setCellValue([$column, $row], sprintf('=SUM(%s%d:%s%d)', $letter, self::HEADER_ROW + 1, $letter, $lastRow));
            }

            $this->styleTotalRow($sheet, $row, count($this->productColumns));
        }

        $this->finishTable($sheet, $this->productColumns, $products === [] ? $lastRow : $row, false, $lastRow);
    }

    /* ------------------------------------------------------ baris tabel --- */

    protected function writeDocument(Worksheet $sheet, int $row, Inbound $inbound): void
    {
        $received = (int) $inbound->items->sum('quantity');
        $damaged = (int) $inbound->items->sum('damaged_quantity');

        $this->writeRow($sheet, $row, [
            $row - self::HEADER_ROW,
            ExcelDate::PHPToExcel($inbound->date->copy()->startOfDay()),
            $inbound->code,
            $inbound->supplier?->name ?? '-',
            $inbound->reference ?: '-',
            $inbound->statusLabel(),
            $inbound->items->count(),
            $received,
            $damaged,
            (int) $inbound->items->sum(fn ($item) => $item->goodQuantity()),
            $inbound->user?->name ?? '-',
            $inbound->approver?->name ?? '-',
            $inbound->approved_at?->translatedFormat('d/m/Y H:i') ?? '-',
            $inbound->note ?: '-',
        ], $this->documentColumns);
    }

    protected function writeDetail(Worksheet $sheet, int $row, Inbound $inbound, $item): void
    {
        $this->writeRow($sheet, $row, [
            $row - self::HEADER_ROW,
            ExcelDate::PHPToExcel($inbound->date->copy()->startOfDay()),
            $inbound->code,
            $inbound->supplier?->name ?? '-',
            $inbound->reference ?: '-',
            $inbound->statusLabel(),
            (string) ($item->product?->sku ?? '-'),
            (string) ($item->product?->name ?? '-'),
            (string) ($item->product?->category ?: '-'),
            (string) ($item->product?->unit ?? '-'),
            (int) $item->quantity,
            (int) $item->damaged_quantity,
            $item->goodQuantity(),
            $item->note ?: '-',
        ], $this->detailColumns);
    }

    /**
     * Teks ditulis eksplisit sebagai teks: SKU seperti "00123" atau nomor
     * referensi panjang tidak boleh berubah jadi angka di Excel.
     *
     * @param  array<int, mixed>  $values
     * @param  array<int, array{0: string, 1: string}>  $columns
     */
    protected function writeRow(Worksheet $sheet, int $row, array $values, array $columns): void
    {
        foreach ($values as $index => $value) {
            $type = $columns[$index][1];

            if ($type === 'text' || $type === 'wrap') {
                $sheet->setCellValueExplicit([$index + 1, $row], (string) $value, DataType::TYPE_STRING);
            } else {
                $sheet->setCellValue([$index + 1, $row], $value);
            }
        }
    }

    /* ------------------------------------------------------------- gaya --- */

    /**
     * @param  array<int, array{0: string, 1: string}>  $columns
     */
    protected function writeTableHeading(Worksheet $sheet, string $title, string $periodLabel, array $columns): void
    {
        $lastColumn = count($columns);

        $sheet->setCellValue('A1', $title);
        $sheet->setCellValue('A2', config('app.name').' · Periode: '.$periodLabel);
        $sheet->setCellValue('A3', 'Diunduh '.now()->translatedFormat('d F Y H:i'));

        $sheet->mergeCells([1, 1, $lastColumn, 1]);
        $sheet->mergeCells([1, 2, $lastColumn, 2]);
        $sheet->mergeCells([1, 3, $lastColumn, 3]);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A2')->getFont()->setSize(10)->getColor()->setRGB('404040');
        $sheet->getStyle('A3')->getFont()->setSize(9)->setItalic(true)->getColor()->setRGB('6D6D6D');

        foreach ($columns as $index => [$label]) {
            $sheet->setCellValue([$index + 1, self::HEADER_ROW], $label);
        }

        $this->styleHeader($sheet, self::HEADER_ROW, $lastColumn);
    }

    protected function styleHeader(Worksheet $sheet, int $row, int $lastColumn): void
    {
        $header = $sheet->getStyle([1, $row, $lastColumn, $row]);
        $header->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $header->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('0A0A0A');
        $header->getAlignment()
            ->setVertical(Alignment::VERTICAL_CENTER)
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setWrapText(true);
        $sheet->getRowDimension($row)->setRowHeight(24);
    }

    protected function styleTotalRow(Worksheet $sheet, int $row, int $lastColumn): void
    {
        $style = $sheet->getStyle([1, $row, $lastColumn, $row]);
        $style->getFont()->setBold(true);
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F1F1F1');
        $style->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('0A0A0A');
    }

    /**
     * @param  array<int, int>  $range
     */
    protected function borders(Worksheet $sheet, array $range): void
    {
        $sheet->getStyle($range)->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)
            ->getColor()->setRGB('D1D1D1');
    }

    /**
     * Rapikan lembar tabel: format angka/tanggal, garis, lebar kolom, saringan
     * otomatis, baris judul dibekukan, dan siap cetak.
     *
     * @param  array<int, array{0: string, 1: string}>  $columns
     */
    protected function finishTable(Worksheet $sheet, array $columns, int $lastRow, bool $zebra, ?int $lastDataRow = null): void
    {
        $lastColumn = count($columns);
        $first = self::HEADER_ROW + 1;
        $lastDataRow ??= $lastRow;

        if ($lastDataRow < $first) {
            $sheet->setCellValue([1, $first], 'Tidak ada data untuk filter ini.');
            $sheet->mergeCells([1, $first, $lastColumn, $first]);
            $sheet->getStyle([1, $first])->getFont()->setItalic(true)->getColor()->setRGB('6D6D6D');
            $lastRow = $lastDataRow = $first;
        } else {
            foreach ($columns as $index => [, $type]) {
                $column = $index + 1;
                $range = [$column, $first, $column, $lastRow];

                match ($type) {
                    'number' => $sheet->getStyle($range)->getNumberFormat()->setFormatCode(self::NUMBER_FORMAT),
                    'date' => $sheet->getStyle($range)->getNumberFormat()->setFormatCode(self::DATE_FORMAT),
                    'wrap' => $sheet->getStyle($range)->getAlignment()->setWrapText(true),
                    default => null,
                };

                if ($type === 'date') {
                    $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                }
            }

            if ($zebra) {
                for ($row = $first + 1; $row <= $lastDataRow; $row += 2) {
                    $sheet->getStyle([1, $row, $lastColumn, $row])
                        ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FAFAFA');
                }
            }
        }

        $this->borders($sheet, [1, self::HEADER_ROW, $lastColumn, $lastRow]);
        $sheet->getStyle([1, $first, $lastColumn, $lastRow])->getAlignment()->setVertical(Alignment::VERTICAL_TOP);

        foreach ($columns as $index => [$label, $type]) {
            $dimension = $sheet->getColumnDimensionByColumn($index + 1);

            // Kolom catatan diberi lebar tetap dan teksnya dibungkus; sisanya
            // menyesuaikan isi agar tidak ada teks terpotong.
            if ($type === 'wrap') {
                $dimension->setWidth(40);
            } elseif ($label === 'No') {
                $dimension->setWidth(6);
            } else {
                $dimension->setAutoSize(true);
            }
        }

        $sheet->setAutoFilter([1, self::HEADER_ROW, $lastColumn, $lastDataRow]);
        $sheet->freezePane([1, self::HEADER_ROW + 1]);
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(self::HEADER_ROW, self::HEADER_ROW);
        $this->pageSetup($sheet, PageSetup::ORIENTATION_LANDSCAPE);
    }

    protected function pageSetup(Worksheet $sheet, string $orientation): void
    {
        $sheet->getPageSetup()
            ->setOrientation($orientation)
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)
            ->setFitToHeight(0);
        $sheet->getPageMargins()->setTop(0.5)->setBottom(0.5)->setLeft(0.4)->setRight(0.4);
        $sheet->getHeaderFooter()->setOddFooter('&L&8'.config('app.name').'&R&8Halaman &P dari &N');
    }

    /* ------------------------------------------------------------ label --- */

    public function periodLabel(DateRange $range): string
    {
        if ($range->isAll()) {
            return 'Semua tanggal';
        }

        $from = $range->from ? Carbon::parse($range->from)->translatedFormat('d F Y') : null;
        $to = $range->to ? Carbon::parse($range->to)->translatedFormat('d F Y') : null;

        return match (true) {
            $from && $to && $from === $to => $from,
            $from && $to => $from.' s/d '.$to,
            (bool) $from => 'Sejak '.$from,
            default => 'Sampai '.$to,
        };
    }

    protected function statusFilterLabel(?string $status): string
    {
        return match ($status) {
            Inbound::STATUS_DRAFT => 'Draft',
            Inbound::STATUS_PENDING => 'Menunggu persetujuan',
            Inbound::STATUS_REJECTED => 'Ditolak',
            Inbound::STATUS_POSTED => 'Diposting',
            default => 'Semua status',
        };
    }

    protected function displayDate(?string $date): string
    {
        return $date ? Carbon::parse($date)->translatedFormat('d F Y') : '-';
    }
}
