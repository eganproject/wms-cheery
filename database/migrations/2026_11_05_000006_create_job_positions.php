<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Master jabatan karyawan. Tiap jabatan boleh punya role bawaan, yang dipakai
 * untuk mengisi role akun login karyawan dengan jabatan itu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_positions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->foreignId('role_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('job_position_id')->nullable()->after('position')->constrained()->nullOnDelete();
        });

        // Jabatan yang selama ini diketik bebas dijadikan master.
        $now = now();
        $names = DB::table('employees')->whereNotNull('position')->where('position', '!=', '')
            ->pluck('position')
            ->map(fn ($name) => trim($name))
            ->filter()
            ->unique(fn ($name) => mb_strtolower($name))
            ->values();

        foreach ($names as $name) {
            $id = DB::table('job_positions')->insertGetId([
                'name' => $name, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);

            DB::table('employees')->whereRaw('LOWER(TRIM(position)) = ?', [mb_strtolower($name)])
                ->update(['job_position_id' => $id, 'position' => $name]);
        }
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('job_position_id');
        });

        Schema::dropIfExists('job_positions');
    }
};
