<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\Payment;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class PaymentRepository implements PaymentRepositoryInterface
{
    public function find(int $id): ?Payment
    {
        return Payment::query()->with(['enrollment.course', 'user'])->find($id);
    }

    public function forEnrollment(int $enrollmentId): Collection
    {
        return Payment::query()
            ->where('enrollment_id', $enrollmentId)
            ->orderByDesc('created_at')
            ->get();
    }

    public function forEnrollmentAndFee(int $enrollmentId, string $feeType): ?Payment
    {
        return Payment::query()
            ->where('enrollment_id', $enrollmentId)
            ->where('fee_type', $feeType)
            ->latest()
            ->first();
    }

    public function forUser(int $userId): Collection
    {
        return Payment::query()
            ->where('user_id', $userId)
            ->with(['enrollment.course'])
            ->orderByDesc('created_at')
            ->get();
    }

    public function findByGatewayTxn(string $txnId): ?Payment
    {
        return Payment::query()
            ->whereJsonContains('meta->gateway_txn_id', $txnId)
            ->orWhere('reference', $txnId)
            ->first();
    }

    public function create(array $data): Payment
    {
        return Payment::query()->create($data);
    }

    public function update(Payment $payment, array $data): Payment
    {
        $payment->update($data);

        return $payment->fresh();
    }

    public function delete(Payment $payment): bool
    {
        return (bool) $payment->delete();
    }
}