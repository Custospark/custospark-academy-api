<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use App\Repositories\Contracts\CertificateRepositoryInterface;

class CertificateService
{
    public function __construct(
        protected CertificateRepositoryInterface $certificates,
        protected EnrollmentStateMachineService $stateMachine,
    ) {}

    /**
     * Issue a certificate for a completed, certification-stage enrollment.
     */
    public function issue(Enrollment $enrollment, User $user): Certificate
    {
        if ($enrollment->status !== Enrollment::STATUS_CERTIFICATION) {
            throw new DomainException('Certificate fee must be paid and course completed before issuance.');
        }

        if ($this->certificates->forEnrollment((int) $enrollment->id) !== null) {
            throw new DomainException('A certificate already exists for this enrollment.');
        }

        $enrollment = $this->stateMachine->certify($enrollment);
        $course = $enrollment->course;

        return $this->certificates->create([
            'enrollment_id' => $enrollment->id,
            'user_id' => $user->id,
            'course_id' => $course->id,
            'certificate_reference' => $this->generateReference($course, $user),
            'issued_at' => now(),
        ]);
    }

    public function forUser(int $userId): array
    {
        return $this->certificates->forUser($userId)->all();
    }

    public function find(int $certificateId): ?Certificate
    {
        return $this->certificates->find($certificateId);
    }

    protected function generateReference(Course $course, User $user): string
    {
        $coursePart = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $course->slug), 0, 4) ?: 'CRS');
        $userPart = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', (string) $user->id), 0, 4));
        $suffix = strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));

        return "CSA-{$coursePart}-{$userPart}-{$suffix}";
    }
}