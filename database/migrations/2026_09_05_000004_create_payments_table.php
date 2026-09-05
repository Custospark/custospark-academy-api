<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->nullable()->constrained('enrollments')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('fee_type')->index();
            $table->decimal('amount', 10, 2);
            $table->char('currency', 3)->default('UGX');
            $table->string('status')->default('pending')->index();
            $table->string('method')->default('mobile_money')->index();
            $table->string('reference')->nullable()->unique();
            $table->timestamp('paid_at')->nullable()->index();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['enrollment_id', 'fee_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};