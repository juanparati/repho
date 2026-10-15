<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One advisory (e.g. a GHSA id) can affect several npm packages hosted
     * here, so uniqueness is per package instead of global.
     */
    public function up(): void
    {
        Schema::create('security_advisories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();
            $table->string('advisory_id');
            $table->string('title');
            $table->string('link');
            $table->string('cve')->nullable();
            $table->string('affected_versions');
            $table->string('severity')->nullable();
            $table->timestamp('reported_at')->nullable();
            $table->timestamps();

            $table->unique(['package_id', 'advisory_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('security_advisories');
    }
};
