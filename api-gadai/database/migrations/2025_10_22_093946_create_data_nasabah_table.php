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
        Schema::create(table: 'data_nasabah', callback: function (Blueprint $table): void {
            $table->id();
             $table->string(column: 'nama_lengkap');
            $table->string(column: 'nik')->unique();
            $table->text(column: 'alamat');
            $table->string(column: 'foto_ktp')->nullable();
            $table->string(column: 'no_hp');
            $table->timestamps(); 
            $table->softDeletes(); 
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(table: 'data_nasabah');
    }
};
