<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approvals', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('detail_gadai_id');
    $table->unsignedBigInteger('user_id');
    $table->enum('role', ['checker', 'hm']);
    
    // status approval diperbaiki
    $table->enum('status', [
        'pending',
        'approved_checker',
        'rejected_checker',
        'approved_hm',
        'rejected_hm'
    ])->default('pending');
    
    $table->text('catatan')->nullable();
    $table->timestamps();

    $table->foreign('detail_gadai_id')->references('id')->on('detail_gadai')->onDelete('cascade');
    $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
});

    }

    public function down(): void
    {
        Schema::dropIfExists('approvals');
    }
};
