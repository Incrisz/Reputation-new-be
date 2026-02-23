<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    /**
     * Seed an admin user.
     */
    public function run(): void
    {
        $name = (string) env('ADMIN_SEED_NAME', 'System Admin');
        $email = (string) env('ADMIN_SEED_EMAIL', 'admin@reputationai.local');
        $password = (string) env('ADMIN_SEED_PASSWORD', 'admin12345');

        $admin = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'role' => 'admin',
                'password' => $password,
                'registration_provider' => 'email',
                'email_verified_at' => now(),
            ]
        );

        if ($this->command) {
            $this->command->info("Admin user seeded: {$admin->email}");
        }
    }
}
