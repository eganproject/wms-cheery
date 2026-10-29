<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Produk induk untuk barang bervarian.
 *
 * Varian tetap baris products biasa: satu varian = satu SKU dengan stok,
 * barcode, dan lokasi rak sendiri. Induk hanya mengelompokkan varian-varian
 * itu di bawah satu nama, kategori, dan gambar utama. Karena stok tidak
 * pernah pindah dari products, seluruh dokumen, pencocokan SKU resi, opname,
 * dan laporan tetap bekerja tanpa tahu soal induk.
 *
 * Seluruh perubahan bersifat menambah. Barang yang sudah ada tidak punya
 * induk dan tetap berjalan sebagai barang tanpa varian.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('category')->nullable();
            $table->string('unit')->default('pcs');
            $table->text('description')->nullable();
            // Nama atribut varian, mis. "Warna" dan "Ukuran". Atribut kedua
            // boleh kosong untuk produk yang hanya punya satu jenis varian.
            $table->string('option1_name')->default('Varian');
            $table->string('option2_name')->nullable();
            $table->string('image_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('name');
        });

        Schema::table('products', function (Blueprint $table) {
            // Sengaja tanpa ->after(): kolom di ujung tabel bisa ditambahkan
            // MariaDB secara INSTANT tanpa menyalin ulang tabel products.
            $table->foreignId('product_group_id')->nullable()->constrained('product_groups')->nullOnDelete();
            $table->string('option1')->nullable();
            $table->string('option2')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_group_id');
            $table->dropColumn(['option1', 'option2']);
        });

        Schema::dropIfExists('product_groups');
    }
};
