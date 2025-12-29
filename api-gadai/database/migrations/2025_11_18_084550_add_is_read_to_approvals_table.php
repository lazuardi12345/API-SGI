<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

public function up()
{
    Schema::table('approvals', function (Blueprint $table) {
        $table->boolean('is_read')->default(false)->after('status');
    });
}

public function down()
{
    Schema::table('approvals', function (Blueprint $table) {
        $table->dropColumn('is_read');
    });
}


};
