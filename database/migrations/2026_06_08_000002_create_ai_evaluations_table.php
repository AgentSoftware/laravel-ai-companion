<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_evaluations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('ai_response_log_id')
                ->constrained('ai_response_logs')
                ->cascadeOnDelete();
            $table->string('agent')->index();
            $table->string('scorer')->nullable();
            $table->unsignedSmallInteger('overall_score');
            $table->json('criteria');
            $table->text('summary');
            $table->string('judge_model');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_evaluations');
    }
};
