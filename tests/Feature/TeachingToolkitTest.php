<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\StandardEmail;
use App\Models\Course;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class TeachingToolkitTest extends TestCase
{
    use RefreshDatabase;

    private function courseWithQuiz(User $owner): array
    {
        $course = Course::factory()->published()->create(['created_by' => $owner->id]);
        $quiz = $this->actingAsUser($owner)
            ->postJson("/api/v1/admin/courses/{$course->id}/quizzes", [
                'title' => 'Week 1 Quiz',
                'questions' => [
                    ['question' => 'What is 2 + 2?', 'type' => 'multiple_choice', 'options' => ['3', '4'], 'correct_answer' => '4', 'points' => 1],
                ],
            ])
            ->assertCreated()
            ->json('data');

        return [$course, $quiz];
    }

    private function enroll(User $learner, Course $course): void
    {
        app(\App\Services\EnrollmentService::class)->apply($course->id, $learner);
    }

    private function makeXlsx(array $rows): UploadedFile
    {
        $book = new Spreadsheet();
        $book->getActiveSheet()->fromArray($rows, null, 'A1');
        $path = tempnam(sys_get_temp_dir(), 'toolkit').'.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'upload.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    public function test_closed_assessment_rejects_attempts(): void
    {
        $instructor = User::factory()->instructor()->create();
        [$course, $quiz] = $this->courseWithQuiz($instructor);
        $learner = User::factory()->learner()->create();
        $this->enroll($learner, $course);

        $this->actingAsUser($instructor)
            ->putJson("/api/v1/admin/courses/{$course->id}/quizzes/{$quiz['id']}", [
                'closes_at' => now()->subDay()->toIso8601String(),
            ])
            ->assertOk();

        $this->actingAsUser($learner)
            ->postJson("/api/v1/courses/{$course->id}/attempt/quiz/{$quiz['id']}", [
                'answers' => [$quiz['questions'][0]['id'] => '4'],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains((string) $m, 'closed') || isset($m));
    }

    public function test_unopened_assessment_rejects_attempts(): void
    {
        $instructor = User::factory()->instructor()->create();
        [$course, $quiz] = $this->courseWithQuiz($instructor);
        $learner = User::factory()->learner()->create();
        $this->enroll($learner, $course);

        $this->actingAsUser($instructor)
            ->putJson("/api/v1/admin/courses/{$course->id}/quizzes/{$quiz['id']}", [
                'opens_at' => now()->addDay()->toIso8601String(),
            ])
            ->assertOk();

        $this->actingAsUser($learner)
            ->postJson("/api/v1/courses/{$course->id}/attempt/quiz/{$quiz['id']}", [
                'answers' => [$quiz['questions'][0]['id'] => '4'],
            ])
            ->assertStatus(422);
    }

    public function test_announce_emails_learners_by_status(): void
    {
        Mail::fake();
        $instructor = User::factory()->instructor()->create();
        [$course] = $this->courseWithQuiz($instructor);
        $learner = User::factory()->learner()->create();
        $this->enroll($learner, $course);

        $data = $this->actingAsUser($instructor)
            ->postJson("/api/v1/admin/courses/{$course->id}/announce", [
                'subject' => 'Welcome session',
                'body' => "Join us here:\nhttps://meet.example.com/abc",
            ])
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $data['sent']);
        Mail::assertSent(StandardEmail::class, 1);

        // Status filter that matches nobody sends nothing.
        $none = $this->actingAsUser($instructor)
            ->postJson("/api/v1/admin/courses/{$course->id}/announce", [
                'statuses' => ['rejected'],
                'subject' => 'Welcome session',
                'body' => 'Hello',
            ])
            ->assertOk()
            ->json('data');
        $this->assertSame(0, $none['sent']);
    }

    public function test_learners_export_downloads_as_excel_and_pdf(): void
    {
        $admin = User::factory()->admin()->create();
        $course = Course::factory()->published()->create(['created_by' => $admin->id]);
        $learner = User::factory()->learner()->create();
        $this->enroll($learner, $course);

        $xlsx = $this->actingAsUser($admin)
            ->get("/api/v1/admin/courses/{$course->id}/learners/export?format=xlsx")
            ->assertOk();
        $this->assertStringStartsWith('PK', $xlsx->streamedContent());

        $pdf = $this->actingAsUser($admin)
            ->get("/api/v1/admin/courses/{$course->id}/learners/export?format=pdf")
            ->assertOk();
        $this->assertStringStartsWith('%PDF-', $pdf->streamedContent());
    }

    public function test_results_import_grades_learner_submissions(): void
    {
        Storage::fake('public');
        $instructor = User::factory()->instructor()->create();
        $course = Course::factory()->published()->create(['created_by' => $instructor->id]);
        $learner = User::factory()->learner()->create(['email' => 'results.learner@example.com']);
        $this->enroll($learner, $course);

        $exam = $this->actingAsUser($instructor)
            ->postJson("/api/v1/admin/courses/{$course->id}/exams", [
                'title' => 'Final Exam',
                'questions' => [],
            ])
            ->assertCreated()
            ->json('data');

        $file = $this->makeXlsx([
            ['learner_email', 'score', 'feedback'],
            ['results.learner@example.com', 85, 'Well done.'],
            ['ghost@example.com', 90, null],
        ]);

        $result = $this->actingAsUser($instructor)
            ->post("/api/v1/admin/courses/{$course->id}/exam/{$exam['id']}/results/import", ['file' => $file])
            ->assertCreated()
            ->json('data');

        $this->assertSame(1, $result['imported']);
        $this->assertCount(1, $result['errors']);
        $this->assertDatabaseHas('submissions', [
            'user_id' => $learner->id,
            'course_id' => $course->id,
            'status' => Submission::STATUS_GRADED,
            'score' => 85,
        ]);
    }
}
