<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enrollment window per course. When set, learners see when enrollment opens
 * and closes, the enroll button states it, and apply() refuses late/early
 * applications. Null = always open (backwards compatible).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->timestamp('enrollment_opens_at')->nullable()->after('end_date');
            $table->timestamp('enrollment_closes_at')->nullable()->after('enrollment_opens_at');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn(['enrollment_opens_at', 'enrollment_closes_at']);
        });
    }
};
