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

    /** Absen lewat HP wajib selfie. */
    public const ATTENDANCE_REQUIRE_SELFIE = 'attendance.require_selfie';

    /** Absen lewat HP di luar radius lokasi tetap diterima (diberi tanda). */
    public const ATTENDANCE_ALLOW_OUTSIDE = 'attendance.allow_outside_radius';

    /** Metode absen yang terpilih saat menambah karyawan baru. */
    public const ATTENDANCE_DEFAULT_METHOD = 'attendance.default_method';

    /** Potongan marketplace dalam persen, JSON {"TikTok Shop": 8.5}. */
    public const MARKETPLACE_FEES = 'reports.marketplace_fees';

    /** Retur ke pemasok dianggap terlambat setelah sekian hari. */
    public const SUPPLIER_RETURN_ALERT_DAYS = 'disposals.supplier_return_alert_days';

    public const DEFAULTS = [
        self::SUPPLIER_RETURN_ALERT_DAYS => '14',
        self::NORMAL_SHIP_DAYS => '2',
        self::ATTENDANCE_REQUIRE_SELFIE => '1',
        self::ATTENDANCE_ALLOW_OUTSIDE => '0',
        self::ATTENDANCE_DEFAULT_METHOD => 'both',
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

    public static function supplierReturnAlertDays(): int
    {
        return max(1, (int) static::get(self::SUPPLIER_RETURN_ALERT_DAYS));
    }

    public static function flag(string $key): bool
    {
        return static::get($key) === '1';
    }

    /**
     * Persen potongan per marketplace untuk laba kotor.
     *
     * @return array<string, float>
     */
    public static function marketplaceFees(): array
    {
        $fees = json_decode((string) static::get(self::MARKETPLACE_FEES), true);

        return is_array($fees)
            ? array_map(fn ($value) => max(0, min(100, (float) $value)), array_filter($fees, 'is_numeric'))
            : [];
    }
}
