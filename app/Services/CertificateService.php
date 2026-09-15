<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use App\Repositories\Contracts\CertificateRepositoryInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CertificateService
{
    public function __construct(
        protected CertificateRepositoryInterface $certificates,
        protected EnrollmentStateMachineService $stateMachine,
        protected CertificatePdfService $certificatePdf,
        protected CertificateNotificationService $certificateNotifications,
        protected EnrollmentNotificationService $notify,
    ) {}

    /**
     * Issue a certificate for a completed, certification-stage enrollment and
     * generate its professional PDF (stored as pdf_path on the certificate).
     * When $mailTo is set, the certificate PDF email goes to that address
     * instead of the learner (local test previews). When $notifyCertified is
     * false, the standalone certified notice is skipped (legacy issues where
     * the learner only receives the certificate + receipt). When
     * $referenceTag is set (e.g. 'LEG'), it replaces the user part of the
     * reference so Registry can spot legacy certificates at a glance.
     */
    public function issue(Enrollment $enrollment, User $user, ?string $mailTo = null, bool $notifyCertified = true, ?string $referenceTag = null): Certificate
    {
        if ($enrollment->status !== Enrollment::STATUS_CERTIFICATION) {
            throw new DomainException('Certificate fee must be paid and course completed before issuance.');
        }

        if ($this->certificates->forEnrollment((int) $enrollment->id) !== null) {
            throw new DomainException('A certificate already exists for this enrollment.');
        }

        $enrollment = $this->stateMachine->certify($enrollment);
        $course = $enrollment->course;

        $certificate = $this->certificates->create([
            'enrollment_id' => $enrollment->id,
            'user_id' => $user->id,
            'course_id' => $course->id,
            'certificate_reference' => $this->generateReference($course, $user, $referenceTag),
            'issued_at' => now(),
        ]);
        $this->notify->certified($enrollment->fresh() ?? $enrollment, $certificate->certificate_reference, $mailTo, $notifyCertified);

        try {
            $path = 'certificates/'.$this->certificatePdf->filename($certificate).'.pdf';
            Storage::disk('local')->put($path, $this->certificatePdf->renderPdf($certificate));
            $certificate = $this->certificates->update($certificate, ['pdf_path' => $path]);
        } catch (\Throwable $e) {
            Log::error('[CertificateService] PDF generation failed - certificate still issued', [
                'certificate_id' => $certificate->id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->certificateNotifications->email($certificate, $mailTo);

        return $certificate;
    }

    /**
     * Read the stored certificate PDF, re-rendering from the record if the
     * file is missing (deterministic - preview, download stay in sync).
     */
    public function pdfBytes(Certificate $certificate): string
    {
        $path = $certificate->pdf_path;

        if ($path !== null && $path !== '' && ($bytes = Storage::disk('local')->get($path)) !== false) {
            return (string) $bytes;
        }

        return $this->certificatePdf->renderPdf($certificate);
    }

    public function filename(Certificate $certificate): string
    {
        return $this->certificatePdf->filename($certificate);
    }

    public function forUser(int $userId): array
    {
        return $this->certificates->forUser($userId)->all();
    }

    public function find(int $certificateId): ?Certificate
    {
        return $this->certificates->find($certificateId);
    }

    public function findByReference(string $reference): ?Certificate
    {
        return $this->certificates->findByReference($reference);
    }

    /** @return array<string, mixed> */
    public function publicSummary(Certificate $certificate): array
    {
        return [
            'valid' => true,
            'certificate_reference' => $certificate->certificate_reference,
            'learner_name' => $certificate->user?->name,
            'course_title' => $certificate->course?->title,
            'issued_at' => $certificate->issued_at?->toIso8601String(),
            'issuer' => 'Custospark Academy',
            'institution' => 'An Institution of Custospark Company Ltd',
            'verify_pdf_url' => route('certificates.verify.pdf', $certificate->certificate_reference),
        ];
    }

    protected function generateReference(Course $course, User $user, ?string $tag = null): string
    {
        $coursePart = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $course->slug), 0, 4) ?: 'CRS');
        $midPart = $tag !== null && $tag !== ''
            ? strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $tag), 0, 4))
            : strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', (string) $user->id), 0, 4));
        $suffix = strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));

        return "CSA-{$coursePart}-{$midPart}-{$suffix}";
    }
}