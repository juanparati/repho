<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropUnique(['name']);
            $table->string('ecosystem')->default('composer')->after('name');
            $table->unique(['ecosystem', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropUnique(['ecosystem', 'name']);
            $table->dropColumn('ecosystem');
            $table->unique('name');
        });
    }
};
