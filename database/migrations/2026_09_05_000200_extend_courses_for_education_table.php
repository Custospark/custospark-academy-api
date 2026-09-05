<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->string('level')->default('beginner')->after('delivery_mode');
            $table->string('language', 10)->default('en')->after('level');
            $table->unsignedInteger('duration_hours')->nullable()->after('language');
            $table->text('target_audience')->nullable()->after('duration_hours');
            $table->text('prerequisites')->nullable()->after('target_audience');
            $table->json('tags')->nullable()->after('prerequisites');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn(['level', 'language', 'duration_hours', 'target_audience', 'prerequisites', 'tags']);
        });
    }
};