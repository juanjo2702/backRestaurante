<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Rol;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['nombre' => 'admin', 'descripcion' => 'Administrador del sistema'],
            ['nombre' => 'waiter', 'descripcion' => 'Mesero encargado de mesas'], // Mantenemos nombres en inglés para compatibilidad con lógica actual del front
            ['nombre' => 'kitchen', 'descripcion' => 'Personal de cocina'],
            ['nombre' => 'cashier', 'descripcion' => 'Cajero'],
            ['nombre' => 'client', 'descripcion' => 'Cliente final'],
        ];

        foreach ($roles as $rol) {
            Rol::create($rol);
        }
    }
}
