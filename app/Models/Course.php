<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Course extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_ARCHIVED = 'archived';

    public const DELIVERY_LIVE = 'live';

    public const DELIVERY_SELF_PACED = 'self_paced';

    public const DELIVERY_HYBRID = 'hybrid';

    public const LEVEL_BEGINNER = 'beginner';

    public const LEVEL_INTERMEDIATE = 'intermediate';

    public const LEVEL_ADVANCED = 'advanced';

    protected $fillable = [
        'title',
        'slug',
        'description',
        'category',
        'cover_url',
        'status',
        'start_date',
        'end_date',
        'is_self_paced',
        'delivery_mode',
        'level',
        'language',
        'duration_hours',
        'target_audience',
        'prerequisites',
        'tags',
        'created_by',
    ];

    public function isLive(): bool
    {
        return $this->delivery_mode === self::DELIVERY_LIVE;
    }

    public function isSelfPaced(): bool
    {
        return $this->delivery_mode === self::DELIVERY_SELF_PACED;
    }

    public function isHybrid(): bool
    {
        return $this->delivery_mode === self::DELIVERY_HYBRID;
    }

    protected function casts(): array
    {
        return [
            'start_date' => 'datetime',
            'end_date' => 'datetime',
            'is_self_paced' => 'boolean',
            'tags' => 'array',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<CourseFee, $this> */
    public function fees(): HasMany
    {
        return $this->hasMany(CourseFee::class, 'course_id');
    }

    /** @return HasMany<Enrollment, $this> */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class, 'course_id');
    }

    /** @return HasMany<Schedule, $this> */
    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class, 'course_id');
    }

    /** @return HasMany<Certificate, $this> */
    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class, 'course_id');
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    /** @return HasMany<CourseSection, $this> */
    public function sections(): HasMany
    {
        return $this->hasMany(CourseSection::class)->orderBy('sort_order');
    }

    /** @return HasMany<Lesson, $this> */
    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class)->orderBy('sort_order');
    }

    /** @return HasMany<LearningOutcome, $this> */
    public function learningOutcomes(): HasMany
    {
        return $this->hasMany(LearningOutcome::class)->orderBy('sort_order');
    }

    /** @return HasMany<Resource, $this> */
    public function resources(): HasMany
    {
        return $this->hasMany(Resource::class)->orderBy('sort_order');
    }

    /** @return HasMany<Quiz, $this> */
    public function quizzes(): HasMany
    {
        return $this->hasMany(Quiz::class)->orderBy('sort_order');
    }

    /** @return HasMany<Exercise, $this> */
    public function exercises(): HasMany
    {
        return $this->hasMany(Exercise::class)->orderBy('sort_order');
    }

    /** @return HasMany<Exam, $this> */
    public function exams(): HasMany
    {
        return $this->hasMany(Exam::class)->orderBy('sort_order');
    }

    /** @return HasMany<Assignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class)->orderBy('sort_order');
    }

    public function fee(string $feeType): ?CourseFee
    {
        return $this->fees->first(fn (CourseFee $fee) => $fee->fee_type === $feeType);
    }
}