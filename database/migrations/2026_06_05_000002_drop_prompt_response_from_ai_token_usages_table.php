<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_token_usages', function (Blueprint $table) {
            $table->dropColumn(['prompt', 'response']);
        });
    }

    public function down(): void
    {
        Schema::table('ai_token_usages', function (Blueprint $table) {
            $table->longText('prompt')->nullable()->after('source_model');
            $table->longText('response')->nullable()->after('prompt');
        });
    }
};
