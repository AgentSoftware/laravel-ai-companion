<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_tool_calls', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('ai_response_log_id')
                ->constrained('ai_response_logs')
                ->cascadeOnDelete();
            $table->string('tool_invocation_id')->nullable()->unique();
            $table->string('tool')->index();
            $table->json('input');
            $table->json('output')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_tool_calls');
    }
};
