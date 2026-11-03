<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Izin, sakit, atau cuti yang dicatat admin. Hari-hari ini tidak dihitung
 * "tidak hadir".
 */
class AttendanceLeave extends Model
{
    public const TYPES = [
        'izin' => 'Izin',
        'sakit' => 'Sakit',
        'cuti' => 'Cuti',
    ];

    public const VARIANTS = [
        'izin' => 'warning',
        'sakit' => 'info',
        'cuti' => 'purple',
    ];

    protected $fillable = [
        'employee_id', 'type', 'start_date', 'end_date', 'is_half_day',
        'reason', 'attachment_path', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'is_half_day' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Catatan yang menyentuh rentang tanggal ini.
     */
    public function scopeOverlapping(Builder $query, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return $query->whereDate('start_date', '<=', $to->format('Y-m-d'))
            ->whereDate('end_date', '>=', $from->format('Y-m-d'));
    }

    public function covers(CarbonInterface $date): bool
    {
        $day = $date->format('Y-m-d');

        return $this->start_date->format('Y-m-d') <= $day && $this->end_date->format('Y-m-d') >= $day;
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function variant(): string
    {
        return self::VARIANTS[$this->type] ?? 'neutral';
    }

    public function days(): float
    {
        $days = $this->start_date->diffInDays($this->end_date) + 1;

        return $this->is_half_day ? 0.5 : $days;
    }

    public function periodLabel(): string
    {
        $start = $this->start_date;
        $end = $this->end_date;

        if ($start->isSameDay($end)) {
            return $start->translatedFormat('j M Y');
        }

        return $start->isSameMonth($end)
            ? $start->format('j').'–'.$end->translatedFormat('j M Y')
            : $start->translatedFormat('j M').' – '.$end->translatedFormat('j M Y');
    }
}
