<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Mesa;

class TableSeeder extends Seeder
{
    public function run(): void
    {
        $tables = [];
        for ($i = 1; $i <= 16; $i++) {
            $isReserved = in_array($i, [4, 8, 12]);
            $capacity = [2, 4, 6, 8][rand(0, 3)];

            Mesa::create([
                'numero' => $i,
                'capacidad' => $capacity,
                'estado' => 'libre', // Inicialmente libres para simplificar
                'ubicacion_x' => rand(0, 100),
                'ubicacion_y' => rand(0, 100),
            ]);
        }
    }
}
