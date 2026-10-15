<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proxy_upstreams', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(true);
            $table->string('ecosystem')->default('composer');
            $table->string('name');
            $table->string('upstream_url');
            $table->string('auth_type')->default('none');
            $table->string('auth_username')->nullable();
            $table->text('auth_password')->nullable();
            $table->text('auth_token')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proxy_upstreams');
    }
};
