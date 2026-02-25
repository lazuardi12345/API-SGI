<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nota_kehilangan', function (Blueprint $table) {
            $table->id();
            $table->string('no_nota')->unique(); 
            $table->foreignId('detail_gadai_id')->constrained('detail_gadai')->onDelete('cascade');
            $table->foreignId('nasabah_id')->constrained('data_nasabah')->onDelete('cascade');
            $table->string('foto_nasabah')->nullable();
            $table->string('foto_nota_ilang')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nota_kehilangan');
    }
};