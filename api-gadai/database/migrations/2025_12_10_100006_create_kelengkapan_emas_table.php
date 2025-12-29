<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kelengkapan_emas', function (Blueprint $table) {
            $table->id();

            // Nama kelengkapan
            $table->string('nama_kelengkapan');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kelengkapan_emas');
    }
};
