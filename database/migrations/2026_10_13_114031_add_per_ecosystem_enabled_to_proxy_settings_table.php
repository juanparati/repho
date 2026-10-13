<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proxy_settings', function (Blueprint $table) {
            $table->renameColumn('enabled', 'composer_enabled');
            $table->boolean('npm_enabled')->default(false)->after('enabled');
        });
    }

    public function down(): void
    {
        Schema::table('proxy_settings', function (Blueprint $table) {
            $table->dropColumn('npm_enabled');
            $table->renameColumn('composer_enabled', 'enabled');
        });
    }
};
