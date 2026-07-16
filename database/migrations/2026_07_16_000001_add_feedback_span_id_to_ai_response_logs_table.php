<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_response_logs', function (Blueprint $table) {
            $table->string('feedback_span_id')->nullable()->after('invocation_id');
        });
    }

    public function down(): void
    {
        Schema::table('ai_response_logs', function (Blueprint $table) {
            $table->dropColumn('feedback_span_id');
        });
    }
};
