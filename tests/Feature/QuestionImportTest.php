<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class QuestionImportTest extends TestCase
{
    use RefreshDatabase;

    private function makeXlsx(array $rows): UploadedFile
    {
        $book = new Spreadsheet();
        $book->getActiveSheet()->fromArray($rows, null, 'A1');
        $path = tempnam(sys_get_temp_dir(), 'qimport').'.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'questions.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    public function test_template_downloads_as_xlsx(): void
    {
        $instructor = User::factory()->instructor()->create();
        $course = Course::factory()->published()->create(['created_by' => $instructor->id]);

        $res = $this->actingAsUser($instructor)
            ->get("/api/v1/admin/courses/{$course->id}/questions/template")
            ->assertOk();

        $this->assertStringContainsString(
            'spreadsheetml.sheet',
            $res->headers->get('Content-Type') ?? ''
        );
        $this->assertStringStartsWith('PK', $res->streamedContent());
    }

    public function test_import_creates_questions_and_reports_row_errors(): void
    {
        $instructor = User::factory()->instructor()->create();
        $course = Course::factory()->published()->create(['created_by' => $instructor->id]);

        $quiz = $this->actingAsUser($instructor)
            ->postJson("/api/v1/admin/courses/{$course->id}/quizzes", [
                'title' => 'Week 1 Quiz',
                'questions' => [],
            ])
            ->assertCreated()
            ->json('data');

        $file = $this->makeXlsx([
            ['question', 'type', 'options', 'correct_answer', 'points', 'explanation'],
            ['What is 2 + 2?', 'multiple_choice', '3|4|5', '4', 1, 'Basic addition.'],
            ['', 'multiple_choice', 'a|b', 'a', 1, null],
            ['The sky is blue.', 'true_false', 'True|False', 'True', 2, null],
        ]);

        $result = $this->actingAsUser($instructor)
            ->post("/api/v1/admin/courses/{$course->id}/quiz/{$quiz['id']}/questions/import", ['file' => $file])
            ->assertCreated()
            ->json('data');

        $this->assertSame(2, $result['imported']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('Row 3', $result['errors'][0]);
        $this->assertDatabaseCount('quiz_questions', 2);
        $this->assertDatabaseHas('quiz_questions', [
            'quiz_id' => $quiz['id'],
            'question' => 'What is 2 + 2?',
            'correct_answer' => '4',
        ]);
    }

    public function test_import_rejects_unknown_kind_and_bad_headers(): void
    {
        $instructor = User::factory()->instructor()->create();
        $course = Course::factory()->published()->create(['created_by' => $instructor->id]);

        $this->actingAsUser($instructor)
            ->post("/api/v1/admin/courses/{$course->id}/midterm/1/questions/import", [
                'file' => $this->makeXlsx([['question'], ['Hi']]),
            ])
            ->assertNotFound();

        $quiz = $this->actingAsUser($instructor)
            ->postJson("/api/v1/admin/courses/{$course->id}/quizzes", [
                'title' => 'Week 1 Quiz',
                'questions' => [],
            ])
            ->assertCreated()
            ->json('data');

        $this->actingAsUser($instructor)
            ->post(
                "/api/v1/admin/courses/{$course->id}/quiz/{$quiz['id']}/questions/import",
                ['file' => $this->makeXlsx([['nope'], ['Hi']])],
                ['Accept' => 'application/json'],
            )
            ->assertStatus(422);
    }
}
