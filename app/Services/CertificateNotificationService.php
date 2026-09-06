<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\StandardEmail;
use App\Models\Certificate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Emails a freshly issued certificate to the learner, exactly as they will
 * receive it in production. Delivery is guarded and failures are swallowed so
 * certificate issuance is never blocked by a mail problem.
 */
class CertificateNotificationService
{
    public function __construct(
        protected CertificatePdfService $certificatePdf,
    ) {}

    /**
     * Email the certificate PDF to the recipient (defaults to the learner).
     * Returns true when dispatched to the transport.
     */
    public function email(Certificate $certificate, ?string $to = null): bool
    {
        $receiverEmail = $to ?? $certificate->user?->email;
        if ($receiverEmail === null || $receiverEmail === '') {
            Log::warning('[CertificateNotificationService] No recipient email for certificate', [
                'certificate_id' => $certificate->id,
            ]);

            return false;
        }

        try {
            Mail::to($receiverEmail)->send(new StandardEmail(
                title: 'Your Certificate of Completion - '.$this->courseTitle($certificate),
                mailBody: sprintf(
                    'Congratulations%s,<br><br>You have successfully completed <strong>%s</strong>, and we are proud to award you an official Custospark Academy Certificate of Completion.<br><br>Your certificate is attached to this email. It carries a unique reference (<strong>%s</strong>) and a QR code that verifies its authenticity online - anyone can confirm it at %s.<br><br>Share it proudly, add it to your portfolio or LinkedIn, and show the world what you have accomplished.',
                    $certificate->user?->name !== null ? ' '.$certificate->user->name : '',
                    $this->courseTitle($certificate),
                    $certificate->certificate_reference,
                    $this->certificatePdf->verifyUrl($certificate),
                ),
                ctaUrl: $this->certificatePdf->verifyUrl($certificate),
                ctaLabel: 'View & verify certificate',
                tip: 'Tip: scan the QR code on your certificate from any smartphone to verify it instantly online.',
                isHtml: true,
                fileAttachments: [
                    [
                        'data' => $this->certificatePdf->renderPdf($certificate),
                        'name' => $this->certificatePdf->filename($certificate).'.pdf',
                        'mime' => 'application/pdf',
                    ],
                ],
            ));

            return true;
        } catch (\Throwable $e) {
            Log::error('[CertificateNotificationService] Certificate email failed', [
                'certificate_id' => $certificate->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    protected function courseTitle(Certificate $certificate): string
    {
        return $certificate->course?->title ?? 'your course';
    }
}