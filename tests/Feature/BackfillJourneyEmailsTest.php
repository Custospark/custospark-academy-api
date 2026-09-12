<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\StandardEmail;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class BackfillJourneyEmailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_lists_without_sending_or_logging(): void
    {
        Mail::fake();

        $learner = User::factory()->learner()->create();
        $admin = User::factory()->admin()->create();
        $course = Course::factory()->published()->create(['created_by' => $admin->id]);
        Enrollment::factory()->create(['course_id' => $course->id, 'user_id' => $learner->id]);

        $log = sys_get_temp_dir().'/backfill-test-'.uniqid().'.csv';

        $this->artisan('notify:backfill', ['--type' => 'all', '--dry-run' => true, '--log' => $log])
            ->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertFileDoesNotExist($log);
    }

    public function test_live_run_sends_once_and_resume_skips_logged(): void
    {
        Mail::fake();

        $learner = User::factory()->learner()->create();
        $admin = User::factory()->admin()->create();
        $course = Course::factory()->published()->create(['created_by' => $admin->id]);
        Enrollment::factory()->create(['course_id' => $course->id, 'user_id' => $learner->id]);

        $log = sys_get_temp_dir().'/backfill-test-'.uniqid().'.csv';

        // First run sends welcome + enrolled.
        $this->artisan('notify:backfill', [
            '--type' => 'all',
            '--log' => $log,
            '--delay' => 0,
        ])->assertSuccessful();
        $titles = collect(Mail::sent(StandardEmail::class))->map(fn ($m) => $m->title)->all();
        $this->assertContains('Welcome to Custospark Academy', $titles);
        $this->assertFileExists($log);
        $rows = array_map('str_getcsv', array_slice(file($log), 1));
        $this->assertCount(2, $rows);
        $this->assertSame(['welcome', 'enrolled'], array_column($rows, 1));

        // Second run with --resume sends nothing new (proves no double-send).
        $this->artisan('notify:backfill', [
            '--type' => 'all',
            '--log' => $log,
            '--resume' => true,
            '--delay' => 0,
        ])->assertSuccessful();
        $this->assertCount(2, array_map('str_getcsv', array_slice(file($log), 1)));

        @unlink($log);
    }

    public function test_test_mode_targets_single_recipient(): void
    {
        Mail::fake();

        User::factory()->learner()->create();
        User::factory()->learner()->create(['email' => 'solo@example.com']);
        $log = sys_get_temp_dir().'/backfill-test-'.uniqid().'.csv';

        $this->artisan('notify:backfill', [
            '--type' => 'welcome',
            '--test' => 'solo@example.com',
            '--log' => $log,
            '--delay' => 0,
        ])->assertSuccessful();

        Mail::assertSent(StandardEmail::class, 1);
        Mail::assertSent(StandardEmail::class, fn ($m) => $m->hasTo('solo@example.com'));

        @unlink($log);
    }
}
