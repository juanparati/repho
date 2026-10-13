<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One advisory (e.g. a GHSA id) can affect several npm packages hosted
     * here, so uniqueness is per package instead of global.
     */
    public function up(): void
    {
        Schema::table('security_advisories', function (Blueprint $table) {
            $table->dropUnique(['advisory_id']);
            $table->unique(['package_id', 'advisory_id']);
        });
    }

    public function down(): void
    {
        Schema::table('security_advisories', function (Blueprint $table) {
            $table->dropUnique(['package_id', 'advisory_id']);
            $table->unique('advisory_id');
        });
    }
};
