<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\Enrollment;
use App\Repositories\Contracts\EnrollmentRepositoryInterface;
use Illuminate\Support\Carbon;

/**
 * Owns the enrollment lifecycle. Every transition is gated by the payment
 * state machine: a learner cannot advance past a fee until it is paid.
 */
class EnrollmentStateMachineService
{
    public function __construct(
        protected EnrollmentRepositoryInterface $enrollments,
    ) {}

    public function apply(int $courseId, int $userId): Enrollment
    {
        if ($this->enrollments->findByCourseAndUser($courseId, $userId) !== null) {
            throw new DomainException('You have already applied for this course.');
        }

        return $this->enrollments->create([
            'course_id' => $courseId,
            'user_id' => $userId,
            'status' => Enrollment::STATUS_APPLIED,
            'applied_at' => now(),
        ]);
    }

    public function markApplicationFeePaid(Enrollment $enrollment): Enrollment
    {
        return $this->transition($enrollment, Enrollment::STATUS_APPLICATION_FEE_PAID);
    }

    public function admit(Enrollment $enrollment, ?string $note = null): Enrollment
    {
        if ($enrollment->status !== Enrollment::STATUS_APPLICATION_FEE_PAID) {
            throw new DomainException('Application fee must be paid before admission.');
        }

        return $this->enrollments->update($enrollment, [
            'status' => Enrollment::STATUS_ADMITTED,
            'application_review_note' => $note,
            'admitted_at' => now(),
        ]);
    }

    public function markTuitionPaid(Enrollment $enrollment): Enrollment
    {
        return $this->transition($enrollment, Enrollment::STATUS_TUITION_PAID);
    }

    public function start(Enrollment $enrollment): Enrollment
    {
        return $this->transition($enrollment, Enrollment::STATUS_IN_PROGRESS);
    }

    public function complete(Enrollment $enrollment): Enrollment
    {
        return $this->transition($enrollment, Enrollment::STATUS_COMPLETED);
    }

    public function markCertificateFeePaid(Enrollment $enrollment): Enrollment
    {
        return $this->transition($enrollment, Enrollment::STATUS_CERTIFICATION);
    }

    public function certify(Enrollment $enrollment, ?Carbon $issuedAt = null): Enrollment
    {
        if ($enrollment->status !== Enrollment::STATUS_CERTIFICATION) {
            throw new DomainException('Certificate fee must be paid before certification.');
        }

        return $this->enrollments->update($enrollment, [
            'status' => Enrollment::STATUS_CERTIFIED,
            'certified_at' => $issuedAt ?? now(),
        ]);
    }

    public function reject(Enrollment $enrollment, ?string $note = null): Enrollment
    {
        if (! in_array($enrollment->status, [Enrollment::STATUS_APPLIED, Enrollment::STATUS_APPLICATION_FEE_PAID], true)) {
            throw new DomainException('Only applications can be rejected.');
        }

        return $this->enrollments->update($enrollment, [
            'status' => Enrollment::STATUS_REJECTED,
            'application_review_note' => $note,
        ]);
    }

    public function cancel(Enrollment $enrollment): Enrollment
    {
        if (in_array($enrollment->status, [Enrollment::STATUS_CERTIFIED, Enrollment::STATUS_REJECTED], true)) {
            throw new DomainException('This enrollment can no longer be cancelled.');
        }

        return $this->enrollments->update($enrollment, ['status' => Enrollment::STATUS_CANCELLED]);
    }

    protected function transition(Enrollment $enrollment, string $next): Enrollment
    {
        if (! $enrollment->canTransitionTo($next)) {
            throw new DomainException(
                "Cannot transition enrollment from {$enrollment->status} to {$next}."
            );
        }

        return $this->enrollments->update($enrollment, ['status' => $next]);
    }
}