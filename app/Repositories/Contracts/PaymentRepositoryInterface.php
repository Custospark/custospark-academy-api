<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Payment;
use Illuminate\Database\Eloquent\Collection;

interface PaymentRepositoryInterface
{
    public function find(int $id): ?Payment;

    /** @return Collection<int, Payment> */
    public function forEnrollment(int $enrollmentId): Collection;

    public function forEnrollmentAndFee(int $enrollmentId, string $feeType): ?Payment;

    /** @return Collection<int, Payment> */
    public function forUser(int $userId): Collection;

    public function findByGatewayTxn(string $txnId): ?Payment;

    public function create(array $data): Payment;

    public function update(Payment $payment, array $data): Payment;

    public function delete(Payment $payment): bool;
}