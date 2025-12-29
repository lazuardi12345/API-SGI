<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jalankan migrasi tabel gadai_perhiasan.
     */
    public function up(): void
    {
        Schema::create('gadai_perhiasan', function (Blueprint $table): void {
            $table->id();
            $table->string('nama_barang');
            $table->string('kode_cap')->nullable();
            $table->decimal('karat', 5, 2)->nullable();
            $table->string('potongan_batu')->nullable();
            $table->decimal('berat', 8, 2)->nullable();
            $table->unsignedBigInteger('detail_gadai_id');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('detail_gadai_id')
                ->references('id')
                ->on('detail_gadai')
                ->onDelete('cascade');
        });
    }

    /**
     * Batalkan migrasi.
     */
    public function down(): void
    {
        Schema::dropIfExists('gadai_perhiasan');
    }
};
