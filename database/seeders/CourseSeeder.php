<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Course;
use App\Models\CourseFee;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class CourseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $admin = User::query()->where('role', User::ROLE_ADMIN)->first()
            ?? User::factory()->admin()->create([
                'name' => 'Academy Admin',
                'email' => 'admin@custospark.com',
            ]);

        $courses = [
            [
                'title' => 'Data Science',
                'slug' => 'data-science',
                'category' => 'Software & Coding',
                'description' => 'Master data analysis, statistics and visualisation to turn raw data into decisions. Hands-on projects with real datasets from week one.',
                'is_self_paced' => false,
                'fees' => [
                    'application' => 50000,
                    'tuition' => 900000,
                    'certificate' => 50000,
                ],
            ],
            [
                'title' => 'Machine Learning',
                'slug' => 'machine-learning',
                'category' => 'AI & Technology',
                'description' => 'Build and train models that learn from data. Cover supervised and unsupervised learning with practical projects you can put on your portfolio.',
                'is_self_paced' => true,
                'fees' => [
                    'application' => 50000,
                    'tuition' => 1100000,
                    'certificate' => 50000,
                ],
            ],
            [
                'title' => 'Mobile Development',
                'slug' => 'mobile-development',
                'category' => 'Mobile Development',
                'description' => 'Design, build and ship mobile apps end to end. Learn cross-platform development and publish real apps to app stores.',
                'is_self_paced' => false,
                'fees' => [
                    'application' => 50000,
                    'tuition' => 950000,
                    'certificate' => 50000,
                ],
            ],
            [
                'title' => 'Web Development',
                'slug' => 'web-development',
                'category' => 'Software & Coding',
                'description' => 'Go from zero to launching production web applications. Master HTML, CSS, JavaScript and modern frameworks with guided projects.',
                'is_self_paced' => true,
                'fees' => [
                    'application' => 50000,
                    'tuition' => 850000,
                    'certificate' => 50000,
                ],
            ],
        ];

        foreach ($courses as $courseData) {
            $fees = $courseData['fees'];
            unset($courseData['fees']);

            $course = Course::query()->updateOrCreate(
                ['slug' => $courseData['slug']],
                array_merge($courseData, [
                    'status' => Course::STATUS_PUBLISHED,
                    'created_by' => $admin->id,
                ]),
            );

            foreach ($fees as $feeType => $amount) {
                CourseFee::query()->updateOrCreate(
                    ['course_id' => $course->id, 'fee_type' => $feeType],
                    ['amount' => $amount],
                );
            }
        }
    }
}