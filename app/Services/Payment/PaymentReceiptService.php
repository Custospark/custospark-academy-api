<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Mail\StandardEmail;
use App\Models\CourseFee;
use App\Models\Payment;
use App\Services\PdfService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Builds, emails and downloads branded PDF receipts for Academy payments.
 * Receipts are generated deterministically from the payment record so a
 * historical payment always renders what the learner actually paid. Sending
 * is guarded against duplicates and zero-amount (waived) payments.
 */
class PaymentReceiptService
{
    public const RECEIPT_VIEW = 'payments.receipt';

    public function __construct(
        protected PdfService $pdf,
    ) {}

    public function invoiceNumber(Payment $payment): string
    {
        return 'INV-'.$payment->id.'-'.($payment->paid_at?->year ?? now()->year);
    }

    public function filename(Payment $payment): string
    {
        $ref = $payment->reference !== null && $payment->reference !== ''
            ? $payment->reference
            : $payment->id;

        return $this->pdf->filename('academy-receipt', $this->invoiceNumber($payment), (string) $ref);
    }

    /** @return array<string, mixed> */
    public function buildData(Payment $payment): array
    {
        $enrollment = $payment->enrollment;

        return [
            'company' => $this->companyBrand(),
            'logoDataUri' => $this->pdf->logoDataUri(),
            'payment' => $payment,
            'student' => $payment->user,
            'courseTitle' => $enrollment?->course?->title ?? 'Enrollment',
            'feeLabel' => $this->feeLabel($payment->fee_type),
            'feeType' => $payment->fee_type,
            'invoiceNumber' => $this->invoiceNumber($payment),
            'amount' => (float) $payment->amount,
            'currency' => strtoupper((string) $payment->currency),
            'reference' => $payment->reference,
            'paidAt' => $payment->paid_at,
            'method' => $payment->method,
        ];
    }

    public function renderPdf(Payment $payment): string
    {
        return $this->pdf->render(self::RECEIPT_VIEW, $this->buildData($payment));
    }

    /**
     * Email the receipt PDF to the payer. Returns false when there is no
     * recipient or the send failed (the payment flow must never break on a
     * receipt delivery problem - failures are logged and swallowed).
     */
    public function email(Payment $payment, ?string $to = null): bool
    {
        $receiverEmail = $to ?? $payment->user?->email;
        if ($receiverEmail === null || $receiverEmail === '') {
            Log::warning('[PaymentReceiptService] No recipient email for receipt', [
                'payment_id' => $payment->id,
            ]);

            return false;
        }

        try {
            $amount = number_format((float) $payment->amount, 0, '.', ',').' '.strtoupper((string) $payment->currency);

            Mail::to($receiverEmail)->send(new StandardEmail(
                title: 'Payment receipt - '.$this->invoiceNumber($payment),
                mailBody: sprintf(
                    'Hello%s,<br><br>Your payment of <strong>%s</strong> for the <strong>%s</strong> %s on %s has been received. Your receipt is attached.<br><br>Thank you for learning with Custospark Academy.',
                    $payment->user?->name !== null ? ' '.$payment->user->name : '',
                    $amount,
                    $this->courseTitle($payment),
                    strtolower($this->feeLabel($payment->fee_type)),
                    $payment->paid_at?->format('j F Y') ?? 'today',
                ),
                isHtml: true,
                fileAttachments: [
                    ['data' => $this->renderPdf($payment), 'name' => $this->filename($payment).'.pdf', 'mime' => 'application/pdf'],
                ],
            ));

            return true;
        } catch (\Throwable $e) {
            Log::error('[PaymentReceiptService] Receipt email failed', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Send a receipt once for a paid, non-zero payment. Guards duplicate sends
     * via the payment meta (no schema change needed). Returns true when sent.
     */
    public function sendReceiptIfDue(Payment $payment): bool
    {
        if (! $payment->isPaid()) {
            return false;
        }

        if ((float) $payment->amount <= 0) {
            return false;
        }

        $meta = $payment->meta ?? [];
        if (! empty($meta['receipt_sent_at'])) {
            return false;
        }

        $sent = $this->email($payment);

        if ($sent) {
            $payment->meta = array_merge($meta, [
                'invoice_number' => $this->invoiceNumber($payment),
                'receipt_sent_at' => now()->toISOString(),
            ]);
            $payment->save();
        }

        return $sent;
    }

    protected function courseTitle(Payment $payment): string
    {
        return $payment->enrollment?->course?->title ?? 'your enrollment';
    }

    protected function feeLabel(string $feeType): string
    {
        return match ($feeType) {
            CourseFee::FEE_APPLICATION => 'application fee',
            CourseFee::FEE_TUITION => 'tuition fee',
            CourseFee::FEE_CERTIFICATE => 'certificate fee',
            default => 'fee',
        };
    }

    /** @return array<string, string|null> */
    protected function companyBrand(): array
    {
        return [
            'name' => config('brand.company_name', 'Custospark Company Ltd'),
            'address' => null,
            'city' => config('brand.company_city', 'Kampala'),
            'country' => config('brand.company_country', 'Uganda'),
            'phone' => null,
            'email' => config('brand.company_email', 'info@custospark.com'),
            'website' => config('brand.url', 'https://www.custospark.com'),
        ];
    }
}