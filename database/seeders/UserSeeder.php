<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * One account per role so the role-based UI can be tested easily.
     * Default password for all three is "12345678".
     */
    public function run(): void
    {
        $accounts = [
            [
                'role' => User::ROLE_LEARNER,
                'name' => 'Academy Learner',
                'email' => 'learner@custospark.com',
            ],
            [
                'role' => User::ROLE_INSTRUCTOR,
                'name' => 'Academy Instructor',
                'email' => 'instructor@custospark.com',
            ],
            [
                'role' => User::ROLE_ADMIN,
                'name' => 'Academy Admin',
                'email' => 'admin@custospark.com',
            ],
        ];

        foreach ($accounts as $account) {
            User::query()->updateOrCreate(
                ['email' => $account['email']],
                array_merge($account, [
                    'password' => Hash::make('12345678'),
                    'status' => User::STATUS_ACTIVE,
                ]),
            );
        }
    }
}