<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Auth;

abstract class TestCase extends BaseTestCase
{
    /**
     * Authenticate as a user for the next request, resetting the guard cache.
     *
     * Guards cache the resolved user per container lifecycle. Because a single
     * test reuses the application container across requests (unlike real FPM
     * requests, which are fresh processes), the guard must be reset before
     * every authenticated request to avoid reusing a previous user.
     */
    protected function actingAsUser(User $user): static
    {
        Auth::forgetGuards();

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken,
        ]);
    }

    /**
     * Make the next request as an anonymous visitor. Needed after actingAsUser()
     * within the same test, because default headers (the bearer token) persist
     * across requests on the test client.
     */
    protected function asGuest(): static
    {
        Auth::forgetGuards();
        $this->flushHeaders();
        $this->defaultHeaders = [];

        return $this;
    }
}