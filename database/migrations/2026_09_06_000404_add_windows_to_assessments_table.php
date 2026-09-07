<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Open/close windows for instructor-graded and auto-graded work. Learners see
 * when each item opens, when it closes, and their own submission status.
 * Null = no restriction (backwards compatible).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['quizzes', 'exercises', 'exams', 'assignments'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->timestamp('opens_at')->nullable()->after('time_limit_minutes');
                $table->timestamp('closes_at')->nullable()->after('opens_at');
            });
        }
    }

    public function down(): void
    {
        foreach (['quizzes', 'exercises', 'exams', 'assignments'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn(['opens_at', 'closes_at']);
            });
        }
    }
};
