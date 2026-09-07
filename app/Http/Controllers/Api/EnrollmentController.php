<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\StandardEmail;
use App\Models\Course;
use App\Models\Enrollment;
use App\Services\EnrollmentService;
use App\Services\PdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class EnrollmentController extends Controller
{
    public function __construct(
        protected EnrollmentService $enrollments,
    ) {}

    public function apply(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'course_id' => ['required', 'integer', 'exists:courses,id'],
        ]);

        $enrollment = $this->enrollments->apply((int) $validated['course_id'], $request->user());

        return response()->json(['data' => $this->serialize($enrollment)], 201);
    }

    public function mine(Request $request): JsonResponse
    {
        return response()->json([
            'data' => array_map(
                fn (Enrollment $e) => $this->serialize($e),
                $this->enrollments->forUser($request->user()),
            ),
        ]);
    }

    /**
     * Staff enrollment listing. Admins see every enrollment; instructors only
     * see enrollments on courses they created. Filters: course_id, status, q.
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $this->requireStaff();

        return response()->json([
            'data' => array_map(
                fn (Enrollment $e) => $this->serialize($e, deep: true),
                $this->enrollments->forAdmin($request->query(), $request->user()),
            ),
        ]);
    }

    public function admit(Request $request, int $id): JsonResponse
    {
        $this->authorizeManageEnrollment($id);
        $validated = $request->validate(['note' => ['nullable', 'string']]);

        return response()->json([
            'data' => $this->serialize($this->enrollments->admit($id, $validated['note'] ?? null), deep: true),
        ]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $this->authorizeManageEnrollment($id);
        $validated = $request->validate(['note' => ['nullable', 'string']]);

        return response()->json([
            'data' => $this->serialize($this->enrollments->reject($id, $validated['note'] ?? null), deep: true),
        ]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        return response()->json([
            'data' => $this->serialize($this->enrollments->cancel($id, $request->user()), deep: true),
        ]);
    }

    public function complete(Request $request, int $id): JsonResponse
    {
        return response()->json([
            'data' => $this->serialize($this->enrollments->complete($id, $request->user()), deep: true),
        ]);
    }

    /**
     * Mass email to a course's learners, optionally filtered by enrollment
     * status (announcements, meeting links, deadline reminders). Sent
     * synchronously in chunks so the sender gets an exact sent count.
     */
    public function announce(Request $request, string|int $courseId): JsonResponse
    {
        $course = Course::resolveByKeyOrFail($courseId);
        $this->authorizeCourseAudience($course, $request->user());

        $validated = $request->validate([
            'statuses' => ['nullable', 'array'],
            'statuses.*' => ['string', 'in:applied,application_fee_paid,admitted,tuition_paid,in_progress,completed,certification,certified,rejected,cancelled'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10000'],
        ]);

        $query = Enrollment::query()->where('course_id', $course->id)->with('user');
        if (! empty($validated['statuses'])) {
            $query->whereIn('status', $validated['statuses']);
        }

        $sent = 0;
        $query->chunkById(100, function ($enrollments) use (&$sent, $validated, $course): void {
            foreach ($enrollments as $enrollment) {
                $email = $enrollment->user?->email;
                if (! $email) {
                    continue;
                }
                Mail::to($email)->send(new StandardEmail(
                    title: $validated['subject'],
                    mailBody: nl2br(e($validated['body'])),
                    tip: "Course: {$course->title}",
                ));
                $sent++;
            }
        });

        return response()->json(['data' => ['sent' => $sent]]);
    }

    /**
     * Download the learner roster as Excel or PDF (names, email, phone,
     * status, dates). Respects an optional status filter.
     */
    public function exportLearners(Request $request, string|int $courseId): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $course = Course::resolveByKeyOrFail($courseId);
        $this->authorizeCourseAudience($course, $request->user());

        $validated = $request->validate([
            'format' => ['nullable', 'string', 'in:xlsx,pdf'],
            'status' => ['nullable', 'string'],
        ]);
        $format = $validated['format'] ?? 'xlsx';

        $enrollments = Enrollment::query()
            ->with('user')
            ->where('course_id', $course->id)
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->orderBy('id')
            ->get();

        $rows = $enrollments->map(fn ($e) => [
            $e->user?->name ?? 'Learner',
            $e->user?->email ?? '',
            $e->user?->phone ?? '',
            str_replace('_', ' ', (string) $e->status),
            $e->applied_at?->format('Y-m-d'),
            $e->admitted_at?->format('Y-m-d'),
        ])->all();

        $filename = 'learners-'.$course->slug.'.'.$format;

        if ($format === 'pdf') {
            $bytes = app(PdfService::class)->render('reports.learners', [
                'course' => $course,
                'rows' => $rows,
                'generatedAt' => now(),
            ], 'a4', 'landscape');

            return response()->stream(
                function () use ($bytes): void {
                    echo $bytes;
                },
                200,
                [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'attachment; filename="'.$filename.'"',
                ],
            );
        }

        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Learners');
        $sheet->fromArray([['Name', 'Email', 'Phone', 'Status', 'Applied', 'Admitted']], null, 'A1');
        $sheet->fromArray($rows, null, 'A2');
        $sheet->getStyle('A1:F1')->getFont()->setBold(true);
        foreach (range('A', 'F') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        return response()->streamDownload(
            function () use ($book): void {
                (new Xlsx($book))->save('php://output');
            },
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    /** Admins reach any course audience; instructors only their own courses. */
    private function authorizeCourseAudience(Course $course, $user): void
    {
        if ($user === null) {
            abort(401);
        }
        if ($user->isAdmin()) {
            return;
        }
        if ($user->isInstructor() && (int) $course->created_by === (int) $user->id) {
            return;
        }
        abort(403, 'You can only message learners on courses you created.');
    }

    private function requireStaff(): void
    {
        $user = request()->user();
        if (! $user?->isAdmin() && ! $user?->isInstructor()) {
            abort(403, 'Only admins and instructors can view enrollments.');
        }
    }

    /** Admins manage any enrollment; instructors only those on their own courses. */
    private function authorizeManageEnrollment(int $enrollmentId): void
    {
        $user = request()->user();
        if ($user === null) {
            abort(401);
        }

        if ($user->isAdmin()) {
            return;
        }

        if (! $user->isInstructor()) {
            abort(403, 'Only admins and instructors can perform this action.');
        }

        $enrollment = $this->enrollments->getEnrollment($enrollmentId);
        if ($enrollment === null) {
            abort(404, 'Enrollment not found.');
        }

        if ((int) $enrollment->course?->created_by !== (int) $user->id) {
            abort(403, 'You can only manage enrollments for courses you created.');
        }
    }

    private function serialize(Enrollment $enrollment, bool $deep = false): array
    {
        return [
            'id' => $enrollment->id,
            'course_id' => $enrollment->course_id,
            'course_slug' => $enrollment->course?->slug,
            'course_title' => $enrollment->course?->title,
            'enrollment_opens_at' => $enrollment->course?->enrollment_opens_at?->toIso8601String(),
            'enrollment_closes_at' => $enrollment->course?->enrollment_closes_at?->toIso8601String(),
            'user_id' => $enrollment->user_id,
            'user_name' => $deep ? $enrollment->user?->name : null,
            'user_email' => $deep ? $enrollment->user?->email : null,
            'status' => $enrollment->status,
            'has_paid_application' => $enrollment->payments->contains(fn ($p) => $p->fee_type === 'application' && $p->status === 'paid'),
            'has_paid_tuition' => $enrollment->payments->contains(fn ($p) => $p->fee_type === 'tuition' && $p->status === 'paid'),
            'has_paid_certificate' => $enrollment->payments->contains(fn ($p) => $p->fee_type === 'certificate' && $p->status === 'paid'),
            'applied_at' => $enrollment->applied_at?->toIso8601String(),
            'admitted_at' => $enrollment->admitted_at?->toIso8601String(),
            'completed_at' => $enrollment->completed_at?->toIso8601String(),
            'certified_at' => $enrollment->certified_at?->toIso8601String(),
            'application_review_note' => $enrollment->application_review_note,
            'payments' => $enrollment->payments->map(fn ($p) => [
                'id' => $p->id,
                'fee_type' => $p->fee_type,
                'amount' => (float) $p->amount,
                'currency' => $p->currency,
                'status' => $p->status,
                'reference' => $p->reference,
            ])->values(),
            'certificate' => $enrollment->certificate ? [
                'reference' => $enrollment->certificate->certificate_reference,
                'issued_at' => $enrollment->certificate->issued_at?->toIso8601String(),
            ] : null,
            'fees' => $enrollment->course?->fees?->map(fn ($f) => [
                'fee_type' => $f->fee_type,
                'amount' => (float) $f->amount,
                'currency' => $f->currency,
            ])->values() ?? [],
        ];
    }
}