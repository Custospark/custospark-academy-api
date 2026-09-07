<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Course;
use App\Models\CourseFee;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Founding course catalog: four live, instructor-led courses created by the
 * admin. Idempotent (updateOrCreate on slug) - safe to run on staging,
 * production and repeatedly; existing records are updated in place, never
 * duplicated. Fees: application 25,000 UGX, tuition 0, certificate 150,000.
 */
class CourseCatalogSeeder extends Seeder
{
    private const COURSES = [
        [
            'title' => 'Data Science Fundamentals',
            'slug' => 'data-science-fundamentals',
            'description' => 'Foundations of data analysis: statistics, Python for data, visualization and reporting on real datasets.',
            'category' => 'Data Science',
            'level' => Course::LEVEL_BEGINNER,
            'duration_hours' => 40,
        ],
        [
            'title' => 'Web Development',
            'slug' => 'web-development',
            'description' => 'Build modern websites and web apps: HTML, CSS, JavaScript and backend basics through guided projects.',
            'category' => 'Software Development',
            'level' => Course::LEVEL_BEGINNER,
            'duration_hours' => 60,
        ],
        [
            'title' => 'Machine Learning',
            'slug' => 'machine-learning',
            'description' => 'Supervised and unsupervised learning, model training and evaluation, and deploying ML features to production.',
            'category' => 'Artificial Intelligence',
            'level' => Course::LEVEL_INTERMEDIATE,
            'duration_hours' => 48,
        ],
        [
            'title' => 'Mobile Development',
            'slug' => 'mobile-development',
            'description' => 'Design and ship mobile apps: UI, state, device APIs and store publishing, step by step.',
            'category' => 'Software Development',
            'level' => Course::LEVEL_INTERMEDIATE,
            'duration_hours' => 36,
        ],
    ];

    private const FEES = [
        ['fee_type' => CourseFee::FEE_APPLICATION, 'amount' => 25000],
        ['fee_type' => CourseFee::FEE_TUITION, 'amount' => 0],
        ['fee_type' => CourseFee::FEE_CERTIFICATE, 'amount' => 150000],
    ];

    public function run(): void
    {
        $admin = User::query()->where('email', 'admin@custospark.com')->first()
            ?? User::query()->where('role', User::ROLE_ADMIN)->first();

        if ($admin === null) {
            $this->command->error('CourseCatalogSeeder: no admin user found - skipping.');

            return;
        }

        foreach (self::COURSES as $data) {
            $course = Course::query()->updateOrCreate(
                ['slug' => $data['slug']],
                [
                    ...$data,
                    'status' => Course::STATUS_PUBLISHED,
                    'delivery_mode' => Course::DELIVERY_LIVE,
                    'is_self_paced' => false,
                    'created_by' => $admin->id,
                ],
            );

            foreach (self::FEES as $fee) {
                CourseFee::query()->updateOrCreate(
                    ['course_id' => $course->id, 'fee_type' => $fee['fee_type']],
                    [...$fee, 'currency' => 'UGX', 'is_required' => true],
                );
            }

            $this->command->info("Catalog: {$course->title} ({$course->slug})");
        }
    }
}
