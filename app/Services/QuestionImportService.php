<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\Exam;
use App\Models\Exercise;
use App\Models\Quiz;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Bulk multiple-choice (and other typed) question import from Excel.
 *
 * Spreadsheet layout (first row = headers, case-insensitive):
 *   question | type | options | correct_answer | points | explanation
 * - options: choices separated by |  (pipe, so commas survive)
 * - type: multiple_choice | true_false | short_answer | code (default multiple_choice)
 * - points: integer >= 1 (default 1)
 */
class QuestionImportService
{
    public const HEADERS = ['question', 'type', 'options', 'correct_answer', 'points', 'explanation'];

    public const TYPES = ['multiple_choice', 'true_false', 'short_answer', 'code'];

    public const MAX_ROWS = 500;

    public function __construct(
        protected CourseContentService $content,
    ) {}

    /** Build the downloadable fill-in template (headers + example rows). */
    public function templateBytes(): string
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Questions');

        $sheet->fromArray([self::HEADERS], null, 'A1');
        $sheet->fromArray([
            ['What is 2 + 2?', 'multiple_choice', '3|4|5', '4', 1, 'Basic addition.'],
            ['The sky is blue.', 'true_false', 'True|False', 'True', 1, null],
            ['Name the capital of Uganda.', 'short_answer', null, 'Kampala', 2, null],
        ], null, 'A2');

        $sheet->getStyle('A1:F1')->getFont()->setBold(true);
        foreach (range('A', 'F') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'qtemplate').'.xlsx';
        (new Xlsx($book))->save($tmp);
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }

    /**
     * Import questions into one quiz/exercise/exam.
     *
     * @return array{imported: int, total: int, errors: list<string>}
     */
    public function import(string $kind, Course $course, int $parentId, UploadedFile $file): array
    {
        $parent = $this->resolveParent($kind, (int) $course->id, $parentId);

        $rows = $this->readRows($file);
        $imported = 0;
        $errors = [];

        foreach ($rows as $sheetRow => $row) {
            if ($this->isBlank($row)) {
                continue;
            }
            $parsed = $this->parseRow($row);
            if (is_string($parsed)) {
                $errors[] = "Row {$sheetRow}: {$parsed}";
                continue;
            }
            $this->createQuestion($kind, $parent, $parsed);
            $imported++;
        }

        return ['imported' => $imported, 'total' => count($rows), 'errors' => $errors];
    }

    /** @return object parent model (Quiz|Exercise|Exam) */
    protected function resolveParent(string $kind, int $courseId, int $parentId): Quiz|Exercise|Exam
    {
        $model = match ($kind) {
            'quiz' => Quiz::class,
            'exercise' => Exercise::class,
            'exam' => Exam::class,
            default => null,
        };
        if ($model === null) {
            throw ValidationException::withMessages(['kind' => 'Invalid assessment kind.']);
        }
        $parent = $model::query()->where('course_id', $courseId)->find($parentId);
        if ($parent === null) {
            throw ValidationException::withMessages(['parent' => 'Assessment not found in this course.']);
        }

        return $parent;
    }

    /** @return array<int, array<int, mixed>> sheet rows keyed by 1-based row number */
    protected function readRows(UploadedFile $file): array
    {
        $book = IOFactory::load($file->getPathname());
        $data = $book->getActiveSheet()->toArray();
        if (count($data) > self::MAX_ROWS + 1) {
            throw ValidationException::withMessages(['file' => 'Too many rows - maximum '.self::MAX_ROWS.' questions per upload.']);
        }

        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $data[0] ?? []);
        if (! in_array('question', $header, true)) {
            throw ValidationException::withMessages(['file' => 'Missing header row. Download the template first.']);
        }

        $rows = [];
        foreach (array_slice($data, 1) as $index => $row) {
            // Re-key by header position for readability.
            $assoc = [];
            foreach (self::HEADERS as $pos => $name) {
                $assoc[$name] = $row[$pos] ?? null;
            }
            $rows[$index + 2] = $assoc;
        }

        return $rows;
    }

    protected function isBlank(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed>|string parsed data or error message */
    protected function parseRow(array $row): array|string
    {
        $question = trim((string) ($row['question'] ?? ''));
        if ($question === '') {
            return 'question text is required.';
        }

        $type = strtolower(trim((string) ($row['type'] ?? 'multiple_choice')));
        if ($type === '') {
            $type = 'multiple_choice';
        }
        if (! in_array($type, self::TYPES, true)) {
            return "unknown type '{$type}' - use: ".implode(', ', self::TYPES).'.';
        }

        $optionsRaw = trim((string) ($row['options'] ?? ''));
        $options = $optionsRaw === ''
            ? null
            : array_values(array_filter(array_map('trim', explode('|', $optionsRaw))));

        $points = (int) ($row['points'] ?? 1);
        if ($points < 1) {
            return 'points must be 1 or more.';
        }

        $correct = trim((string) ($row['correct_answer'] ?? ''));

        return [
            'question' => $question,
            'type' => $type,
            'options' => $options,
            'correct_answer' => $correct === '' ? null : $correct,
            'points' => $points,
            'explanation' => trim((string) ($row['explanation'] ?? '')) ?: null,
        ];
    }

    /** @param array<string, mixed> $data */
    protected function createQuestion(string $kind, Quiz|Exercise|Exam $parent, array $data): void
    {
        match ($kind) {
            'quiz' => $this->content->createQuizQuestion($parent, $data),
            'exercise' => $this->content->createExerciseQuestion($parent, $data),
            'exam' => $this->content->createExamQuestion($parent, $data),
        };
    }
}
