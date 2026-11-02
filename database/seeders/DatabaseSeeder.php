<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** This install runs one business: AC Villa. DemoSeeder's sample tenants are only seeded by the tests. */
    public function run(): void
    {
        $this->call(AcVillaSeeder::class);
    }
}
