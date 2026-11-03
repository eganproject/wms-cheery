<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Mesin fingerprint (ZKTeco, protokol push "iclock") yang boleh mengirim tap.
 */
class AttendanceDevice extends Model
{
    /** Mesin dianggap offline bila tidak menghubungi server selama ini. */
    public const ONLINE_WINDOW_MINUTES = 5;

    protected $fillable = ['name', 'serial_number', 'is_active', 'last_seen_at', 'last_ip'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    public function punches(): HasMany
    {
        return $this->hasMany(AttendancePunch::class);
    }

    public function isOnline(): bool
    {
        return $this->last_seen_at !== null
            && $this->last_seen_at->greaterThan(now()->subMinutes(self::ONLINE_WINDOW_MINUTES));
    }
}
