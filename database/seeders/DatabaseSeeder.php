<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Admin accounts are never seeded; create one with `php artisan app:create-admin`.
     */
    public function run(): void
    {
        $this->call(ProductSeeder::class);
    }
}
