<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseFee;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\PaymentJournal;
use App\Models\User;
use App\Services\CertificatePdfService;
use App\Services\CertificateService;
use App\Services\Payment\PaymentReceiptService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Issues a certificate for a legacy graduate who completed outside the
 * platform (e.g. Obace Peterson, Data Science, awarded 18 Feb 2026).
 *
 *   php artisan certificate:legacy-issue --name="Obace Peterson" \
 *     --email=petersonobace@gmail.com --course=data-science-fundamentals \
 *     --awarded=2026-02-18 --rename-to="Data Science" --cert-amount=50000 \
 *     --send-to=opiyooscar414@gmail.com
 *
 * - Renames the course title (slug untouched so URLs keep working).
 * - Creates the learner account (random temp password, printed once).
 * - Records the offline UGX certificate-fee payment (manual, paid_at=awarded).
 * - Records a certification-stage enrollment silently (no journey spam).
 * - Issues via CertificateService (certificate PDF email only - no certified
 *   notice, per Registry instruction).
 * - Backdates issued_at / certified_at to --awarded and re-renders the PDF.
 * - Sends the payment receipt PDF to the same recipient.
 * - With --send-to, BOTH emails go to the test inbox and the learner
 *   gets nothing (safe preview of exactly what Peterson will receive).
 *   Without --send-to, everything goes to the learner (production run).
 */
class IssueLegacyCertificate extends Command
{
    protected $signature = 'certificate:legacy-issue
        {--name= : Learner full name}
        {--email= : Learner email address}
        {--course= : Course slug}
        {--awarded= : Award date (Y-m-d)}
        {--rename-to= : Optional new course title (slug kept)}
        {--cert-amount=50000 : Offline certificate fee paid (UGX)}
        {--send-to= : Optional test inbox - all mail goes here, learner gets nothing}
        {--password= : Optional preset password (else generated)}';

    protected $description = 'Create learner + backdated certificate for a legacy graduate';

