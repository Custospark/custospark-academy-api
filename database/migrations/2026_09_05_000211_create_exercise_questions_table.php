<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exercise_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exercise_id')->constrained('exercises')->cascadeOnDelete();
            $table->string('question');
            $table->string('type')->default('multiple_choice'); // multiple_choice | true_false | short_answer | code
            $table->json('options')->nullable();
            $table->string('correct_answer')->nullable();
            $table->unsignedInteger('points')->default(1);
            $table->text('explanation')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exercise_questions');
    }
};