<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Rol;
use Illuminate\Support\Str;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $roles = Rol::all();

        $users = [
            [
                'nombre' => 'Administrador',
                'email' => 'admin@test.com',
                'rol' => 'admin',
            ],
            [
                'nombre' => 'Carlos Mesero',
                'email' => 'mesero@test.com',
                'rol' => 'waiter',
            ],
            [
                'nombre' => 'Chef Pedro',
                'email' => 'cocina@test.com',
                'rol' => 'kitchen',
            ],
            [
                'nombre' => 'Cajero Principal',
                'email' => 'caja@test.com',
                'rol' => 'cashier',
            ],
        ];

        foreach ($users as $userData) {
            $role = $roles->firstWhere('nombre', $userData['rol']);

            User::create([
                'nombre' => $userData['nombre'],
                'email' => $userData['email'],
                'password' => 'password',
                'rol_id' => $role->id,
                'email_verified_at' => now(),
                'remember_token' => Str::random(10),
            ]);
        }
    }
}
