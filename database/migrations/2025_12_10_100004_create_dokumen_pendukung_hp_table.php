<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dokumen_pendukung_hp', function (Blueprint $table) {
            $table->id();

            $table->foreignId('gadai_hp_id')
                  ->constrained('gadai_hp')
                  ->onDelete('cascade');

            // Checklist kolom (SEMUA)
           $table->string('body')->nullable();
$table->string('imei')->nullable();
$table->string('about')->nullable();
$table->string('akun')->nullable();
$table->string('admin')->nullable();
$table->string('cam_depan')->nullable();
$table->string('cam_belakang')->nullable();
$table->string('rusak')->nullable();

$table->string('samsung_account')->nullable();
$table->string('galaxy_store')->nullable();

$table->string('icloud')->nullable();
$table->string('battery')->nullable();
$table->string('utools')->nullable();  
$table->string('iunlocker')->nullable();
$table->string('cek_pencurian')->nullable();


            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dokumen_pendukung_hp');
    }
};
