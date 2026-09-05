<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Payment\Contracts\PaymentGatewayInterface;
use App\Services\PaymentService;
use App\Services\EnrollmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(
        protected PaymentService $payments,
        protected EnrollmentService $enrollments,
        protected PaymentGatewayInterface $gateway,
    ) {}

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

        $payment = $this->payments->createForFee($enrollment, $user, $feeType);

        $result = $this->gateway->initiate([
            'payment_id' => $payment->id,
            'amount' => (float) $payment->amount,
            'currency' => $payment->currency,
            'description' => "{$enrollment->course?->title} - {$feeType} fee",
            'email' => $user->email,
            'phone_number' => $user->phone ?? '',
            'customer_name' => $user->name,
            'country_code' => 'UG',
        ]);

        if (isset($result['gateway_ref']) && $result['gateway_ref']) {
            $this->payments->updateReference($payment, $result['gateway_ref']);
        }

        if (($result['type'] ?? null) === 'bypass') {
            $payment = $this->payments->markPaid(
                $payment,
                $result['gateway_ref'] ?? null,
                $result['raw_response'] ?? [],
            );
        }

        return response()->json([
            'data' => [
                'payment' => $this->serializePayment($payment),
                'redirect_url' => $result['redirect_url'] ?? null,
                'type' => $result['type'] ?? 'redirect',
                'message' => $result['message'] ?? null,
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
            'fee_type' => $payment->fee_type,
            'amount' => (float) $payment->amount,
            'currency' => $payment->currency,
            'status' => $payment->status,
            'method' => $payment->method,
            'reference' => $payment->reference,
            'paid_at' => $payment->paid_at?->toIso8601String(),
        ];
    }
}