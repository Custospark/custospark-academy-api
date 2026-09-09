<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseFee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CourseContentTest extends TestCase
{
    use RefreshDatabase;

    private function courseFor(User $creator): Course
    {
        $course = Course::factory()->published()->create(['created_by' => $creator->id]);
        CourseFee::factory()->tuition()->create(['course_id' => $course->id, 'amount' => 500000]);

        return $course;
    }

    public function test_instructor_can_build_sections_and_lessons(): void
    {
        $instructor = User::factory()->instructor()->create();
        $course = $this->courseFor($instructor);

        $section = $this->actingAsUser($instructor)
            ->postJson("/api/v1/admin/courses/{$course->id}/sections", ['title' => 'Introduction'])
            ->assertCreated()
            ->json('data');

        $lesson = $this->actingAsUser($instructor)
            ->postJson("/api/v1/admin/courses/{$course->id}/lessons", [
                'title' => 'Welcome',
                'content_type' => 'video',
                'video_url' => 'https://youtube.com/watch?v=abc',
                'section_id' => $section['id'],
                'is_free_preview' => true,
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame('Welcome', $lesson['title']);
        $this->assertSame('video', $lesson['content_type']);
        $this->assertTrue($lesson['is_free_preview']);
    }

    public function test_full_course_content_returns_all_structures(): void
    {
        $instructor = User::factory()->instructor()->create();
        $course = $this->courseFor($instructor);

        $section = $this->actingAsUser($instructor)
            ->postJson("/api/v1/admin/courses/{$course->id}/sections", ['title' => 'Module 1'])
            ->json('data');
        $this->actingAsUser($instructor)
            ->postJson("/api/v1/admin/courses/{$course->id}/lessons", [
                'title' => 'Lesson 1',
                'section_id' => $section['id'],
            ]);
        $this->actingAsUser($instructor)
            ->postJson("/api/v1/admin/courses/{$course->id}/outcomes", [
                'description' => 'Build a full app',
            ]);
        $this->actingAsUser($instructor)
            ->postJson("/api/v1/admin/courses/{$course->id}/resources", [
                'title' => 'Course Book',
                'type' => 'book',
                'url' => 'https://example.com/book',
            ]);
        $this->actingAsUser($instructor)
            ->postJson("/api/v1/admin/courses/{$course->id}/quizzes", [
                'title' => 'Quiz 1',
                'questions' => [
                    [
                        'question' => 'What is 2+2?',
                        'type' => 'multiple_choice',
                        'options' => ['3', '4', '5'],
                        'correct_answer' => '4',
                        'points' => 2,
                    ],
                ],
            ]);
        $this->actingAsUser($instructor)
            ->postJson("/api/v1/admin/courses/{$course->id}/exercises", [
                'title' => 'Practice 1',
                'questions' => [['question' => 'Fix the bug', 'type' => 'short_answer']],
            ]);
        $this->actingAsUser($instructor)
            ->postJson("/api/v1/admin/courses/{$course->id}/exams", [
                'title' => 'Final Exam',
                'questions' => [['question' => 'Essay', 'type' => 'essay']],
            ]);
        $this->actingAsUser($instructor)
            ->postJson("/api/v1/admin/courses/{$course->id}/assignments", [
                'title' => 'Project',
                'instructions' => 'Submit your project',
                'max_score' => 100,
            ]);

        $response = $this->actingAsUser($instructor)
            ->getJson("/api/v1/admin/courses/{$course->id}/content")
            ->assertOk();

        $data = $response->json('data');
        $this->assertCount(1, $data['sections']);
        $this->assertCount(1, $data['sections'][0]['lessons']);
        $this->assertCount(1, $data['learning_outcomes']);
        $this->assertCount(1, $data['resources']);
        $this->assertCount(1, $data['quizzes']);
        $this->assertCount(1, $data['quizzes'][0]['questions']);
        $this->assertCount(1, $data['exercises']);
        $this->assertCount(1, $data['exams']);
        $this->assertCount(1, $data['assignments']);
    }

    public function test_instructor_cannot_edit_others_course_content(): void
    {
        $instructor = User::factory()->instructor()->create();
        $other = User::factory()->instructor()->create();
        $course = $this->courseFor($other);

        $this->actingAsUser($instructor)
            ->postJson("/api/v1/admin/courses/{$course->id}/sections", ['title' => 'Hack'])
            ->assertStatus(403);
    }

    public function test_delete_lesson_removes_it(): void
    {
        $instructor = User::factory()->instructor()->create();
        $course = $this->courseFor($instructor);

        $lesson = $this->actingAsUser($instructor)
            ->postJson("/api/v1/admin/courses/{$course->id}/lessons", ['title' => 'Temp'])
            ->json('data');

        $this->actingAsUser($instructor)
            ->deleteJson("/api/v1/admin/courses/{$course->id}/lessons/{$lesson['id']}")
            ->assertOk();

        $this->assertDatabaseMissing('lessons', ['id' => $lesson['id']]);
    }

    public function test_learner_cannot_manage_content(): void
    {
        $admin = User::factory()->admin()->create();
        $learner = User::factory()->learner()->create();
        $course = $this->courseFor($admin);

        $this->actingAsUser($learner)
            ->postJson("/api/v1/admin/courses/{$course->id}/lessons", ['title' => 'Nope'])
            ->assertStatus(403);
    }

    public function test_exam_accepts_a_paper_file_and_replaces_it(): void
    {
        Storage::fake('public');
        $instructor = User::factory()->instructor()->create();
        $course = $this->courseFor($instructor);

        $exam = $this->actingAsUser($instructor)
            ->post("/api/v1/admin/courses/{$course->id}/exams", [
                'title' => 'Final Exam',
                'passing_score' => 50,
                'file' => UploadedFile::fake()->create('paper.pdf', 200, 'application/pdf'),
            ])
            ->assertCreated()
            ->json('data');

        $this->assertNotNull($exam['file_path']);
        Storage::disk('public')->assertExists($exam['file_path']);

        $replaced = $this->actingAsUser($instructor)
            ->post("/api/v1/admin/courses/{$course->id}/exams/{$exam['id']}", [
                '_method' => 'PUT',
                'file' => UploadedFile::fake()->create('paper-v2.pdf', 200, 'application/pdf'),
            ])
            ->assertOk()
            ->json('data');

        $this->assertNotSame($exam['file_path'], $replaced['file_path']);
        Storage::disk('public')->assertExists($replaced['file_path']);
        Storage::disk('public')->assertMissing($exam['file_path']);

        $this->actingAsUser($instructor)
            ->deleteJson("/api/v1/admin/courses/{$course->id}/exams/{$exam['id']}")
            ->assertOk();
        Storage::disk('public')->assertMissing($replaced['file_path']);
        $this->assertDatabaseMissing('exams', ['id' => $exam['id']]);
    }

    public function test_deleting_a_resource_removes_its_file(): void {
        Storage::fake('public');
        $instructor = User::factory()->instructor()->create();
        $course = $this->courseFor($instructor);

        $resource = $this->actingAsUser($instructor)
            ->post("/api/v1/admin/courses/{$course->id}/resources", [
                'title' => 'Course Book',
                'type' => 'book',
                'file' => UploadedFile::fake()->create('book.pdf', 200, 'application/pdf'),
            ])
            ->assertCreated()
            ->json('data');

        Storage::disk('public')->assertExists($resource['file_path']);

        $this->actingAsUser($instructor)
            ->deleteJson("/api/v1/admin/courses/{$course->id}/resources/{$resource['id']}")
            ->assertOk();

        Storage::disk('public')->assertMissing($resource['file_path']);
        $this->assertDatabaseMissing('resources', ['id' => $resource['id']]);
    }

    public function test_exercise_accepts_a_paper_file(): void
    {
        Storage::fake('public');
        $instructor = User::factory()->instructor()->create();
        $course = $this->courseFor($instructor);

        $exercise = $this->actingAsUser($instructor)
            ->post("/api/v1/admin/courses/{$course->id}/exercises", [
                'title' => 'Practice Set 1',
                'questions' => [],
                'file' => UploadedFile::fake()->create('worksheet.pdf', 200, 'application/pdf'),
            ])
            ->assertCreated()
            ->json('data');

        $this->assertNotNull($exercise['file_path']);
        Storage::disk('public')->assertExists($exercise['file_path']);
    }

    public function test_outcome_can_be_updated(): void
    {
        $instructor = User::factory()->instructor()->create();
        $course = $this->courseFor($instructor);

        $outcome = $this->actingAsUser($instructor)
            ->postJson("/api/v1/admin/courses/{$course->id}/outcomes", [
                'description' => 'Original outcome',
            ])
            ->assertCreated()
            ->json('data');

        $updated = $this->actingAsUser($instructor)
            ->putJson("/api/v1/admin/courses/{$course->id}/outcomes/{$outcome['id']}", [
                'description' => 'Edited outcome',
            ])
            ->assertOk()
            ->json('data');

        $this->assertSame('Edited outcome', $updated['description']);
    }

    public function test_assignment_accepts_a_file_and_replaces_it(): void {
        Storage::fake('public');
        $instructor = User::factory()->instructor()->create();
        $course = $this->courseFor($instructor);

        $assignment = $this->actingAsUser($instructor)
            ->post("/api/v1/admin/courses/{$course->id}/assignments", [
                'title' => 'Build a landing page',
                'instructions' => 'Follow the brief.',
                'submission_type' => 'file',
                'file' => UploadedFile::fake()->create('brief.pdf', 200, 'application/pdf'),
            ])
            ->assertCreated()
            ->json('data');

        $this->assertNotNull($assignment['file_path']);
        Storage::disk('public')->assertExists($assignment['file_path']);

        $replaced = $this->actingAsUser($instructor)
            ->post("/api/v1/admin/courses/{$course->id}/assignments/{$assignment['id']}", [
                '_method' => 'PUT',
                'file' => UploadedFile::fake()->create('brief-v2.pdf', 200, 'application/pdf'),
            ])
            ->assertOk()
            ->json('data');

        $this->assertNotSame($assignment['file_path'], $replaced['file_path']);
        Storage::disk('public')->assertExists($replaced['file_path']);
        Storage::disk('public')->assertMissing($assignment['file_path']);

        $this->actingAsUser($instructor)
            ->deleteJson("/api/v1/admin/courses/{$course->id}/assignments/{$assignment['id']}")
            ->assertOk();
        Storage::disk('public')->assertMissing($replaced['file_path']);
    }

    public function test_lesson_accepts_an_uploaded_video_or_a_link(): void
    {
        Storage::fake('public');
        $instructor = User::factory()->instructor()->create();
        $course = $this->courseFor($instructor);
        $section = $this->actingAsUser($instructor)
            ->postJson("/api/v1/admin/courses/{$course->id}/sections", ['title' => 'Module 1'])
            ->assertCreated()->json('data');

        // Upload path: real video file.
        $lesson = $this->actingAsUser($instructor)
            ->post("/api/v1/admin/courses/{$course->id}/lessons", [
                'title' => 'Intro video',
                'content_type' => 'video',
                'section_id' => $section['id'],
                'video' => UploadedFile::fake()->create('intro.mp4', 500, 'video/mp4'),
            ])
            ->assertCreated()
            ->json('data');

        $this->assertNotNull($lesson['video_path']);
        Storage::disk('public')->assertExists($lesson['video_path']);

        // Link path: URL only, no file.
        $linked = $this->actingAsUser($instructor)
            ->postJson("/api/v1/admin/courses/{$course->id}/lessons", [
                'title' => 'External talk',
                'content_type' => 'video',
                'section_id' => $section['id'],
                'video_url' => 'https://youtube.com/watch?v=abc',
            ])
            ->assertCreated()
            ->json('data');
        $this->assertSame('https://youtube.com/watch?v=abc', $linked['video_url']);
        $this->assertNull($linked['video_path']);

        // Non-video uploads are rejected.
        $this->actingAsUser($instructor)
            ->post(
                "/api/v1/admin/courses/{$course->id}/lessons",
                [
                    'title' => 'Bad upload',
                    'content_type' => 'video',
                    'video' => UploadedFile::fake()->create('notes.pdf', 200, 'application/pdf'),
                ],
                ['Accept' => 'application/json'],
            )
            ->assertStatus(422);

        // Replace removes the old file; delete removes the current one.
        $replaced = $this->actingAsUser($instructor)
            ->post("/api/v1/admin/courses/{$course->id}/lessons/{$lesson['id']}", [
                '_method' => 'PUT',
                'video' => UploadedFile::fake()->create('intro-v2.mp4', 500, 'video/mp4'),
            ])
            ->assertOk()
            ->json('data');
        Storage::disk('public')->assertMissing($lesson['video_path']);

        $this->actingAsUser($instructor)
            ->deleteJson("/api/v1/admin/courses/{$course->id}/lessons/{$lesson['id']}")
            ->assertOk();
        Storage::disk('public')->assertMissing($replaced['video_path']);
    }

    public function test_lesson_accepts_a_book_file(): void {
        Storage::fake('public');
        $instructor = User::factory()->instructor()->create();
        $course = $this->courseFor($instructor);
        $section = $this->actingAsUser($instructor)
            ->postJson("/api/v1/admin/courses/{$course->id}/sections", ['title' => 'Module 1'])
            ->assertCreated()->json('data');

        $lesson = $this->actingAsUser($instructor)
            ->post("/api/v1/admin/courses/{$course->id}/lessons", [
                'title' => 'Course book',
                'content_type' => 'book',
                'section_id' => $section['id'],
                'book' => UploadedFile::fake()->create('book.pdf', 200, 'application/pdf'),
            ])
            ->assertCreated()
            ->json('data');

        $this->assertNotNull($lesson['book_path']);
        Storage::disk('public')->assertExists($lesson['book_path']);
    }

    public function test_instructor_inbox_lists_grades_and_learner_sees_result(): void
    {
        Storage::fake('public');
        $instructor = User::factory()->instructor()->create();
        $course = $this->courseFor($instructor);
        $learner = User::factory()->learner()->create();
        app(\App\Services\EnrollmentService::class)->apply($course->id, $learner);

        $assignment = $this->actingAsUser($instructor)
            ->postJson("/api/v1/admin/courses/{$course->id}/assignments", [
                'title' => 'Build a landing page',
                'submission_type' => 'file',
            ])
            ->assertCreated()->json('data');

        $this->actingAsUser($learner)
            ->post("/api/v1/courses/{$course->id}/submit/assignment/{$assignment['id']}", [
                'file' => UploadedFile::fake()->create('work.pdf', 200, 'application/pdf'),
            ])
            ->assertCreated();

        // Instructor inbox sees the submission with learner + file.
        $inbox = $this->actingAsUser($instructor)
            ->getJson("/api/v1/admin/courses/{$course->id}/submissions")
            ->assertOk()->json('data');
        $this->assertCount(1, $inbox);
        $this->assertSame('submitted', $inbox[0]['status']);
        $this->assertSame($learner->email, $inbox[0]['learner_email']);
        $this->assertNotNull($inbox[0]['file_path']);

        // Grade it; learner sees score + feedback via course content.
        $this->actingAsUser($instructor)
            ->putJson("/api/v1/admin/courses/{$course->id}/submissions/{$inbox[0]['id']}/grade", [
                'score' => 85,
                'feedback' => 'Great structure.',
            ])
            ->assertOk();

        $content = $this->actingAsUser($learner)
            ->getJson("/api/v1/courses/{$course->id}/content")
            ->assertOk()->json('data');
        $status = collect($content['assignments'])->firstWhere('id', $assignment['id'])['my_status'];
        $this->assertSame('graded', $status['status']);
        $this->assertSame(85, (int) $status['score']);
        $this->assertSame('Great structure.', $status['feedback']);
    }
}