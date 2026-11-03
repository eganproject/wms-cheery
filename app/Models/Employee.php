<?php

namespace App\Models;

use App\Models\Concerns\GeneratesDocumentCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Karyawan yang dicatat absensinya.
 *
 * Terpisah dari akun login: tidak semua staf gudang perlu masuk ke WMS. Akun
 * dihubungkan hanya untuk karyawan yang absen lewat HP.
 */
class Employee extends Model
{
    use GeneratesDocumentCode;

    public const METHOD_MACHINE = 'machine';

    public const METHOD_PHONE = 'phone';

    public const METHOD_BOTH = 'both';

    public const METHODS = [
        self::METHOD_MACHINE => 'Mesin',
        self::METHOD_PHONE => 'HP',
        self::METHOD_BOTH => 'Mesin & HP',
    ];

    protected $fillable = [
        'code', 'name', 'position', 'phone', 'user_id', 'work_shift_id',
        'attendance_method', 'machine_pin', 'joined_at', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'joined_at' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Tap mesin dengan PIN yang belum terdaftar disimpan tanpa karyawan.
     * Begitu PIN itu diisi di data karyawan, tap lamanya ikut tersambung.
     */
    protected static function booted(): void
    {
        static::saved(function (Employee $employee) {
            if ($employee->machine_pin && $employee->wasChanged('machine_pin') || ($employee->wasRecentlyCreated && $employee->machine_pin)) {
                AttendancePunch::query()
                    ->whereNull('employee_id')
                    ->where('source', AttendancePunch::SOURCE_MACHINE)
                    ->where('machine_pin', $employee->machine_pin)
                    ->update(['employee_id' => $employee->id]);
            }
        });
    }

    protected static function codePrefix(): string
    {
        return 'K-';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workShift(): BelongsTo
    {
        return $this->belongsTo(WorkShift::class);
    }

    public function punches(): HasMany
    {
        return $this->hasMany(AttendancePunch::class);
    }

    public function leaves(): HasMany
    {
        return $this->hasMany(AttendanceLeave::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        return $query->when($term, function (Builder $query, string $term) {
            $query->where(function (Builder $query) use ($term) {
                $query->where('name', 'like', "%{$term}%")
                    ->orWhere('code', 'like', "%{$term}%")
                    ->orWhere('position', 'like', "%{$term}%");
            });
        });
    }

    public function methodLabel(): string
    {
        return self::METHODS[$this->attendance_method] ?? $this->attendance_method;
    }

    public function allowsPhone(): bool
    {
        return in_array($this->attendance_method, [self::METHOD_PHONE, self::METHOD_BOTH], true);
    }

    public function allowsMachine(): bool
    {
        return in_array($this->attendance_method, [self::METHOD_MACHINE, self::METHOD_BOTH], true);
    }

    public function initials(): string
    {
        $words = preg_split('/\s+/', trim($this->name)) ?: [];

        return mb_strtoupper(collect($words)->take(2)->map(fn ($word) => mb_substr($word, 0, 1))->implode(''));
    }
}
