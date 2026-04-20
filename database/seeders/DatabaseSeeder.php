<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Admin credentials are configured via .env:
        //   ADMIN_EMAIL=admin@yourdomain.com
        //   ADMIN_PASSWORD=yourpassword
        //   ADMIN_NAME=Admin
        $email    = env('ADMIN_EMAIL', 'admin@' . parse_url(env('APP_URL', 'http://localhost'), PHP_URL_HOST) . '.com');
        $password = env('ADMIN_PASSWORD', 'changeme');
        $name     = env('ADMIN_NAME', 'Admin');

        User::updateOrCreate(
            ['email' => $email],
            [
                'name'              => $name,
                'password'          => Hash::make($password),
                'role'              => 'admin',
                'email_verified_at' => now(),
            ]
        );

        $this->command->info("Admin seeded: {$email}");
    }
}
