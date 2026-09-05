<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_fees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->string('fee_type')->index();
            $table->decimal('amount', 10, 2);
            $table->char('currency', 3)->default('UGX');
            $table->boolean('is_required')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['course_id', 'fee_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_fees');
    }
};