<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Hari libur nasional atau cuti bersama: tidak dihitung hari kerja.
 */
class Holiday extends Model
{
    protected $fillable = ['date', 'name'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }
}
