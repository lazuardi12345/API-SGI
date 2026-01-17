<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dokumen_pendukung_emas', function (Blueprint $table) {
    $table->id();
    $table->enum('emas_type', ['logam_mulia', 'retro', 'perhiasan']);
    $table->unsignedBigInteger('emas_id');

    // optional attributes
    $table->string('emas_timbangan')->nullable();
    $table->string('gosokan_timer')->nullable();
    $table->string('gosokan_ktp')->nullable();
    $table->string('batu')->nullable();
    $table->string('cap_merek')->nullable();
    $table->string('karatase')->nullable();
    $table->string('ukuran_batu')->nullable();

    $table->timestamps();
});

    }

    public function down(): void
    {
        Schema::dropIfExists('dokumen_pendukung_emas');
    }
};
