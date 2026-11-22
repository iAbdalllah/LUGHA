<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Configuration data — safe in every environment.
        $this->call([RoleSeeder::class, ReferenceDataSeeder::class]);

        // Demo catalog and accounts — never in production (games and packages are created in the admin).
        if (! app()->environment('production')) {
            $this->call(DemoSeeder::class);
        }
    }
}
