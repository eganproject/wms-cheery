<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Pengaturan sederhana kunci-nilai yang bisa diubah dari aplikasi.
 */
class AppSetting extends Model
{
    /** Batas kirim pesanan Normal (bukan PO), dalam hari sejak dibayar. */
    public const NORMAL_SHIP_DAYS = 'orders.normal_ship_days';

    public const DEFAULTS = [
        self::NORMAL_SHIP_DAYS => '2',
    ];

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    public static function get(string $key): ?string
    {
        return Cache::remember('app-setting.'.$key, now()->addMinutes(10), function () use ($key) {
            return static::query()->whereKey($key)->value('value') ?? (self::DEFAULTS[$key] ?? null);
        });
    }

    public static function put(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget('app-setting.'.$key);
    }

    public static function normalShipDays(): int
    {
        return max(0, (int) static::get(self::NORMAL_SHIP_DAYS));
    }
}
