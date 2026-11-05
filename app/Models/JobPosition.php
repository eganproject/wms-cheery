<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Jabatan karyawan. Role bawaannya mengisi role akun login karyawan dengan
 * jabatan ini, tapi role akun tetap bisa diubah manual di menu Pengguna.
 */
class JobPosition extends Model
{
    protected $fillable = ['name', 'role_id', 'description', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Nama jabatan ikut disalin ke kolom karyawan supaya pencarian, ekspor,
     * dan aplikasi HP yang membaca `position` tetap benar.
     */
    protected static function booted(): void
    {
        static::updated(function (JobPosition $position) {
            if ($position->wasChanged('name')) {
                $position->employees()->update(['position' => $position->name]);
            }
        });
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Samakan role akun karyawan berjabatan ini dengan role bawaannya.
     * Akun super admin tidak disentuh supaya tidak ada yang terkunci.
     *
     * @return int jumlah akun yang berubah
     */
    public function applyRoleToAccounts(): int
    {
        if (! $this->role_id) {
            return 0;
        }

        $changed = 0;

        User::query()
            ->whereIn('id', $this->employees()->whereNotNull('user_id')->select('user_id'))
            ->where(fn ($query) => $query->whereNull('role_id')->orWhere('role_id', '!=', $this->role_id))
            ->with('role')
            ->get()
            ->each(function (User $user) use (&$changed) {
                if ($user->isSuperAdmin()) {
                    return;
                }

                $user->update(['role_id' => $this->role_id]);
                $changed++;
            });

        return $changed;
    }
}
