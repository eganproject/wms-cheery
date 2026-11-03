<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Token login aplikasi Android (Scan Kemas & Serah Terima).
 *
 * Satu baris per HP yang login. Yang disimpan hanya hash SHA-256-nya; token
 * aslinya hanya dikirim sekali ke aplikasi saat login.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('token', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_tokens');
    }
};
