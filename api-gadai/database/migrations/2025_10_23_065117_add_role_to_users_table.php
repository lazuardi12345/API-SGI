<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jalankan migration.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // tambahkan kolom role enum
            $table->enum('role', ['hm', 'admin', 'checker','petugas', 'gudang', 'kasir'])->default('petugas')->after('password');
        });
    }

    /**
     * Rollback migration.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
