<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Exercise extends Model
{
    use HasFactory;

    public const TYPE_QUIZ = 'quiz';

    public const TYPE_PRACTICAL = 'practical';

    protected $fillable = [
        'course_id',
        'lesson_id',
        'title',
        'instructions',
        'file_path',
        'type',
        'max_score',
        'passing_score',
        'time_limit_minutes',
        'sort_order',
        'is_published',
    ];

    protected function casts(): array
    {
        return [
            'max_score' => 'integer',
            'passing_score' => 'integer',
            'time_limit_minutes' => 'integer',
            'sort_order' => 'integer',
            'is_published' => 'boolean',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /** @return HasMany<ExerciseQuestion, $this> */
    public function questions(): HasMany
    {
        return $this->hasMany(ExerciseQuestion::class)->orderBy('sort_order');
    }

    /** @return MorphMany<AssessmentAttempt, $this> */
    public function attempts(): MorphMany
    {
        return $this->morphMany(AssessmentAttempt::class, 'assessmentable');
    }
}