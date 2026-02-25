<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('surat_kuasa_pelunasan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('detail_gadai_id')->constrained('detail_gadai')->onDelete('cascade');
            $table->foreignId('nasabah_id')->constrained('data_nasabah')->onDelete('cascade');
            
            $table->string('wakil_nama');
            $table->string('wakil_nik', 20);
            $table->text('wakil_alamat');
            $table->string('wakil_hp', 20);
            $table->string('wakil_hubungan'); 
            $table->string('foto_wakil')->nullable()->comment('Path foto wajah penerima kuasa');
            $table->string('foto_surat')->nullable()->comment('Path foto fisik surat kuasa yang sudah ditandatangani');
            
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('surat_kuasa_pelunasan');
    }
};
