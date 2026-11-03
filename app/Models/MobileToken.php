<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Token login aplikasi Android. Hanya hash-nya yang tersimpan.
 */
class MobileToken extends Model
{
    protected $fillable = ['user_id', 'name', 'token', 'last_used_at'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Buat token baru untuk pengguna. Yang dikembalikan adalah token asli,
     * satu-satunya kesempatan aplikasi menerimanya.
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(User $user, string $name): array
    {
        $plain = Str::random(60);

        $token = static::create([
            'user_id' => $user->id,
            'name' => Str::limit($name, 100, ''),
            'token' => hash('sha256', $plain),
        ]);

        return [$token, $plain];
    }

    public static function findPlain(?string $plain): ?self
    {
        if (blank($plain)) {
            return null;
        }

        return static::with('user')->where('token', hash('sha256', $plain))->first();
    }
}
