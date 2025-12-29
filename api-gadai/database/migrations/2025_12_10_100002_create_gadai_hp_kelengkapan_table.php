<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gadai_hp_kelengkapan', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('gadai_hp_id');
            $table->unsignedBigInteger('kelengkapan_id');
            $table->integer('nominal_override')->nullable(); 
            $table->timestamps();

            $table->foreign('gadai_hp_id')->references('id')->on('gadai_hp')->onDelete('cascade');
            $table->foreign('kelengkapan_id')->references('id')->on('kelengkapan')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gadai_hp_kelengkapan');
    }
};
