<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Certificate;
use App\Models\Course;
use App\Services\Qr\QrCodeService;

/**
 * Renders professional course certificates (landscape A4) to PDF bytes via
 * the shared PdfService. Deterministic per certificate, so previews and
 * downloads are always in sync with the issued record.
 */
class CertificatePdfService
{
    public function __construct(
        protected PdfService $pdf,
        protected QrCodeService $qr,
    ) {}

    /** @return array<string, mixed> */
    public function buildData(Certificate $certificate): array
    {
        return [
            'certificate' => $certificate,
            'student' => $certificate->user,
            'course' => $certificate->course,
            'courseTitle' => $certificate->course?->title,
            'courseLevel' => $certificate->course?->level,
            'deliveryMode' => $certificate->course?->delivery_mode,
            'reference' => $certificate->certificate_reference,
            'issuedAt' => $certificate->issued_at,
            'verifyUrl' => $this->verifyUrl($certificate),
            'logoDataUri' => $this->pdf->roundedLogoDataUri(),
            'qrDataUri' => $this->qr->dataUri(
                $this->verifyUrl($certificate),
                'qrm',
                10,
            ),
        ];
    }

    /**
     * Public verification URL customers scan to confirm authenticity. The QR
     * references the certificate reference (guaranteed unique). The base is
     * configurable per environment (CERTIFICATE_VERIFY_URL) so it can point at
     * either the documentation verify page or the SPA, and defaults to the
     * backend application URL.
     */
    public function verifyUrl(Certificate $certificate): string
    {
        $base = rtrim((string) config('app.certificate_verify_url'), '/');
        if ($base === '' || $base === '0') {
            $base = rtrim((string) config('app.url'), '/') ?: 'https://academy-api.custospark.com';
        }

        return $base.'/verify/'.$certificate->certificate_reference;
    }

    public function renderPdf(Certificate $certificate): string
    {
        return $this->pdf->render('certificates.certificate', $this->buildData($certificate), 'a4', 'landscape');
    }

    /**
     * Watermarked SAMPLE of the certificate design for a course. This is not a
     * certificate: it takes a Course (never a Certificate), uses placeholder
     * learner data, has no award date, no registry reference and no QR code,
     * and the sheet is covered by a tiled diagonal "PREVIEW" watermark so no
     * crop of it can pass as a real document.
     */
    public function renderPreviewPdf(Course $course): string
    {
        return $this->pdf->render('certificates.certificate', [
            'isPreview' => true,
            'certificate' => null,
            'student' => (object) ['name' => 'Sample Learner'],
            'course' => $course,
            'courseTitle' => $course->title,
            'courseLevel' => $course->level,
            'deliveryMode' => $course->delivery_mode,
            'reference' => 'PREVIEW-SAMPLE',
            'issuedAt' => null,
            'verifyUrl' => null,
            'logoDataUri' => $this->pdf->roundedLogoDataUri(),
            'qrDataUri' => null,
        ], 'a4', 'landscape');
    }

    public function filename(Certificate $certificate): string
    {
        return $this->pdf->filename('certificate', $certificate->certificate_reference);
    }
}