<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

/**
 * Batasi sel yang dibaca dari spreadsheet: sampai baris tertentu, dan/atau
 * hanya kolom tertentu (indeks mulai 0). Berkas besar jadi hemat memori.
 */
class SpreadsheetCellFilter implements IReadFilter
{
    /** @var array<string, true> */
    protected array $letters = [];

    protected bool $all;

    /**
     * @param  array<int, int>|null  $columns
     */
    public function __construct(protected ?int $maxRow = null, ?array $columns = null)
    {
        foreach ($columns ?? [] as $index) {
            $this->letters[Coordinate::stringFromColumnIndex($index + 1)] = true;
        }

        $this->all = $columns === null;
    }

    public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
    {
        if ($this->maxRow !== null && $row > $this->maxRow) {
            return false;
        }

        return $this->all || isset($this->letters[$columnAddress]);
    }
}
