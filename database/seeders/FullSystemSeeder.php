<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class FullSystemSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            BoliviaReferenceSeeder::class,
            BoliviaRestaurantSeeder::class,
            OperationalDemoSeeder::class,
        ]);
    }
}
