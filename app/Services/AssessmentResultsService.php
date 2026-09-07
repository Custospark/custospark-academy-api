<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Assignment;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\Exercise;
use App\Models\Submission;
use App\Models\User;
use App\Repositories\Contracts\SubmissionRepositoryInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Bulk instructor results from Excel (learner_email | score | feedback) for
 * instructor-graded work (exams, exercises, assignments). Each valid row
 * becomes a graded submission the learner immediately sees as performance.
 */
class AssessmentResultsService
{
    public function __construct(
        protected SubmissionRepositoryInterface $submissions,
    ) {}

    /** Build the downloadable fill-in template. */
    public function templateBytes(): string
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Results');
        $sheet->fromArray([['learner_email', 'score', 'feedback']], null, 'A1');
        $sheet->fromArray([
            ['learner@example.com', 85, 'Well done - clear working.'],
        ], null, 'A2');
        $sheet->getStyle('A1:C1')->getFont()->setBold(true);
        foreach (range('A', 'C') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'rtemplate').'.xlsx';
        (new Xlsx($book))->save($tmp);
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }

    /** @return array{imported: int, total: int, errors: list<string>} */
    public function import(string $kind, Course $course, int $parentId, UploadedFile $file, int $graderId): array
    {
        $model = match ($kind) {
            'exam' => Exam::class,
            'exercise' => Exercise::class,
            'assignment' => Assignment::class,
            default => null,
        };
        if ($model === null) {
            throw ValidationException::withMessages(['kind' => 'Results upload is for exams, exercises and assignments.']);
        }
        $parent = $model::query()->where('course_id', $course->id)->find($parentId);
        if ($parent === null) {
            throw ValidationException::withMessages(['parent' => 'Assessment not found in this course.']);
        }

        $book = IOFactory::load($file->getPathname());
        $data = $book->getActiveSheet()->toArray();
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $data[0] ?? []);
        if (! in_array('learner_email', $header, true)) {
            throw ValidationException::withMessages(['file' => 'Missing header row. Download the results template first.']);
        }

        $max = (int) ($parent->max_score ?? 100);
        $imported = 0;
        $errors = [];

        foreach (array_slice($data, 1) as $index => $row) {
            $sheetRow = $index + 2;
            $email = strtolower(trim((string) ($row[0] ?? '')));
            if ($email === '' && trim((string) ($row[1] ?? '')) === '' && trim((string) ($row[2] ?? '')) === '') {
                continue;
            }
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Row {$sheetRow}: learner_email is required.";
                continue;
            }
            $user = User::query()->where('email', $email)->first();
            if ($user === null) {
                $errors[] = "Row {$sheetRow}: no account with email {$email}.";
                continue;
            }
            if (! Enrollment::query()->where('course_id', $course->id)->where('user_id', $user->id)->exists()) {
                $errors[] = "Row {$sheetRow}: {$email} is not enrolled in this course.";
                continue;
            }
            if (! is_numeric($row[1] ?? null) || (float) $row[1] < 0 || (float) $row[1] > $max) {
                $errors[] = "Row {$sheetRow}: score must be between 0 and {$max}.";
                continue;
            }

            $this->submissions->create([
                'user_id' => $user->id,
                'course_id' => $course->id,
                'submissionable_type' => $model,
                'submissionable_id' => $parent->id,
                'content' => null,
                'file_path' => null,
                'status' => Submission::STATUS_GRADED,
                'score' => (float) $row[1],
                'max_score' => $max,
                'feedback' => trim((string) ($row[2] ?? '')) ?: null,
                'graded_by' => $graderId,
                'graded_at' => now(),
                'submitted_at' => now(),
            ]);
            $imported++;
        }

        return ['imported' => $imported, 'total' => count($data) - 1, 'errors' => $errors];
    }
}
