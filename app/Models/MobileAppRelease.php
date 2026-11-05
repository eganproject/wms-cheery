<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu versi APK aplikasi Android yang diunggah lewat admin.
 */
class MobileAppRelease extends Model
{
    public const DISK = 'local';

    protected $fillable = [
        'version_code', 'version_name', 'notes', 'is_required', 'is_active',
        'file_path', 'file_size', 'sha256', 'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'version_code' => 'integer',
            'file_size' => 'integer',
            'is_required' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Versi aktif terbaru — yang ditawarkan ke HP. */
    public static function latestActive(): ?self
    {
        return static::active()->orderByDesc('version_code')->first();
    }

    /**
     * HP dengan versi di bawah angka ini wajib memperbarui: versi aktif
     * "wajib" yang paling baru.
     */
    public static function requiredVersionCode(): int
    {
        return (int) static::active()->where('is_required', true)->max('version_code');
    }
}
