<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\PaymentJournal;
use Illuminate\Database\Eloquent\Collection;

interface PaymentJournalRepositoryInterface
{
    /** @return Collection<int, PaymentJournal> */
    public function forPayment(int $paymentId): Collection;

    public function create(array $data): PaymentJournal;
}