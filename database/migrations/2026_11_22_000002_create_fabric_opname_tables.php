<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Stock opname kain: hitung fisik semua roll sekaligus, lalu terapkan selisihnya. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fabric_opnames', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->date('date');
            $table->foreignId('fabric_id')->nullable()->constrained()->nullOnDelete();
            $table->string('location', 60)->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('missing_rule', 20)->nullable();
            $table->string('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('fabric_opname_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fabric_opname_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fabric_roll_id')->constrained()->cascadeOnDelete();
            $table->decimal('system_quantity', 12, 2);
            $table->decimal('counted_quantity', 12, 2)->nullable();
            $table->decimal('applied_difference', 12, 2)->nullable();
            $table->timestamp('counted_at')->nullable();
            $table->foreignId('counted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unique(['fabric_opname_id', 'fabric_roll_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fabric_opname_lines');
        Schema::dropIfExists('fabric_opnames');
    }
};
