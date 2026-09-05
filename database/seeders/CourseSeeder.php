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

    /**
     * Dev-only seeding: two test courses so the catalog has something to
     * render during development. Guarded to local/dev environments only.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'dev', 'testing'])) {
            return;
        }

        $admin = User::query()->where('role', User::ROLE_ADMIN)->first()
            ?? User::factory()->admin()->create([
                'name' => 'Academy Admin',
                'email' => 'admin@custospark.com',
            ]);

        $courses = [
            [
                'title' => 'Python for Beginners',
                'slug' => 'python-for-beginners',
                'category' => 'Software & Coding',
                'description' => 'A hands-on introduction to Python. Build small scripts and your first CLI projects from day one, no prior experience needed.',
                'is_self_paced' => true,
                'fees' => [
                    'application' => 0,
                    'tuition' => 0,
                    'certificate' => 0,
                ],
            ],
            [
                'title' => 'Digital Marketing Essentials',
                'slug' => 'digital-marketing-essentials',
                'category' => 'Business',
                'description' => 'Plan and run campaigns that convert. Cover social media, email and basic paid ads with a project you can add to your portfolio.',
                'is_self_paced' => false,
                'fees' => [
                    'application' => 25000,
                    'tuition' => 0,
                    'certificate' => 25000,
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