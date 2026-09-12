<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\StandardEmail;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Personalized professional emails for every learner journey step, from
 * registration through each enrollment status to certification. Delivery must
 * never break the underlying flow, so every send is guarded and failures are
 * logged, never thrown.
 */
class EnrollmentNotificationService
{
    protected function firstName(User $user): string
    {
        $parts = preg_split('/\s+/', trim($user->name ?? ''));
        $first = is_array($parts) && $parts !== [] ? $parts[0] : '';

        return $first !== '' ? $first : 'there';
    }

    protected function courseUrl(Enrollment $enrollment, string $path = ''): string
    {
        $base = rtrim((string) config('app.frontend_url'), '/');

        return $base.'/catalog/'.($enrollment->course?->slug ?? '').$path;
    }

    protected function send(User $user, string $subject, string $body, ?string $ctaUrl = null, ?string $ctaLabel = null): void
    {
        if ($user->email === null || $user->email === '') {
            return;
        }

        try {
            Mail::to($user->email)->send(new StandardEmail(
                title: $subject,
                mailBody: $body,
                ctaUrl: $ctaUrl,
                ctaLabel: $ctaLabel,
                signature: StandardEmail::OSCAR_SIGNATURE,
            ));
        } catch (\Throwable $e) {
            Log::warning('[EnrollmentNotification] Email delivery failed', [
                'user_id' => $user->id,
                'subject' => $subject,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function learnerOf(Enrollment $enrollment): ?User
    {
        return $enrollment->user ?? User::query()->find($enrollment->user_id);
    }

    /** Actual configured fee amount for a course (null when not configured). */
    protected function feeAmount(Enrollment $enrollment, string $feeType): ?float
    {
        $fee = \App\Models\CourseFee::query()
            ->where('course_id', $enrollment->course_id)
            ->where('fee_type', $feeType)
            ->first();

        return $fee === null ? null : (float) $fee->amount;
    }

    protected function money(?float $amount): string
    {
        return $amount === null ? '' : 'UGX '.number_format($amount, 0);
    }

    protected function portal(): string
    {
        return rtrim((string) config('app.frontend_url'), '/');
    }

    public function welcome(User $user): void
    {
        $first = $this->firstName($user);
        $portal = $this->portal();
        $this->send(
            $user,
            'Welcome to Custospark Academy',
            "Hi {$first},<br><br>Your Custospark Academy account is ready - here is how to get learning in minutes:<br><br>"
            ."1. <strong>Pick your course</strong> - browse Data Science, Machine Learning, Mobile and Web Development on the portal.<br>"
            ."2. <strong>Apply in one click</strong> - your application is recorded instantly.<br>"
            ."3. <strong>Secure your place</strong> - clear the application fee to unlock the course materials.<br><br>"
            ."No credit card required to start. Your journey from learner to certified starts here:<br>"
            ."Explore courses: <a href=\"{$portal}/catalog\">{$portal}/catalog</a><br>"
            ."Your courses: <a href=\"{$portal}/my-courses\">{$portal}/my-courses</a>",
            "{$portal}/catalog",
            'Browse courses',
        );
    }

    public function applicationReceived(Enrollment $enrollment): void
    {
        $user = $this->learnerOf($enrollment);
        if ($user === null) {
            return;
        }
        $first = $this->firstName($user);
        $course = $enrollment->course?->title ?? 'your course';
        $fee = $this->feeAmount($enrollment, \App\Models\CourseFee::FEE_APPLICATION);
        $feeLine = ($fee === null || $fee <= 0)
            ? 'There is <strong>no application fee</strong> on this course - you are headed straight to admission.'
            : "Clear the <strong>{$this->money($fee)} application fee</strong> to unlock the course materials and secure your place - tuition is fully sponsored.";
        $this->send(
            $user,
            "Application received - {$course}",
            "Hi {$first},<br><br>We have received your application for <strong>{$course}</strong>. Next step: {$feeLine}",
            $this->courseUrl($enrollment),
            'Continue application',
        );
    }

    public function applicationFeePaid(Enrollment $enrollment): void
    {
        $user = $this->learnerOf($enrollment);
        if ($user === null) {
            return;
        }
        $first = $this->firstName($user);
        $course = $enrollment->course?->title ?? 'your course';
        $this->send(
            $user,
            "Application fee confirmed - {$course}",
            "Hi {$first},<br><br>Your application fee for <strong>{$course}</strong> is confirmed. Our team is reviewing your application and you will hear from us shortly.",
        );
    }

    public function admitted(Enrollment $enrollment, ?string $note = null): void
    {
        $user = $this->learnerOf($enrollment);
        if ($user === null) {
            return;
        }
        $first = $this->firstName($user);
        $course = $enrollment->course?->title ?? 'your course';
        $extra = $note !== null && $note !== '' ? "<br><br>A note from admissions: {$note}" : '';
        $this->send(
            $user,
            "Admitted - {$course}",
            "Hi {$first},<br><br>Congratulations! You have been <strong>admitted</strong> to {$course}. Tuition is sponsored - proceed whenever you are ready to start learning.{$extra}",
            $this->courseUrl($enrollment),
            'View your course',
        );
    }

    public function tuitionPaid(Enrollment $enrollment): void
    {
        $user = $this->learnerOf($enrollment);
        if ($user === null) {
            return;
        }
        $first = $this->firstName($user);
        $course = $enrollment->course?->title ?? 'your course';
        $this->send(
            $user,
            "Tuition settled - start learning {$course}",
            "Hi {$first},<br><br>Your tuition for <strong>{$course}</strong> is settled. Dive into your lessons, join the live sessions and track your progress from day one.",
            $this->courseUrl($enrollment),
            'Start learning',
        );
    }

    public function started(Enrollment $enrollment): void
    {
        $user = $this->learnerOf($enrollment);
        if ($user === null) {
            return;
        }
        $first = $this->firstName($user);
        $course = $enrollment->course?->title ?? 'your course';
        $this->send(
            $user,
            "Learning underway - {$course}",
            "Hi {$first},<br><br>You are now <strong>in progress</strong> on {$course}. Keep the streak going - complete every lesson and assessment to finish strong.",
            $this->courseUrl($enrollment),
            'Continue learning',
        );
    }

    public function completed(Enrollment $enrollment): void
    {
        $user = $this->learnerOf($enrollment);
        if ($user === null) {
            return;
        }
        $first = $this->firstName($user);
        $course = $enrollment->course?->title ?? 'your course';
        $fee = $this->feeAmount($enrollment, \App\Models\CourseFee::FEE_CERTIFICATE);
        $feeLine = ($fee === null || $fee <= 0)
            ? 'Your certificate is on the house - claim your QR-verified certificate below.'
            : "Settle the <strong>{$this->money($fee)} certificate fee</strong> to claim your QR-verified certificate.";
        $this->send(
            $user,
            "Course completed - claim your certificate",
            "Hi {$first},<br><br>You did it - <strong>{$course}</strong> is complete. {$feeLine}",
            rtrim((string) config('app.frontend_url'), '/').'/my-courses',
            'Claim certificate',
        );
    }

    public function certificationReady(Enrollment $enrollment): void
    {
        $user = $this->learnerOf($enrollment);
        if ($user === null) {
            return;
        }
        $first = $this->firstName($user);
        $course = $enrollment->course?->title ?? 'your course';
        $this->send(
            $user,
            "Your certificate is ready - {$course}",
            "Hi {$first},<br><br>Everything is settled for <strong>{$course}</strong>. Your verified certificate is ready to claim and share with employers.",
            rtrim((string) config('app.frontend_url'), '/').'/certificates',
            'View certificates',
        );
    }

    public function certified(Enrollment $enrollment, string $reference): void
    {
        $user = $this->learnerOf($enrollment);
        if ($user === null) {
            return;
        }
        $first = $this->firstName($user);
        $course = $enrollment->course?->title ?? 'your course';
        $this->send(
            $user,
            "Certified - {$course} ({$reference})",
            "Hi {$first},<br><br>Congratulations, you are now <strong>certified</strong> in {$course}. Reference <strong>{$reference}</strong> - anyone can verify it online in seconds.",
            rtrim((string) config('app.frontend_url'), '/').'/certificates',
            'View certificate',
        );
    }

    public function rejected(Enrollment $enrollment, ?string $note = null): void
    {
        $user = $this->learnerOf($enrollment);
        if ($user === null) {
            return;
        }
        $first = $this->firstName($user);
        $course = $enrollment->course?->title ?? 'your course';
        $extra = $note !== null && $note !== '' ? "<br><br>Reviewer note: {$note}" : '';
        $this->send(
            $user,
            "Update on your application - {$course}",
            "Hi {$first},<br><br>Thank you for applying to <strong>{$course}</strong>. This cohort is full for your profile, but you are welcome to re-apply for the next one - your details are kept.{$extra}",
            rtrim((string) config('app.frontend_url'), '/').'/catalog',
            'Browse courses',
        );
    }

    public function cancelled(Enrollment $enrollment): void
    {
        $user = $this->learnerOf($enrollment);
        if ($user === null) {
            return;
        }
        $first = $this->firstName($user);
        $course = $enrollment->course?->title ?? 'your course';
        $this->send(
            $user,
            "Enrollment cancelled - {$course}",
            "Hi {$first},<br><br>Your enrollment in <strong>{$course}</strong> was cancelled. If this was a mistake, simply re-apply - we would love to have you back.",
            rtrim((string) config('app.frontend_url'), '/').'/catalog',
            'Re-apply',
        );
    }
}
