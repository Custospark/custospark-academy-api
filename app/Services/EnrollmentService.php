<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\User;
use App\Repositories\Contracts\EnrollmentRepositoryInterface;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use Illuminate\Validation\ValidationException;

class EnrollmentService
{
    public function __construct(
        protected EnrollmentRepositoryInterface $enrollments,
        protected PaymentRepositoryInterface $payments,
        protected EnrollmentStateMachineService $stateMachine,
        protected PaymentService $paymentService,
        protected CourseCompletionService $completion,
    ) {}

    public function apply(int $courseId, User $user): Enrollment
    {
        $enrollment = $this->stateMachine->apply($courseId, (int) $user->id);

        // Sponsored/waivered application fees skip the payment step entirely,
        // so the enrollment advances (and auto-admits) with no human loop.
        return $this->paymentService->advanceWaivedFees($enrollment);
    }

    public function forUser(User $user): array
    {
        return $this->enrollments->forUser((int) $user->id)->all();
    }

    /**
     * Enrollments visible to staff. Admins see everything; instructors are
     * scoped to enrollments on courses they created. Supports filtering by
     * course, status and a free-text search (learner name/email, course title).
     */
    public function forAdmin(array $filters = [], ?User $viewer = null): array
    {
        $instructorId = null;
        if ($viewer !== null && ! $viewer->isAdmin()) {
            $instructorId = (int) $viewer->id;
        }

        $courseId = isset($filters['course_id']) && $filters['course_id'] !== '' ? (int) $filters['course_id'] : null;
        $status = isset($filters['status']) ? (string) $filters['status'] : null;
        $search = isset($filters['q']) ? (string) $filters['q'] : null;

        return $this->enrollments->queryForAdmin($courseId, $status, $search, $instructorId)->all();
    }

    public function getEnrollment(int $enrollmentId): ?Enrollment
    {
        return $this->enrollments->find($enrollmentId);
    }

    public function admit(int $enrollmentId, ?string $note = null): Enrollment
    {
        $enrollment = $this->stateMachine->admit($this->requireEnrollment($enrollmentId), $note);

        // Sponsored tuition means the admitted learner can start immediately.
        return $this->paymentService->advanceWaivedFees($enrollment);
    }

    public function reject(int $enrollmentId, ?string $note = null): Enrollment
    {
        return $this->stateMachine->reject($this->requireEnrollment($enrollmentId), $note);
    }

    public function cancel(int $enrollmentId, User $user): Enrollment
    {
        $enrollment = $this->requireEnrollment($enrollmentId);
        if ((int) $enrollment->user_id !== (int) $user->id && ! $user->isAdmin()) {
            abort(403, 'You cannot cancel this enrollment.');
        }

        return $this->stateMachine->cancel($enrollment);
    }

    public function payApplicationFee(int $enrollmentId, User $user): Payment
    {
        return $this->createAndPay($enrollmentId, $user, \App\Models\CourseFee::FEE_APPLICATION);
    }

    public function payTuition(int $enrollmentId, User $user): Payment
    {
        return $this->createAndPay($enrollmentId, $user, \App\Models\CourseFee::FEE_TUITION);
    }

    public function payCertificateFee(int $enrollmentId, User $user): Payment
    {
        return $this->createAndPay($enrollmentId, $user, \App\Models\CourseFee::FEE_CERTIFICATE);
    }

    public function complete(int $enrollmentId, User $user): Enrollment
    {
        $enrollment = $this->requireEnrollment($enrollmentId);
        $isOwner = (int) $enrollment->user_id === (int) $user->id;
        $canForce = $user->isAdmin() || $user->isInstructor();

        if (! $isOwner && ! $canForce) {
            abort(403, 'You cannot complete this enrollment.');
        }

        // Learners complete self-paced/hybrid courses by satisfying every
        // required item (a course with no required items is trivially complete).
        // Live courses are closed by the instructor, unless there is nothing
        // required to complete (a learner cannot "finish" an empty course).
        if ($isOwner && ! $canForce) {
            $course = $enrollment->course;
            $manifest = $this->completion->evaluate($enrollment->user ?? $user, $course);

            if ($course->isLive() && ! $manifest['is_complete']) {
                throw ValidationException::withMessages([
                    'completion' => 'Live courses are completed by your instructor after the final assessment is graded.',
                ]);
            }

            if (! $manifest['is_complete']) {
                throw ValidationException::withMessages([
                    'completion' => 'Finish every lesson and pass every assessment to complete this course.',
                ]);
            }
        }

        return $this->completeEnrollment($enrollment);
    }

    /**
     * Auto-complete a learner's enrollment after a learning action when the
     * course delivery mode allows it (self-paced or hybrid) and every required
     * item is done. Live courses never auto-complete; the instructor closes
     * those via the complete endpoint.
     */
    public function refreshCompletionAfterProgress(User $user, int $courseId): ?Enrollment
    {
        $enrollment = $this->enrollments->findByCourseAndUser($courseId, (int) $user->id);
        if ($enrollment === null) {
            return null;
        }

        if (! in_array($enrollment->status, [Enrollment::STATUS_TUITION_PAID, Enrollment::STATUS_IN_PROGRESS], true)) {
            return null;
        }

        $course = $enrollment->course;
        if (! ($course->isSelfPaced() || $course->isHybrid())) {
            return null;
        }

        $manifest = $this->completion->evaluate($enrollment->user ?? $user, $course);
        if (! $manifest['is_complete']) {
            return null;
        }

        return $this->completeEnrollment($enrollment);
    }

    public function evaluateCompletion(User $user, int $courseId): array
    {
        return $this->completion->evaluate($user, \App\Models\Course::query()->findOrFail($courseId));
    }

    protected function createAndPay(int $enrollmentId, User $user, string $feeType): Payment
    {
        $enrollment = $this->requireEnrollment($enrollmentId);
        if ((int) $enrollment->user_id !== (int) $user->id && ! $user->isAdmin()) {
            abort(403, 'You cannot pay for this enrollment.');
        }

        $payment = $this->paymentService->createForFee($enrollment, $user, $feeType);

        // For v1 the payment is approved inline (gateway integration slots here).
        return $this->paymentService->markPaid($payment, 'INLINE-'.strtoupper(bin2hex(random_bytes(4))));
    }

    /** Complete an in-progress enrollment and roll through any waived fees. */
    protected function completeEnrollment(Enrollment $enrollment): Enrollment
    {
        // Move through in_progress if tuition has been paid but not started.
        if ($enrollment->status === Enrollment::STATUS_TUITION_PAID) {
            $enrollment = $this->stateMachine->start($enrollment);
        }

        $enrollment = $this->stateMachine->complete($enrollment);

        // A waivered certificate fee should not block certification.
        return $this->paymentService->advanceWaivedFees($enrollment);
    }

    protected function requireEnrollment(int $enrollmentId): Enrollment
    {
        $enrollment = $this->enrollments->find($enrollmentId);
        if ($enrollment === null) {
            abort(404, 'Enrollment not found.');
        }

        return $enrollment;
    }
}