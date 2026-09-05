<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\Certificate;
use App\Repositories\Contracts\CertificateRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class CertificateRepository implements CertificateRepositoryInterface
{
    public function find(int $id): ?Certificate
    {
        return Certificate::query()->with(['course', 'user', 'enrollment'])->find($id);
    }

    public function findByReference(string $reference): ?Certificate
    {
        return Certificate::query()
            ->with(['course', 'user', 'enrollment'])
            ->where('certificate_reference', $reference)
            ->first();
    }

    public function forUser(int $userId): Collection
    {
        return Certificate::query()
            ->where('user_id', $userId)
            ->with(['course', 'enrollment'])
            ->orderByDesc('issued_at')
            ->get();
    }

    public function forEnrollment(int $enrollmentId): ?Certificate
    {
        return Certificate::query()
            ->with(['course', 'user'])
            ->where('enrollment_id', $enrollmentId)
            ->first();
    }

    public function create(array $data): Certificate
    {
        return Certificate::query()->create($data);
    }

    public function update(Certificate $certificate, array $data): Certificate
    {
        $certificate->update($data);

        return $certificate->fresh();
    }

    public function delete(Certificate $certificate): bool
    {
        return (bool) $certificate->delete();
    }
}