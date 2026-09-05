<?php
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
$u = User::factory()->admin()->create();
echo 'role='.$u->role.' id='.$u->id.PHP_EOL;
