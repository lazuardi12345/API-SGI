<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('perpanjangan_tempo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('detail_gadai_id')
                  ->constrained('detail_gadai')
                  ->onDelete('cascade'); 
            $table->date('tanggal_perpanjangan');
            $table->date('jatuh_tempo_baru');
            $table->decimal('nominal_jasa', 15, 2)->default(0);
            $table->decimal('nominal_denda', 15, 2)->default(0);
            $table->decimal('nominal_penalty', 15, 2)->default(0);
            $table->decimal('nominal_admin', 15, 2)->default(0); 
            $table->decimal('total_bayar', 15, 2)->default(0); 
            $table->enum('status_bayar', ['pending', 'lunas'])->default('pending');
            $table->enum('metode_pembayaran', ['cash', 'transfer'])->nullable();
            $table->string('bukti_transfer')->nullable();
            $table->timestamp('tanggal_bayar')->nullable(); 
            
            $table->timestamps(); 
            $table->index(['detail_gadai_id', 'status_bayar', 'jatuh_tempo_baru'], 'idx_gadai_status_tempo');
            
            $table->index('status_bayar');
            
            $table->index('tanggal_bayar');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('perpanjangan_tempo');
    }
};