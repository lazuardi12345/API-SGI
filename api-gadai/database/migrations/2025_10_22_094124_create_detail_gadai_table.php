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
         Schema::create(table: 'detail_gadai', callback: function (Blueprint $table): void {
            $table->id(); 
            $table->string(column: 'no_gadai')->unique();
            $table->string(column: 'no_nasabah');
            $table->date(column: 'tanggal_gadai');
            $table->date(column: 'jatuh_tempo');
            $table->decimal(column: 'taksiran', total: 15, places: 2);
            $table->decimal(column: 'uang_pinjaman', total: 15, places: 2);
            $table->enum('status', ['proses', 'selesai', 'lunas'])->default('proses');
            $table->unsignedBigInteger(column: 'type_id');
            $table->unsignedBigInteger(column: 'nasabah_id');
            $table->decimal('nominal_bayar', 15, 2)->nullable(); 
            $table->timestamp('tanggal_bayar')->nullable();
            $table->enum('metode_pembayaran', ['cash', 'transfer'])->nullable();
            $table->string('bukti_transfer')->nullable(); 
            $table->timestamps();
            $table->softDeletes();

            // Foreign keys
            $table->foreign(columns: 'type_id')->references(columns: 'id')->on(table: 'types')->onDelete(action: 'cascade');
            $table->foreign(columns: 'nasabah_id')->references(columns: 'id')->on(table: 'data_nasabah')->onDelete(action: 'cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(table: 'detail_gadai');
    }
};
