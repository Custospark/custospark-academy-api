<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\StandardEmail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Sends a personalized campaign email to a CSV recipient list with full
 * audit (no double-sends, resumable). Ports the Custosell email:classmates
 * pattern so Academy campaigns carry the same discipline: per-recipient CSV
 * log, --resume skips anyone already logged, --test sends one review copy,
 * --dry-run renders without sending.
 *
 *   php artisan email:campaign --campaign=cohort3 --test="Opiyo Oscar|opiyooscar414@gmail.com"
 *   php artisan email:campaign --campaign=cohort3 --resume --batch=25 --delay=10
 *
 * The mail uses the standard branded Academy template with the CEO/Corporate
 * Strategist signature and the campaign poster attached.
 */
class EmailCampaign extends Command
{
    protected $signature = 'email:campaign
        {--campaign=cohort3 : Campaign tag (also names the default log file)}
        {--list= : Path to recipients CSV (name,email,phone)}
        {--test= : Single review recipient: EMAIL or "Name|email"}
        {--delay=10 : Seconds to sleep between sends}
        {--dry-run : Render and print, do not send}
        {--batch= : Max recipients this run (0 = all)}
        {--resume : Skip recipients already recorded in the log (no double-send)}
        {--subject= : Subject override}
        {--poster= : Poster image path to attach}
        {--log= : CSV log path (default storage/logs/email-<campaign>.csv)}';

    protected $description = 'Send a personalized Academy campaign email to a CSV list with resume-safe logging';

    public function handle(): int
    {
        $campaign = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $this->option('campaign')) ?: 'campaign';
        $delay = max(0, (int) $this->option('delay'));
        $test = (string) $this->option('test');
        $list = (string) $this->option('list');
        $logPath = (string) $this->option('log') ?: storage_path("logs/email-{$campaign}.csv");

        $recipients = $this->loadRecipients($list !== '' ? $list : base_path('docs/academy-cohort3-recipients.csv'), $test);
        if ($recipients === []) {
            $this->error('No recipients. Provide --test "Name|email" or --list CSV.');

            return self::FAILURE;
        }

        if ($this->option('resume') && is_file($logPath)) {
            $sent = $this->loggedEmails($logPath);
            $before = count($recipients);
            $recipients = array_values(array_filter(
                $recipients,
                fn (array $r) => ! isset($sent[strtolower((string) $r['email'])]),
            ));
            $this->info(sprintf('resume: skipping %d already-processed, %d remaining', $before - count($recipients), count($recipients)));
        }

        $batch = max(0, (int) $this->option('batch'));
        if ($batch > 0 && count($recipients) > $batch) {
            $recipients = array_slice($recipients, 0, $batch);
            $this->info("batch: this run sends up to {$batch} recipients");
        }

        $posterPath = (string) $this->option('poster') ?: public_path('images/custospark_academy_two_day_left_poster.png');
        if (! is_file($posterPath)) {
            $this->error("poster not found: {$posterPath}");

            return self::FAILURE;
        }

        $subject = (string) $this->option('subject');

        $total = count($recipients);
        $this->info("from: ".config('mail.from.address'));
        $this->info("campaign: {$campaign} | recipients: {$total} | delay: {$delay}s | dry-run: ".($this->option('dry-run') ? 'yes' : 'no'));
        $this->info("poster: {$posterPath}");

        $sent = 0;
        $failed = 0;
        $logHandle = $this->openLog($logPath);

        foreach ($recipients as $i => $recipient) {
            $name = trim((string) $recipient['name']);
            $first = $this->firstName($name);

            if ($this->option('dry-run')) {
                $this->line(sprintf('[%d] %s -> "%s" (dry-run)', $i + 1, $recipient['email'], $first));
                continue;
            }

            $status = 'sent';
            $error = '';
            try {
                $this->sendCampaignEmail((string) $recipient['email'], $first, $subject, $posterPath);
                $this->line(sprintf('[%d] sent to %s', $i + 1, $recipient['email']));
                $sent++;
            } catch (\Throwable $e) {
                $status = 'failed';
                $error = str_replace(["\r", "\n"], ' ', $e->getMessage());
                $this->error(sprintf('[%d] FAILED %s: %s', $i + 1, $recipient['email'], $e->getMessage()));
                $failed++;
            }

            $this->writeLog($logHandle, (string) $recipient['email'], $name, $status, $error);

            if ($i < count($recipients) - 1) {
                sleep($delay);
            }
        }

        fclose($logHandle);
        $this->info('results logged to: '.$logPath);
        $this->info(sprintf('done. sent=%d failed=%d of %d', $sent, $failed, count($recipients)));

