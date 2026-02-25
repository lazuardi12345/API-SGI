<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('penggajian', function (Blueprint $table) {
            $table->id();
            $table->tinyInteger('bulan');                        // 1-12
            $table->year('tahun');
            $table->integer('jumlah_karyawan')->default(0);     // berapa orang digaji
            $table->decimal('total_gaji', 15, 2)->default(0);   // total uang keluar bulan itu
            $table->text('keterangan')->nullable();              // catatan tambahan
            $table->timestamps();
            $table->unique(['bulan', 'tahun']);                  // 1 periode = 1 record
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('penggajian');
    }
};