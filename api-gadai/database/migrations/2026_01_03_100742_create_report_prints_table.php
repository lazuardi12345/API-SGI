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
        Schema::create('report_prints', function (Blueprint $table) {
        $table->id();
        $table->string('doc_id', 50)->unique()->index();
        $table->string('report_type', 50)->index();
        $table->date('report_date')->index();
        $table->boolean('is_approved')->default(false)->index();
        $table->string('approved_by', 100)->nullable();
        $table->string('printed_by', 100);
        
        // TAMBAHKAN ->nullable() DI SINI
        $table->timestamp('printed_at')->nullable()->comment('Waktu cetak dokumen');
        $table->string('ip_address', 45)->nullable()->comment('IP address saat cetak');
        
        $table->timestamps();
        $table->index(['report_type', 'report_date', 'is_approved'], 'idx_report_lookup');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('report_prints');
    }
};