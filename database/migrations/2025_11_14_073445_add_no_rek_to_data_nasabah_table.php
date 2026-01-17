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
        Schema::table('data_nasabah', function (Blueprint $table) {
            $table->string('no_rek')->after('no_hp')->nullable(); 
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('data_nasabah', function (Blueprint $table) {

            $table->dropColumn('no_rek');
        });
    }
};