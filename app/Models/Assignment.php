<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Assignment extends Model
{
    use HasFactory;

    public const SUBMISSION_TEXT = 'text';

    public const SUBMISSION_FILE = 'file';

    public const SUBMISSION_LINK = 'link';

    protected $fillable = [
        'course_id',
        'lesson_id',
        'title',
        'instructions',
        'submission_type',
        'due_after_days',
        'max_score',
        'sort_order',
        'is_published',
    ];

    protected function casts(): array
    {
        return [
            'due_after_days' => 'integer',
            'max_score' => 'integer',
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

    /** @return MorphMany<Submission, $this> */
    public function submissions(): MorphMany
    {
        return $this->morphMany(Submission::class, 'submissionable');
    }
}