<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseFee extends Model
{
    use HasFactory;

    public const FEE_APPLICATION = 'application';

    public const FEE_TUITION = 'tuition';

    public const FEE_CERTIFICATE = 'certificate';

    protected $fillable = [
        'course_id',
        'fee_type',
        'amount',
        'currency',
        'is_required',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'is_required' => 'boolean',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function isApplication(): bool
    {
        return $this->fee_type === self::FEE_APPLICATION;
    }

    public function isTuition(): bool
    {
        return $this->fee_type === self::FEE_TUITION;
    }

    public function isCertificate(): bool
    {
        return $this->fee_type === self::FEE_CERTIFICATE;
    }
}