<?php

namespace App\Services;

use RuntimeException;
use XMLReader;
use ZipArchive;

/**
 * Pembaca .xlsx yang mengalir baris demi baris, hanya untuk kolom yang perlu.
 *
 * PhpSpreadsheet memuat seluruh lembar ke memori. Eksport TikTok berisi 60-an
 * kolom dan puluhan ribu baris, sehingga import pertama (seluruh pesanan yang
 * sedang berjalan) bisa menghabiskan memori dan waktu server. Pembaca ini
 * membaca XML lembar pertama secara mengalir dan hanya menyimpan nilai teks
 * dari kolom yang diminta.
 */
class XlsxStreamReader
{
    /**
     * Baca lembar pertama.
     *
     * $pick menerima baris-baris awal (maks. $probeRows, semua kolom) dan
     * mengembalikan indeks kolom (mulai 0) yang perlu disimpan, atau null bila
     * belum bisa ditentukan. Selama null, semua kolom tetap disimpan.
     *
     * @param  callable(array<int, array<int, string>>): ?array<int, int>  $pick
     * @return array<int, array<int, string>> nomor baris => [indeks kolom => nilai]
     */
    public function read(string $path, callable $pick, int $probeRows = 10): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException('Bukan berkas xlsx.');
        }

        try {
            $sheet = $this->firstSheetPath($zip);
            $exists = $zip->locateName($sheet) !== false;
            $hasStrings = $zip->locateName('xl/sharedStrings.xml') !== false;
        } finally {
            $zip->close();
        }

        if (! $exists) {
            throw new RuntimeException('Lembar kerja tidak ditemukan.');
        }

        $strings = $hasStrings ? $this->sharedStrings($path) : [];

        // Dibaca langsung dari dalam zip, mengalir: XML lembar kerja bisa
        // berukuran ratusan MB setelah dibuka.
        $reader = new XMLReader;

        if (! $reader->open('zip://'.$path.'#'.$sheet, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
            throw new RuntimeException('Lembar kerja tidak bisa dibaca.');
        }

        // Sel dibaca satu per satu, bukan per elemen <row>: eksport TikTok
        // menulis setiap sel dalam elemen <row> tersendiri dengan nomor baris
        // yang sama, jadi nomor barisnya diambil dari alamat sel (mis. "G12").
        $rows = [];
        $columns = null;
        $decided = false;
        $maxLine = 0;
        $line = 0;
        $next = 0;
        $index = null;
        $type = '';

        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }

            switch ($reader->localName) {
                case 'row':
                    $line = (int) $reader->getAttribute('r') ?: $maxLine + 1;
                    $next = 0;
                    break;

                case 'c':
                    $ref = (string) $reader->getAttribute('r');

                    if ($ref !== '') {
                        $line = (int) preg_replace('/\D/', '', $ref) ?: $line;
                        $index = $this->columnIndex($ref);
                    } else {
                        $index = $next;
                    }

                    $next = $index + 1;
                    $type = (string) $reader->getAttribute('t');

                    if ($line > $maxLine) {
                        // Baris baru dimulai: putuskan kolom yang disimpan
                        // dari baris-baris awal yang sudah terbaca.
                        if (! $decided && $rows !== []) {
                            $chosen = $pick($rows);

                            if ($chosen !== null) {
                                $columns = array_flip($chosen);
                                $decided = true;

                                foreach ($rows as $key => $value) {
                                    $rows[$key] = array_intersect_key($value, $columns);
                                }
                            } elseif ($line > $probeRows) {
                                $decided = true; // judul tidak ketemu: simpan semua
                            }
                        }

                        $maxLine = $line;
                    }

                    if ($columns !== null && ! isset($columns[$index])) {
                        $index = null;
                    }
                    break;

                case 'v':
                    if ($index !== null) {
                        $raw = $reader->readString();
                        $this->put($rows, $line, $index, $type === 's' ? ($strings[(int) $raw] ?? '') : $raw);
                        $index = null;
                    }
                    break;

                case 'is':
                    if ($index !== null) {
                        $this->put($rows, $line, $index, $reader->readString());
                        $index = null;
                    }
                    break;
            }
        }

        $reader->close();

        ksort($rows);

        foreach ($rows as $key => $row) {
            ksort($row);
            $rows[$key] = $row;
        }

        return $rows;
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     */
    protected function put(array &$rows, int $line, int $index, string $value): void
    {
        $value = trim($value);

        if ($value !== '') {
            $rows[$line][$index] = $value;
        }
    }

    /**
     * @param  array<int, string>  $row
     */
    protected function blank(array $row): bool
    {
        foreach ($row as $value) {
            if ($value !== '') {
                return false;
            }
        }

        return true;
    }

    protected function columnIndex(string $ref): int
    {
        $letters = preg_replace('/[^A-Z]/', '', strtoupper($ref));
        $index = 0;

        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index - 1;
    }

    /**
     * @return array<int, string>
     */
    protected function sharedStrings(string $path): array
    {
        $strings = [];
        $reader = new XMLReader;

        if (! $reader->open('zip://'.$path.'#xl/sharedStrings.xml', null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
            return [];
        }

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'si') {
                $strings[] = $reader->readString();
            }
        }

        $reader->close();

        return $strings;
    }

    protected function firstSheetPath(ZipArchive $zip): string
    {
        $workbook = (string) $zip->getFromName('xl/workbook.xml');
        $rels = (string) $zip->getFromName('xl/_rels/workbook.xml.rels');

        if (preg_match('/<(?:\w+:)?sheet\b[^>]*\br:id="([^"]+)"/', $workbook, $sheet)
            && preg_match_all('/<(?:\w+:)?Relationship\b[^>]*>/', $rels, $relations)) {
            foreach ($relations[0] as $relation) {
                if (str_contains($relation, 'Id="'.$sheet[1].'"') && preg_match('/Target="([^"]+)"/', $relation, $target)) {
                    $path = ltrim($target[1], '/');

                    return str_starts_with($path, 'xl/') ? $path : 'xl/'.$path;
                }
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }
}
