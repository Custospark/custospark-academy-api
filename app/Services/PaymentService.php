<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\Course;
use App\Models\CourseFee;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\PaymentJournal;
use App\Models\User;
use App\Repositories\Contracts\CourseFeeRepositoryInterface;
use App\Repositories\Contracts\PaymentJournalRepositoryInterface;
use App\Repositories\Contracts\PaymentRepositoryInterface;

class PaymentService
{
    public function __construct(
        protected PaymentRepositoryInterface $payments,
        protected CourseFeeRepositoryInterface $fees,
        protected PaymentJournalRepositoryInterface $journal,
        protected EnrollmentStateMachineService $stateMachine,
    ) {}

    /**
     * Create a pending payment for a fee stage on an enrollment.
     */
    public function createForFee(Enrollment $enrollment, User $payer, string $feeType, string $method = Payment::METHOD_MOBILE_MONEY): Payment
    {
        $course = $enrollment->course;
        $fee = $this->resolveFee($course, $feeType);

        if ($enrollment->isPaid($feeType)) {
            throw new DomainException("The {$feeType} fee is already paid.");
        }

        $payment = $this->payments->create([
            'enrollment_id' => $enrollment->id,
            'user_id' => $payer->id,
            'fee_type' => $feeType,
            'amount' => (float) $fee->amount,
            'currency' => $fee->currency,
            'status' => Payment::STATUS_PENDING,
            'method' => $method,
        ]);

        $this->journal->create([
            'payment_id' => $payment->id,
            'event' => PaymentJournal::EVENT_CREATED,
            'created_by' => $payer->id,
        ]);

        return $payment;
    }

    /**
     * Mark a payment paid and advance the enrollment to the next state.
     */
    public function markPaid(Payment $payment, ?string $reference = null, array $meta = []): Payment
    {
        if ($payment->isPaid()) {
            throw new DomainException('This payment is already marked as paid.');
        }

        $payment = $this->payments->update($payment, [
            'status' => Payment::STATUS_PAID,
            'reference' => $reference ?? $payment->reference,
            'paid_at' => now(),
            'meta' => $meta ?: $payment->meta,
        ]);

        $this->journal->create([
            'payment_id' => $payment->id,
            'event' => PaymentJournal::EVENT_APPROVED,
            'created_by' => $payment->user_id,
        ]);

        $this->advanceEnrollment($payment->enrollment, $payment->fee_type);

        return $payment;
    }

    public function markFailed(Payment $payment, string $note = 'Payment failed'): Payment
    {
        $payment = $this->payments->update($payment, ['status' => Payment::STATUS_FAILED]);

        $this->journal->create([
            'payment_id' => $payment->id,
            'event' => PaymentJournal::EVENT_FAILED,
            'note' => $note,
            'created_by' => $payment->user_id,
        ]);

        return $payment;
    }

    public function find(int $paymentId): ?Payment
    {
        return $this->payments->find($paymentId);
    }

    public function updateReference(Payment $payment, string $reference): Payment
    {
        return $this->payments->update($payment, ['reference' => $reference]);
    }

    public function findByGatewayTxn(string $txnId): ?Payment
    {
        // The gateway txn id is stored in meta during markPaid; fall back to reference.
        return $this->payments->findByGatewayTxn($txnId);
    }

    public function markRefunded(Payment $payment, string $note = 'Payment refunded'): Payment
    {
        $payment = $this->payments->update($payment, ['status' => Payment::STATUS_REFUNDED]);

        $this->journal->create([
            'payment_id' => $payment->id,
            'event' => PaymentJournal::EVENT_REFUNDED,
            'note' => $note,
            'created_by' => $payment->user_id,
        ]);

        return $payment;
    }

    protected function resolveFee(Course $course, string $feeType): CourseFee
    {
        $fee = $this->fees->forCourse((int) $course->id, $feeType);
        if ($fee === null) {
            throw new DomainException("No {$feeType} fee is configured for this course.");
        }

        return $fee;
    }

    protected function advanceEnrollment(Enrollment $enrollment, string $feeType): void
    {
        match ($feeType) {
            CourseFee::FEE_APPLICATION => $this->stateMachine->markApplicationFeePaid($enrollment),
            CourseFee::FEE_TUITION => $this->stateMachine->markTuitionPaid($enrollment),
            CourseFee::FEE_CERTIFICATE => $this->stateMachine->markCertificateFeePaid($enrollment),
            default => null,
        };
    }
}