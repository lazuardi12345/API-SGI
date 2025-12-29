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
         Schema::create(table: 'gadai_logam_mulia', callback: function (Blueprint $table): void {
            $table->id(); 
            $table->string(column: 'nama_barang');
            $table->string(column: 'kode_cap')->nullable();
            $table->decimal(column: 'karat', total: 5, places: 2);
            $table->string(column: 'potongan_batu')->nullable();
            $table->decimal(column: 'berat', total: 8, places: 2)->nullable();
            $table->unsignedBigInteger(column: 'detail_gadai_id');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign(columns: 'detail_gadai_id')
                ->references(columns: 'id')->on(table: 'detail_gadai')
                ->onDelete(action: 'cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(table: 'gadai_logam_mulia');
    }
};
