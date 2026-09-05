<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AssessmentAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'course_id',
        'assessmentable_type',
        'assessmentable_id',
        'answers',
        'score',
        'max_score',
        'is_passed',
        'started_at',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'answers' => 'array',
            'score' => 'integer',
            'max_score' => 'integer',
            'is_passed' => 'boolean',
            'started_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function assessmentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isPassed(): bool
    {
        return $this->is_passed;
    }
}