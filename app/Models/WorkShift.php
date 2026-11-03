<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Jam kerja: jam masuk, jam pulang, hari kerja, dan toleransi telat.
 *
 * Jam pulang yang lebih kecil dari jam masuk berarti shift melewati tengah
 * malam (mis. 22:00–06:00), dan pulangnya jatuh di tanggal berikutnya.
 */
class WorkShift extends Model
{
    public const DAY_NAMES = [1 => 'Sen', 2 => 'Sel', 3 => 'Rab', 4 => 'Kam', 5 => 'Jum', 6 => 'Sab', 7 => 'Min'];

    protected $fillable = [
        'name', 'start_time', 'end_time', 'break_start', 'break_end',
        'work_days', 'late_tolerance_minutes', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'work_days' => 'array',
            'late_tolerance_minutes' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function worksOn(CarbonInterface $date): bool
    {
        return in_array($date->isoWeekday(), array_map('intval', $this->work_days ?? []), true);
    }

    /**
     * Awal dan akhir shift pada tanggal kerja tertentu.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function boundsOn(CarbonInterface $date): array
    {
        $day = CarbonImmutable::parse($date->format('Y-m-d'), config('app.timezone'));
        $start = $day->setTimeFromTimeString($this->start_time);
        $end = $day->setTimeFromTimeString($this->end_time);

        if ($end->lessThanOrEqualTo($start)) {
            $end = $end->addDay();
        }

        return [$start, $end];
    }

    public function timeRange(): string
    {
        return substr($this->start_time, 0, 5).'–'.substr($this->end_time, 0, 5);
    }

    public function breakRange(): ?string
    {
        return $this->break_start && $this->break_end
            ? substr($this->break_start, 0, 5).'–'.substr($this->break_end, 0, 5)
            : null;
    }

    public function daysLabel(): string
    {
        $days = collect($this->work_days ?? [])->map(fn ($day) => (int) $day)->sort()->values();

        if ($days->isEmpty()) {
            return '—';
        }

        // Hari berurutan diringkas: Sen–Sab.
        $isRun = $days->count() > 2 && $days->last() - $days->first() === $days->count() - 1;

        return $isRun
            ? self::DAY_NAMES[$days->first()].'–'.self::DAY_NAMES[$days->last()]
            : $days->map(fn ($day) => self::DAY_NAMES[$day])->implode(', ');
    }
}
