<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Certificate;
use Illuminate\Database\Eloquent\Collection;

interface CertificateRepositoryInterface
{
    public function find(int $id): ?Certificate;

    public function findByReference(string $reference): ?Certificate;

    public function forUser(int $userId): Collection;

    public function forEnrollment(int $enrollmentId): ?Certificate;

    public function create(array $data): Certificate;

    public function update(Certificate $certificate, array $data): Certificate;

    public function delete(Certificate $certificate): bool;
}