        return self::SUCCESS;
    }

    protected function sendCampaignEmail(string $to, string $firstName, string $subject, string $posterPath): void
    {
        Mail::to($to)->send(new StandardEmail(
            title: $subject !== '' ? $subject : 'Cohort 3 closes in 2 days - tuition is on us',
            mailBody: $this->body($firstName),
            ctaUrl: 'https://academy.custospark.com/register',
            ctaLabel: 'Register here',
            isHtml: true,
            signature: StandardEmail::OSCAR_SIGNATURE,
            fileAttachments: [
                [
                    'data' => (string) file_get_contents($posterPath),
                    'name' => basename($posterPath),
                    'mime' => 'image/png',
                ],
            ],
        ));
    }

    protected function body(string $firstName): string
    {
        return "Hi {$firstName},<br><br>"
            ."Two days. That's all that stands between you and a real, in-demand tech skill.<br><br>"
            ."<strong>Custospark Academy's Cohort 3</strong> is closing its applications - and when the window shuts, it's gone.<br><br>"
            ."This is the strongest offer we've ever made:<br><br>"
            ."&bull; <strong>Tuition: 100% covered.</strong> You pay nothing for the programme.<br>"
            ."&bull; <strong>Application fee: just UGX 25,000</strong> to lock in your seat.<br>"
            ."&bull; <strong>100% online</strong> - learn from anywhere, at your pace.<br><br>"
            ."Four courses, all on the table:<br>"
            ."<strong>Data Science &middot; Machine Learning &middot; Web Development &middot; Mobile Development</strong><br><br>"
            ."Why remove every barrier? Because the people who need these skills most shouldn't be priced out of them. We'd rather you apply than wonder \"what if.\"<br><br>"
            ."Your seat won't wait, and neither should you - hit the button below to lock it in.<br><br>"
            ."PS - if this isn't your season, be the one who shares it. Send this to a student, a recent graduate, or a friend stuck between \"maybe\" and \"next year.\" Every forward opens a door for someone who genuinely needs it.<br><br>"
            ."Thank you for reading. I hope to welcome you inside.";
    }

    /** Greeting uses the first name token (CSV stores full names). */
    protected function firstName(string $name): string
    {
        $clean = trim((string) preg_replace('/[\p{Cc}\p{Cf}\x{00AD}]/u', '', $name));
        $tokens = preg_split('/\s+/', $clean);
        $first = is_array($tokens) && $tokens !== [] ? (string) $tokens[0] : '';

        return $first !== '' ? $first : 'there';
    }

    public function loadRecipients(string $path, string $test): array
    {
        if (! is_file($path)) {
            $this->error("recipients file not found: {$path}");

            return [];
        }

        $recipients = [];
        if (($fh = fopen($path, 'r')) !== false) {
            $header = fgetcsv($fh);
            while (($row = fgetcsv($fh)) !== false) {
                $assoc = $this->assocRow($header, $row);
                $email = strtolower(trim((string) ($assoc['email'] ?? '')));
                if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }
                $recipients[] = [
                    'name' => trim((string) ($assoc['name'] ?? '')),
                    'email' => $email,
                    'phone' => isset($assoc['phone']) && $assoc['phone'] !== '' ? (string) $assoc['phone'] : null,
                ];
            }
            fclose($fh);
        }

        if ($test !== '') {
            $target = strtolower(trim($test));
            if (str_contains($target, '|')) {
                [$testName, $testEmail] = array_map('trim', explode('|', $target, 2));

                return [['name' => $testName, 'email' => strtolower($testEmail), 'phone' => null]];
            }
            foreach ($recipients as $rec) {
                if ($rec['email'] === $target) {
                    return [$rec];
                }
            }

            return [['name' => '', 'email' => $target, 'phone' => null]];
        }

        return $recipients;
    }

    /** @param  list<string|null>  $header */
    private function assocRow(array $header, array $row): array
    {
        $assoc = [];
        foreach ($row as $i => $value) {
            $key = $header[$i] ?? null;
            if ($key !== null) {
                $assoc[$key] = $value;
            }
        }

        return $assoc;
    }

    private function openLog(string $path)
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        $append = is_file($path);
        $fh = fopen($path, $append ? 'a' : 'w');
        if ($fh !== false && ! $append) {
            fputcsv($fh, ['email', 'name', 'status', 'error', 'time']);
        }

        return $fh;
    }

    /** @return array<string, bool> emails already present in the log */
    private function loggedEmails(string $path): array
    {
        $set = [];
        if (($fh = fopen($path, 'r')) !== false) {
            fgetcsv($fh);
            while (($row = fgetcsv($fh)) !== false) {
                $email = strtolower(trim((string) ($row[0] ?? '')));
                if ($email !== '') {
                    $set[$email] = true;
                }
            }
            fclose($fh);
        }

        return $set;
    }

    /** @param  resource  $fh */
    private function writeLog($fh, string $email, string $name, string $status, string $error): void
    {
        fputcsv($fh, [$email, $name, $status, $error, now()->toIso8601String()]);
    }
}