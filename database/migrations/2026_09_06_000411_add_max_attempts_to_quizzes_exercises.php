<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['quizzes', 'exercises'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->unsignedInteger('max_attempts')->default(2)->after('passing_score');
            });
        }
    }

    public function down(): void
    {
        foreach (['quizzes', 'exercises'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('max_attempts');
            });
        }
    }
};
