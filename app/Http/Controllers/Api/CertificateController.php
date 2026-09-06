<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Services\CertificateService;
use App\Services\EnrollmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CertificateController extends Controller
{
    public function __construct(
        protected CertificateService $certificates,
        protected EnrollmentService $enrollments,
    ) {}

    public function mine(Request $request): JsonResponse
    {
        return response()->json([
            'data' => array_map(
                fn ($certificate) => $this->serialize($certificate),
                $this->certificates->forUser((int) $request->user()->id),
            ),
        ]);
    }

    /**
     * Public registry page - no authentication. Anyone with the reference can
     * confirm a certificate comes from the academy registry. Served by the
     * backend so verification always works, independent of the apps.
     */
    public function verifyPublic(Request $request, string $reference): \Illuminate\Contracts\View\View
    {
        $certificate = $this->certificates->findByReference($reference);

        return view('certificates.verify', [
            'certificate' => $certificate,
            'learner' => $certificate?->user,
            'course' => $certificate?->course,
            'courseTitle' => $certificate?->course?->title,
            'reference' => $reference,
            'issuedAt' => $certificate?->issued_at,
            'valid' => $certificate !== null,
            'logoDataUri' => app(\App\Services\PdfService::class)->logoDataUri(),
            'verifyUrl' => route('certificates.verify', $reference),
            'checkedAt' => now(),
        ]);
    }

    /** Public PDF of a certificate (registry expose) - no authentication. */
    public function publicPdf(Request $request, string $reference): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $certificate = $this->certificates->findByReference($reference);
        if ($certificate === null) {
            abort(404, 'Certificate not found.');
        }

        $bytes = $this->certificates->pdfBytes($certificate);

        return response()->stream(
            function () use ($bytes): void {
                echo $bytes;
            },
            200,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.$this->certificates->filename($certificate).'.pdf"',
            ],
        );
    }

    /** Public JSON summary - no authentication (for the SPA later). */
    public function verifyPublicJson(Request $request, string $reference): JsonResponse
    {
        $certificate = $this->certificates->findByReference($reference);

        if ($certificate === null) {
            return response()->json([
                'data' => ['valid' => false, 'certificate_reference' => $reference],
            ], 404);
        }

        return response()->json(['data' => $this->certificates->publicSummary($certificate)]);
    }

    public function issue(Request $request, int $enrollmentId): JsonResponse
    {
        $enrollment = $this->enrollments->getEnrollment($enrollmentId);
        if ($enrollment === null) {
            abort(404, 'Enrollment not found.');
        }

        if ((int) $enrollment->user_id !== (int) $request->user()->id && ! $request->user()->isAdmin()) {
            abort(403, 'You cannot issue a certificate for this enrollment.');
        }

        $certificate = $this->certificates->issue($enrollment, $request->user());

        return response()->json(['data' => $this->serialize($certificate)], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $certificate = $this->certificates->find((int) $id);
        $this->authorize($request, $certificate);

        return response()->json(['data' => $this->serialize($certificate)]);
    }

    /** Preview the certificate PDF inline (owner/admin). */
    public function pdf(Request $request, int $id): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $certificate = $this->certificates->find((int) $id);
        $this->authorize($request, $certificate);

        $bytes = $this->certificates->pdfBytes($certificate);

        return response()->streamDownload(
            function () use ($bytes): void {
                echo $bytes;
            },
            $this->certificates->filename($certificate).'.pdf',
            ['Content-Type' => 'application/pdf'],
        );
    }

    /** Download the certificate PDF (owner/admin). */
    public function download(Request $request, int $id): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $certificate = $this->certificates->find((int) $id);
        $this->authorize($request, $certificate);

        $bytes = $this->certificates->pdfBytes($certificate);

        return response()->streamDownload(
            function () use ($bytes): void {
                echo $bytes;
            },
            $this->certificates->filename($certificate).'.pdf',
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$this->certificates->filename($certificate).'.pdf"',
            ],
        );
    }

    private function authorize(Request $request, ?Certificate $certificate): void
    {
        if ($certificate === null) {
            abort(404, 'Certificate not found.');
        }

        if ((int) $certificate->user_id !== (int) $request->user()->id && ! $request->user()->isAdmin()) {
            abort(403, 'You cannot view this certificate.');
        }
    }

    private function serialize($certificate): array
    {
        return [
            'id' => $certificate->id,
            'enrollment_id' => $certificate->enrollment_id,
            'course_title' => $certificate->course?->title,
            'user_name' => $certificate->user?->name,
            'certificate_reference' => $certificate->certificate_reference,
            'issued_at' => $certificate->issued_at?->toIso8601String(),
            'pdf_path' => $certificate->pdf_path,
            'pdf_url' => "/api/v1/certificates/{$certificate->id}/pdf",
            'download_url' => "/api/v1/certificates/{$certificate->id}/download",
        ];
    }
}