<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Absensi karyawan gudang: lewat mesin fingerprint, HP, atau keduanya.
 *
 * Status harian (hadir, telat, tidak hadir, ...) tidak disimpan. Ia dihitung
 * dari tap, jam kerja, hari libur, dan catatan izin setiap kali dibuka, jadi
 * tidak pernah basi ketika salah satunya diubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_shifts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->time('start_time');
            $table->time('end_time');
            $table->time('break_start')->nullable();
            $table->time('break_end')->nullable();
            // Hari kerja ISO: 1 = Senin ... 7 = Minggu.
            $table->json('work_days');
            $table->unsignedSmallInteger('late_tolerance_minutes')->default(10);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->string('name', 120);
            $table->timestamps();
        });

        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 120);
            $table->string('position', 80)->nullable();
            $table->string('phone', 30)->nullable();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('work_shift_id')->nullable()->constrained()->nullOnDelete();
            // machine | phone | both
            $table->string('attendance_method', 10)->default('both');
            $table->string('machine_pin', 20)->nullable()->unique();
            $table->date('joined_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('attendance_locations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedInteger('radius_meters')->default(100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('attendance_devices', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('serial_number', 60)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->timestamps();
        });

        Schema::create('attendance_punches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->nullable()->constrained()->cascadeOnDelete();
            // machine | phone
            $table->string('source', 10);
            // in | out untuk HP; kosong untuk mesin (ditentukan dari jam kerja).
            $table->string('type', 5)->nullable();
            $table->dateTime('punched_at');
            $table->foreignId('attendance_device_id')->nullable()->constrained()->nullOnDelete();
            $table->string('machine_pin', 20)->nullable();
            $table->foreignId('attendance_location_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('accuracy_meters')->nullable();
            $table->unsignedInteger('distance_meters')->nullable();
            $table->boolean('outside_radius')->default(false);
            $table->string('selfie_path')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'punched_at']);
            // Mesin sering mengirim ulang log yang sama; jangan dobel.
            $table->unique(['attendance_device_id', 'machine_pin', 'punched_at'], 'attendance_punches_machine_unique');
        });

        Schema::create('attendance_leaves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            // izin | sakit | cuti
            $table->string('type', 10);
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_half_day')->default(false);
            $table->string('reason', 500)->nullable();
            $table->string('attachment_path')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'start_date', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_leaves');
        Schema::dropIfExists('attendance_punches');
        Schema::dropIfExists('attendance_devices');
        Schema::dropIfExists('attendance_locations');
        Schema::dropIfExists('employees');
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('work_shifts');
    }
};
