<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a lifecycle transition is not permitted for the current state.
 * Rendered as a 422 Unprocessable Entity so clients can recover gracefully.
 */
class DomainException extends RuntimeException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }

    public function render(): \Illuminate\Http\JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}