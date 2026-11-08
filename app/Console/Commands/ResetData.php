<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ResetData extends Command
{
    protected $signature = 'reset:data
        {--force : Jalankan tanpa konfirmasi (wajib di production)}
        {--keep=* : Tabel tambahan yang tidak ikut dikosongkan}
        {--dry-run : Hanya tampilkan tabel yang akan dikosongkan}';

    protected $description = 'Mengosongkan semua data, kecuali akun pengguna beserta role/hak akses dan pengaturan sistem';

    /**
     * Tabel yang selalu dipertahankan: akun & hak akses, sesi login,
     * catatan migrasi, dan pengaturan sistem.
     */
    public const KEEP = [
        'migrations',
        'users',
        'roles',
        'permissions',
        'permission_role',
        'sessions',
        'mobile_tokens',
        'app_settings',
        'stock_api_allowed_ips',
        'mobile_app_releases',
    ];

    public function handle(): int
    {
        $keep = array_unique(array_merge(self::KEEP, (array) $this->option('keep')));
        $tables = collect($this->tables())
            ->reject(fn (string $t) => in_array($t, $keep, true))
            ->sort()
            ->values();

        if ($tables->isEmpty()) {
            $this->info('Tidak ada tabel yang perlu dikosongkan.');

            return self::SUCCESS;
        }

        $this->line('Tabel yang dipertahankan: '.implode(', ', $keep));
        $this->newLine();
        $this->table(['Tabel yang akan dikosongkan', 'Jumlah baris'], $tables->map(
            fn (string $t) => [$t, number_format(DB::table($t)->count(), 0, ',', '.')]
        )->all());

        if ($this->option('dry-run')) {
            $this->info('Mode uji coba: tidak ada data yang dihapus.');

            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            if (app()->isProduction()) {
                $this->error('Aplikasi berjalan di production. Tambahkan --force bila benar-benar ingin mengosongkan data.');

                return self::FAILURE;
            }

            if (! $this->confirm('Semua data di tabel di atas akan DIHAPUS PERMANEN. Lanjutkan?')) {
                $this->warn('Dibatalkan.');

                return self::FAILURE;
            }
        }

        Schema::disableForeignKeyConstraints();
        try {
            foreach ($tables as $table) {
                DB::table($table)->truncate();
                $this->line("  dikosongkan: {$table}");
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        $this->callSilently('cache:clear');

        $this->newLine();
        $this->info("Selesai. {$tables->count()} tabel dikosongkan, akun pengguna tetap ada.");

        return self::SUCCESS;
    }

    /**
     * Nama tabel di database aktif (tanpa awalan skema).
     *
     * @return array<int, string>
     */
    private function tables(): array
    {
        $connection = DB::connection();
        $schema = match ($connection->getDriverName()) {
            'mysql', 'mariadb' => $connection->getDatabaseName(),
            'pgsql' => $connection->selectOne('select current_schema() as s')->s,
            'sqlite' => 'main',
            default => null,
        };

        return collect(Schema::getTables())
            ->filter(fn (array $t) => $schema === null || ($t['schema'] ?? $schema) === $schema)
            ->pluck('name')
            ->reject(fn (string $t) => str_starts_with($t, 'sqlite_'))
            ->all();
    }
}
