<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Lesson extends Model
{
    use HasFactory;

    public const TYPE_TEXT = 'text';

    public const TYPE_VIDEO = 'video';

    public const TYPE_ARTICLE = 'article';

    public const TYPE_EMBED = 'embed';

    protected $fillable = [
        'course_id',
        'section_id',
        'title',
        'content_type',
        'content',
        'video_url',
        'duration_minutes',
        'sort_order',
        'is_free_preview',
        'is_published',
    ];

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'sort_order' => 'integer',
            'is_free_preview' => 'boolean',
            'is_published' => 'boolean',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(CourseSection::class, 'section_id');
    }

    /** @return HasMany<Resource, $this> */
    public function resources(): HasMany
    {
        return $this->hasMany(Resource::class)->orderBy('sort_order');
    }

    /** @return MorphMany<AssessmentAttempt, $this> */
    public function attempts(): MorphMany
    {
        return $this->morphMany(AssessmentAttempt::class, 'assessmentable');
    }
}