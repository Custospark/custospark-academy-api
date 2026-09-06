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
use App\Services\Payment\PaymentReceiptService;
use Illuminate\Support\Facades\Log;

class PaymentService
{
    public function __construct(
        protected PaymentRepositoryInterface $payments,
        protected CourseFeeRepositoryInterface $fees,
        protected PaymentJournalRepositoryInterface $journal,
        protected EnrollmentStateMachineService $stateMachine,
        protected PaymentReceiptService $receipts,
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
     * Pay (or auto-advance) a fee stage. When no fee is configured, or the
     * amount is zero (sponsored/waived), the enrollment simply advances with
     * no payment record or gateway call.
     *
     * @return array{payment: ?Payment, auto_advanced: bool}
     */
    public function payFee(Enrollment $enrollment, User $payer, string $feeType): array
    {
        // No fee configured or zero amount: advance state without payment.
        if ($this->feeIsWaived((int) $enrollment->course_id, $feeType)) {
            $this->advanceEnrollment($enrollment, $feeType);

            return ['payment' => null, 'auto_advanced' => true];
        }

        if ($enrollment->isPaid($feeType)) {
            throw new DomainException("The {$feeType} fee is already paid.");
        }

        $payment = $this->createForFee($enrollment, $payer, $feeType);

        return ['payment' => $payment, 'auto_advanced' => false];
    }

    /**
     * Whether a fee is waived for a course (not configured, or amount zero).
     * Tuition waived -> "Sponsored"; other fees -> "Waived".
     */
    public function feeIsWaived(int $courseId, string $feeType): bool
    {
        $fee = $this->fees->forCourse($courseId, $feeType);

        return $fee === null || (float) $fee->amount <= 0;
    }

    /**
     * Auto-advance an enrollment through any waivered fee stages so the state
     * matches what the learner actually owes. e.g. a sponsored course (all fees
     * zero) skips straight to tuition_paid when applying, and a nil certificate
     * fee is moved through on completion.
     */
    public function advanceWaivedFees(Enrollment $enrollment): Enrollment
    {
        $current = $enrollment->fresh() ?? $enrollment;
        $courseId = (int) $current->course_id;

        // Sponsored application fee: no payment needed, auto-admit (no human loop).
        if ($current->status === Enrollment::STATUS_APPLIED && $this->feeIsWaived($courseId, CourseFee::FEE_APPLICATION)) {
            $current = $this->stateMachine->markApplicationFeePaid($current->fresh());
            // No human in the loop: a waived application fee auto-admits (mirrors paid flow).
            $current = $this->stateMachine->admit($current->fresh());
        }

        // Sponsored tuition: skip the tuition step so learning can start.
        if ($current->status === Enrollment::STATUS_ADMITTED && $this->feeIsWaived($courseId, CourseFee::FEE_TUITION)) {
            $current = $this->stateMachine->markTuitionPaid($current->fresh());
        }

        // Waived certificate fee on a completed course: move to certification.
        if ($current->status === Enrollment::STATUS_COMPLETED && $this->feeIsWaived($courseId, CourseFee::FEE_CERTIFICATE)) {
            $current = $this->stateMachine->markCertificateFeePaid($current->fresh());
        }

        return $current->fresh() ?? $current;
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

        // Email the receipt (invoice PDF). Deliverability must never break the
        // payment flow, so any failure is logged and swallowed.
        try {
            $this->receipts->sendReceiptIfDue($payment->fresh());
        } catch (\Throwable $e) {
            Log::error('[PaymentService] Receipt delivery failed after payment', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
        }

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

    /** @return \Illuminate\Database\Eloquent\Collection<int, Payment> */
    public function forUser(int $userId): \Illuminate\Database\Eloquent\Collection
    {
        return $this->payments->forUser($userId);
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
        switch ($feeType) {
            case CourseFee::FEE_APPLICATION:
                $this->stateMachine->markApplicationFeePaid($enrollment);
                // No human in the loop: paying the application fee auto-admits.
                $this->stateMachine->admit($enrollment->fresh());
                break;

            case CourseFee::FEE_TUITION:
                $this->stateMachine->markTuitionPaid($enrollment);
                break;

            case CourseFee::FEE_CERTIFICATE:
                $this->stateMachine->markCertificateFeePaid($enrollment);
                break;

            default:
                break;
        }

        // After a fee stage is met, roll through any remaining waivered fees.
        $this->advanceWaivedFees($enrollment->fresh());
    }
}