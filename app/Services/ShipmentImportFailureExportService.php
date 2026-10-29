<?php

namespace App\Services;

use App\Models\ShipmentImport;
use App\Models\ShipmentImportFailure;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Daftar baris berkas import yang gagal masuk, dalam bentuk Excel.
 */
class ShipmentImportFailureExportService
{
    /** @var array<int, array{0: string, 1: string, 2: int}> */
    protected array $columns = [
        ['Baris di Berkas', 'row_number', 10],
        ['Order ID', 'order_number', 22],
        ['Tracking ID', 'tracking_number', 22],
        ['Seller SKU', 'sku', 18],
        ['Nama Produk', 'product_name', 40],
        ['Qty', 'quantity', 6],
        ['Alasan Gagal', 'reason', 34],
        ['Keterangan', 'detail', 70],
    ];

    public function download(ShipmentImport $import): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Gagal Import');

        $sheet->setCellValue('A1', 'BARIS GAGAL IMPORT RESI');
        $sheet->setCellValue('A2', sprintf(
            'Berkas "%s" · diimport %s · %d baris gagal',
            $import->filename,
            $import->created_at->translatedFormat('d F Y H:i'),
            $import->failed_row_count,
        ));
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A2')->getFont()->setSize(9)->getColor()->setRGB('6D6D6D');

        $headerRow = 4;

        foreach ($this->columns as $index => [$label, , $width]) {
            $sheet->setCellValue([$index + 1, $headerRow], $label);
            $sheet->getColumnDimensionByColumn($index + 1)->setWidth($width);
        }

        $header = $sheet->getStyle([1, $headerRow, count($this->columns), $headerRow]);
        $header->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $header->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('0A0A0A');

        $rowNumber = $headerRow + 1;

        $import->failures()->chunk(500, function ($failures) use ($sheet, &$rowNumber) {
            foreach ($failures as $failure) {
                /** @var ShipmentImportFailure $failure */
                foreach ($this->columns as $index => [, $field]) {
                    // Order ID dan resi ditulis sebagai teks agar Excel tidak
                    // mengubah angka panjangnya menjadi notasi ilmiah.
                    $sheet->setCellValueExplicit([$index + 1, $rowNumber], (string) $failure->{$field},
                        in_array($field, ['row_number', 'quantity'], true) && $failure->{$field} !== null
                            ? \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC
                            : \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                }

                $rowNumber++;
            }
        });

        if ($rowNumber > $headerRow + 1) {
            $sheet->getStyle([count($this->columns), $headerRow + 1, count($this->columns), $rowNumber - 1])
                ->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        }

        $sheet->freezePane('A'.($headerRow + 1));

        $filename = 'gagal-import-'.pathinfo($import->filename, PATHINFO_FILENAME).'-'.$import->id.'.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0, must-revalidate',
        ]);
    }
}
