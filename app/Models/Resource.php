<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Resource extends Model
{
    use HasFactory;

    public const TYPE_BOOK = 'book';

    public const TYPE_LINK = 'link';

    public const TYPE_VIDEO = 'video';

    public const TYPE_FILE = 'file';

    public const TYPE_ARTICLE = 'article';

    protected $fillable = [
        'course_id',
        'lesson_id',
        'title',
        'type',
        'url',
        'file_path',
        'description',
        'sort_order',
    ];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }
}