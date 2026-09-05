<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentJournal extends Model
{
    use HasFactory;

    protected $table = 'payments_journal';

    public const EVENT_CREATED = 'created';

    public const EVENT_APPROVED = 'approved';

    public const EVENT_FAILED = 'failed';

    public const EVENT_REFUNDED = 'refunded';

    protected $fillable = [
        'payment_id',
        'event',
        'note',
        'created_by',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}