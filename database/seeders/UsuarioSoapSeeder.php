<?php

// Declara el espacio de nombres de los seeders de la aplicacion.
namespace Database\Seeders;

// Importa el modelo User para crear el usuario de demostracion.
use App\Models\User;

// Importa el seeder base de Laravel.
use Illuminate\Database\Seeder;

// Importa el facade Hash para cifrar la contrasena.
use Illuminate\Support\Facades\Hash;

/**
 * Crea el usuario que se usara en Postman y en SoapUI.
 *
 * Credenciales:
 * - Correo: lenin@hotel.test
 * - Contrasena: 123456
 *
 * El seeder es idempotente: si el usuario ya existe, solo actualiza la
 * contrasena y no duplica registros.
 */
class UsuarioSoapSeeder extends Seeder
{
    /**
     * Ejecuta la creacion o actualizacion del usuario de demostracion.
     *
     * @return void
     */
    public function run(): void
    {
        // Crea el usuario si no existe o actualiza sus datos si ya existe.
        User::updateOrCreate(
            // Busca al usuario por su correo electronico.
            ['email' => 'lenin@hotel.test'],
            // Define los valores que se guardan o actualizan.
            [
                // Nombre visible del usuario.
                'name' => 'Lenin',
                // Cifra la contrasena antes de guardarla.
                'password' => Hash::make('123456'),
                // Marca el correo como verificado para evitar bloqueos.
                'email_verified_at' => now(),
            ]
        );
    }
}
