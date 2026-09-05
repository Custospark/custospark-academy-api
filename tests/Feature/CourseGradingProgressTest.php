<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseFee;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CourseGradingProgressTest extends TestCase
{
    use RefreshDatabase;

    private function setupCourseWithLesson(User $creator): array
    {
        $course = Course::factory()->published()->create(['created_by' => $creator->id]);
        CourseFee::factory()->tuition()->create(['course_id' => $course->id, 'amount' => 500000]);

        $section = $this->actingAsUser($creator)
            ->postJson("/api/v1/admin/courses/{$course->id}/sections", ['title' => 'Module 1'])
            ->json('data');
        $lesson = $this->actingAsUser($creator)
            ->postJson("/api/v1/admin/courses/{$course->id}/lessons", [
                'title' => 'Lesson 1',
                'section_id' => $section['id'],
            ])
            ->json('data');

        return [$course, $lesson];
    }

    public function test_learner_can_mark_lesson_progress_and_see_percent(): void
    {
        $instructor = User::factory()->instructor()->create();
        $learner = User::factory()->learner()->create();
        [$course, $lesson] = $this->setupCourseWithLesson($instructor);

        // Enroll the learner so they can access the course.
        Enrollment::factory()->create(['course_id' => $course->id, 'user_id' => $learner->id]);

        $this->actingAsUser($learner)
            ->postJson("/api/v1/courses/{$course->id}/lessons/{$lesson['id']}/progress", [
                'status' => 'completed',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->actingAsUser($learner)
            ->getJson("/api/v1/courses/{$course->id}/progress")
            ->assertOk()
            ->assertJsonPath('data.completed_lessons', 1)
            ->assertJsonPath('data.percent', 100);
    }

    public function test_learner_submits_assignment_and_instructor_grades(): void
    {
        $instructor = User::factory()->instructor()->create();
        $learner = User::factory()->learner()->create();
        $course = Course::factory()->published()->create(['created_by' => $instructor->id]);
        CourseFee::factory()->tuition()->create(['course_id' => $course->id, 'amount' => 500000]);
        Enrollment::factory()->create(['course_id' => $course->id, 'user_id' => $learner->id]);

        $assignment = $this->actingAsUser($instructor)
            ->postJson("/api/v1/admin/courses/{$course->id}/assignments", [
                'title' => 'Build a portfolio',
                'instructions' => 'Submit your portfolio link',
                'max_score' => 100,
            ])
            ->json('data');

        $submission = $this->actingAsUser($learner)
            ->postJson("/api/v1/courses/{$course->id}/submit/assignment/{$assignment['id']}", [
                'content' => 'https://myportfolio.com',
            ])
            ->assertCreated()
            ->json('data');

        $this->actingAsUser($instructor)
            ->putJson("/api/v1/admin/courses/{$course->id}/submissions/{$submission['id']}/grade", [
                'score' => 85,
                'feedback' => 'Great work!',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'graded')
            ->assertJsonPath('data.score', 85);
    }

    public function test_auto_graded_exercise_scores_and_passes(): void
    {
        $instructor = User::factory()->instructor()->create();
        $learner = User::factory()->learner()->create();
        $course = Course::factory()->published()->create(['created_by' => $instructor->id]);
        CourseFee::factory()->tuition()->create(['course_id' => $course->id, 'amount' => 500000]);
        Enrollment::factory()->create(['course_id' => $course->id, 'user_id' => $learner->id]);

        $exercise = $this->actingAsUser($instructor)
            ->postJson("/api/v1/admin/courses/{$course->id}/exercises", [
                'title' => 'Math quiz',
                'type' => 'quiz',
                'passing_score' => 50,
                'questions' => [
                    [
                        'question' => '2+2?',
                        'type' => 'multiple_choice',
                        'options' => ['3', '4', '5'],
                        'correct_answer' => '4',
                        'points' => 2,
                    ],
                    [
                        'question' => '1+1?',
                        'type' => 'multiple_choice',
                        'options' => ['1', '2', '3'],
                        'correct_answer' => '2',
                        'points' => 2,
                    ],
                ],
            ])
            ->json('data');

        $attempt = $this->actingAsUser($learner)
            ->postJson("/api/v1/courses/{$course->id}/attempt/exercise/{$exercise['id']}", [
                'answers' => [
                    $exercise['questions'][0]['id'] => '4',
                    $exercise['questions'][1]['id'] => '2',
                ],
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame(4, $attempt['score']);
        $this->assertSame(4, $attempt['max_score']);
        $this->assertTrue($attempt['is_passed']);
    }

    public function test_enrolled_learner_can_view_course_content_without_answers(): void
    {
        $instructor = User::factory()->instructor()->create();
        $learner = User::factory()->learner()->create();
        $course = Course::factory()->published()->create(['created_by' => $instructor->id]);
        CourseFee::factory()->tuition()->create(['course_id' => $course->id, 'amount' => 500000]);
        Enrollment::factory()->create(['course_id' => $course->id, 'user_id' => $learner->id]);

        $this->actingAsUser($instructor)->postJson("/api/v1/admin/courses/{$course->id}/quizzes", [
            'title' => 'Quiz',
            'questions' => [['question' => 'Q', 'correct_answer' => 'secret', 'points' => 1]],
        ]);

        $data = $this->actingAsUser($learner)
            ->getJson("/api/v1/courses/{$course->id}/content")
            ->assertOk()
            ->json('data');

        $this->assertSame('Quiz', $data['quizzes'][0]['title']);
        $this->assertArrayNotHasKey('correct_answer', $data['quizzes'][0]['questions'][0]);
    }

    public function test_unenrolled_learner_cannot_view_course_content(): void
    {
        $instructor = User::factory()->instructor()->create();
        $learner = User::factory()->learner()->create();
        $course = Course::factory()->published()->create(['created_by' => $instructor->id]);

        $this->actingAsUser($learner)
            ->getJson("/api/v1/courses/{$course->id}/content")
            ->assertStatus(403);
    }

    public function test_learner_can_upload_a_file_with_submission(): void
    {
        Storage::fake('public');
        $instructor = User::factory()->instructor()->create();
        $learner = User::factory()->learner()->create();
        $course = Course::factory()->published()->create(['created_by' => $instructor->id]);
        CourseFee::factory()->tuition()->create(['course_id' => $course->id, 'amount' => 500000]);
        Enrollment::factory()->create(['course_id' => $course->id, 'user_id' => $learner->id]);

        $assignment = $this->actingAsUser($instructor)
            ->postJson("/api/v1/admin/courses/{$course->id}/assignments", [
                'title' => 'Upload a book review',
                'submission_type' => 'file',
                'max_score' => 100,
            ])
            ->json('data');

        $file = UploadedFile::fake()->create('review.pdf', 100);

        $submission = $this->actingAsUser($learner)
            ->post("/api/v1/courses/{$course->id}/submit/assignment/{$assignment['id']}", [
                'content' => 'Here is my review',
                'file' => $file,
            ])
            ->assertCreated()
            ->json('data');

        $this->assertNotNull($submission['file_path']);
        Storage::disk('public')->assertExists($submission['file_path']);
    }
}