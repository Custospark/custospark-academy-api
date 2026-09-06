<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Backfill slugs for any courses created without one (legacy rows or paths
 * that bypassed the controller generator). New courses always get a unique
 * slug at creation; this only repairs existing gaps. Duplicate titles get
 * -2, -3, ... suffixes, mirroring CourseController::generateUniqueSlug.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('courses')
            ->whereNull('slug')
            ->orWhere('slug', '')
            ->orderBy('id')
            ->get(['id', 'title', 'slug']);

        foreach ($rows as $row) {
            $base = Str::slug((string) ($row->title ?: 'course'));
            $base = $base !== '' ? $base : 'course';

            $candidate = $base;
            $suffix = 2;
            while (DB::table('courses')->where('slug', $candidate)->where('id', '!=', $row->id)->exists()) {
                $candidate = $base.'-'.$suffix;
                $suffix++;
            }

            DB::table('courses')->where('id', $row->id)->update(['slug' => $candidate]);
        }
    }

    public function down(): void
    {
        // Slugs are identity data - backfilling is not reversible.
    }
};
