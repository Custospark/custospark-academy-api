<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExerciseQuestion extends Model
{
    use HasFactory;

    public const TYPE_MULTIPLE_CHOICE = 'multiple_choice';

    public const TYPE_TRUE_FALSE = 'true_false';

    public const TYPE_SHORT_ANSWER = 'short_answer';

    public const TYPE_CODE = 'code';

    protected $fillable = [
        'exercise_id',
        'question',
        'type',
        'options',
        'correct_answer',
        'points',
        'explanation',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'points' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }
}