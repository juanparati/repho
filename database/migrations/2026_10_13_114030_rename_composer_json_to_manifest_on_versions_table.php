<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('versions', function (Blueprint $table) {
            $table->renameColumn('composer_json', 'manifest');
            $table->string('shasum', 40)->nullable()->after('reference');
            $table->string('integrity')->nullable()->after('shasum');
        });
    }

    public function down(): void
    {
        Schema::table('versions', function (Blueprint $table) {
            $table->dropColumn(['shasum', 'integrity']);
            $table->renameColumn('manifest', 'composer_json');
        });
    }
};
