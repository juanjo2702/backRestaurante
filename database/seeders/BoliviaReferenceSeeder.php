<?php

namespace Database\Seeders;

use App\Models\Rol;
use App\Models\Setting;
use Illuminate\Database\Seeder;

class BoliviaReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['nombre' => 'admin', 'descripcion' => 'Administrador del sistema'],
            ['nombre' => 'waiter', 'descripcion' => 'Mesero'],
            ['nombre' => 'kitchen', 'descripcion' => 'Cocina'],
            ['nombre' => 'cashier', 'descripcion' => 'Caja'],
            ['nombre' => 'client', 'descripcion' => 'Cliente'],
        ];

        foreach ($roles as $role) {
            Rol::updateOrCreate(['nombre' => $role['nombre']], $role);
        }

        $settings = [
            ['key' => 'restaurantName', 'value' => 'Gusto Bolivia', 'type' => 'string', 'group' => 'general'],
            ['key' => 'slogan', 'value' => 'Sabor boliviano con operación inteligente', 'type' => 'string', 'group' => 'general'],
            ['key' => 'phone', 'value' => '+591 4 4589001', 'type' => 'string', 'group' => 'general'],
            ['key' => 'email', 'value' => 'hola@gusto.bo', 'type' => 'string', 'group' => 'general'],
            ['key' => 'address', 'value' => 'Av. América Oeste N° 1200, Cochabamba, Bolivia', 'type' => 'string', 'group' => 'general'],
            ['key' => 'currencyCode', 'value' => 'BOB', 'type' => 'string', 'group' => 'payments'],
            ['key' => 'currencySymbol', 'value' => 'Bs', 'type' => 'string', 'group' => 'payments'],
            ['key' => 'timezone', 'value' => 'America/La_Paz', 'type' => 'string', 'group' => 'general'],
            ['key' => 'acceptCash', 'value' => 'true', 'type' => 'boolean', 'group' => 'payments'],
            ['key' => 'acceptCard', 'value' => 'true', 'type' => 'boolean', 'group' => 'payments'],
            ['key' => 'acceptQR', 'value' => 'true', 'type' => 'boolean', 'group' => 'payments'],
            ['key' => 'taxRate', 'value' => '13', 'type' => 'integer', 'group' => 'payments'],
            ['key' => 'openTime', 'value' => '11:30', 'type' => 'string', 'group' => 'hours'],
            ['key' => 'closeTime', 'value' => '23:00', 'type' => 'string', 'group' => 'hours'],
            ['key' => 'daysOpen', 'value' => json_encode(['lun', 'mar', 'mie', 'jue', 'vie', 'sab', 'dom']), 'type' => 'json', 'group' => 'hours'],
        ];

        foreach ($settings as $setting) {
            Setting::updateOrCreate(['key' => $setting['key']], $setting);
        }
    }
}
