<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('ecosystem')->default('composer');
            $table->string('repository_url');
            $table->string('subdirectory')->nullable();
            $table->string('type');
            $table->foreignId('credential_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('download_dists')->default(false);
            $table->text('description')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('sync_error')->nullable();
            $table->boolean('is_syncing')->default(false);
            $table->unsignedTinyInteger('sync_progress')->default(0);
            $table->timestamps();

            $table->unique(['ecosystem', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packages');
    }
};
