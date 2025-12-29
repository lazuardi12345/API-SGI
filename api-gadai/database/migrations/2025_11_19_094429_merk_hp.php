<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('merk_hp', function (Blueprint $table) {
            $table->id();
            $table->string('nama_merk');
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('merk_hp');
    }
};

