<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Payment\Contracts\PaymentGatewayInterface;
use App\Services\Payment\PaymentReceiptService;
use App\Services\PaymentService;
use App\Services\EnrollmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function __construct(
        protected PaymentService $payments,
        protected EnrollmentService $enrollments,
        protected PaymentGatewayInterface $gateway,
        protected PaymentReceiptService $receipts,
    ) {}

    /** The authenticated user's payment history with receipts. */
    public function index(Request $request): JsonResponse
    {
        $items = $this->payments->forUser((int) $request->user()->id);

        return response()->json([
            'data' => array_map(fn (Payment $payment) => $this->serializePayment($payment), $items),
        ]);
    }

    /** Stream a branded PDF receipt for a payment the user owns. */
    public function receipt(Request $request, int $paymentId): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $payment = $this->payments->find($paymentId);
        if ($payment === null) {
            abort(404, 'Payment not found.');
        }
        if ((int) $payment->user_id !== (int) $request->user()->id && ! $request->user()->isAdmin()) {
            abort(403, 'You cannot view this receipt.');
        }

        $content = $this->receipts->renderPdf($payment);

        return response()->streamDownload(
            function () use ($content): void {
                echo $content;
            },
            $this->receipts->filename($payment).'.pdf',
            ['Content-Type' => 'application/pdf'],
        );
    }

    public function initiate(Request $request, int $enrollmentId, string $feeType): JsonResponse
    {
        $feeType = $this->validateFeeType($feeType);
        $user = $request->user();
        $enrollment = $this->enrollments->getEnrollment($enrollmentId);
        if ($enrollment === null) {
            abort(404, 'Enrollment not found.');
        }
        if ((int) $enrollment->user_id !== (int) $user->id && ! $user->isAdmin()) {
            abort(403, 'You cannot pay for this enrollment.');
        }

        $result = $this->payments->payFee($enrollment, $user, $feeType);

        // No fee configured or sponsored (amount 0): state already advanced.
        if ($result['auto_advanced']) {
            $enrollment->refresh();

            return response()->json([
                'data' => [
                    'payment' => null,
                    'enrollment' => $this->serializeEnrollment($enrollment),
                    'redirect_url' => null,
                    'type' => 'auto_advanced',
                    'message' => 'No fee required - enrollment advanced.',
                ],
            ]);
        }

        $payment = $result['payment'];

        $gatewayResult = $this->gateway->initiate([
            'payment_id' => $payment->id,
            'amount' => (float) $payment->amount,
            'currency' => $payment->currency,
            'description' => "{$enrollment->course?->title} - {$feeType} fee",
            'email' => $user->email,
            'phone_number' => $user->phone ?? '',
            'customer_name' => $user->name,
            'country_code' => 'UG',
        ]);

        if (isset($gatewayResult['gateway_ref']) && $gatewayResult['gateway_ref']) {
            $this->payments->updateReference($payment, $gatewayResult['gateway_ref']);
        }

        if (($gatewayResult['type'] ?? null) === 'bypass') {
            $payment = $this->payments->markPaid(
                $payment,
                $gatewayResult['gateway_ref'] ?? null,
                $gatewayResult['raw_response'] ?? [],
            );
            $enrollment->refresh();
        }

        return response()->json([
            'data' => [
                'payment' => $this->serializePayment($payment),
                'enrollment' => $this->serializeEnrollment($enrollment),
                'redirect_url' => $gatewayResult['redirect_url'] ?? null,
                'type' => $gatewayResult['type'] ?? 'redirect',
                'message' => $gatewayResult['message'] ?? null,
            ],
        ]);
    }

    public function callback(Request $request): JsonResponse
    {
        $payload = $this->gateway->parseWebhookPayload($request);
        $trackingId = (string) $payload['gateway_txn_id'];
        $merchantRef = (string) $payload['our_reference'];

        if ($trackingId === '') {
            abort(422, 'Missing order tracking id.');
        }

        $result = $this->gateway->verify($trackingId);

        // Find the payment by gateway ref (CSA-<payment_id>-...) and mark it.
        $payment = null;
        $id = $this->extractPaymentId($merchantRef);
        if ($id !== null) {
            $payment = $this->payments->find($id);
        }

        if ($payment === null && $result['gateway_txn_id']) {
            $payment = $this->payments->findByGatewayTxn($result['gateway_txn_id']);
        }

        if ($payment === null) {
            abort(404, 'Payment not found for this transaction.');
        }

        if ($result['success']) {
            $this->payments->markPaid($payment, $trackingId, $result['raw_response']);
        } elseif ($result['status'] === 'failed') {
            $this->payments->markFailed($payment, $result['message']);
        }

        return response()->json([
            'data' => [
                'payment' => $this->serializePayment($payment->fresh()),
                'status' => $payment->fresh()->status,
            ],
        ]);
    }

    public function verify(Request $request, int $paymentId): JsonResponse
    {
        $payment = $this->payments->find($paymentId);
        if ($payment === null) {
            abort(404, 'Payment not found.');
        }
        if ((int) $payment->user_id !== (int) $request->user()->id && ! $request->user()->isAdmin()) {
            abort(403, 'You cannot view this payment.');
        }

        // While a payment is still in flight, pull the fresh status from the
        // gateway so polling/manual "check status" reflects reality, not the
        // last snapshot. markPaid advances the enrollment when it succeeds.
        if (in_array($payment->status, [Payment::STATUS_PENDING, Payment::STATUS_PROCESSING], true)
            && $payment->reference !== null && $payment->reference !== '') {
            try {
                $result = $this->gateway->verify($payment->reference);
                if ($result['success']) {
                    $payment = $this->payments->markPaid(
                        $payment,
                        $result['gateway_txn_id'] ?? $payment->reference,
                        $result['raw_response'] ?? [],
                    );
                } elseif (($result['status'] ?? null) === 'failed') {
                    $payment = $this->payments->markFailed($payment, $result['message'] ?? 'Payment failed.');
                }
            } catch (\Throwable $e) {
                Log::error('[PaymentController] Gateway verify failed', [
                    'payment_id' => $paymentId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json(['data' => $this->serializePayment($payment)]);
    }

    private function validateFeeType(string $feeType): string
    {
        if (! in_array($feeType, ['application', 'tuition', 'certificate'], true)) {
            abort(422, 'Invalid fee type.');
        }

        return $feeType;
    }

    private function extractPaymentId(string $merchantRef): ?int
    {
        // CSA-<payment_id>-<timestamp>
        if (preg_match('/^CSA-(\d+)-/', $merchantRef, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    private function serializePayment(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'enrollment_id' => $payment->enrollment_id,
            'course_title' => $payment->enrollment?->course?->title,
            'fee_type' => $payment->fee_type,
            'amount' => (float) $payment->amount,
            'currency' => $payment->currency,
            'status' => $payment->status,
            'method' => $payment->method,
            'reference' => $payment->reference,
            'invoice_number' => $this->receipts->invoiceNumber($payment),
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'created_at' => $payment->created_at?->toIso8601String(),
            'receipt_url' => $payment->isPaid() && (float) $payment->amount > 0
                ? "/api/v1/payments/{$payment->id}/receipt"
                : null,
        ];
    }

    private function serializeEnrollment(\App\Models\Enrollment $enrollment): array
    {
        return [
            'id' => $enrollment->id,
            'course_id' => $enrollment->course_id,
            'status' => $enrollment->status,
            'applied_at' => $enrollment->applied_at?->toIso8601String(),
            'admitted_at' => $enrollment->admitted_at?->toIso8601String(),
        ];
    }
}