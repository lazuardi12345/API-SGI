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
    Schema::create('gadai_awal_details', function (Blueprint $table) {
        $table->id();
        $table->foreignId('detail_gadai_id')->constrained('detail_gadai')->onDelete('cascade');
        
        // Rincian Biaya sesuai struk
        $table->decimal('pokok', 15, 2);
        $table->decimal('jasa_sewa', 15, 2);
        $table->decimal('administrasi', 15, 2);
        $table->decimal('asuransi', 15, 2);
        $table->decimal('total_diterima', 15, 2);
        
        // Info Tambahan (Snapshot)
        $table->integer('tenor_hari'); 
        $table->decimal('persen_jasa', 5, 2)->nullable(); 
        
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gadai_awal_details');
    }
};
