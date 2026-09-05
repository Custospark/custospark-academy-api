<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Quiz extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_id',
        'lesson_id',
        'title',
        'description',
        'passing_score',
        'time_limit_minutes',
        'sort_order',
        'is_published',
    ];

    protected function casts(): array
    {
        return [
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

    /** @return HasMany<QuizQuestion, $this> */
    public function questions(): HasMany
    {
        return $this->hasMany(QuizQuestion::class)->orderBy('sort_order');
    }

    /** @return MorphMany<AssessmentAttempt, $this> */
    public function attempts(): MorphMany
    {
        return $this->morphMany(AssessmentAttempt::class, 'assessmentable');
    }
}