<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status')->default('applied')->index();
            $table->text('application_review_note')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('admitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('certified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['course_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollments');
    }
};