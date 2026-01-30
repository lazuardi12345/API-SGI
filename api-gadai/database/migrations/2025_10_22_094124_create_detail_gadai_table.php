<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */

public function up(): void
{
     Schema::create('detail_gadai', function (Blueprint $table) {
        $table->id(); 
        $table->string('no_gadai')->unique();
        $table->string('no_nasabah');
        $table->date('tanggal_gadai');
        $table->date('jatuh_tempo');
        $table->decimal('taksiran', 15, 2);
        $table->decimal('uang_pinjaman', 15, 2);
        $table->enum('status', ['proses', 'selesai', 'lunas'])->default('proses');
        
        // --- TAMBAHAN BARU ---
        $table->boolean('is_repeat')->default(0)->comment('0: Baru, 1: Repeat Order');
        // ---------------------

        $table->enum('approval_status', ['draft', 'pending', 'approved', 'rejected'])
              ->default('draft')
              ->comment('Status approval SBG untuk ACC online');
        
        $table->unsignedBigInteger('type_id');
        $table->unsignedBigInteger('nasabah_id');
        $table->decimal('nominal_bayar', 15, 2)->nullable(); 
        $table->timestamp('tanggal_bayar')->nullable();
        $table->enum('metode_pembayaran', ['cash', 'transfer'])->nullable();
        $table->string('bukti_transfer')->nullable(); 
        $table->timestamps();
        $table->softDeletes();

        // Foreign keys
        $table->foreign('type_id')->references('id')->on('types')->onDelete('cascade');
        $table->foreign('nasabah_id')->references('id')->on('data_nasabah')->onDelete('cascade');
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(table: 'detail_gadai');
    }
};