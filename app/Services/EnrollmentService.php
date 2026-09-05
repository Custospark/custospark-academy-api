<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\User;
use App\Repositories\Contracts\EnrollmentRepositoryInterface;
use App\Repositories\Contracts\PaymentRepositoryInterface;

class EnrollmentService
{
    public function __construct(
        protected EnrollmentRepositoryInterface $enrollments,
        protected PaymentRepositoryInterface $payments,
        protected EnrollmentStateMachineService $stateMachine,
        protected PaymentService $paymentService,
    ) {}

    public function apply(int $courseId, User $user): Enrollment
    {
        return $this->stateMachine->apply($courseId, (int) $user->id);
    }

    public function forUser(User $user): array
    {
        return $this->enrollments->forUser((int) $user->id)->all();
    }

    public function forAdmin(array $filters = []): array
    {
        if (! empty($filters['status'])) {
            return $this->enrollments->withStatus((string) $filters['status'])->all();
        }

        return $this->enrollments->forCourse((int) ($filters['course_id'] ?? 0))->all();
    }

    public function getEnrollment(int $enrollmentId): ?Enrollment
    {
        return $this->enrollments->find($enrollmentId);
    }

    public function admit(int $enrollmentId, ?string $note = null): Enrollment
    {
        return $this->stateMachine->admit($this->requireEnrollment($enrollmentId), $note);
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
        if ((int) $enrollment->user_id !== (int) $user->id && ! $user->isAdmin()) {
            abort(403, 'You cannot complete this enrollment.');
        }

        // Move through in_progress if tuition has been paid but not started.
        if ($enrollment->status === Enrollment::STATUS_TUITION_PAID) {
            $enrollment = $this->stateMachine->start($enrollment);
        }

        return $this->stateMachine->complete($enrollment);
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

    protected function requireEnrollment(int $enrollmentId): Enrollment
    {
        $enrollment = $this->enrollments->find($enrollmentId);
        if ($enrollment === null) {
            abort(404, 'Enrollment not found.');
        }

        return $enrollment;
    }
}