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
            $table->string('delivery_mode')->default('live')->after('is_self_paced');
        });

        // Backfill: self-paced courses map to self_paced, others to live.
        DB::table('courses')->where('is_self_paced', true)->update(['delivery_mode' => 'self_paced']);
        DB::table('courses')->where('is_self_paced', false)->update(['delivery_mode' => 'live']);
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn('delivery_mode');
        });
    }
};