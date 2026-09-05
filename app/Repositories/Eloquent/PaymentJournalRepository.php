<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\PaymentJournal;
use App\Repositories\Contracts\PaymentJournalRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class PaymentJournalRepository implements PaymentJournalRepositoryInterface
{
    public function forPayment(int $paymentId): Collection
    {
        return PaymentJournal::query()
            ->where('payment_id', $paymentId)
            ->with('creator')
            ->orderByDesc('created_at')
            ->get();
    }

    public function create(array $data): PaymentJournal
    {
        return PaymentJournal::query()->create($data);
    }
}