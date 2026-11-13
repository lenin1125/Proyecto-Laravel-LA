<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Crea los cuatro usuarios de demostracion, uno por rol.
 *
 * Todos usan la contrasena 123456. Es una credencial de prueba: en un
 * entorno real cada rol debe tener su propia clave.
 */
class UsuarioRolSeeder extends Seeder
{
    public function run(): void
    {
        $usuarios = [
            ['username' => 'administrador', 'email' => 'administrador@hotel.test', 'name' => 'Administrador', 'role' => User::ROLE_ADMIN],
            ['username' => 'servicio_habitacion', 'email' => 'servicio@hotel.test', 'name' => 'Servicio de habitacion', 'role' => User::ROLE_SERVICIO],
            ['username' => 'cajero', 'email' => 'cajero@hotel.test', 'name' => 'Cajero', 'role' => User::ROLE_CAJERO],
            ['username' => 'recepcionista', 'email' => 'recepcionista@hotel.test', 'name' => 'Recepcionista', 'role' => User::ROLE_RECEPCION],
        ];

        foreach ($usuarios as $datos) {
            User::updateOrCreate(
                ['email' => $datos['email']],
                [
                    'username' => $datos['username'],
                    'name' => $datos['name'],
                    'role' => $datos['role'],
                    'password' => Hash::make('123456'),
                    'email_verified_at' => now(),
                ]
            );
        }
    }
}
