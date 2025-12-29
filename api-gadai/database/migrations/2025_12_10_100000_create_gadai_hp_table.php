<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gadai_hp', function (Blueprint $table) {
            $table->id();

            $table->string('nama_barang');
            $table->string('imei')->nullable();
            $table->string('warna')->nullable();
            $table->string('kunci_password')->nullable();
            $table->string('kunci_pin')->nullable();
            $table->string('kunci_pola')->nullable();
            $table->string('ram')->nullable();
            $table->string('rom')->nullable();

   
            $table->foreignId('merk_hp_id')
                  ->nullable()
                  ->constrained('merk_hp')
                  ->nullOnDelete();

            $table->foreignId('type_hp_id')
                  ->nullable()
                  ->constrained('type_hp')
                  ->nullOnDelete();

            $table->foreignId('grade_hp_id')
                  ->nullable()
                  ->constrained('grade_hp')
                  ->nullOnDelete();

    
            $table->json('dokumen_pendukung')->nullable();

          
            $table->foreignId('detail_gadai_id')
                  ->constrained('detail_gadai')
                  ->onDelete('cascade');

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gadai_hp');
    }
};
