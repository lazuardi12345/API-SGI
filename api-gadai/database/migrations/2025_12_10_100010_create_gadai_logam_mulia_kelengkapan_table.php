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
        Schema::create('gadai_logam_mulia_kelengkapan', function (Blueprint $table) {
    $table->id();
    $table->foreignId('gadai_logam_mulia_id')->constrained('gadai_logam_mulia')->onDelete('cascade');
    $table->foreignId('kelengkapan_emas_id')->constrained()->onDelete('cascade');
    $table->timestamps();
});

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gadai_logam_mulia_kelengkapan');
    }
};
