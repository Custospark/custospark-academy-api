<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Attendance;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Digital class register: instructors mark each learner (or the whole class
 * at once) per session date; attendance rate auto-calculates from days
 * attended over total marked days. Learners read their own records.
 */
class AttendanceService
{
    /**
     * Attendance is for live enrollments: every status range except the dead
     * ends (rejected, cancelled). Ranges, not points - statuses keep moving
     * (applied -> paid -> admitted -> learning -> finished) and the register
     * must keep working through all of it.
     */
    public const ELIGIBLE_STATUSES = [
        \App\Models\Enrollment::STATUS_APPLIED,
        \App\Models\Enrollment::STATUS_APPLICATION_FEE_PAID,
        \App\Models\Enrollment::STATUS_ADMITTED,
        \App\Models\Enrollment::STATUS_TUITION_PAID,
        \App\Models\Enrollment::STATUS_IN_PROGRESS,
        \App\Models\Enrollment::STATUS_COMPLETED,
        \App\Models\Enrollment::STATUS_CERTIFICATION,
        \App\Models\Enrollment::STATUS_CERTIFIED,
    ];

    /** @deprecated Use ELIGIBLE_STATUSES (statuses are ranges, not points). */
    public const ADMITTED_STATUSES = self::ELIGIBLE_STATUSES;

    /**
     * Mark attendance for specific learners on a date. Unknown or unenrolled
     * users are reported back, never created.
     *
     * @param  list<array{user_id: int, status: string}>  $records
     * @return array{marked: int, errors: list<string>}
     */
    public function mark(Course $course, string $date, array $records, int $markerId): array
    {
        $day = $this->parseDate($date);
        $marked = 0;
        $errors = [];

        foreach ($records as $record) {
            $userId = (int) ($record['user_id'] ?? 0);
            $status = (string) ($record['status'] ?? '');
            if (! in_array($status, Attendance::STATUSES, true)) {
                $errors[] = "User {$userId}: unknown status '{$status}'.";
                continue;
            }
            $user = User::query()->find($userId);
            if ($user === null) {
                $errors[] = "User {$userId}: no such account.";
                continue;
            }
            if (! Enrollment::query()->where('course_id', $course->id)->where('user_id', $userId)->whereIn('status', self::ELIGIBLE_STATUSES)->exists()) {
                $errors[] = "{$user->email}: no live enrollment in this course.";
                continue;
            }

            Attendance::query()->updateOrCreate(
                ['course_id' => $course->id, 'user_id' => $userId, 'session_date' => $day],
                ['status' => $status, 'marked_by' => $markerId],
            );
            $marked++;
        }

        return ['marked' => $marked, 'errors' => $errors];
    }

    /**
     * Mark every enrolled learner with the same status for a date.
     *
     * @return array{marked: int}
     */
    public function markAll(Course $course, string $date, string $status, int $markerId): array
    {
        if (! in_array($status, Attendance::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'Unknown attendance status.']);
        }
        $day = $this->parseDate($date);

        $ids = Enrollment::query()
            ->where('course_id', $course->id)
            ->whereIn('status', self::ELIGIBLE_STATUSES)
            ->pluck('user_id')->unique()->all();
        foreach ($ids as $userId) {
            Attendance::query()->updateOrCreate(
                ['course_id' => $course->id, 'user_id' => $userId, 'session_date' => $day],
                ['status' => $status, 'marked_by' => $markerId],
            );
        }

        return ['marked' => count($ids)];
    }

    /**
     * Roster for a date: every enrolled learner with their mark (or null when
     * unmarked) plus each learner's running rate.
     *
     * @return list<array<string, mixed>>
     */
    public function roster(Course $course, string $date): array
    {
        $day = $this->parseDate($date);
        $marks = Attendance::query()
            ->where('course_id', $course->id)
            ->whereDate('session_date', $day)
            ->get()
            ->keyBy('user_id');

        $enrollments = Enrollment::query()
            ->with('user')
            ->where('course_id', $course->id)
            ->whereIn('status', self::ELIGIBLE_STATUSES)
            ->orderBy('id')
            ->get();

        return $enrollments->map(fn ($e) => [
            'user_id' => $e->user_id,
            'name' => $e->user?->name ?? 'Learner',
            'email' => $e->user?->email ?? '',
            'status' => $marks->get($e->user_id)?->status,
            'rate' => $this->rate((int) $e->user_id, (int) $course->id)['rate'],
        ])->all();
    }

    /**
     * One learner's records plus auto-calculated rate.
     *
     * @return array{records: list<array<string, mixed>>, rate: array<string, mixed>}
     */
    public function mine(int $userId, int $courseId): array
    {
        $records = Attendance::query()
            ->where('course_id', $courseId)
            ->where('user_id', $userId)
            ->orderByDesc('session_date')
            ->get()
            ->map(fn ($a) => [
                'date' => $a->session_date->format('Y-m-d'),
                'status' => $a->status,
            ])->all();

        return ['records' => $records, 'rate' => $this->rate($userId, $courseId)];
    }

    /**
     * Rate = days attended (present + late) over total marked days.
     *
     * @return array{present: int, late: int, absent: int, excused: int, total_days: int, attended_days: int, rate: int}
     */
    public function rate(int $userId, int $courseId): array
    {
        $counts = Attendance::query()
            ->where('course_id', $courseId)
            ->where('user_id', $userId)
            ->get()
            ->countBy('status')
            ->all();

        $present = (int) ($counts[Attendance::STATUS_PRESENT] ?? 0);
        $late = (int) ($counts[Attendance::STATUS_LATE] ?? 0);
        $absent = (int) ($counts[Attendance::STATUS_ABSENT] ?? 0);
        $excused = (int) ($counts[Attendance::STATUS_EXCUSED] ?? 0);
        $total = $present + $late + $absent + $excused;
        $attended = $present + $late;

        return [
            'present' => $present,
            'late' => $late,
            'absent' => $absent,
            'excused' => $excused,
            'total_days' => $total,
            'attended_days' => $attended,
            'rate' => $total > 0 ? (int) round(($attended / $total) * 100) : 0,
        ];
    }

    protected function parseDate(string $date): string
    {
        try {
            return Carbon::parse($date)->toDateString();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['date' => 'Use YYYY-MM-DD.']);
        }
    }
}
