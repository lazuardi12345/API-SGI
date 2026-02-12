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
    Schema::create('pelunasan_logs', function (Blueprint $table) {
        $table->id();
        $table->foreignId('detail_gadai_id')->constrained('detail_gadai')->onDelete('cascade');
        $table->foreignId('user_id')->constrained('users'); 
        
        $table->decimal('pokok', 15, 2);
        $table->decimal('denda', 15, 2)->default(0);
        $table->decimal('penalty', 15, 2)->default(0);
        $table->decimal('total_bayar', 15, 2);
        
        $table->integer('hari_terlambat')->default(0);
        $table->string('metode_pembayaran');
        $table->string('bukti_transfer')->nullable();
        $table->timestamp('tanggal_bayar');
        
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pelunasan_logs');
    }
};
