<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Tambahkan kolom user_id kalau belum ada
        if (!Schema::hasColumn('data_nasabah', 'user_id')) {
            Schema::table('data_nasabah', function (Blueprint $table): void {
                $table->unsignedBigInteger('user_id')->nullable()->after('id');
            });
        }

        // Tambahkan foreign key manual tanpa Doctrine
        $foreignKeys = DB::select("
            SELECT CONSTRAINT_NAME 
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
            WHERE TABLE_NAME = 'data_nasabah' 
              AND COLUMN_NAME = 'user_id' 
              AND CONSTRAINT_SCHEMA = DATABASE()
        ");

        $hasForeignKey = !empty($foreignKeys);

        if (!$hasForeignKey) {
            Schema::table('data_nasabah', function (Blueprint $table): void {
                $table->foreign('user_id')
                      ->references('id')
                      ->on('users')
                      ->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('data_nasabah', 'user_id')) {
            Schema::table('data_nasabah', function (Blueprint $table): void {
                $table->dropForeign(['user_id']);
                $table->dropColumn('user_id');
            });
        }
    }
};
