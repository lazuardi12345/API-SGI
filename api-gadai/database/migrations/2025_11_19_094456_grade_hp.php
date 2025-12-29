<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('grade_hp', function (Blueprint $table) {
            $table->id();

            // Foreign key mengarah ke tabel hargaHp
            $table->foreignId('harga_hp_id')->constrained('harga_hp')->cascadeOnDelete();

            // === HASIL GRADE 6 (Nilai Pinjaman) ===
            $table->integer('grade_a_dus')->nullable();
            $table->integer('grade_a_tanpa_dus')->nullable();

            $table->integer('grade_b_dus')->nullable();
            $table->integer('grade_b_tanpa_dus')->nullable();

            $table->integer('grade_c_dus')->nullable();
            $table->integer('grade_c_tanpa_dus')->nullable();

            // === KOLOM TAKSIRAN (Nilai Harga Pasar/Taksiran Dasar) ===
            // Menambahkan kolom taksiran untuk melengkapi data perhitungan
            $table->integer('taksiran_a_dus')->nullable();
            $table->integer('taksiran_a_tanpa_dus')->nullable();
            
            $table->integer('taksiran_b_dus')->nullable();
            $table->integer('taksiran_b_tanpa_dus')->nullable();
            
            $table->integer('taksiran_c_dus')->nullable();
            $table->integer('taksiran_c_tanpa_dus')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('grade_hp');
    }
};