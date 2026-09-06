<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\PdfService;
use App\Services\Qr\QrCodeService;
use App\Models\Certificate;

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

    public function filename(Certificate $certificate): string
    {
        return $this->pdf->filename('certificate', $certificate->certificate_reference);
    }
}