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
        Schema::create('transaksi_brankas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('detail_gadai_id')->nullable()->constrained('detail_gadai')->onDelete('set null');
            $table->string('deskripsi'); 
            $table->enum('kategori', ['topup_pusat', 'operasional_toko', 'setor_ke_admin']);
            $table->enum('metode', ['cash', 'transfer'])->default('cash');
            $table->decimal('saldo_awal', 15, 2); 
            $table->decimal('pemasukan', 15, 2)->default(0); 
            $table->decimal('pengeluaran', 15, 2)->default(0);
            $table->decimal('saldo_akhir', 15, 2); 
            $table->string('bukti_transaksi')->nullable(); 
            $table->enum('status_validasi', ['pending', 'tervalidasi', 'ditolak'])->default('tervalidasi');
            $table->foreignId('validator_id')->nullable()->constrained('users'); 
            $table->timestamps();
            $table->index('kategori');
            $table->index('status_validasi');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaksi_brankas');
    }
};