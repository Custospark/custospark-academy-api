<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\EnrollmentNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Sends one sample journey email at a time for visual review.
 *
 *   php artisan notify:sample --to=you@example.com --step=welcome
 *
 * Steps: welcome, applied, fee-paid, admitted, tuition, started,
 * completed, certification-ready, certified, rejected, cancelled, all.
 * Uses the real Web Development course when present (else the first
 * published course) and a fictional learner profile - nothing is persisted.
 */
class SendSampleNotification extends Command
{
    protected $signature = 'notify:sample
        {--to= : Recipient email address}
        {--step=welcome : Journey step (or "all")}';

    protected $description = 'Send a sample learner-journey email for visual review';

    public const STEPS = [
        'welcome',
        'applied',
        'fee-paid',
        'admitted',
        'tuition',
        'started',
        'completed',
        'certification-ready',
        'certified',
        'rejected',
        'cancelled',
    ];

    public function handle(EnrollmentNotificationService $notify): int
    {
        $to = strtolower(trim((string) $this->option('to')));
        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->error('Provide a valid --to email address.');

            return self::FAILURE;
        }

        $step = strtolower(trim((string) $this->option('step')));
        $steps = $step === 'all' ? self::STEPS : [$step];
        foreach ($steps as $item) {
            if (! in_array($item, self::STEPS, true)) {
                $this->error('Unknown step "'.$item.'". Choose from: '.implode(', ', self::STEPS).', all.');

                return self::FAILURE;
            }
        }

        $course = Course::query()->where('slug', 'web-development')->first()
            ?? Course::query()->where('status', Course::STATUS_PUBLISHED)->first();
        if ($course === null) {
            $this->error('No published course found to sample with.');

            return self::FAILURE;
        }

        // Fictional learner + enrollment (never persisted).
        $user = new User([
            'name' => 'George Ochieng',
            'email' => $to,
            'phone' => '+256700000000',
            'role' => User::ROLE_LEARNER,
            'status' => User::STATUS_ACTIVE,
        ]);
        $enrollment = new Enrollment([
            'course_id' => $course->id,
            'user_id' => 0,
            'status' => Enrollment::STATUS_APPLIED,
        ]);
        $enrollment->setRelation('course', $course);
        $enrollment->setRelation('user', $user);

        $this->info('from: '.config('mail.from.address'));
        $this->info('course: '.$course->title.' ('.$course->slug.')');

        foreach ($steps as $item) {
            match ($item) {
                'welcome' => $notify->welcome($user),
                'applied' => $notify->applicationReceived($enrollment),
                'fee-paid' => $notify->applicationFeePaid($enrollment),
                'admitted' => $notify->admitted($enrollment),
                'tuition' => $notify->tuitionPaid($enrollment),
                'started' => $notify->started($enrollment),
                'completed' => $notify->completed($enrollment),
                'certification-ready' => $notify->certificationReady($enrollment),
                'certified' => $notify->certified($enrollment, 'CSA-DEMO-0001'),
                'rejected' => $notify->rejected($enrollment, 'Cohort full - please re-apply next intake.'),
                'cancelled' => $notify->cancelled($enrollment),
            };
            $this->info("sent [{$item}] to {$to}");
        }

        $this->info('done.');

        return self::SUCCESS;
    }
}
