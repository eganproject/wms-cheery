<?php

namespace App\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;

/**
 * Aturan bersama untuk tabel daftar: jumlah baris per halaman dan urutan kolom.
 *
 * Parameter URL-nya `per_page`, `sort`, dan `dir`. Bila satu halaman punya
 * lebih dari satu tabel berhalaman (mis. pageName "stok"), parameternya diberi
 * awalan: `stok_per_page`, `stok_sort`, `stok_dir`.
 *
 * Keadaan yang terpilih disimpan di atribut request supaya komponen
 * <x-ui.pagination> dan <x-ui.sort> bisa menampilkannya tanpa dioper manual.
 */
class Listing
{
    public const PER_PAGE_OPTIONS = [10, 25, 50, 100];

    public static function param(string $base, string $pageName = 'page'): string
    {
        return $pageName === 'page' ? $base : "{$pageName}_{$base}";
    }

    /**
     * Jumlah baris per halaman yang diminta, dibatasi pada pilihan yang ada.
     */
    public static function perPage(Request $request, int $default = 25, string $pageName = 'page', ?array $options = null): int
    {
        $options = $options ?? self::PER_PAGE_OPTIONS;

        if (! in_array($default, $options, true)) {
            $options[] = $default;
            sort($options);
        }

        $param = self::param('per_page', $pageName);
        $value = $request->integer($param);
        $perPage = in_array($value, $options, true) ? $value : $default;

        $request->attributes->set("listing.{$pageName}.per_page", [
            'param' => $param,
            'options' => $options,
            'default' => $default,
            'value' => $perPage,
        ]);

        return $perPage;
    }

    /**
     * Mengurutkan query menurut kolom yang diklik di kepala tabel.
     *
     * $columns berisi kunci => nama kolom, atau Closure(query, arah) untuk
     * urutan yang lebih rumit. Kunci di luar daftar diabaikan. Bila tidak ada
     * urutan yang diminta, query dibiarkan memakai urutan bawaannya; $default
     * hanya menandai kolom mana yang sedang aktif di tampilan, jadi harus sama
     * dengan urutan bawaan query.
     *
     * @param  array<string, string|Closure>  $columns
     * @param  array{0: string, 1: string}|null  $default  [kunci, 'asc'|'desc']
     */
    public static function sort(EloquentBuilder|QueryBuilder|Relation $query, Request $request, array $columns, ?array $default = null, string $pageName = 'page'): void
    {
        [$key, $dir, $explicit] = self::requestedSort($request, array_keys($columns), $default, $pageName);

        if ($explicit) {
            $query->reorder();

            $column = $columns[$key];
            $column instanceof Closure ? $column($query, $dir) : $query->orderBy($column, $dir);

            // Pengurut cadangan supaya baris dengan nilai sama tidak berpindah
            // halaman secara acak.
            $model = $query instanceof EloquentBuilder || $query instanceof Relation ? $query->getModel() : null;
            if ($model) {
                $query->orderBy($model->getQualifiedKeyName(), $dir);
            }
        }
    }

    /**
     * Versi koleksi: mengurutkan lalu memotong per halaman di memori. Untuk
     * laporan yang barisnya dihitung di PHP, bukan langsung dari query.
     *
     * $columns berisi kunci => nama atribut/indeks, atau Closure(baris) yang
     * mengembalikan nilai pembanding.
     *
     * @param  array<string, string|Closure>  $columns
     * @param  array{0: string, 1: string}|null  $default
     */
    public static function collection(Collection $rows, Request $request, array $columns, ?array $default = null, int $perPage = 25, string $pageName = 'page'): LengthAwarePaginator
    {
        [$key, $dir, $explicit] = self::requestedSort($request, array_keys($columns), $default, $pageName);

        if ($explicit) {
            $value = $columns[$key];
            $resolver = $value instanceof Closure ? $value : fn ($row) => data_get($row, $value);

            $rows = $rows->sort(function ($a, $b) use ($resolver) {
                $left = $resolver($a);
                $right = $resolver($b);

                if (is_string($left) || is_string($right)) {
                    return strnatcasecmp((string) $left, (string) $right);
                }

                return $left <=> $right;
            });

            if ($dir === 'desc') {
                $rows = $rows->reverse();
            }

            $rows = $rows->values();
        }

        $perPage = self::perPage($request, $perPage, $pageName);
        $page = max(1, Paginator::resolveCurrentPage($pageName));
        $lastPage = max(1, (int) ceil($rows->count() / $perPage));
        $page = min($page, $lastPage);

        return (new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath(), 'pageName' => $pageName],
        ))->withQueryString();
    }

    /**
     * @return array{0: string|null, 1: string, 2: bool}
     */
    protected static function requestedSort(Request $request, array $keys, ?array $default, string $pageName): array
    {
        $sortParam = self::param('sort', $pageName);
        $dirParam = self::param('dir', $pageName);

        $key = (string) $request->query($sortParam, '');
        $dir = strtolower((string) $request->query($dirParam, '')) === 'asc' ? 'asc' : 'desc';
        $explicit = $key !== '' && in_array($key, $keys, true);

        if (! $explicit) {
            $key = $default[0] ?? null;
            $dir = ($default[1] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        }

        $request->attributes->set("listing.{$pageName}.sort", [
            'key' => $key,
            'dir' => $dir,
            'sort_param' => $sortParam,
            'dir_param' => $dirParam,
            'keys' => $keys,
        ]);

        return [$key, $dir, $explicit];
    }

    /**
     * Untuk halaman yang sudah punya pilihan urutan sendiri (mis. laporan
     * dengan dropdown "Urut"): judul kolom menjadi jalan pintas ke pilihan
     * itu, masing-masing dengan arah yang sudah tetap.
     *
     * @param  array<string, string>  $directions  kunci urutan => 'asc'|'desc'
     */
    public static function presetSort(Request $request, string $current, array $directions, string $pageName = 'page'): void
    {
        $request->attributes->set("listing.{$pageName}.sort", [
            'key' => $current,
            'dir' => $directions[$current] ?? 'desc',
            'sort_param' => self::param('sort', $pageName),
            'dir_param' => self::param('dir', $pageName),
            'keys' => array_keys($directions),
            'fixed' => $directions,
        ]);
    }
}
