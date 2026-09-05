<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Enrollment extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const STATUS_APPLIED = 'applied';

    public const STATUS_APPLICATION_FEE_PAID = 'application_fee_paid';

    public const STATUS_ADMITTED = 'admitted';

    public const STATUS_TUITION_PAID = 'tuition_paid';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CERTIFICATION = 'certification';

    public const STATUS_CERTIFIED = 'certified';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    /** Valid transitions: current status => allowed next statuses. */
    public const TRANSITIONS = [
        self::STATUS_APPLIED => [self::STATUS_APPLICATION_FEE_PAID, self::STATUS_REJECTED, self::STATUS_CANCELLED],
        self::STATUS_APPLICATION_FEE_PAID => [self::STATUS_ADMITTED, self::STATUS_REJECTED, self::STATUS_CANCELLED],
        self::STATUS_ADMITTED => [self::STATUS_TUITION_PAID, self::STATUS_CANCELLED],
        self::STATUS_TUITION_PAID => [self::STATUS_IN_PROGRESS, self::STATUS_CANCELLED],
        self::STATUS_IN_PROGRESS => [self::STATUS_COMPLETED, self::STATUS_CANCELLED],
        self::STATUS_COMPLETED => [self::STATUS_CERTIFICATION],
        self::STATUS_CERTIFICATION => [self::STATUS_CERTIFIED],
        self::STATUS_CERTIFIED => [],
        self::STATUS_REJECTED => [],
        self::STATUS_CANCELLED => [],
    ];

    protected $fillable = [
        'course_id',
        'user_id',
        'status',
        'application_review_note',
        'applied_at',
        'admitted_at',
        'completed_at',
        'certified_at',
    ];

    protected function casts(): array
    {
        return [
            'applied_at' => 'datetime',
            'admitted_at' => 'datetime',
            'completed_at' => 'datetime',
            'certified_at' => 'datetime',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'enrollment_id');
    }

    /** @return HasOne<Certificate, $this> */
    public function certificate(): HasOne
    {
        return $this->hasOne(Certificate::class, 'enrollment_id');
    }

    public function canTransitionTo(string $next): bool
    {
        return in_array($next, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function isPaid(string $feeType): bool
    {
        return $this->payments()
            ->where('fee_type', $feeType)
            ->where('status', Payment::STATUS_PAID)
            ->exists();
    }

    public function hasPaidApplication(): bool
    {
        return $this->isPaid(CourseFee::FEE_APPLICATION);
    }

    public function hasPaidTuition(): bool
    {
        return $this->isPaid(CourseFee::FEE_TUITION);
    }

    public function hasPaidCertificate(): bool
    {
        return $this->isPaid(CourseFee::FEE_CERTIFICATE);
    }
}