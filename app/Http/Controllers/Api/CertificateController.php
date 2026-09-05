<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
        return response()->json(['data' => $this->certificates->forUser((int) $request->user()->id)]);
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
        if ($certificate === null) {
            abort(404, 'Certificate not found.');
        }

        if ((int) $certificate->user_id !== (int) $request->user()->id && ! $request->user()->isAdmin()) {
            abort(403, 'You cannot view this certificate.');
        }

        return response()->json(['data' => $this->serialize($certificate)]);
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
        ];
    }
}