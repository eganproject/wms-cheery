<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Barang bukan milik kita di dalam paket retur.
 *
 * Tidak terhubung ke master barang dan tidak pernah menyentuh stok. Fotonya
 * disimpan di disk privat dan hanya dibuka lewat route yang dijaga izin.
 */
class ReturnReceiptForeignItem extends Model
{
    public const PHOTO_DISK = 'local';

    public const PHOTO_DIR = 'return-foreign';

    protected $fillable = [
        'return_receipt_id',
        'description',
        'quantity',
        'photo_path',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::deleted(function (self $item) {
            if ($item->photo_path) {
                Storage::disk(self::PHOTO_DISK)->delete($item->photo_path);
            }
        });
    }

    public function returnReceipt(): BelongsTo
    {
        return $this->belongsTo(ReturnReceipt::class);
    }

    public static function storePhoto(?UploadedFile $photo): ?string
    {
        return $photo?->store(self::PHOTO_DIR.'/'.now()->format('Y/m'), self::PHOTO_DISK);
    }

    public function photoUrl(): ?string
    {
        return $this->photo_path ? route('admin.returns.foreign.photo', $this) : null;
    }
}
