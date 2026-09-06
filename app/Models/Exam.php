<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Exam extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_id',
        'title',
        'description',
        'file_path',
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

    /** @return HasMany<ExamQuestion, $this> */
    public function questions(): HasMany
    {
        return $this->hasMany(ExamQuestion::class)->orderBy('sort_order');
    }

    /** @return MorphMany<Submission, $this> */
    public function submissions(): MorphMany
    {
        return $this->morphMany(Submission::class, 'submissionable');
    }
}