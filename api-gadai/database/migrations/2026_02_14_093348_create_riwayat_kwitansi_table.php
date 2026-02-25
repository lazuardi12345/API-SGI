<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('riwayat_kwitansi', function (Blueprint $table) {
    $table->id();
    $table->string('no_kwitansi')->unique();
    $table->enum('jenis_transaksi', ['pelunasan', 'perpanjangan', 'lelang']);
    $table->unsignedBigInteger('transaksi_id');
    $table->unsignedBigInteger('user_id');
    $table->integer('jumlah_cetak')->default(1);
    $table->timestamp('tgl_cetak_pertama')->nullable();
    $table->timestamp('tgl_cetak_terakhir')->nullable();
    $table->timestamps();
});
    }

    public function down(): void
    {
        Schema::dropIfExists('riwayat_kwitansi');
    }
};