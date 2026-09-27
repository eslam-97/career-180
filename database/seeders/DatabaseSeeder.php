<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * DemoSeeder, not ScaleSeeder: `migrate:fresh --seed` should leave a
     * database somebody can read. The 500,000-row fixture is opt-in —
     * `php artisan db:seed --class=ScaleSeeder`.
     */
    public function run(): void
    {
        // §13: the Filament panel is the only authenticated surface, and this
        // is the account that opens it.
        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $this->call(DemoSeeder::class);
    }
}
