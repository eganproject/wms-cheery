<?php

namespace App\Services;

use DateTimeInterface;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Export Excel sederhana untuk halaman daftar: satu atau beberapa lembar,
 * masing-masing berisi judul, keterangan saringan, lalu tabel.
 *
 * Jenis kolom menentukan cara nilainya ditulis:
 *  - text     selalu teks, supaya nomor resi dan SKU panjang tidak berubah
 *             jadi notasi ilmiah atau kehilangan nol di depan.
 *  - number   angka asli, bisa diurutkan dan dijumlahkan.
 *  - sum      angka, dan dijumlahkan di baris total paling bawah.
 *  - date     tanggal Excel (dd/mm/yyyy).
 *  - datetime tanggal dan jam Excel (dd/mm/yyyy hh:mm).
 *
 * Baris boleh berupa generator, sehingga data besar bisa dibaca per potongan
 * dari database tanpa dimuat sekaligus.
 */
class TableExport
{
    /** @var array<int, array{name: string, columns: array<int, array{0: string, 1: string}>, rows: iterable<int, array<int, mixed>>}> */
    protected array $sheets = [];

    /** @var array<int, string> */
    protected array $notes = [];

    public function __construct(protected string $title)
    {
    }

    public static function make(string $title): self
    {
        return new self($title);
    }

    /**
     * Keterangan di bawah judul, misalnya saringan yang dipakai.
     */
    public function note(?string $line): self
    {
        if (filled($line)) {
            $this->notes[] = $line;
        }

        return $this;
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $columns  label, jenis kolom
     * @param  iterable<int, array<int, mixed>>  $rows
     */
    public function sheet(string $name, array $columns, iterable $rows): self
    {
        $this->sheets[] = ['name' => $name, 'columns' => $columns, 'rows' => $rows];

        return $this;
    }

    public function download(string $filename): StreamedResponse
    {
        $spreadsheet = $this->build();

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0, must-revalidate',
        ]);
    }

    public function build(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        foreach ($this->sheets as $definition) {
            $sheet = $spreadsheet->createSheet();
            // Nama lembar Excel maksimal 31 karakter dan tidak boleh memuat : \ / ? * [ ].
            $sheet->setTitle(mb_substr(str_replace([':', '\\', '/', '?', '*', '[', ']'], ' ', $definition['name']), 0, 31));
            $this->writeSheet($sheet, $definition['columns'], $definition['rows']);
        }

        if ($this->sheets === []) {
            $spreadsheet->createSheet();
        }

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $columns
     * @param  iterable<int, array<int, mixed>>  $rows
     */
    protected function writeSheet(Worksheet $sheet, array $columns, iterable $rows): void
    {
        $lastColumn = max(1, count($columns));
        $sheet->setCellValue('A1', mb_strtoupper($this->title));
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $lines = array_merge($this->notes, [config('app.name').' · diunduh '.now()->translatedFormat('d F Y H:i')]);

        foreach ($lines as $index => $line) {
            $sheet->setCellValue([1, 2 + $index], $line);
            $sheet->getStyle([1, 2 + $index])->getFont()->setSize(9)->getColor()->setRGB('6D6D6D');
        }

        // Satu baris kosong di antara keterangan dan tabel.
        $header = 2 + count($lines) + 1;

        foreach ($columns as $index => [$label]) {
            $sheet->setCellValue([$index + 1, $header], $label);
        }

        $row = $header + 1;

        foreach ($rows as $values) {
            foreach ($columns as $index => [, $type]) {
                $this->writeCell($sheet, $index + 1, $row, $values[$index] ?? null, $type);
            }

            $row++;
        }

        $lastRow = $row - 1;
        $this->style($sheet, $columns, $header, $lastRow, $lastColumn);
    }

    protected function writeCell(Worksheet $sheet, int $column, int $row, mixed $value, string $type): void
    {
        if ($value === null || $value === '') {
            return;
        }

        match ($type) {
            'number', 'sum' => is_numeric($value)
                ? $sheet->setCellValueExplicit([$column, $row], $value + 0, DataType::TYPE_NUMERIC)
                : $sheet->setCellValueExplicit([$column, $row], (string) $value, DataType::TYPE_STRING),
            'date', 'datetime' => $value instanceof DateTimeInterface
                ? $sheet->setCellValue([$column, $row], ExcelDate::PHPToExcel($value))
                : $sheet->setCellValueExplicit([$column, $row], (string) $value, DataType::TYPE_STRING),
            default => $sheet->setCellValueExplicit([$column, $row], (string) $value, DataType::TYPE_STRING),
        };
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $columns
     */
    protected function style(Worksheet $sheet, array $columns, int $header, int $lastRow, int $lastColumn): void
    {
        $head = $sheet->getStyle([1, $header, $lastColumn, $header]);
        $head->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $head->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('0A0A0A');
        $head->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $sheet->getRowDimension($header)->setRowHeight(22);

        $hasSum = collect($columns)->contains(fn ($column) => $column[1] === 'sum');
        $bodyEnd = $lastRow;

        if ($hasSum && $lastRow > $header) {
            $totalRow = $lastRow + 1;
            $sheet->setCellValue([1, $totalRow], 'TOTAL');

            foreach ($columns as $index => [, $type]) {
                if ($type === 'sum') {
                    $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
                    // SUBTOTAL ikut menyesuaikan saat tabel disaring di Excel.
                    $sheet->setCellValue([$index + 1, $totalRow], "=SUBTOTAL(9,{$letter}".($header + 1).":{$letter}{$lastRow})");
                }
            }

            $total = $sheet->getStyle([1, $totalRow, $lastColumn, $totalRow]);
            $total->getFont()->setBold(true);
            $total->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F3F3F3');
            $bodyEnd = $totalRow;
        }

        if ($bodyEnd > $header) {
            $sheet->getStyle([1, $header, $lastColumn, $bodyEnd])
                ->getBorders()->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN)
                ->getColor()->setRGB('D1D1D1');

            foreach ($columns as $index => [, $type]) {
                $range = [$index + 1, $header + 1, $index + 1, $bodyEnd];

                match ($type) {
                    'number', 'sum' => $sheet->getStyle($range)->getNumberFormat()->setFormatCode('#,##0'),
                    'date' => $sheet->getStyle($range)->getNumberFormat()->setFormatCode('dd/mm/yyyy'),
                    'datetime' => $sheet->getStyle($range)->getNumberFormat()->setFormatCode('dd/mm/yyyy hh:mm'),
                    default => null,
                };
            }
        }

        foreach ($columns as $index => [$label, $type]) {
            $dimension = $sheet->getColumnDimensionByColumn($index + 1);

            match ($type) {
                'number', 'sum' => $dimension->setWidth(max(10, min(18, mb_strlen($label) + 4))),
                'date' => $dimension->setWidth(12),
                'datetime' => $dimension->setWidth(17),
                default => $dimension->setAutoSize(true),
            };
        }

        $sheet->setAutoFilter([1, $header, $lastColumn, max($lastRow, $header)]);
        $sheet->freezePane([1, $header + 1]);
        $sheet->setSelectedCell('A1');
    }
}
