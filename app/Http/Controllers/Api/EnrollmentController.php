<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Enrollment;
use App\Services\EnrollmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
            'course_title' => $enrollment->course?->title,
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