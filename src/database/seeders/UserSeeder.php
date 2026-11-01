<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Date;

class UserSeeder extends Seeder
{
    private const string DEFAULT_PASSWORD = 'password';

    public function run(): void
    {
        $users = [
            ['name' => 'System Admin', 'email' => 'admin@example.com', 'role' => UserRole::ADMIN],
            ['name' => 'Agent One', 'email' => 'agent1@example.com', 'role' => UserRole::AGENT],
            ['name' => 'Agent Two', 'email' => 'agent2@example.com', 'role' => UserRole::AGENT],
            ['name' => 'Demo Customer', 'email' => 'customer@example.com', 'role' => UserRole::CUSTOMER],
        ];

        foreach ($users as $data) {
            // role / is_available are guarded, so use forceFill for trusted seed data.
            User::query()
                ->firstOrNew(['email' => $data['email']])
                ->forceFill([
                    'name' => $data['name'],
                    'password' => self::DEFAULT_PASSWORD,
                    'role' => $data['role'],
                    'is_available' => true,
                    'email_verified_at' => Date::now(),
                ])
                ->save();
        }
    }
}
