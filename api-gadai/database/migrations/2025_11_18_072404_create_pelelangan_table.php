<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pelelangan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('detail_gadai_id') ->unique()->constrained('detail_gadai')->onDelete('cascade');
            $table->enum('status_lelang', ['siap', 'terlelang', 'lunas'])->default('siap');
            $table->decimal('nominal_diterima', 15, 2)->nullable(); // Harga terjual atau nominal tebus
            $table->decimal('keuntungan_lelang', 15, 2)->nullable();
            
            $table->enum('metode_pembayaran', ['cash', 'transfer'])->nullable();
            $table->timestamp('waktu_bayar')->nullable(); 
            $table->string('bukti_transfer')->nullable(); 
            
            // 4. Informasi Tambahan
            $table->text('keterangan')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pelelangan');
    }
};