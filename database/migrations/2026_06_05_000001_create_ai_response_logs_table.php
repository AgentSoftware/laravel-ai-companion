<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_response_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('invocation_id')->nullable()->unique();
            $table->string('agent')->index();
            $table->longText('prompt');
            $table->json('response')->nullable();
            $table->json('properties')->nullable();
            $table->json('metadata')->nullable();
            $table->string('status')->default('running');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_response_logs');
    }
};
