<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
       Schema::create('gadai_perhiasan_kelengkapan', function (Blueprint $table) {
    $table->id();
    $table->foreignId('gadai_perhiasan_id')->constrained('gadai_perhiasan')->onDelete('cascade');
    $table->foreignId('kelengkapan_emas_id')->constrained()->onDelete('cascade');
    $table->timestamps();
});

    }

    public function down(): void
    {
        Schema::dropIfExists('gadai_perhiasan_kelengkapan');
    }
};
