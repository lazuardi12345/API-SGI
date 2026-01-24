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
        Schema::create('harga_hp', function (Blueprint $table) {
            $table->id();
            $table->foreignId('type_hp_id')->constrained('type_hp')->cascadeOnDelete();
            $table->integer('harga_barang');
            $table->integer('harga_pasar');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('harga_hp');
    }
};