<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Widen learning_outcomes.description from VARCHAR(255) to TEXT so long
     * instructor-written outcomes no longer 500 with SQLSTATE 22001.
     * SQLite ignores varchar limits, so only MySQL needs the ALTER.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `learning_outcomes` MODIFY `description` TEXT NOT NULL');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `learning_outcomes` MODIFY `description` VARCHAR(255) NOT NULL');
        }
    }
};
