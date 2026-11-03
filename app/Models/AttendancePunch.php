<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu tap absen: dari mesin fingerprint atau dari HP.
 */
class AttendancePunch extends Model
{
    public const SOURCE_MACHINE = 'machine';

    public const SOURCE_PHONE = 'phone';

    public const TYPE_IN = 'in';

    public const TYPE_OUT = 'out';

    protected $fillable = [
        'employee_id', 'source', 'type', 'punched_at', 'attendance_device_id', 'machine_pin',
        'attendance_location_id', 'latitude', 'longitude', 'accuracy_meters', 'distance_meters',
        'outside_radius', 'selfie_path',
    ];

    protected function casts(): array
    {
        return [
            'punched_at' => 'datetime',
            'latitude' => 'float',
            'longitude' => 'float',
            'outside_radius' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(AttendanceDevice::class, 'attendance_device_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(AttendanceLocation::class, 'attendance_location_id');
    }

    public function sourceLabel(): string
    {
        return $this->source === self::SOURCE_PHONE ? 'HP' : 'Mesin';
    }
}
