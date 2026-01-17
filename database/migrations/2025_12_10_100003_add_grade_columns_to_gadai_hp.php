<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('gadai_hp', function (Blueprint $table) {
            $table->string('grade_type')->nullable()->after('type_hp_id'); 
            $table->integer('grade_nominal')->nullable()->after('grade_type'); 
        });
    }

    public function down(): void {
        Schema::table('gadai_hp', function (Blueprint $table) {
            $table->dropColumn(['grade_type', 'grade_nominal']);
        });
    }
};
