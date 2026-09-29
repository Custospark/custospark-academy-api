<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Enrollment;
use App\Services\EnrollmentNotificationService;
use Illuminate\Console\Command;

/**
 * Study-while-you-pay outreach: `applied` learners get the grace-access
 * invite, paid-but-learning statuses get encouragement. DB-driven recipients
 * with resume-safe CSV logging (same discipline as email:campaign).
 */
class EmailGraceAccess extends Command
{
    protected $signature = 'email:grace-access
        {--test= : Single review recipient: EMAIL or "Name|email"}
        {--delay=3 : Seconds to sleep between sends}
        {--dry-run : List recipients, do not send}
        {--batch= : Max recipients this run (0 = all)}
        {--resume : Skip recipients already recorded in the log (no double-send)}
        {--log= : CSV log path (default storage/logs/email-grace-access.csv)}';

    protected $description = 'Email grace-access invites to applied learners and encouragement to paid learners';

    public function __construct(protected EnrollmentNotificationService $notify)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $delay = max(0, (int) $this->option('delay'));
        $test = (string) $this->option('test');
        $logPath = (string) $this->option('log') ?: storage_path('logs/email-grace-access.csv');

        $recipients = $this->recipients($test);
        if ($recipients === []) {
            $this->error('No recipients. Provide --test "Name|email".');

            return self::FAILURE;
        }

        if ($this->option('resume') && is_file($logPath)) {
            $sent = $this->loggedEmails($logPath);
            $before = count($recipients);
            $recipients = array_values(array_filter(
                $recipients,
                fn (array $r) => ! isset($sent[strtolower($r['email'])]),
            ));
            $this->info(sprintf('resume: skipping %d already-processed, %d remaining', $before - count($recipients), count($recipients)));
        }

        $batch = max(0, (int) $this->option('batch'));
        if ($batch > 0 && count($recipients) > $batch) {
            $recipients = array_slice($recipients, 0, $batch);
            $this->info("batch: this run sends up to {$batch} recipients");
        }

        $this->info(sprintf(
            'grace-access: recipients: %d | delay: %ds | dry-run: %s',
            count($recipients),
            $delay,
            $this->option('dry-run') ? 'yes' : 'no'
        ));

        $logHandle = fopen($logPath, 'ab');
        if ($logHandle === false) {
            $this->error("cannot open log: {$logPath}");

            return self::FAILURE;
        }

        $sent = 0;
        $failed = 0;
        foreach ($recipients as $i => $recipient) {
            if ($this->option('dry-run')) {
                $this->line(sprintf(
                    '[%d] %s -> %s (%s, dry-run)',
                    $i + 1,
                    $recipient['email'],
                    $recipient['name'],
                    $recipient['kind']
                ));
                continue;
            }

            $status = 'sent';
            $error = '';
            try {
                $enrollment = Enrollment::query()->findOrFail($recipient['enrollment_id']);
                if ($recipient['kind'] === 'grace') {
                    $this->notify->graceAccess($enrollment);
                } else {
                    $this->notify->keepLearning($enrollment);
                }
                $this->line(sprintf('[%d] sent %s to %s', $i + 1, $recipient['kind'], $recipient['email']));
                $sent++;
            } catch (\Throwable $e) {
                $status = 'failed';
                $error = str_replace(["\r", "\n"], ' ', $e->getMessage());
                $this->error(sprintf('[%d] FAILED %s: %s', $i + 1, $recipient['email'], $e->getMessage()));
                $failed++;
            }

            fputcsv($logHandle, [$recipient['email'], $recipient['name'], $recipient['kind'], $status, $error, now()->toDateTimeString()]);
            unset($enrollment);

            if ($i < count($recipients) - 1) {
                sleep($delay);
            }
        }

        fclose($logHandle);
        $this->info('results logged to: '.$logPath);
        $this->info(sprintf('done. sent=%d failed=%d of %d', $sent, $failed, count($recipients)));

        return self::SUCCESS;
    }

    /** @return list<array{email: string, name: string, kind: string, enrollment_id: int}> */
    private function recipients(string $test): array
    {
        if ($test !== '') {
            $name = $test;
            $email = $test;
            if (str_contains($test, '|')) {
                [$name, $email] = array_map(trim(...), explode('|', $test, 2));
            }
            $first = Enrollment::query()
                ->where('status', Enrollment::STATUS_APPLIED)
                ->orderByDesc('id')
                ->first();
            if ($first === null) {
                return [];
            }

            return [
                ['email' => $email, 'name' => $name, 'kind' => 'grace', 'enrollment_id' => $first->id],
                ['email' => $email, 'name' => $name, 'kind' => 'keep-learning', 'enrollment_id' => $first->id],
            ];
        }

        $rows = Enrollment::query()
            ->with('user:id,name,email')
            ->whereIn('status', [
                Enrollment::STATUS_APPLIED,
                Enrollment::STATUS_APPLICATION_FEE_PAID,
                Enrollment::STATUS_ADMITTED,
                Enrollment::STATUS_TUITION_PAID,
                Enrollment::STATUS_IN_PROGRESS,
            ])
            ->orderBy('id')
            ->get(['id', 'user_id', 'status']);

        $out = [];
        foreach ($rows as $enrollment) {
            $user = $enrollment->user;
            if ($user === null || $user->email === null || $user->email === '') {
                continue;
            }
            $out[] = [
                'email' => $user->email,
                'name' => $user->name ?? '',
                'kind' => $enrollment->status === Enrollment::STATUS_APPLIED ? 'grace' : 'keep-learning',
                'enrollment_id' => $enrollment->id,
            ];
        }

        return $out;
    }

    /** @return array<string, bool> */
    private function loggedEmails(string $logPath): array
    {
        $sent = [];
        $handle = fopen($logPath, 'rb');
        if ($handle === false) {
            return $sent;
        }
        while (($row = fgetcsv($handle)) !== false) {
            if (isset($row[0], $row[3]) && strtolower(trim((string) $row[3])) === 'sent') {
                $sent[strtolower(trim((string) $row[0]))] = true;
            }
        }
        fclose($handle);

        return $sent;
    }
}
