<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Enrollment;
use App\Models\User;
use App\Services\EnrollmentNotificationService;
use Illuminate\Console\Command;

/**
 * Backfill journey emails to learners who registered/enrolled before mail
 * integration existed. Source of truth is the database; a CSV send-log
 * records every delivery so re-runs with --resume never double-send
 * (same approach as Custosell's classmate sends).
 *
 *   php artisan notify:backfill --type=all --dry-run
 *   php artisan notify:backfill --type=all --log=storage/logs/notify-backfill.csv --delay=5
 *   php artisan notify:backfill --type=all --log=storage/logs/notify-backfill.csv --resume
 *   php artisan notify:backfill --test=you@example.com --type=enrolled
 */
class BackfillJourneyEmails extends Command
{
    protected $signature = 'notify:backfill
        {--type=all : welcome|enrolled|all}
        {--test= : Single recipient email (looked up in DB, sends once)}
        {--delay=5 : Seconds to sleep between sends}
        {--batch= : Max recipients in this run (0 = all)}
        {--resume : Skip recipients already recorded in the log}
        {--log= : CSV path recording email,type,status,error,time}
        {--dry-run : List only, do not send}';

    protected $description = 'Backfill welcome + enrollment emails to pre-mail-integration learners (tracked, resumable)';

    public function handle(EnrollmentNotificationService $notify): int
    {
        $type = strtolower(trim((string) $this->option('type')));
        if (! in_array($type, ['welcome', 'enrolled', 'all'], true)) {
            $this->error('Unknown --type. Use welcome|enrolled|all.');

            return self::FAILURE;
        }

        $jobs = $this->buildJobs($type, (string) $this->option('test'));
        if ($jobs === []) {
            $this->error('No recipients.');

            return self::FAILURE;
        }

        $logPath = (string) $this->option('log');
        if ($this->option('resume') && $logPath !== '' && is_file($logPath)) {
            $done = $this->loggedKeys($logPath);
            $before = count($jobs);
            $jobs = array_values(array_filter(
                $jobs,
                fn ($j) => ! isset($done[strtolower($j['email']).'|'.$j['type']]),
            ));
            $this->info(sprintf('resume: skipping %d already-sent, %d remaining', $before - count($jobs), count($jobs)));
        }

        $batch = max(0, (int) $this->option('batch'));
        if ($batch > 0 && count($jobs) > $batch) {
            $jobs = array_slice($jobs, 0, $batch);
            $this->info("batch: this run sends up to {$batch}");
        }

        $delay = max(0, (int) $this->option('delay'));
        $this->info(sprintf(
            'recipients: %d | delay: %ds | dry-run: %s | log: %s',
            count($jobs),
            $delay,
            $this->option('dry-run') ? 'yes' : 'no',
            $logPath !== '' ? $logPath : '(none - duplicate protection OFF)',
        ));

        $logHandle = ($logPath !== '' && ! $this->option('dry-run')) ? $this->openLog($logPath) : null;
        $sent = 0;
        $failed = 0;

        foreach ($jobs as $i => $job) {
            $label = sprintf('[%d] %s -> %s', $i + 1, $job['type'], $job['email']);
            if ($this->option('dry-run')) {
                $this->line($label.' (dry-run)');
                continue;
            }

            $status = 'sent';
            $error = '';
            try {
                if ($job['type'] === 'welcome') {
                    $notify->welcome($job['user']);
                } else {
                    $notify->applicationReceived($job['enrollment']);
                }
                $this->line($label.' sent');
                $sent++;
            } catch (\Throwable $e) {
                // The service itself never throws; this guards the unexpected.
                $status = 'failed';
                $error = str_replace(["\r", "\n"], ' ', $e->getMessage());
                $this->error($label.' FAILED: '.$e->getMessage());
                $failed++;
            }

            if ($logHandle !== null) {
                fputcsv($logHandle, [$job['email'], $job['type'], $status, $error, now()->toIso8601String()]);
            }

            if ($i < count($jobs) - 1 && $delay > 0) {
                sleep($delay);
            }
        }

        if ($logHandle !== null) {
            fclose($logHandle);
            $this->info('results logged to: '.$logPath);
        }

        $this->info(sprintf('done. sent=%d failed=%d of %d', $sent, $failed, count($jobs)));

        return self::SUCCESS;
    }

    /**
     * Build the send list from the database: every learner gets `welcome`,
     * every enrollment gets `enrolled` (filtered by --type).
     *
     * @return list<array{email: string, type: string, user: User, enrollment: ?Enrollment}>
     */
    private function buildJobs(string $type, string $test): array
    {
        $jobs = [];

        if ($type === 'welcome' || $type === 'all') {
            $users = User::query()->where('role', User::ROLE_LEARNER)->orderBy('id')->get();
            if ($test !== '') {
                $users = $users->filter(fn ($u) => strtolower((string) $u->email) === strtolower($test))->values();
            }
            foreach ($users as $user) {
                if ($user->email === null || $user->email === '') {
                    continue;
                }
                $jobs[] = ['email' => $user->email, 'type' => 'welcome', 'user' => $user, 'enrollment' => null];
            }
        }

        if ($type === 'enrolled' || $type === 'all') {
            $enrollments = Enrollment::query()->with(['user', 'course'])->orderBy('id')->get();
            foreach ($enrollments as $enrollment) {
                $email = $enrollment->user?->email;
                if ($email === null || $email === '') {
                    continue;
                }
                if ($test !== '' && strtolower($email) !== strtolower($test)) {
                    continue;
                }
                $jobs[] = ['email' => $email, 'type' => 'enrolled', 'user' => $enrollment->user, 'enrollment' => $enrollment];
            }
        }

        return $jobs;
    }

    /** @return array<string, bool> email|type keys already recorded. */
    private function loggedKeys(string $path): array
    {
        $set = [];
        if (($fh = fopen($path, 'r')) !== false) {
            fgetcsv($fh); // header
            while (($row = fgetcsv($fh)) !== false) {
                $key = strtolower(trim((string) ($row[0] ?? ''))).'|'.strtolower(trim((string) ($row[1] ?? '')));
                if ($key !== '|') {
                    $set[$key] = true;
                }
            }
            fclose($fh);
        }

        return $set;
    }

    /** @return resource */
    private function openLog(string $path)
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        $append = is_file($path);
        $fh = fopen($path, $append ? 'a' : 'w');
        if ($fh !== false && ! $append) {
            fputcsv($fh, ['email', 'type', 'status', 'error', 'time']);
        }

        return $fh;
    }
}
