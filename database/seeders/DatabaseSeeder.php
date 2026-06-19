<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        \App\Models\ServiceTemplate::create([
            'key' => 'gojek',
            'name' => 'Gojek Ride & Order',
            'template_path' => 'gojek',
            'is_active' => true,
        ]);

        \App\Models\ServiceTemplate::create([
            'key' => 'shopee-spm',
            'name' => 'Shopee SPM Promotion',
            'template_path' => 'shopee-spm',
            'is_active' => true,
        ]);
    }
}