    public function handle(
        CertificateService $certificates,
        CertificatePdfService $pdf,
        PaymentReceiptService $receipts,
    ): int {
        $name = trim((string) $this->option('name'));
        $email = strtolower(trim((string) $this->option('email')));
        $slug = trim((string) $this->option('course'));
        $awardedRaw = trim((string) $this->option('awarded'));
        $renameTo = trim((string) $this->option('rename-to'));
        $sendTo = strtolower(trim((string) $this->option('send-to')));
        $presetPassword = (string) $this->option('password');
        $certAmount = (float) $this->option('cert-amount');

        if ($name === '' || $email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Provide --name and a valid --email.');

            return self::FAILURE;
        }
        if ($slug === '') {
            $this->error('Provide --course slug (e.g. data-science-fundamentals).');

            return self::FAILURE;
        }
        if ($sendTo !== '' && ! filter_var($sendTo, FILTER_VALIDATE_EMAIL)) {
            $this->error('Invalid --send-to address.');

            return self::FAILURE;
        }
        if ($certAmount <= 0) {
            $this->error('Provide a positive --cert-amount (e.g. 50000).');

            return self::FAILURE;
        }
        try {
            $awarded = Carbon::parse($awardedRaw)->startOfDay();
        } catch (\Throwable) {
            $this->error('Provide --awarded as a valid date (Y-m-d, e.g. 2026-02-18).');

            return self::FAILURE;
        }

        $course = Course::query()->where('slug', $slug)->first();
        if ($course === null) {
            $this->error("Course slug [{$slug}] not found.");

            return self::FAILURE;
        }

        if ($renameTo !== '' && $renameTo !== $course->title) {
            $course->update(['title' => $renameTo]);
            $this->info("course renamed: [{$slug}] title -> {$renameTo} (slug kept)");
        }

        // Ensure a certificate fee row exists so the ledger has a matching
        // fee type (amount left untouched when already configured).
        $fee = CourseFee::query()
            ->where('course_id', $course->id)
            ->where('fee_type', CourseFee::FEE_CERTIFICATE)
            ->first();
        if ($fee === null) {
            $fee = CourseFee::create([
                'course_id' => $course->id,
                'fee_type' => CourseFee::FEE_CERTIFICATE,
                'amount' => $certAmount,
                'currency' => 'UGX',
                'is_required' => true,
                'description' => 'Certificate fee',
            ]);
            $this->info("certificate fee configured: UGX ".number_format($certAmount, 0));
        }

        $user = User::query()->where('email', $email)->first();
        if ($user === null) {
            $plain = $presetPassword !== '' ? $presetPassword : Str::random(12);
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($plain),
                'role' => User::ROLE_LEARNER,
                'status' => User::STATUS_ACTIVE,
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();
            $this->info("learner created: {$name} <{$email}>");
            $this->warn('TEMP PASSWORD (share securely, shown once): '.$plain);
        } else {
            $this->info("learner exists: {$user->name} <{$user->email}> (id {$user->id})");
        }

        $existing = Certificate::query()
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->first();
        if ($existing !== null) {
            $this->error("Certificate already exists for this learner+course: {$existing->certificate_reference}");

            return self::FAILURE;
        }

        $enrollment = Enrollment::query()
            ->where('course_id', $course->id)
            ->where('user_id', $user->id)
            ->first();

        if ($enrollment !== null && $enrollment->status === Enrollment::STATUS_CERTIFIED) {
            $this->error("Enrollment {$enrollment->id} already certified - refusing duplicate.");

            return self::FAILURE;
        }

        if ($enrollment === null) {
            $enrollment = Enrollment::create([
                'course_id' => $course->id,
                'user_id' => $user->id,
                'status' => Enrollment::STATUS_CERTIFICATION,
                'applied_at' => $awarded,
                'admitted_at' => $awarded,
                'completed_at' => $awarded,
                'application_review_note' => 'Legacy completion recorded by Academy Registry.',
            ]);
            $this->info("enrollment recorded at certification stage (id {$enrollment->id})");
        } else {
            $enrollment->update([
                'status' => Enrollment::STATUS_CERTIFICATION,
                'completed_at' => $enrollment->completed_at ?? $awarded,
            ]);
            $this->info("enrollment {$enrollment->id} moved to certification stage");
        }

        // Offline certificate-fee payment (cash/manual, already collected).
        // Recorded directly as paid so the receipt carries the real award
        // date and no gateway/state-machine mail fires mid-flow.
        $payment = Payment::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('fee_type', CourseFee::FEE_CERTIFICATE)
            ->where('status', Payment::STATUS_PAID)
            ->first();
        if ($payment === null) {
            $payment = Payment::create([
                'enrollment_id' => $enrollment->id,
                'user_id' => $user->id,
                'fee_type' => CourseFee::FEE_CERTIFICATE,
                'amount' => $certAmount,
                'currency' => $fee?->currency ?? 'UGX',
                'status' => Payment::STATUS_PAID,
                'method' => Payment::METHOD_MANUAL,
                'reference' => 'LEG-CERT-'.$awarded->format('Ymd').'-'.strtoupper(Str::random(4)),
                'paid_at' => $awarded,
                'meta' => ['legacy' => true, 'recorded_by' => 'Academy Registry', 'note' => 'Offline certificate fee collected before platform onboarding.'],
            ]);
            PaymentJournal::create([
                'payment_id' => $payment->id,
                'event' => PaymentJournal::EVENT_APPROVED,
                'note' => 'Legacy offline certificate fee confirmed by Academy Registry.',
                'created_by' => $user->id,
            ]);
            $this->info("certificate fee recorded: UGX ".number_format($certAmount, 0)." ({$payment->reference})");
        } else {
            $this->info("certificate fee already recorded: {$payment->reference}");
        }

        $mailTo = $sendTo !== '' ? $sendTo : null;

        /** @var Certificate $certificate */
        $certificate = $certificates->issue($enrollment->fresh(), $user, $mailTo, false, 'LEG');

        // Backdate to the real award date and re-render so the PDF matches.
        $certificate->update(['issued_at' => $awarded]);
        $enrollment->fresh()?->update(['certified_at' => $awarded]);
        $fresh = $certificate->fresh();
        if ($fresh instanceof Certificate) {
            $certificate = $fresh;
            $path = 'certificates/'.$pdf->filename($certificate).'.pdf';
            Storage::disk('local')->put($path, $pdf->renderPdf($certificate));
            $certificate->update(['pdf_path' => $path]);
        }

        $this->info("certificate issued: {$certificate->certificate_reference} awarded {$awarded->toDateString()}");

        $receiptSent = $receipts->email($payment->fresh() ?? $payment, $mailTo);
        $this->info($receiptSent
            ? 'receipt sent ('.$this->recipientLabel($mailTo, $email).')'
            : 'receipt FAILED to send - see logs');

        $this->info('mail summary: 2 emails -> '.$this->recipientLabel($mailTo, $email)
            .' | 1x certificate PDF + 1x UGX '.number_format($certAmount, 0).' receipt PDF (no certified notice)');

        return self::SUCCESS;
    }

    protected function recipientLabel(?string $mailTo, string $learnerEmail): string
    {
        return $mailTo !== null ? "all to {$mailTo} (learner {$learnerEmail} untouched)" : "all to {$learnerEmail}";
    }
}
